import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { join } from 'node:path';
import { afterEach, beforeEach, test } from 'node:test';
import { WebhookOutbox } from '../src/events/outbox.ts';
import { StateCipher } from '../src/security/crypto.ts';
import { GatewayDatabase } from '../src/store/database.ts';
import { recordingFetch, silentLogger, tempDir } from './helpers.ts';

let dir: ReturnType<typeof tempDir>;
let clock: number;
const key = randomBytes(32);
const secret = 'webhook-secret-for-tests-0123456789abcdef';

beforeEach(() => {
    dir = tempDir();
    clock = 1_800_000_000_000;
});

afterEach(() => dir.cleanup());

function outbox(
    fetchImpl: typeof fetch,
    database = new GatewayDatabase(join(dir.path, 'gateway.sqlite')),
) {
    return {
        database,
        outbox: new WebhookOutbox(
            database,
            new StateCipher(key),
            {
                webhookUrl: 'http://laravel.test/hook',
                webhookSecret: secret,
                timeoutMs: 500,
                maxAttempts: 3,
                retryBaseMs: 1000,
                retryMaxMs: 4000,
            },
            silentLogger,
            fetchImpl,
            () => clock,
        ),
    };
}

test('signs the raw body with the timestamp and deletes delivered events', async () => {
    const recorder = recordingFetch([202]);
    const { outbox: box, database } = outbox(recorder.fetch);

    const event = box.publish('session.connected', 'session-a', {
        phoneNumber: '+15550100100',
    });
    await box.drain();

    const request = recorder.requests[0]!;
    const timestamp = request.headers['X-Gateway-Timestamp']!;
    const expected = `sha256=${createHmac('sha256', secret).update(`${timestamp}.${request.body}`).digest('hex')}`;

    assert.equal(request.headers['X-Gateway-Signature'], expected);
    assert.equal(request.headers['X-Gateway-Event-Id'], event.eventId);
    assert.deepEqual(JSON.parse(request.body), event);
    assert.deepEqual(box.stats(), { pending: 0, dead: 0 });
    database.close();
});

test('retries transient failures with backoff and keeps order', async () => {
    const recorder = recordingFetch([503, 202, 202]);
    const { outbox: box, database } = outbox(recorder.fetch);
    const first = box.publish('message.received', 'session-a', {
        externalId: '1',
    });
    box.publish('message.received', 'session-a', { externalId: '2' });

    await box.drain();
    assert.equal(
        recorder.requests.length,
        1,
        'stops at the first failure to preserve order',
    );

    await box.drain();
    assert.equal(
        recorder.requests.length,
        1,
        'not retried before the backoff elapses',
    );

    clock += 1_000;
    await box.drain();

    assert.deepEqual(
        recorder.requests
            .map(
                (request) =>
                    (JSON.parse(request.body) as { eventId: string }).eventId,
            )
            .slice(0, 2),
        [first.eventId, first.eventId],
    );
    assert.equal(recorder.requests.length, 3);
    database.close();
});

test('dead-letters permanent 4xx rejections without blocking later events', async () => {
    const recorder = recordingFetch([401, 202]);
    const { outbox: box, database } = outbox(recorder.fetch);
    box.publish('session.connected', 'session-a', {});
    box.publish('session.connected', 'session-a', {});

    await box.drain();

    assert.equal(recorder.requests.length, 2);
    assert.deepEqual(box.stats(), { pending: 0, dead: 1 });
    database.close();
});

test('dead-letters after the maximum number of attempts', async () => {
    const recorder = recordingFetch([500, 500, 500]);
    const { outbox: box, database } = outbox(recorder.fetch);
    box.publish('session.connected', 'session-a', {});

    for (let attempt = 0; attempt < 3; attempt++) {
        await box.drain();
        clock += 10_000;
    }

    assert.deepEqual(box.stats(), { pending: 0, dead: 1 });
    database.close();
});

test('pending events survive a restart and are encrypted at rest', async () => {
    const failing = outbox(recordingFetch([503]).fetch);
    failing.outbox.publish('message.received', 'session-a', {
        body: 'confidential customer text',
    });
    await failing.outbox.drain();
    const raw = failing.database.db
        .prepare('SELECT body FROM outbox')
        .get() as { body: string };
    assert.ok(!raw.body.includes('confidential'));
    failing.database.close();

    clock += 60_000;
    const recorder = recordingFetch([202]);
    const restarted = outbox(recorder.fetch);
    await restarted.outbox.drain();

    assert.equal(recorder.requests.length, 1);
    assert.match(recorder.requests[0]!.body, /confidential customer text/);
    restarted.database.close();
});
