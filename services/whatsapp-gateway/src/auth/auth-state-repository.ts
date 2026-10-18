import {
    BufferJSON,
    initAuthCreds,
    proto,
    type AuthenticationCreds,
    type AuthenticationState,
    type SignalDataSet,
    type SignalDataTypeMap,
} from '@whiskeysockets/baileys';
import type { StateCipher } from '../security/crypto.ts';
import type { GatewayDatabase } from '../store/database.ts';

export type AuthStateHandle = {
    state: AuthenticationState;
    saveCreds: () => Promise<void>;
};

/**
 * Durable, encrypted Baileys auth state (credentials + Signal keys) in SQLite.
 *
 * Every value is AES-256-GCM encrypted with associated data bound to its row. Key
 * updates from Baileys are written in a single transaction; the returned promise
 * resolves only after COMMIT, so acknowledged keys are on disk.
 */
export class AuthStateRepository {
    private readonly database: GatewayDatabase;
    private readonly cipher: StateCipher;

    constructor(database: GatewayDatabase, cipher: StateCipher) {
        this.database = database;
        this.cipher = cipher;
    }

    hasCredentials(sessionId: string): boolean {
        return (
            this.database.db
                .prepare(
                    'SELECT 1 AS found FROM auth_creds WHERE session_id = ?',
                )
                .get(sessionId)?.found === 1
        );
    }

    isRegistered(sessionId: string): boolean {
        return this.loadCreds(sessionId)?.registered === true;
    }

    loadCreds(sessionId: string): AuthenticationCreds | null {
        const row = this.database.db
            .prepare('SELECT value FROM auth_creds WHERE session_id = ?')
            .get(sessionId) as { value: string } | undefined;

        if (row === undefined) {
            return null;
        }

        return this.decode(
            row.value,
            `${sessionId}:creds`,
        ) as AuthenticationCreds;
    }

    saveCreds(sessionId: string, creds: AuthenticationCreds): void {
        this.database.db
            .prepare(
                `INSERT INTO auth_creds (session_id, value, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT (session_id) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at`,
            )
            .run(
                sessionId,
                this.encode(creds, `${sessionId}:creds`),
                Date.now(),
            );
    }

    getKeys<T extends keyof SignalDataTypeMap>(
        sessionId: string,
        type: T,
        ids: string[],
    ): { [id: string]: SignalDataTypeMap[T] } {
        const result: { [id: string]: SignalDataTypeMap[T] } = {};
        const statement = this.database.db.prepare(
            'SELECT value FROM auth_keys WHERE session_id = ? AND type = ? AND key_id = ?',
        );

        for (const id of ids) {
            const row = statement.get(sessionId, type, id) as
                | { value: string }
                | undefined;

            if (row === undefined) {
                continue;
            }

            let value = this.decode(row.value, `${sessionId}:${type}:${id}`);

            if (type === 'app-state-sync-key' && value !== null) {
                value = proto.Message.AppStateSyncKeyData.fromObject(
                    value as object,
                );
            }

            result[id] = value as SignalDataTypeMap[T];
        }

        return result;
    }

    setKeys(sessionId: string, data: SignalDataSet): void {
        const upsert = this.database.db.prepare(
            `INSERT INTO auth_keys (session_id, type, key_id, value) VALUES (?, ?, ?, ?)
             ON CONFLICT (session_id, type, key_id) DO UPDATE SET value = excluded.value`,
        );
        const remove = this.database.db.prepare(
            'DELETE FROM auth_keys WHERE session_id = ? AND type = ? AND key_id = ?',
        );

        this.database.transaction(() => {
            for (const [type, entries] of Object.entries(data)) {
                for (const [id, value] of Object.entries(entries ?? {})) {
                    if (value === null || value === undefined) {
                        remove.run(sessionId, type, id);
                    } else {
                        upsert.run(
                            sessionId,
                            type,
                            id,
                            this.encode(value, `${sessionId}:${type}:${id}`),
                        );
                    }
                }
            }
        });
    }

    deleteSession(sessionId: string): void {
        this.database.transaction(() => {
            this.database.db
                .prepare('DELETE FROM auth_keys WHERE session_id = ?')
                .run(sessionId);
            this.database.db
                .prepare('DELETE FROM auth_creds WHERE session_id = ?')
                .run(sessionId);
        });
    }

    /**
     * The Baileys-facing handle: creds are created on first use and persisted on every
     * `creds.update`.
     */
    handle(sessionId: string): AuthStateHandle {
        const creds = this.loadCreds(sessionId) ?? initAuthCreds();

        return {
            state: {
                creds,
                keys: {
                    get: async (type, ids) =>
                        this.getKeys(sessionId, type, ids),
                    set: async (data) => this.setKeys(sessionId, data),
                    clear: async () => {
                        this.database.db
                            .prepare(
                                'DELETE FROM auth_keys WHERE session_id = ?',
                            )
                            .run(sessionId);
                    },
                },
            },
            saveCreds: async () => this.saveCreds(sessionId, creds),
        };
    }

    private encode(value: unknown, associatedData: string): string {
        return this.cipher.encrypt(
            JSON.stringify(value, BufferJSON.replacer),
            associatedData,
        );
    }

    private decode(encoded: string, associatedData: string): unknown {
        return JSON.parse(
            this.cipher.decryptString(encoded, associatedData),
            BufferJSON.reviver,
        );
    }
}
