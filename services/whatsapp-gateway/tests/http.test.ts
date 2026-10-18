import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import { createGateway, type Gateway } from '../src/app.ts';
import { MockAdapter } from '../src/whatsapp/mock-adapter.ts';
import {
    recordingFetch,
    silentLogger,
    tempDir,
    testConfig,
    waitFor,
} from './helpers.ts';

let dir: ReturnType<typeof tempDir>;
let gateway: Gateway;
let adapter: MockAdapter;
const token = testConfig('.').apiToken;
const auth = { authorization: `Bearer ${token}` };

beforeEach(() => {
    dir = tempDir();
    adapter = new MockAdapter();
    gateway = createGateway(testConfig(dir.path), silentLogger, {
        adapter,
        fetchImpl: recordingFetch().fetch,
    });
});

afterEach(async () => {
    await gateway.stop();
    dir.cleanup();
});

test('health and readiness are public and expose only counts', async () => {
    const health = await gateway.server.inject({
        method: 'GET',
        url: '/health',
    });
    const ready = await gateway.server.inject({ method: 'GET', url: '/ready' });

    assert.equal(health.statusCode, 200);
    assert.equal(ready.statusCode, 200);
    assert.deepEqual(Object.keys(ready.json()).sort(), [
        'adapter',
        'checks',
        'outbox',
        'sessions',
        'status',
    ]);
});

test('rejects requests without a valid bearer token with 401', async () => {
    for (const headers of [
        {},
        { authorization: 'Bearer wrong-token' },
        { authorization: token },
    ]) {
        const response = await gateway.server.inject({
            method: 'POST',
            url: '/v1/sessions',
            headers,
            payload: { sessionId: '01JSESSIONAAAA' },
        });

        assert.equal(response.statusCode, 401);
        assert.equal(response.json().error.code, 'unauthorized');
    }
});

test('echoes a valid correlation id and generates one otherwise', async () => {
    const echoed = await gateway.server.inject({
        method: 'GET',
        url: '/health',
        headers: { 'x-correlation-id': 'corr-12345678' },
    });
    const generated = await gateway.server.inject({
        method: 'GET',
        url: '/health',
        headers: { 'x-correlation-id': 'bad id!' },
    });

    assert.equal(echoed.headers['x-correlation-id'], 'corr-12345678');
    assert.match(
        String(generated.headers['x-correlation-id']),
        /^[0-9a-f-]{36}$/,
    );
});

test('creates a session and serves its temporary QR without caching', async () => {
    const created = await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions',
        headers: auth,
        payload: { sessionId: '01JSESSIONAAAA' },
    });
    assert.equal(created.statusCode, 202);
    await waitFor(
        () => gateway.sessions.view('01JSESSIONAAAA').status === 'qr_ready',
    );

    const qr = await gateway.server.inject({
        method: 'GET',
        url: '/v1/sessions/01JSESSIONAAAA/qr',
        headers: auth,
    });

    assert.equal(qr.statusCode, 200);
    assert.equal(qr.headers['cache-control'], 'no-store');
    assert.match(qr.json().qr, /^MOCK-GW-QR:/);
});

test('returns 422 with field paths for invalid send requests', async () => {
    const response = await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions/01JSESSIONAAAA/messages',
        headers: auth,
        payload: { idempotencyKey: 'k', to: '+1 555 0100', type: 'text' },
    });

    assert.equal(response.statusCode, 422);
    assert.deepEqual(
        response
            .json()
            .error.issues.map((issue: { path: string }) => issue.path)
            .sort(),
        ['idempotencyKey', 'text', 'to'],
    );
});

test('returns 409 session_not_connected when the session cannot send', async () => {
    await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions',
        headers: auth,
        payload: { sessionId: '01JSESSIONAAAA' },
    });

    const response = await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions/01JSESSIONAAAA/messages',
        headers: auth,
        payload: {
            idempotencyKey: '01JIDEMPOTENCY01',
            to: '15550100101@s.whatsapp.net',
            type: 'text',
            text: 'hi',
        },
    });

    assert.equal(response.statusCode, 409);
    assert.equal(response.json().error.code, 'session_not_connected');
});

test('returns 404 for unknown sessions', async () => {
    const response = await gateway.server.inject({
        method: 'GET',
        url: '/v1/sessions/01JUNKNOWNSESSION',
        headers: auth,
    });

    assert.equal(response.statusCode, 404);
    assert.equal(response.json().error.code, 'session_not_found');
});

test('sends a message once and reports duplicates for retried idempotency keys', async () => {
    await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions',
        headers: auth,
        payload: { sessionId: '01JSESSIONAAAA' },
    });
    await waitFor(
        () => gateway.sessions.view('01JSESSIONAAAA').status === 'qr_ready',
    );
    await adapter.scan('01JSESSIONAAAA');
    const payload = {
        idempotencyKey: '01JIDEMPOTENCY02',
        to: '15550100101@s.whatsapp.net',
        type: 'text',
        text: 'hello',
    };

    const first = await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions/01JSESSIONAAAA/messages',
        headers: auth,
        payload,
    });
    const retry = await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions/01JSESSIONAAAA/messages',
        headers: auth,
        payload,
    });

    assert.equal(first.statusCode, 200);
    assert.equal(first.json().duplicate, false);
    assert.equal(retry.json().messageId, first.json().messageId);
    assert.equal(retry.json().duplicate, true);
});

test('does not leak internal error details', async () => {
    gateway.sessions.view = () => {
        throw new Error('sqlite path /secret/location exploded');
    };

    const response = await gateway.server.inject({
        method: 'GET',
        url: '/v1/sessions/01JSESSIONAAAA',
        headers: auth,
    });

    assert.equal(response.statusCode, 500);
    assert.deepEqual(response.json(), {
        error: { code: 'internal_error', message: 'Internal gateway error.' },
    });
});

test('validates LID resolution requests', async () => {
    const response = await gateway.server.inject({
        method: 'POST',
        url: '/v1/sessions/01JSESSIONAAAA/contacts/resolve',
        headers: auth,
        payload: { lids: ['15550100101@s.whatsapp.net'] },
    });

    assert.equal(response.statusCode, 422);
});
