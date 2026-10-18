import type { Logger } from 'pino';
import type { AuthStateRepository } from '../auth/auth-state-repository.ts';
import type { EventSink } from '../events/types.ts';
import type { MediaStore } from '../messaging/media-store.ts';
import type { GatewayDatabase } from '../store/database.ts';
import { backoffDelay, withTimeout } from '../utils/backoff.ts';
import {
    sendRejected,
    sendTimeout,
    sessionNotConnected,
    sessionNotFound,
    shuttingDown,
} from '../utils/errors.ts';
import type {
    AccountInfo,
    CloseReason,
    ContactIdentity,
    IncomingMessage,
    OutgoingContent,
    WhatsAppAdapter,
    WhatsAppConnection,
} from '../whatsapp/adapter.ts';

export type SessionStatus =
    | 'connecting'
    | 'qr_ready'
    | 'connected'
    | 'reconnecting'
    | 'disconnected'
    | 'logged_out'
    | 'failed';

export type SessionManagerOptions = {
    maxReconnectAttempts: number;
    reconnectBaseMs: number;
    reconnectMaxMs: number;
    qrTtlSeconds: number;
    sendTimeoutMs: number;
};

type Session = {
    id: string;
    status: SessionStatus;
    connection: WhatsAppConnection | null;
    /** Incremented on every (re)connect so callbacks from an old socket are ignored. */
    generation: number;
    reconnectAttempts: number;
    reconnectTimer: NodeJS.Timeout | null;
    qr: { value: string; expiresAt: number } | null;
    account: AccountInfo | null;
    lastError: string | null;
};

export type SessionView = {
    id: string;
    status: SessionStatus;
    phoneNumber: string | null;
    jid: string | null;
    displayName: string | null;
    lastError: string | null;
    reconnectAttempts: number;
    hasCredentials: boolean;
};

const ACTIVE: SessionStatus[] = [
    'connecting',
    'qr_ready',
    'connected',
    'reconnecting',
];

/**
 * Owns every WhatsApp socket. Exactly one connection per session id exists at a time;
 * all lifecycle transitions and their events are produced here.
 */
export class SessionManager {
    readonly #sessions = new Map<string, Session>();
    readonly #inflightSends = new Map<
        string,
        Promise<{ messageId: string; duplicate: boolean }>
    >();
    #shuttingDown = false;

    private readonly adapter: WhatsAppAdapter;
    private readonly authState: AuthStateRepository;
    private readonly database: GatewayDatabase;
    private readonly events: EventSink;
    private readonly media: MediaStore;
    private readonly options: SessionManagerOptions;
    private readonly logger: Logger;

    constructor(
        adapter: WhatsAppAdapter,
        authState: AuthStateRepository,
        database: GatewayDatabase,
        events: EventSink,
        media: MediaStore,
        options: SessionManagerOptions,
        logger: Logger,
    ) {
        this.adapter = adapter;
        this.authState = authState;
        this.database = database;
        this.events = events;
        this.media = media;
        this.options = options;
        this.logger = logger;
    }

    /**
     * Start a session, or return the existing one when it is already active (never opens
     * a second socket for the same session).
     */
    async start(sessionId: string): Promise<SessionView> {
        this.#assertRunning();
        const existing = this.#sessions.get(sessionId);

        if (existing !== undefined && ACTIVE.includes(existing.status)) {
            return this.view(sessionId);
        }

        this.#setDesiredState(sessionId, 'running');
        const session = existing ?? this.#create(sessionId);
        session.reconnectAttempts = 0;
        session.lastError = null;
        await this.#connect(session);

        return this.view(sessionId);
    }

    /** Force a fresh socket for an existing session (e.g. stuck reconnecting). */
    async reconnect(sessionId: string): Promise<SessionView> {
        this.#assertRunning();
        const session = this.#require(sessionId);
        this.#clearReconnect(session);
        await this.#closeConnection(session);
        session.reconnectAttempts = 0;
        this.#setDesiredState(sessionId, 'running');
        await this.#connect(session);

        return this.view(sessionId);
    }

