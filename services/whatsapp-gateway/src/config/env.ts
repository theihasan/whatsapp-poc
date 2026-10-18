import { z } from 'zod';

const base64Key = z
    .string()
    .refine(
        (value) => Buffer.from(value, 'base64').length === 32,
        'must be 32 random bytes encoded as base64 (openssl rand -base64 32)',
    );

const schema = z.object({
    NODE_ENV: z
        .enum(['development', 'test', 'production'])
        .default('development'),
    GATEWAY_HOST: z.string().default('127.0.0.1'),
    GATEWAY_PORT: z.coerce.number().int().min(1).max(65535).default(3100),
    GATEWAY_API_TOKEN: z.string().min(32, 'must be at least 32 characters'),
    GATEWAY_WEBHOOK_URL: z.url(),
    GATEWAY_WEBHOOK_SECRET: z
        .string()
        .min(32, 'must be at least 32 characters'),
    GATEWAY_STATE_KEY: base64Key,
    GATEWAY_DATA_DIR: z.string().default('./data'),
    GATEWAY_ADAPTER: z.enum(['baileys', 'mock']).default('baileys'),
    GATEWAY_LOG_LEVEL: z
        .enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent'])
        .default('info'),
    GATEWAY_MAX_RECONNECT_ATTEMPTS: z.coerce
        .number()
        .int()
        .min(0)
        .max(50)
        .default(8),
    GATEWAY_RECONNECT_BASE_MS: z.coerce.number().int().min(100).default(2_000),
    GATEWAY_RECONNECT_MAX_MS: z.coerce
        .number()
        .int()
        .min(1_000)
        .default(120_000),
    GATEWAY_QR_TTL_SECONDS: z.coerce.number().int().min(5).max(120).default(20),
    GATEWAY_SEND_TIMEOUT_MS: z.coerce.number().int().min(1_000).default(15_000),
    GATEWAY_MAX_MEDIA_BYTES: z.coerce
        .number()
        .int()
        .min(1)
        .default(16 * 1024 * 1024),
    GATEWAY_BODY_LIMIT_BYTES: z.coerce
        .number()
        .int()
        .min(1024)
        .default(24 * 1024 * 1024),
    GATEWAY_WEBHOOK_TIMEOUT_MS: z.coerce
        .number()
        .int()
        .min(500)
        .default(10_000),
    GATEWAY_WEBHOOK_MAX_ATTEMPTS: z.coerce.number().int().min(1).default(25),
    GATEWAY_MOCK_AUTO_CONNECT_MS: z.coerce.number().int().min(0).default(0),
});

export type Config = {
    env: 'development' | 'test' | 'production';
    host: string;
    port: number;
    apiToken: string;
    webhookUrl: string;
    webhookSecret: string;
    stateKey: Buffer;
    dataDir: string;
    adapter: 'baileys' | 'mock';
    logLevel: string;
    maxReconnectAttempts: number;
    reconnectBaseMs: number;
    reconnectMaxMs: number;
    qrTtlSeconds: number;
    sendTimeoutMs: number;
    maxMediaBytes: number;
    bodyLimitBytes: number;
    webhookTimeoutMs: number;
    webhookMaxAttempts: number;
    mockAutoConnectMs: number;
};

export class ConfigError extends Error {}

/**
 * Validates the environment. Error messages name the offending variables but never
 * echo their values.
 */
export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
    const result = schema.safeParse(env);

    if (!result.success) {
        const problems = result.error.issues
            .map((issue) => `  - ${issue.path.join('.')}: ${issue.message}`)
            .join('\n');

        throw new ConfigError(`Invalid gateway configuration:\n${problems}`);
    }

    const value = result.data;

    return {
        env: value.NODE_ENV,
        host: value.GATEWAY_HOST,
        port: value.GATEWAY_PORT,
        apiToken: value.GATEWAY_API_TOKEN,
        webhookUrl: value.GATEWAY_WEBHOOK_URL,
        webhookSecret: value.GATEWAY_WEBHOOK_SECRET,
        stateKey: Buffer.from(value.GATEWAY_STATE_KEY, 'base64'),
        dataDir: value.GATEWAY_DATA_DIR,
        adapter: value.GATEWAY_ADAPTER,
        logLevel: value.GATEWAY_LOG_LEVEL,
        maxReconnectAttempts: value.GATEWAY_MAX_RECONNECT_ATTEMPTS,
        reconnectBaseMs: value.GATEWAY_RECONNECT_BASE_MS,
        reconnectMaxMs: value.GATEWAY_RECONNECT_MAX_MS,
        qrTtlSeconds: value.GATEWAY_QR_TTL_SECONDS,
        sendTimeoutMs: value.GATEWAY_SEND_TIMEOUT_MS,
        maxMediaBytes: value.GATEWAY_MAX_MEDIA_BYTES,
        bodyLimitBytes: value.GATEWAY_BODY_LIMIT_BYTES,
        webhookTimeoutMs: value.GATEWAY_WEBHOOK_TIMEOUT_MS,
        webhookMaxAttempts: value.GATEWAY_WEBHOOK_MAX_ATTEMPTS,
        mockAutoConnectMs: value.GATEWAY_MOCK_AUTO_CONNECT_MS,
    };
}
