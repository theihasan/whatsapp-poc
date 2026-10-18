import { pino, type Logger } from 'pino';

/**
 * Structured logger. QR codes, credentials, tokens, media and message text are
 * redacted wherever they appear in log objects.
 */
export function createLogger(level: string): Logger {
    return pino({
        level,
        base: { service: 'whatsapp-gateway' },
        redact: {
            paths: [
                'qr',
                '*.qr',
                'payload.qr',
                'creds',
                '*.creds',
                'keys',
                '*.keys',
                'text',
                '*.text',
                'body',
                '*.body',
                'base64',
                '*.base64',
                'media.base64',
                'req.headers.authorization',
                'headers.authorization',
                'authorization',
            ],
            censor: '[redacted]',
        },
    });
}

export type { Logger };
