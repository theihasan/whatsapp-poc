import { randomBytes } from 'node:crypto';
import {
    isPaired,
    type AuthStateHandle,
} from '../auth/auth-state-repository.ts';
import type {
    ConnectionHandlers,
    ContactIdentity,
    OutgoingContent,
    WhatsAppAdapter,
    WhatsAppConnection,
} from './adapter.ts';

/**
 * Deterministic stand-in for WhatsApp, used for tests and end-to-end demos of the
 * gateway without a phone. A session without stored credentials shows a QR; after
 * `autoConnectMs` (or `scan()`) it "pairs", persists fake credentials and opens. Like
 * real Baileys QR pairing, a scan sets `creds.me` but leaves `registered` false.
 * Text containing "#fail" throws on send, "#hang" never resolves.
 */
export class MockAdapter implements WhatsAppAdapter {
    readonly name = 'mock' as const;
    readonly connections = new Map<
        string,
        { handlers: ConnectionHandlers; auth: AuthStateHandle; open: boolean }
    >();
    connectCalls = 0;
    readonly lidMappings = new Map<string, ContactIdentity>();
    readonly avatars = new Map<string, { data: Buffer; mimeType: string }>();
    private readonly autoConnectMs: number;

    constructor(autoConnectMs = 0) {
        this.autoConnectMs = autoConnectMs;
    }

    async connect(
        sessionId: string,
        auth: AuthStateHandle,
        handlers: ConnectionHandlers,
    ): Promise<WhatsAppConnection> {
        this.connectCalls++;
        const entry = { handlers, auth, open: false };
        this.connections.set(sessionId, entry);

        queueMicrotask(() => {
            if (isPaired(auth.state.creds)) {
                this.#open(sessionId);

                return;
            }

            handlers.onQr(
                `MOCK-GW-QR:${sessionId}:${randomBytes(6).toString('hex')}`,
            );

            if (this.autoConnectMs > 0) {
                setTimeout(
                    () => void this.scan(sessionId),
                    this.autoConnectMs,
                ).unref();
            }
        });

        return {
            send: async (to: string, content: OutgoingContent) => {
                if (!entry.open) {
                    throw new Error('Mock connection is not open.');
                }

                const text =
                    content.type === 'text'
                        ? content.text
                        : (content.caption ?? '');

                if (text.includes('#fail')) {
                    throw new Error('Simulated send failure.');
                }

                if (text.includes('#hang')) {
                    return new Promise<never>(() => {});
                }

                const messageId = `MOCKGW${randomBytes(8).toString('hex').toUpperCase()}`;
                setTimeout(
                    () => handlers.onStatus(messageId, 'sent'),
                    10,
                ).unref();
                setTimeout(
                    () => handlers.onStatus(messageId, 'delivered'),
                    20,
                ).unref();

                return { messageId };
            },
            close: async () => {
                entry.open = false;
            },
            logout: async () => {
                entry.open = false;
            },
            profilePicture: async (jid: string) =>
                this.avatars.get(jid) ?? null,
            resolveLids: async (lids: string[]) =>
                lids
                    .filter((lid) => this.lidMappings.has(lid))
                    .map((lid) => this.lidMappings.get(lid)!),
        };
    }

    /** Simulates the phone scanning the QR code. */
    async scan(sessionId: string): Promise<void> {
        const entry = this.connections.get(sessionId);

        if (entry === undefined || isPaired(entry.auth.state.creds)) {
            return;
        }

        entry.auth.state.creds.me = {
            id: '15550100100:1@s.whatsapp.net',
            name: 'Mock gateway device',
        };
        await entry.auth.saveCreds();
        this.#open(sessionId);
    }

    /** Simulates a dropped connection with the given close kind. */
    drop(
        sessionId: string,
        kind: 'transient' | 'logged_out' | 'connection_replaced' = 'transient',
    ): void {
        const entry = this.connections.get(sessionId);

        if (entry !== undefined) {
            entry.open = false;
            entry.handlers.onClose({
                kind,
                message: `Simulated ${kind} close`,
            });
        }
    }

    /** Simulates WhatsApp revealing the phone number behind a LID. */
    identify(sessionId: string, identity: ContactIdentity): void {
        this.lidMappings.set(identity.lid, identity);
        this.connections.get(sessionId)?.handlers.onIdentity(identity);
    }

    /** Simulates a direct-chat reaction (empty emoji = removed). */
    react(
        sessionId: string,
        fromPhone: string,
        targetExternalId: string,
        emoji: string,
    ): void {
        this.connections.get(sessionId)?.handlers.onReaction({
            externalId: `MOCKGWRX${randomBytes(8).toString('hex').toUpperCase()}`,
            targetExternalId,
            emoji,
            from: {
                jid: `${fromPhone.replace('+', '')}@s.whatsapp.net`,
                lid: null,
                phoneNumber: fromPhone,
                pushName: 'Mock contact',
            },
            chatType: 'direct',
            participant: null,
            timestamp: new Date().toISOString(),
        });
    }

    /** Simulates an incoming text message, optionally inside a group. */
    receive(
        sessionId: string,
        fromPhone: string,
        text: string,
        group?: { jid: string; subject: string },
    ): void {
        const sender = {
            jid: `${fromPhone.replace('+', '')}@s.whatsapp.net`,
            lid: null,
            phoneNumber: fromPhone,
            pushName: 'Mock contact',
        };

        this.connections.get(sessionId)?.handlers.onMessage({
            externalId: `MOCKGWIN${randomBytes(8).toString('hex').toUpperCase()}`,
            from: group
                ? {
                      jid: group.jid,
                      lid: null,
                      phoneNumber: null,
                      pushName: group.subject,
                  }
                : sender,
            chatType: group ? 'group' : 'direct',
            participant: group ? sender : null,
            type: 'text',
            body: text,
            rawType: 'conversation',
            timestamp: new Date().toISOString(),
            media: null,
        });
    }

    #open(sessionId: string): void {
        const entry = this.connections.get(sessionId);

        if (entry === undefined) {
            return;
        }

        entry.open = true;
        entry.handlers.onOpen({
            jid: '15550100100:1@s.whatsapp.net',
            phoneNumber: '+15550100100',
            displayName: 'Mock gateway device',
        });
    }
}
