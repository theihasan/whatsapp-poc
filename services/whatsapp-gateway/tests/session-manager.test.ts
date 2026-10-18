import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import { createGateway, type Gateway } from '../src/app.ts';
import { MockAdapter } from '../src/whatsapp/mock-adapter.ts';
import {
    eventsOf,
    recordingFetch,
    silentLogger,
    tempDir,
    testConfig,
    waitFor,
} from './helpers.ts';

let dir: ReturnType<typeof tempDir>;
let gateway: Gateway;
let adapter: MockAdapter;
let recorder: ReturnType<typeof recordingFetch>;
let stateKey: Buffer;

function boot(): void {
    adapter = new MockAdapter();
    recorder = recordingFetch();
    const config = testConfig(dir.path);
    stateKey ??= config.stateKey;
    gateway = createGateway({ ...config, stateKey }, silentLogger, {
        adapter,
        fetchImpl: recorder.fetch,
    });
}

async function flush(): Promise<
    { type: string; payload: Record<string, unknown> }[]
> {
    await new Promise((resolve) => setTimeout(resolve, 30));
    await gateway.outbox.drain();

    return eventsOf(recorder.requests);
}

beforeEach(() => {
    dir = tempDir();
    stateKey = undefined as unknown as Buffer;
    boot();
});

afterEach(async () => {
    await gateway.stop().catch(() => undefined);
    dir.cleanup();
});

test('pairs through QR and reports connected only after the scan', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    assert.ok(gateway.sessions.qr('session-a')?.qr.startsWith('MOCK-GW-QR:'));

    await adapter.scan('session-a');

    assert.equal(gateway.sessions.view('session-a').status, 'connected');
    assert.equal(gateway.sessions.qr('session-a'), null);
    assert.deepEqual(
        (await flush()).map((event) => event.type),
        ['session.connecting', 'session.qr.updated', 'session.connected'],
    );
});

test('never opens a second socket for an active session', async () => {
    await gateway.sessions.start('session-a');
    await gateway.sessions.start('session-a');

    assert.equal(adapter.connectCalls, 1);
});

test('restores a paired session after a gateway restart without a new QR', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');
    await gateway.stop();

    boot();
    const restored = await gateway.sessions.restore();
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'connected',
    );

    assert.equal(restored, 1);
    assert.ok(
        !(await flush()).some((event) => event.type === 'session.qr.updated'),
    );
});

test('reconnects a transient drop with backoff and resets the attempt counter', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');

    adapter.drop('session-a');

    assert.equal(gateway.sessions.view('session-a').status, 'reconnecting');
    assert.equal(gateway.sessions.view('session-a').reconnectAttempts, 1);
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'connected',
    );
    assert.equal(gateway.sessions.view('session-a').reconnectAttempts, 0);
    assert.equal(adapter.connectCalls, 2);
});

test('gives up after the maximum consecutive reconnection attempts', async () => {
    adapter.connect = async () => {
        throw new Error('network down');
    };

    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'failed',
        2000,
    );

    const types = (await flush()).map((event) => event.type);
    assert.equal(
        types.filter((type) => type === 'session.reconnecting').length,
        3,
    );
    assert.equal(types.at(-1), 'session.failed');
});

test('deletes credentials when the phone logs the device out', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');

    adapter.drop('session-a', 'logged_out');

    assert.equal(gateway.sessions.view('session-a').status, 'logged_out');
    assert.equal(gateway.sessions.view('session-a').hasCredentials, false);
});

test('revoke removes stored credentials and emits logged out', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');

    await gateway.sessions.revoke('session-a');

    assert.throws(() => gateway.sessions.view('session-a'), {
        code: 'session_not_found',
    });
    assert.equal((await flush()).at(-1)?.type, 'session.logged_out');
});

test('sends idempotently and links status updates to the idempotency key', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');

    const first = await gateway.sessions.send(
        'session-a',
        'key-00000001',
        '15550100101@s.whatsapp.net',
        { type: 'text', text: 'hi' },
    );
    const second = await gateway.sessions.send(
        'session-a',
        'key-00000001',
        '15550100101@s.whatsapp.net',
        { type: 'text', text: 'hi' },
    );

    assert.equal(second.messageId, first.messageId);
    assert.equal(second.duplicate, true);

    const statuses = (await flush()).filter(
        (event) => event.type === 'message.status.updated',
    );
    assert.deepEqual(
        statuses.map((event) => event.payload.status),
        ['sent', 'delivered'],
    );
    assert.ok(
        statuses.every(
            (event) => event.payload.idempotencyKey === 'key-00000001',
        ),
    );
});

test('refuses to send while the session is not connected', async () => {
    await gateway.sessions.start('session-a');

    await assert.rejects(
        gateway.sessions.send(
            'session-a',
            'key-00000002',
            '15550100101@s.whatsapp.net',
            { type: 'text', text: 'hi' },
        ),
        { code: 'session_not_connected' },
    );
});

test('reports an unknown outcome when the send does not complete in time', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');

    await assert.rejects(
        gateway.sessions.send(
            'session-a',
            'key-00000003',
            '15550100101@s.whatsapp.net',
            { type: 'text', text: '#hang' },
        ),
        { code: 'send_timeout' },
    );
});

test('publishes normalized incoming messages', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');

    adapter.receive('session-a', '+15550100102', 'Hello there');

    const received = (await flush()).find(
        (event) => event.type === 'message.received',
    );
    assert.equal(received?.payload.body, 'Hello there');
    assert.deepEqual(received?.payload.from, {
        jid: '15550100102@s.whatsapp.net',
        lid: null,
        phoneNumber: '+15550100102',
        pushName: 'Mock contact',
    });
});

test('graceful shutdown closes sockets but keeps sessions restorable', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');

    await gateway.stop();

    assert.equal(adapter.connections.get('session-a')?.open, false);
    assert.equal(
        eventsOf(recorder.requests).at(-1)?.type,
        'session.disconnected',
    );
});

test('publishes contact identities learned from WhatsApp and resolves known LIDs', async () => {
    await gateway.sessions.start('session-a');
    await waitFor(
        () => gateway.sessions.view('session-a').status === 'qr_ready',
    );
    await adapter.scan('session-a');
    const identity = {
        lid: '123456789012345@lid',
        jid: '15550100104@s.whatsapp.net',
        phoneNumber: '+15550100104',
    };

    adapter.identify('session-a', identity);

    const identified = (await flush()).find(
        (event) => event.type === 'contact.identified',
    );
    assert.deepEqual(identified?.payload, identity);
    assert.deepEqual(
        await gateway.sessions.resolveLids('session-a', [
            '123456789012345@lid',
            '999999999999999@lid',
        ]),
        [identity],
    );
});
