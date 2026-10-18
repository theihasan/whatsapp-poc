import { randomUUID } from 'node:crypto';
import type { Logger } from 'pino';
import type { StateCipher } from '../security/crypto.ts';
import { signWebhook } from '../security/auth.ts';
import type { GatewayDatabase } from '../store/database.ts';
import { backoffDelay } from '../utils/backoff.ts';
import {
    SCHEMA_VERSION,
    type EventSink,
    type GatewayEvent,
    type GatewayEventType,
} from './types.ts';

export type OutboxOptions = {
    webhookUrl: string;
    webhookSecret: string;
    timeoutMs: number;
    maxAttempts: number;
    retryBaseMs?: number;
    retryMaxMs?: number;
    pollIntervalMs?: number;
};

type OutboxRow = {
    id: number;
    event_id: string;
    body: string;
    attempts: number;
};

/**
 * Durable webhook delivery. Events are written (encrypted) to SQLite before any network
 * call, delivered in order, and retried with exponential backoff until Laravel answers
 * 2xx. 4xx responses other than 408/429 are permanent and dead-lettered. Delivery is
 * at-least-once; Laravel deduplicates by eventId.
 */
export class WebhookOutbox implements EventSink {
    #timer: NodeJS.Timeout | undefined;
    #draining: Promise<void> | undefined;
    #stopped = true;

    private readonly database: GatewayDatabase;
    private readonly cipher: StateCipher;
    private readonly options: OutboxOptions;
    private readonly logger: Logger;
    private readonly fetchImpl: typeof fetch;
    private readonly now: () => number;

    constructor(
        database: GatewayDatabase,
        cipher: StateCipher,
        options: OutboxOptions,
        logger: Logger,
        fetchImpl: typeof fetch = fetch,
        now: () => number = Date.now,
    ) {
        this.database = database;
        this.cipher = cipher;
        this.options = options;
        this.logger = logger;
        this.fetchImpl = fetchImpl;
        this.now = now;
    }

    publish(
        type: GatewayEventType,
        sessionId: string,
        payload: Record<string, unknown>,
    ): GatewayEvent {
        const event: GatewayEvent = {
            eventId: randomUUID(),
            type,
            schemaVersion: SCHEMA_VERSION,
            sessionId,
            occurredAt: new Date(this.now()).toISOString(),
            payload,
        };

        this.database.db
            .prepare(
                `INSERT INTO outbox (event_id, session_id, type, body, next_attempt_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)`,
            )
            .run(
                event.eventId,
                sessionId,
                type,
                this.cipher.encrypt(
                    JSON.stringify(event),
                    `outbox:${event.eventId}`,
                ),
                this.now(),
                this.now(),
            );

        this.logger.debug(
            { eventId: event.eventId, type, sessionId },
            'event queued',
        );
        this.kick();

        return event;
    }

    start(): void {
        this.#stopped = false;
        this.#schedule(0);
    }

    /**
     * Stops polling and waits (bounded) for an in-flight delivery to settle.
     */
    async stop(flushTimeoutMs = 5_000): Promise<void> {
        this.#stopped = true;
        clearTimeout(this.#timer);

        if (this.#draining !== undefined) {
            await Promise.race([
                this.#draining,
                new Promise((resolve) => setTimeout(resolve, flushTimeoutMs)),
            ]);
        }
    }

    kick(): void {
        if (!this.#stopped && this.#draining === undefined) {
            this.#schedule(0);
        }
    }

    stats(): { pending: number; dead: number } {
        const rows = this.database.db
            .prepare(
                `SELECT status, COUNT(*) AS total FROM outbox GROUP BY status`,
            )
            .all() as { status: string; total: number }[];

        return {
            pending: rows.find((row) => row.status === 'pending')?.total ?? 0,
            dead: rows.find((row) => row.status === 'dead')?.total ?? 0,
        };
    }

    /**
     * Delivers pending events strictly in insertion order. Stops at the first event that
     * is still backing off or fails transiently, so events are never delivered out of order.
     */
    async drain(): Promise<void> {
        const pending = this.database.db
            .prepare(
                `SELECT id, event_id, body, attempts, next_attempt_at FROM outbox WHERE status = 'pending' ORDER BY id LIMIT 50`,
            )
            .all() as (OutboxRow & { next_attempt_at: number })[];

        for (const row of pending) {
            if (row.next_attempt_at > this.now()) {
                return;
            }

            const delivered = await this.#deliver(row);

            if (!delivered) {
                return;
            }
        }
    }

    #schedule(delayMs: number): void {
        clearTimeout(this.#timer);

        this.#timer = setTimeout(() => {
            this.#draining = this.drain()
                .catch((error: unknown) =>
                    this.logger.error({ err: error }, 'outbox drain failed'),
                )
                .finally(() => {
                    this.#draining = undefined;

                    if (!this.#stopped) {
                        this.#schedule(
                            this.#hasDueWork()
                                ? 0
                                : (this.options.pollIntervalMs ?? 1_000),
                        );
                    }
                });
        }, delayMs);
        this.#timer.unref();
    }

    #hasDueWork(): boolean {
        const head = this.database.db
            .prepare(
                `SELECT next_attempt_at FROM outbox WHERE status = 'pending' ORDER BY id LIMIT 1`,
            )
            .get() as { next_attempt_at: number } | undefined;

        return head !== undefined && head.next_attempt_at <= this.now();
    }

    /**
     * @returns true when the row is finished (delivered or dead), false to pause the queue.
     */
    async #deliver(row: OutboxRow): Promise<boolean> {
        const body = this.cipher.decryptString(
            row.body,
            `outbox:${row.event_id}`,
        );
        const timestamp = Math.floor(this.now() / 1000);
        let status = 0;
        let failure: string;

        try {
            const response = await this.fetchImpl(this.options.webhookUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Gateway-Event-Id': row.event_id,
                    'X-Gateway-Timestamp': String(timestamp),
                    'X-Gateway-Signature': signWebhook(
                        this.options.webhookSecret,
                        timestamp,
                        body,
                    ),
                },
                body,
                signal: AbortSignal.timeout(this.options.timeoutMs),
            });
            status = response.status;

            if (response.ok) {
                this.database.db
                    .prepare('DELETE FROM outbox WHERE id = ?')
                    .run(row.id);

                return true;
            }

            failure = `HTTP ${response.status}`;
        } catch (error) {
            failure = error instanceof Error ? error.name : 'network error';
        }

        const attempts = row.attempts + 1;
        const permanent =
            status >= 400 && status < 500 && status !== 408 && status !== 429;

        if (permanent || attempts >= this.options.maxAttempts) {
            this.database.db
                .prepare(
                    `UPDATE outbox SET status = 'dead', attempts = ?, last_error = ? WHERE id = ?`,
                )
                .run(attempts, failure, row.id);
            this.logger.error(
                { eventId: row.event_id, attempts, failure },
                'webhook event dead-lettered',
            );

            return true;
        }

        const delay = backoffDelay(
            attempts,
            this.options.retryBaseMs ?? 1_000,
            this.options.retryMaxMs ?? 300_000,
        );
        this.database.db
            .prepare(
                'UPDATE outbox SET attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ?',
            )
            .run(attempts, this.now() + delay, failure, row.id);
        this.logger.warn(
            { eventId: row.event_id, attempts, failure, retryInMs: delay },
            'webhook delivery failed',
        );

        return false;
    }
}
