import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { join } from 'node:path';
import { afterEach, beforeEach, test } from 'node:test';
import { proto } from '@whiskeysockets/baileys';
import { AuthStateRepository } from '../src/auth/auth-state-repository.ts';
import { StateCipher } from '../src/security/crypto.ts';
import { GatewayDatabase } from '../src/store/database.ts';
import { tempDir } from './helpers.ts';

let dir: ReturnType<typeof tempDir>;
const key = randomBytes(32);

beforeEach(() => {
    dir = tempDir();
});

afterEach(() => dir.cleanup());

function open() {
    const database = new GatewayDatabase(join(dir.path, 'gateway.sqlite'));

    return {
        database,
        repository: new AuthStateRepository(database, new StateCipher(key)),
    };
}

test('credentials survive a process restart with binary fields intact', async () => {
    const first = open();
    const handle = first.repository.handle('session-a');
    handle.state.creds.registered = true;
    await handle.saveCreds();
    const noiseKey = Buffer.from(handle.state.creds.noiseKey.private);
    first.database.close();

    const second = open();
    const restored = second.repository.loadCreds('session-a');

    assert.ok(restored);
    assert.equal(restored.registered, true);
    assert.ok(Buffer.isBuffer(restored.noiseKey.private));
    assert.deepEqual(Buffer.from(restored.noiseKey.private), noiseKey);
    second.database.close();
});

test('signal keys are persisted, read back and deleted when set to null', async () => {
    const first = open();
    const keys = first.repository.handle('session-a').state.keys;
    const session = new Uint8Array([1, 2, 3, 4]);

    await keys.set({
        session: { 'peer.0': session },
        'pre-key': {
            '7': { public: Buffer.from('pub'), private: Buffer.from('priv') },
        },
    });
    first.database.close();

    const second = open();
    const reopened = second.repository.handle('session-a').state.keys;
    const loaded = await reopened.get('session', ['peer.0', 'missing']);

    assert.deepEqual([...(loaded['peer.0'] ?? [])], [1, 2, 3, 4]);
    assert.equal(loaded.missing, undefined);

    await reopened.set({ 'pre-key': { '7': null } });
    assert.deepEqual(await reopened.get('pre-key', ['7']), {});
    second.database.close();
});

test('app state sync keys are revived as protobuf objects', async () => {
    const { database, repository } = open();
    const keys = repository.handle('session-a').state.keys;
    const keyData = proto.Message.AppStateSyncKeyData.fromObject({
        keyData: Buffer.from('k'),
        timestamp: 5,
    });

    await keys.set({ 'app-state-sync-key': { abc: keyData } });
    const loaded = await keys.get('app-state-sync-key', ['abc']);

    assert.ok(loaded.abc instanceof proto.Message.AppStateSyncKeyData);
    database.close();
});

test('stores nothing in plaintext', async () => {
    const { database, repository } = open();
    await repository.handle('session-a').state.keys.set({
        session: { peer: Buffer.from('VERY-SECRET-MATERIAL') },
    });
    const rows = database.db.prepare('SELECT value FROM auth_keys').all() as {
        value: string;
    }[];

    assert.equal(rows.length, 1);
    assert.ok(!rows[0]!.value.includes('VERY-SECRET'));
    assert.ok(
        !Buffer.from(rows[0]!.value)
            .toString()
            .includes(Buffer.from('VERY-SECRET-MATERIAL').toString('base64')),
    );
    database.close();
});

test('a batch of key updates is atomic', () => {
    const { database, repository } = open();
    const poisoned = {
        toJSON: () => {
            throw new Error('serialization failed');
        },
    };

    assert.throws(() =>
        repository.setKeys('session-a', {
            session: {
                good: new Uint8Array([1]),
                bad: poisoned as unknown as Uint8Array,
            },
        }),
    );
    assert.deepEqual(repository.getKeys('session-a', 'session', ['good']), {});
    database.close();
});

test('deleting a session removes credentials and keys only for that session', async () => {
    const { database, repository } = open();
    for (const id of ['session-a', 'session-b']) {
        await repository.handle(id).saveCreds();
        repository.setKeys(id, { session: { peer: new Uint8Array([1]) } });
    }

    repository.deleteSession('session-a');

    assert.equal(repository.hasCredentials('session-a'), false);
    assert.deepEqual(repository.getKeys('session-a', 'session', ['peer']), {});
    assert.equal(repository.hasCredentials('session-b'), true);
    database.close();
});

test('treats QR-paired credentials (me set, registered false) as paired', async () => {
    const { isPaired } = await import('../src/auth/auth-state-repository.ts');

    assert.equal(
        isPaired({
            registered: false,
            me: { id: '15550100100:1@s.whatsapp.net' },
        } as never),
        true,
    );
    assert.equal(isPaired({ registered: false } as never), false);
    assert.equal(isPaired(null), false);
});
