import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { test } from 'node:test';
import { StateCipher } from '../src/security/crypto.ts';

test('round-trips plaintext bound to its associated data', () => {
    const cipher = new StateCipher(randomBytes(32));
    const encrypted = cipher.encrypt(
        'signal-session-secret',
        'session-1:session:abc',
    );

    assert.ok(!encrypted.includes('signal-session-secret'));
    assert.equal(
        cipher.decryptString(encrypted, 'session-1:session:abc'),
        'signal-session-secret',
    );
});

test('rejects ciphertext moved to another row', () => {
    const cipher = new StateCipher(randomBytes(32));
    const encrypted = cipher.encrypt('value', 'session-1:creds');

    assert.throws(() => cipher.decrypt(encrypted, 'session-2:creds'));
});

test('rejects tampered ciphertext', () => {
    const cipher = new StateCipher(randomBytes(32));
    const encrypted = cipher.encrypt('value', 'row');
    const raw = Buffer.from(encrypted.slice(3), 'base64');
    raw[raw.length - 1] = (raw[raw.length - 1] ?? 0) ^ 0xff;

    assert.throws(() => cipher.decrypt(`v1:${raw.toString('base64')}`, 'row'));
});

test('rejects keys that are not 32 bytes', () => {
    assert.throws(() => new StateCipher(randomBytes(16)));
});
