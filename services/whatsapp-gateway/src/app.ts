import { join } from 'node:path';
import type { Logger } from 'pino';
import { AuthStateRepository } from './auth/auth-state-repository.ts';
import type { Config } from './config/env.ts';
import { WebhookOutbox } from './events/outbox.ts';
import { buildServer, type GatewayServer } from './http/server.ts';
import { MediaStore } from './messaging/media-store.ts';
import { StateCipher } from './security/crypto.ts';
import { SessionManager } from './sessions/session-manager.ts';
import { GatewayDatabase } from './store/database.ts';
import type { WhatsAppAdapter } from './whatsapp/adapter.ts';
import { BaileysAdapter } from './whatsapp/baileys-adapter.ts';
import { MockAdapter } from './whatsapp/mock-adapter.ts';

export type Gateway = {
    server: GatewayServer;
    sessions: SessionManager;
    outbox: WebhookOutbox;
    database: GatewayDatabase;
    adapter: WhatsAppAdapter;
    stop: () => Promise<void>;
};

const MEDIA_RETENTION_MS = 24 * 60 * 60 * 1000;
const SENT_LEDGER_RETENTION_MS = 30 * 24 * 60 * 60 * 1000;

/**
 * Wires the gateway together. `fetchImpl` and `adapter` are injectable for tests.
 */
export function createGateway(
    config: Config,
    logger: Logger,
    overrides: {
        adapter?: WhatsAppAdapter;
        fetchImpl?: typeof fetch;
        databasePath?: string;
    } = {},
): Gateway {
    const database = new GatewayDatabase(
        overrides.databasePath ?? join(config.dataDir, 'gateway.sqlite'),
    );
    const cipher = new StateCipher(config.stateKey);
    const authState = new AuthStateRepository(database, cipher);
    const media = new MediaStore(
        database,
        cipher,
        join(config.dataDir, 'media'),
    );
    const outbox = new WebhookOutbox(
        database,
        cipher,
        {
            webhookUrl: config.webhookUrl,
            webhookSecret: config.webhookSecret,
            timeoutMs: config.webhookTimeoutMs,
            maxAttempts: config.webhookMaxAttempts,
        },
        logger.child({ module: 'outbox' }),
        overrides.fetchImpl,
    );
    const adapter =
        overrides.adapter ??
        (config.adapter === 'mock'
            ? new MockAdapter(config.mockAutoConnectMs)
            : new BaileysAdapter(logger, config.maxMediaBytes));
    const sessions = new SessionManager(
        adapter,
        authState,
        database,
        outbox,
        media,
        {
            maxReconnectAttempts: config.maxReconnectAttempts,
            reconnectBaseMs: config.reconnectBaseMs,
            reconnectMaxMs: config.reconnectMaxMs,
            qrTtlSeconds: config.qrTtlSeconds,
            sendTimeoutMs: config.sendTimeoutMs,
        },
        logger.child({ module: 'sessions' }),
    );
    const server = buildServer({
        apiToken: config.apiToken,
        bodyLimitBytes: config.bodyLimitBytes,
        maxMediaBytes: config.maxMediaBytes,
        adapterName: adapter.name,
        sessions,
        outbox,
        media,
        database,
        logger,
    });

    const housekeeping = setInterval(
        () => {
            media.purgeOlderThan(MEDIA_RETENTION_MS);
            database.db
                .prepare('DELETE FROM sent_messages WHERE created_at < ?')
                .run(Date.now() - SENT_LEDGER_RETENTION_MS);
        },
        60 * 60 * 1000,
    );
    housekeeping.unref();

    return {
        server,
        sessions,
        outbox,
        database,
        adapter,
        stop: async () => {
            clearInterval(housekeeping);
            await server.close();
            await sessions.shutdown();
            await outbox.drain().catch(() => undefined);
            await outbox.stop();
            database.close();
        },
    };
}
