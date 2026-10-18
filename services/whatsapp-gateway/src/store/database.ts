import { chmodSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { DatabaseSync } from 'node:sqlite';

const MIGRATIONS: string[] = [
    `CREATE TABLE IF NOT EXISTS sessions (
        session_id TEXT PRIMARY KEY,
        desired_state TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )`,
    `CREATE TABLE IF NOT EXISTS auth_creds (
        session_id TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at INTEGER NOT NULL
    )`,
    `CREATE TABLE IF NOT EXISTS auth_keys (
        session_id TEXT NOT NULL,
        type TEXT NOT NULL,
        key_id TEXT NOT NULL,
        value TEXT NOT NULL,
        PRIMARY KEY (session_id, type, key_id)
    )`,
    `CREATE TABLE IF NOT EXISTS outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL UNIQUE,
        session_id TEXT NOT NULL,
        type TEXT NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at INTEGER NOT NULL,
        last_error TEXT,
        created_at INTEGER NOT NULL
    )`,
    `CREATE INDEX IF NOT EXISTS outbox_due ON outbox (status, next_attempt_at, id)`,
    `CREATE TABLE IF NOT EXISTS sent_messages (
        session_id TEXT NOT NULL,
        idempotency_key TEXT NOT NULL,
        message_id TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (session_id, idempotency_key)
    )`,
    `CREATE INDEX IF NOT EXISTS sent_messages_message_id ON sent_messages (session_id, message_id)`,
    `CREATE TABLE IF NOT EXISTS media (
        id TEXT PRIMARY KEY,
        session_id TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_name TEXT,
        size INTEGER NOT NULL,
        path TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )`,
];

/**
 * Gateway-local SQLite database (protocol state, webhook outbox, send ledger). WAL mode
 * with synchronous=FULL means a committed write survives a crash or power loss.
 */
export class GatewayDatabase {
    readonly db: DatabaseSync;

    constructor(path: string) {
        if (path !== ':memory:') {
            mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
        }

        this.db = new DatabaseSync(path);
        this.db.exec('PRAGMA journal_mode = WAL');
        this.db.exec('PRAGMA synchronous = FULL');
        this.db.exec('PRAGMA busy_timeout = 5000');

        for (const statement of MIGRATIONS) {
            this.db.exec(statement);
        }

        if (path !== ':memory:') {
            chmodSync(path, 0o600);
        }
    }

    /**
     * Runs fn inside BEGIN IMMEDIATE … COMMIT; any throw rolls the whole unit back.
     */
    transaction<T>(fn: () => T): T {
        this.db.exec('BEGIN IMMEDIATE');

        try {
            const result = fn();
            this.db.exec('COMMIT');

            return result;
        } catch (error) {
            this.db.exec('ROLLBACK');
            throw error;
        }
    }

    isHealthy(): boolean {
        try {
            return this.db.prepare('SELECT 1 AS ok').get()?.ok === 1;
        } catch {
            return false;
        }
    }

    close(): void {
        if (this.db.isOpen) {
            this.db.close();
        }
    }
}
