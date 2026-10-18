import { randomUUID } from 'node:crypto';
import Fastify from 'fastify';
import type { Logger } from 'pino';
import { z } from 'zod';
import type { WebhookOutbox } from '../events/outbox.ts';
import type { MediaStore } from '../messaging/media-store.ts';
import { isValidBearer } from '../security/auth.ts';
import type { SessionManager } from '../sessions/session-manager.ts';
import type { GatewayDatabase } from '../store/database.ts';
import { GatewayError } from '../utils/errors.ts';
import type { OutgoingContent } from '../whatsapp/adapter.ts';

export type ServerDependencies = {
    apiToken: string;
    bodyLimitBytes: number;
    maxMediaBytes: number;
    adapterName: string;
    sessions: SessionManager;
    outbox: WebhookOutbox;
    media: MediaStore;
    database: GatewayDatabase;
    logger: Logger;
};

const sessionId = z
    .string()
    .regex(/^[0-9A-Za-z_-]{8,64}$/, 'invalid session id');
const recipient = z
    .string()
    .regex(/^(\d{6,15}@s\.whatsapp\.net|\d{6,20}@lid)$/, 'must be a user JID');

const createSessionBody = z.object({ sessionId });

const sendMessageBody = z
    .object({
        idempotencyKey: z.string().min(8).max(64),
        to: recipient,
        type: z.enum(['text', 'image', 'document', 'audio']),
        text: z.string().max(4096).nullable().optional(),
        media: z
            .object({
                fileName: z.string().max(255).nullable().optional(),
                mimeType: z.string().regex(/^[\w.+-]+\/[\w.+-]+$/),
                base64: z.string().min(1),
            })
            .optional(),
    })
    .superRefine((value, context) => {
        if (value.type === 'text' && !value.text) {
            context.addIssue({
                code: 'custom',
                path: ['text'],
                message: 'text is required for text messages',
            });
        }

        if (value.type !== 'text' && value.media === undefined) {
            context.addIssue({
                code: 'custom',
                path: ['media'],
                message: 'media is required for this type',
            });
        }
    });

const correlationIdPattern = /^[\w.:-]{8,128}$/;
const PUBLIC_ROUTES = new Set(['/health', '/ready']);

class ValidationError extends GatewayError {
    readonly issues: { path: string; message: string }[];

    constructor(error: z.ZodError) {
        super('validation_failed', 422, 'The request is invalid.');
        this.issues = error.issues.map((issue) => ({
            path: issue.path.join('.'),
            message: issue.message,
        }));
    }
}

function parse<T>(schema: z.ZodType<T>, value: unknown): T {
    const result = schema.safeParse(value);

    if (!result.success) {
        throw new ValidationError(result.error);
    }

    return result.data;
}

/**
 * Internal API (docs/gateway-api.md). Binds to a private interface only; every route
 * except health/readiness requires the bearer token.
 */
