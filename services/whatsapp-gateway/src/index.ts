import { ConfigError, loadConfig } from './config/env.ts';
import { createGateway } from './app.ts';
import { createLogger } from './utils/logger.ts';

let config;

try {
    config = loadConfig();
} catch (error) {
    if (error instanceof ConfigError) {
        console.error(error.message);
        process.exit(1);
    }

    throw error;
}

const logger = createLogger(config.logLevel);
const gateway = createGateway(config, logger);
let stopping = false;

async function shutdown(signal: string): Promise<void> {
    if (stopping) {
        return;
    }

    stopping = true;
    logger.info({ signal }, 'shutting down');
    const forceExit = setTimeout(() => process.exit(1), 15_000);
    forceExit.unref();

    try {
        await gateway.stop();
        logger.info('shutdown complete');
        process.exit(0);
    } catch (error) {
        logger.error({ err: error }, 'shutdown failed');
        process.exit(1);
    }
}

process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));
process.on('unhandledRejection', (reason) =>
    logger.error({ err: reason }, 'unhandled rejection'),
);
process.on('uncaughtException', (error) => {
    logger.fatal({ err: error }, 'uncaught exception');
    process.exit(1);
});

gateway.outbox.start();
await gateway.server.listen({ host: config.host, port: config.port });
const restored = await gateway.sessions.restore();
logger.info(
    {
        host: config.host,
        port: config.port,
        adapter: gateway.adapter.name,
        restored,
    },
    'gateway ready',
);
