import { randomBytes } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pino } from 'pino';
import type { Config } from '../src/config/env.ts';

export const silentLogger = pino({ level: 'silent' });

export function tempDir(): { path: string; cleanup: () => void } {
    const path = mkdtempSync(join(tmpdir(), 'wa-gateway-test-'));

    return {
        path,
        cleanup: () => rmSync(path, { recursive: true, force: true }),
    };
}

export function testConfig(
    dataDir: string,
    overrides: Partial<Config> = {},
): Config {
    return {
        env: 'test',
        host: '127.0.0.1',
        port: 0,
        apiToken: 'test-api-token-0123456789-abcdefghijkl',
        webhookUrl: 'http://laravel.test/api/gateway/events',
        webhookSecret: 'test-webhook-secret-0123456789-abcdefgh',
        stateKey: randomBytes(32),
        dataDir,
        adapter: 'mock',
        logLevel: 'silent',
        maxReconnectAttempts: 3,
        reconnectBaseMs: 5,
        reconnectMaxMs: 20,
        qrTtlSeconds: 20,
        sendTimeoutMs: 200,
        maxMediaBytes: 1024 * 1024,
        bodyLimitBytes: 4 * 1024 * 1024,
        webhookTimeoutMs: 1000,
        webhookMaxAttempts: 5,
        mockAutoConnectMs: 0,
        ...overrides,
    };
}

export type RecordedRequest = {
    url: string;
    headers: Record<string, string>;
    body: string;
};

/**
 * fetch stand-in that records requests and answers with the queued statuses (default 202).
 */
export function recordingFetch(statuses: number[] = []): {
    fetch: typeof fetch;
    requests: RecordedRequest[];
} {
    const requests: RecordedRequest[] = [];
    const queue = [...statuses];

    const fake = (async (input: string | URL | Request, init?: RequestInit) => {
        requests.push({
            url: String(input),
            headers: Object.fromEntries(
                Object.entries((init?.headers ?? {}) as Record<string, string>),
            ),
            body: String(init?.body ?? ''),
        });

        return new Response('{}', { status: queue.shift() ?? 202 });
    }) as typeof fetch;

    return { fetch: fake, requests };
}

export async function waitFor(
    condition: () => boolean,
    timeoutMs = 1000,
): Promise<void> {
    const started = Date.now();

    while (!condition()) {
        if (Date.now() - started > timeoutMs) {
            throw new Error('Timed out waiting for condition');
        }

        await new Promise((resolve) => setTimeout(resolve, 5));
    }
}

export function eventsOf(
    requests: RecordedRequest[],
): { type: string; payload: Record<string, unknown> }[] {
    return requests.map(
        (request) =>
            JSON.parse(request.body) as {
                type: string;
                payload: Record<string, unknown>;
            },
    );
}
