import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';

const VERSION = 'v1';

/**
 * AES-256-GCM for data at rest. The associated data binds a ciphertext to its row
 * (e.g. session + key id) so values cannot be swapped between rows undetected.
 */
export class StateCipher {
    readonly #key: Buffer;

    constructor(key: Buffer) {
        if (key.length !== 32) {
            throw new Error('State encryption key must be 32 bytes.');
        }

        this.#key = key;
    }

    encrypt(plaintext: string | Buffer, associatedData: string): string {
        const iv = randomBytes(12);
        const cipher = createCipheriv('aes-256-gcm', this.#key, iv);
        cipher.setAAD(Buffer.from(associatedData, 'utf8'));

        const ciphertext = Buffer.concat([
            cipher.update(plaintext),
            cipher.final(),
        ]);

        return `${VERSION}:${Buffer.concat([iv, cipher.getAuthTag(), ciphertext]).toString('base64')}`;
    }

    decrypt(encoded: string, associatedData: string): Buffer {
        const [version, payload] = encoded.split(':', 2);

        if (version !== VERSION || payload === undefined) {
            throw new Error('Unsupported ciphertext format.');
        }

        const raw = Buffer.from(payload, 'base64');
        const decipher = createDecipheriv(
            'aes-256-gcm',
            this.#key,
            raw.subarray(0, 12),
        );
        decipher.setAAD(Buffer.from(associatedData, 'utf8'));
        decipher.setAuthTag(raw.subarray(12, 28));

        return Buffer.concat([
            decipher.update(raw.subarray(28)),
            decipher.final(),
        ]);
    }

    decryptString(encoded: string, associatedData: string): string {
        return this.decrypt(encoded, associatedData).toString('utf8');
    }
}
