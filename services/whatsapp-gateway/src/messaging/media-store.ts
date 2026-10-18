import { randomUUID } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import type { StateCipher } from '../security/crypto.ts';
import type { GatewayDatabase } from '../store/database.ts';

export type StoredMedia = {
    id: string;
    sessionId: string;
    mimeType: string;
    fileName: string | null;
    size: number;
};

/**
 * Short-lived encrypted holding area for downloaded incoming media until Laravel fetches
 * it. Entries are deleted after fetch confirmation or when they exceed the retention.
 */
export class MediaStore {
    private readonly database: GatewayDatabase;
    private readonly cipher: StateCipher;
    private readonly directory: string;

    constructor(
        database: GatewayDatabase,
        cipher: StateCipher,
        directory: string,
    ) {
        this.database = database;
        this.cipher = cipher;
        this.directory = directory;
        mkdirSync(directory, { recursive: true, mode: 0o700 });
    }

    put(
        sessionId: string,
        data: Buffer,
        mimeType: string,
        fileName: string | null,
    ): StoredMedia {
        const id = randomUUID();
        const path = join(this.directory, `${id}.bin`);

        writeFileSync(path, this.cipher.encrypt(data, `media:${id}`), {
            mode: 0o600,
        });
        this.database.db
            .prepare(
                'INSERT INTO media (id, session_id, mime_type, file_name, size, path, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            )
            .run(
                id,
                sessionId,
                mimeType,
                fileName,
                data.length,
                path,
                Date.now(),
            );

        return { id, sessionId, mimeType, fileName, size: data.length };
    }

    get(id: string): (StoredMedia & { data: Buffer }) | null {
        const row = this.database.db
            .prepare('SELECT * FROM media WHERE id = ?')
            .get(id) as
            | {
                  id: string;
                  session_id: string;
                  mime_type: string;
                  file_name: string | null;
                  size: number;
                  path: string;
              }
            | undefined;

        if (row === undefined) {
            return null;
        }

        return {
            id: row.id,
            sessionId: row.session_id,
            mimeType: row.mime_type,
            fileName: row.file_name,
            size: row.size,
            data: this.cipher.decrypt(
                readFileSync(row.path, 'utf8'),
                `media:${row.id}`,
            ),
        };
    }

    delete(id: string): boolean {
        const row = this.database.db
            .prepare('SELECT path FROM media WHERE id = ?')
            .get(id) as { path: string } | undefined;

        if (row === undefined) {
            return false;
        }

        rmSync(row.path, { force: true });
        this.database.db.prepare('DELETE FROM media WHERE id = ?').run(id);

        return true;
    }

    purgeOlderThan(maxAgeMs: number): number {
        const stale = this.database.db
            .prepare('SELECT id FROM media WHERE created_at < ?')
            .all(Date.now() - maxAgeMs) as { id: string }[];

        for (const { id } of stale) {
            this.delete(id);
        }

        return stale.length;
    }
}
