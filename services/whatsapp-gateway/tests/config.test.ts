import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { test } from 'node:test';
import { ConfigError, loadConfig } from '../src/config/env.ts';
import { backoffDelay } from '../src/utils/backoff.ts';

const valid = {
    GATEWAY_API_TOKEN: 'a'.repeat(40),
    GATEWAY_WEBHOOK_URL: 'http://127.0.0.1:8000/api/gateway/events',
    GATEWAY_WEBHOOK_SECRET: 'b'.repeat(40),
    GATEWAY_STATE_KEY: randomBytes(32).toString('base64'),
};

test('loads defaults for a minimal valid environment', () => {
    const config = loadConfig(valid);

    assert.equal(config.host, '127.0.0.1');
    assert.equal(config.port, 3100);
    assert.equal(config.adapter, 'baileys');
    assert.equal(config.stateKey.length, 32);
});

test('names invalid variables without echoing secret values', () => {
    const env = {
        ...valid,
        GATEWAY_API_TOKEN: 'short-secret-value',
        GATEWAY_STATE_KEY: 'bm90LTMyLWJ5dGVz',
    };

    assert.throws(
        () => loadConfig(env),
        (error: unknown) =>
            error instanceof ConfigError &&
            error.message.includes('GATEWAY_API_TOKEN') &&
            error.message.includes('GATEWAY_STATE_KEY') &&
            !error.message.includes('short-secret-value'),
    );
});

test('backoff grows exponentially within the cap and keeps half as jitter', () => {
    assert.equal(
        backoffDelay(1, 1000, 60_000, () => 0),
        500,
    );
    assert.equal(
        backoffDelay(3, 1000, 60_000, () => 1),
        4000,
    );
    assert.equal(
        backoffDelay(20, 1000, 60_000, () => 1),
        60_000,
    );
    assert.equal(
        backoffDelay(20, 1000, 60_000, () => 0),
        30_000,
    );
});