    /** Close the socket but keep credentials. */
    async disconnect(
        sessionId: string,
        reason = 'manual',
    ): Promise<SessionView> {
        const known = this.#sessions.get(sessionId);

        if (known === undefined && !this.authState.hasCredentials(sessionId)) {
            throw sessionNotFound();
        }

        const session = known ?? this.#create(sessionId);
        this.#setDesiredState(sessionId, 'stopped');
        this.#clearReconnect(session);
        await this.#closeConnection(session);
        this.#transition(session, 'disconnected', 'session.disconnected', {
            reason,
        });

        return this.view(sessionId);
    }

    /** Unlink the device and delete all stored credentials and keys. */
    async revoke(sessionId: string): Promise<void> {
        const session = this.#sessions.get(sessionId);

        if (session !== undefined) {
            this.#clearReconnect(session);
            const connection = session.connection;
            session.generation++;
            session.connection = null;

            if (connection !== null && session.status === 'connected') {
                await connection
                    .logout()
                    .catch((error: unknown) =>
                        this.logger.warn(
                            { err: error, sessionId },
                            'logout failed',
                        ),
                    );
            }

            await connection?.close().catch(() => undefined);
        }

        this.authState.deleteSession(sessionId);
        this.database.db
            .prepare('DELETE FROM sessions WHERE session_id = ?')
            .run(sessionId);
        this.database.db
            .prepare('DELETE FROM sent_messages WHERE session_id = ?')
            .run(sessionId);

        if (session !== undefined) {
            this.#transition(session, 'logged_out', 'session.logged_out', {
                reason: 'revoked',
            });
            this.#sessions.delete(sessionId);
        } else {
            this.events.publish('session.logged_out', sessionId, {
                reason: 'revoked',
            });
        }
    }

    /**
     * Idempotent send: the same idempotency key always maps to the same WhatsApp message.
     */
    async send(
        sessionId: string,
        idempotencyKey: string,
        to: string,
        content: OutgoingContent,
    ): Promise<{ messageId: string; duplicate: boolean }> {
        this.#assertRunning();
        const ledgerKey = `${sessionId}:${idempotencyKey}`;
        const previous = this.#lookupSent(sessionId, idempotencyKey);

        if (previous !== null) {
            return { messageId: previous, duplicate: true };
        }

        const inflight = this.#inflightSends.get(ledgerKey);

        if (inflight !== undefined) {
            return inflight.then((result) => ({ ...result, duplicate: true }));
        }

        const session = this.#require(sessionId);

        if (session.status !== 'connected' || session.connection === null) {
            throw sessionNotConnected();
        }

        const connection = session.connection;
        const attempt = (async () => {
            let messageId: string;

            try {
                ({ messageId } = await withTimeout(
                    connection.send(to, content),
                    this.options.sendTimeoutMs,
                    sendTimeout,
                ));
            } catch (error) {
                if (error instanceof Error && 'code' in error) {
                    throw error;
                }

                throw sendRejected(
                    error instanceof Error ? error.message : 'Send failed.',
                );
            }

            this.database.db
                .prepare(
                    'INSERT OR IGNORE INTO sent_messages (session_id, idempotency_key, message_id, created_at) VALUES (?, ?, ?, ?)',
                )
                .run(sessionId, idempotencyKey, messageId, Date.now());

            return { messageId, duplicate: false };
        })();

        this.#inflightSends.set(ledgerKey, attempt);

        try {
            return await attempt;
        } finally {
            this.#inflightSends.delete(ledgerKey);
        }
    }

    async profilePicture(
        sessionId: string,
        jid: string,
    ): Promise<{ data: Buffer; mimeType: string } | null> {
        const session = this.#require(sessionId);

        if (session.status !== 'connected' || session.connection === null) {
            throw sessionNotConnected();
        }

        return session.connection.profilePicture(jid);
    }

    /**
     * Phone-number identities for LIDs, from the session's local mapping store only.
     */
    async resolveLids(
        sessionId: string,
        lids: string[],
    ): Promise<ContactIdentity[]> {
        const session = this.#require(sessionId);

        if (session.status !== 'connected' || session.connection === null) {
            throw sessionNotConnected();
        }

        return session.connection.resolveLids(lids);
    }

    view(sessionId: string): SessionView {
        const session = this.#sessions.get(sessionId);
        const hasCredentials = this.authState.hasCredentials(sessionId);

        if (session === undefined) {
            if (!hasCredentials) {
                throw sessionNotFound();
            }

            return {
                id: sessionId,
                status: 'disconnected',
                phoneNumber: null,
                jid: null,
                displayName: null,
                lastError: null,
                reconnectAttempts: 0,
                hasCredentials,
            };
        }

        return {
            id: session.id,
            status: session.status,
            phoneNumber: session.account?.phoneNumber ?? null,
            jid: session.account?.jid ?? null,
            displayName: session.account?.displayName ?? null,
            lastError: session.lastError,
            reconnectAttempts: session.reconnectAttempts,
            hasCredentials,
        };
    }

    qr(sessionId: string): { qr: string; expiresAt: string } | null {
        const qr = this.#require(sessionId).qr;

        return qr === null || qr.expiresAt <= Date.now()
            ? null
            : { qr: qr.value, expiresAt: new Date(qr.expiresAt).toISOString() };
    }

    counts(): { total: number; connected: number } {
        const sessions = [...this.#sessions.values()];

        return {
            total: sessions.length,
            connected: sessions.filter(
                (session) => session.status === 'connected',
            ).length,
        };
    }

    /** Resume every session that was running and still has credentials. */
    async restore(): Promise<number> {
        const rows = this.database.db
            .prepare(
                `SELECT session_id FROM sessions WHERE desired_state = 'running'`,
            )
            .all() as { session_id: string }[];
        let restored = 0;

        for (const { session_id: sessionId } of rows) {
            if (!this.authState.isRegistered(sessionId)) {
                continue;
            }

            await this.start(sessionId).catch((error: unknown) =>
                this.logger.error({ err: error, sessionId }, 'restore failed'),
            );
            restored++;
        }

        return restored;
    }

    /** Close every socket without logging out; desired state is kept for restore. */
    async shutdown(): Promise<void> {
        this.#shuttingDown = true;

        for (const session of this.#sessions.values()) {
            this.#clearReconnect(session);
            const wasActive = ACTIVE.includes(session.status);
            await this.#closeConnection(session);

            if (wasActive) {
                this.#transition(
                    session,
                    'disconnected',
                    'session.disconnected',
                    { reason: 'gateway_shutdown' },
                );
            }
        }
    }

    #create(sessionId: string): Session {
        const session: Session = {
            id: sessionId,
            status: 'disconnected',
            connection: null,
            generation: 0,
            reconnectAttempts: 0,
            reconnectTimer: null,
            qr: null,
            account: null,
            lastError: null,
        };
        this.#sessions.set(sessionId, session);

        return session;
    }

    async #connect(session: Session): Promise<void> {
        const generation = ++session.generation;
        const current = () =>
            session.generation === generation && !this.#shuttingDown;

        this.#transition(session, 'connecting', 'session.connecting', {});

        try {
            session.connection = await this.adapter.connect(
                session.id,
                this.authState.handle(session.id),
                {
                    onQr: (qr) => {
                        if (!current()) {
                            return;
                        }

                        session.qr = {
                            value: qr,
                            expiresAt:
                                Date.now() + this.options.qrTtlSeconds * 1000,
                        };
                        this.#transition(
                            session,
                            'qr_ready',
                            'session.qr.updated',
                            {
                                qr,
                                expiresInSeconds: this.options.qrTtlSeconds,
                            },
                        );
                    },
                    onOpen: (account) => {
                        if (!current()) {
                            return;
                        }

                        session.account = account;
                        session.qr = null;
                        session.reconnectAttempts = 0;
                        session.lastError = null;
                        this.#transition(
                            session,
                            'connected',
                            'session.connected',
                            { ...account },
                        );
                    },
                    onClose: (reason) => {
                        if (current()) {
                            this.#handleClose(session, reason);
                        }
                    },
                    onMessage: (message) => {
                        if (current()) {
                            void this.#publishIncoming(session.id, message);
                        }
                    },
                    onIdentity: (identity) => {
                        if (current()) {
                            this.events.publish(
                                'contact.identified',
                                session.id,
                                { ...identity },
                            );
                        }
                    },
                    onStatus: (messageId, status) => {
                        if (!current()) {
                            return;
                        }

                        const idempotencyKey = this.#idempotencyKeyFor(
                            session.id,
                            messageId,
                        );

                        if (status === 'failed') {
                            this.events.publish('message.failed', session.id, {
                                externalId: messageId,
                                idempotencyKey,
                                reason: 'WhatsApp reported the message as failed.',
                            });

                            return;
                        }

                        this.events.publish(
                            'message.status.updated',
                            session.id,
                            {
                                externalId: messageId,
                                idempotencyKey,
                                status,
                                timestamp: new Date().toISOString(),
                            },
                        );
                    },
                },
            );
        } catch (error) {
            if (current()) {
                this.#handleClose(session, {
                    kind: 'transient',
                    message:
                        error instanceof Error
                            ? error.message
                            : 'connect failed',
                });
            }
        }
    }

    #handleClose(session: Session, reason: CloseReason): void {
        session.connection = null;
        session.qr = null;
        session.lastError = reason.message;
        this.logger.info(
            { sessionId: session.id, kind: reason.kind },
            'connection closed',
        );

        switch (reason.kind) {
            case 'restart_required':
                void this.#connect(session);

                return;
            case 'logged_out':
                this.authState.deleteSession(session.id);
                this.#setDesiredState(session.id, 'stopped');
                this.#transition(session, 'logged_out', 'session.logged_out', {
                    reason: reason.message,
                });

                return;
            case 'connection_replaced':
            case 'qr_timeout':
                this.#setDesiredState(session.id, 'stopped');
                this.#transition(
                    session,
                    'disconnected',
                    'session.disconnected',
                    { reason: reason.message },
                );

                return;
            case 'fatal':
                this.#setDesiredState(session.id, 'stopped');
                this.#transition(session, 'failed', 'session.failed', {
                    reason: reason.message,
                });

                return;
            case 'transient':
                this.#scheduleReconnect(session, reason.message);
        }
    }

    #scheduleReconnect(session: Session, reason: string): void {
        session.reconnectAttempts++;

        if (session.reconnectAttempts > this.options.maxReconnectAttempts) {
            this.#setDesiredState(session.id, 'stopped');
            this.#transition(session, 'failed', 'session.failed', {
                reason: `Gave up after ${this.options.maxReconnectAttempts} reconnection attempts: ${reason}`,
            });

            return;
        }

        const delay = backoffDelay(
            session.reconnectAttempts,
            this.options.reconnectBaseMs,
            this.options.reconnectMaxMs,
        );
        this.#transition(session, 'reconnecting', 'session.reconnecting', {
            reason,
            attempt: session.reconnectAttempts,
            retryInMs: delay,
        });

        this.#clearReconnect(session);
        session.reconnectTimer = setTimeout(() => {
            session.reconnectTimer = null;
            void this.#connect(session);
        }, delay);
        session.reconnectTimer.unref();
    }

    async #publishIncoming(
        sessionId: string,
        message: IncomingMessage,
    ): Promise<void> {
        let media: Record<string, unknown> | null = null;

        if (message.media !== null) {
            try {
                const data = await message.media.download();
                const stored = this.media.put(
                    sessionId,
                    data,
                    message.media.mimeType,
                    message.media.fileName,
                );
                media = {
                    mediaId: stored.id,
                    mimeType: stored.mimeType,
                    fileName: stored.fileName,
                    size: stored.size,
                };
            } catch (error) {
                this.logger.warn(
                    { err: error, sessionId, externalId: message.externalId },
                    'media download failed',
                );
                media = { error: 'Media could not be downloaded.' };
            }
        }

        this.events.publish('message.received', sessionId, {
            externalId: message.externalId,
            from: message.from,
            chatType: message.chatType,
            participant: message.participant,
            type: message.type,
            body: message.body,
            rawType: message.type === 'unsupported' ? message.rawType : null,
            timestamp: message.timestamp,
            media,
        });
    }

    #transition(
        session: Session,
        status: SessionStatus,
        eventType: Parameters<EventSink['publish']>[0],
        payload: Record<string, unknown>,
    ): void {
        session.status = status;
        this.events.publish(eventType, session.id, payload);
    }

    async #closeConnection(session: Session): Promise<void> {
        const connection = session.connection;
        session.generation++;
        session.connection = null;
        session.qr = null;
        await connection
            ?.close()
            .catch((error: unknown) =>
                this.logger.warn(
                    { err: error, sessionId: session.id },
                    'close failed',
                ),
            );
    }

    #clearReconnect(session: Session): void {
        if (session.reconnectTimer !== null) {
            clearTimeout(session.reconnectTimer);
            session.reconnectTimer = null;
        }
    }

    #require(sessionId: string): Session {
        const session = this.#sessions.get(sessionId);

        if (session === undefined) {
            throw sessionNotFound();
        }

        return session;
    }

    #assertRunning(): void {
        if (this.#shuttingDown) {
            throw shuttingDown();
        }
    }

    #setDesiredState(sessionId: string, state: 'running' | 'stopped'): void {
        this.database.db
            .prepare(
                `INSERT INTO sessions (session_id, desired_state, created_at, updated_at) VALUES (?, ?, ?, ?)
                 ON CONFLICT (session_id) DO UPDATE SET desired_state = excluded.desired_state, updated_at = excluded.updated_at`,
            )
            .run(sessionId, state, Date.now(), Date.now());
    }

    #lookupSent(sessionId: string, idempotencyKey: string): string | null {
        const row = this.database.db
            .prepare(
                'SELECT message_id FROM sent_messages WHERE session_id = ? AND idempotency_key = ?',
            )
            .get(sessionId, idempotencyKey) as
            | { message_id: string }
            | undefined;

        return row?.message_id ?? null;
    }

    #idempotencyKeyFor(sessionId: string, messageId: string): string | null {
        const row = this.database.db
            .prepare(
                'SELECT idempotency_key FROM sent_messages WHERE session_id = ? AND message_id = ?',
            )
            .get(sessionId, messageId) as
            | { idempotency_key: string }
            | undefined;

        return row?.idempotency_key ?? null;
    }
}