export function buildServer(deps: ServerDependencies) {
    const app = Fastify({
        loggerInstance: deps.logger,
        bodyLimit: deps.bodyLimitBytes,
        requestIdHeader: false,
        genReqId: (request) => {
            const header = request.headers['x-correlation-id'];

            return typeof header === 'string' &&
                correlationIdPattern.test(header)
                ? header
                : randomUUID();
        },
    });

    app.addHook('onRequest', async (request, reply) => {
        reply.header('X-Correlation-Id', request.id);

        if (PUBLIC_ROUTES.has(request.url)) {
            return;
        }

        if (!isValidBearer(request.headers.authorization, deps.apiToken)) {
            await reply.code(401).send({
                error: {
                    code: 'unauthorized',
                    message: 'Missing or invalid token.',
                },
            });
        }
    });

    app.setErrorHandler((error, request, reply) => {
        if (error instanceof ValidationError) {
            return reply.code(422).send({
                error: {
                    code: error.code,
                    message: error.message,
                    issues: error.issues,
                },
            });
        }

        if (error instanceof GatewayError) {
            return reply
                .code(error.statusCode)
                .send({ error: { code: error.code, message: error.message } });
        }

        const statusCode = (error as { statusCode?: number }).statusCode;

        if (statusCode === 413 || statusCode === 400 || statusCode === 415) {
            return reply.code(statusCode).send({
                error: {
                    code: 'bad_request',
                    message: 'Malformed or oversized request.',
                },
            });
        }

        request.log.error({ err: error }, 'unhandled error');

        return reply.code(500).send({
            error: {
                code: 'internal_error',
                message: 'Internal gateway error.',
            },
        });
    });

    app.get('/health', async () => ({ status: 'ok' }));

    app.get('/ready', async (_request, reply) => {
        const storeHealthy = deps.database.isHealthy();
        const body = {
            status: storeHealthy ? 'ready' : 'not_ready',
            adapter: deps.adapterName,
            checks: { store: storeHealthy },
            sessions: deps.sessions.counts(),
            outbox: deps.outbox.stats(),
        };

        return reply.code(storeHealthy ? 200 : 503).send(body);
    });

    app.post('/v1/sessions', async (request, reply) => {
        const body = parse(createSessionBody, request.body);
        const session = await deps.sessions.start(body.sessionId);

        return reply.code(202).send({ session });
    });

    app.get('/v1/sessions/:id', async (request) => {
        const { id } = parse(z.object({ id: sessionId }), request.params);

        return { session: deps.sessions.view(id) };
    });

    app.get('/v1/sessions/:id/qr', async (request, reply) => {
        const { id } = parse(z.object({ id: sessionId }), request.params);
        const qr = deps.sessions.qr(id);
        reply.header('Cache-Control', 'no-store');

        return qr === null
            ? reply.code(404).send({
                  error: {
                      code: 'no_qr',
                      message: 'No QR code is pending for this session.',
                  },
              })
            : qr;
    });

    app.post('/v1/sessions/:id/reconnect', async (request, reply) => {
        const { id } = parse(z.object({ id: sessionId }), request.params);

        return reply
            .code(202)
            .send({ session: await deps.sessions.reconnect(id) });
    });

    app.post('/v1/sessions/:id/disconnect', async (request, reply) => {
        const { id } = parse(z.object({ id: sessionId }), request.params);

        return reply
            .code(202)
            .send({ session: await deps.sessions.disconnect(id) });
    });

    app.delete('/v1/sessions/:id', async (request, reply) => {
        const { id } = parse(z.object({ id: sessionId }), request.params);
        await deps.sessions.revoke(id);

        return reply.code(202).send({ revoked: true });
    });

    app.post('/v1/sessions/:id/messages', async (request) => {
        const { id } = parse(z.object({ id: sessionId }), request.params);
        const body = parse(sendMessageBody, request.body);
        const result = await deps.sessions.send(
            id,
            body.idempotencyKey,
            body.to,
            toContent(body, deps.maxMediaBytes),
        );

        return {
            messageId: result.messageId,
            status: 'accepted',
            duplicate: result.duplicate,
        };
    });

    app.get('/v1/media/:mediaId', async (request, reply) => {
        const { mediaId } = parse(
            z.object({ mediaId: z.uuid() }),
            request.params,
        );
        const media = deps.media.get(mediaId);

        if (media === null) {
            return reply.code(404).send({
                error: {
                    code: 'media_not_found',
                    message: 'Unknown or expired media.',
                },
            });
        }

        return reply
            .header('Content-Type', media.mimeType)
            .header('Cache-Control', 'no-store')
            .header(
                'X-Media-File-Name',
                encodeURIComponent(media.fileName ?? ''),
            )
            .send(media.data);
    });

    app.delete('/v1/media/:mediaId', async (request, reply) => {
        const { mediaId } = parse(
            z.object({ mediaId: z.uuid() }),
            request.params,
        );
        deps.media.delete(mediaId);

        return reply.code(204).send();
    });

    return app;
}

export type GatewayServer = ReturnType<typeof buildServer>;

function toContent(
    body: z.infer<typeof sendMessageBody>,
    maxMediaBytes: number,
): OutgoingContent {
    if (body.type === 'text') {
        return { type: 'text', text: body.text ?? '' };
    }

    const media = body.media!;
    const data = Buffer.from(media.base64, 'base64');

    if (data.length === 0 || data.length > maxMediaBytes) {
        throw new GatewayError(
            'invalid_media',
            422,
            'Media is empty or exceeds the size limit.',
        );
    }

    return {
        type: body.type,
        data,
        mimeType: media.mimeType,
        fileName: media.fileName ?? null,
        caption: body.text ?? null,
    };
}
