#!/usr/bin/env bash
# Nightly backup: MySQL dump + private attachments, and (only when BACKUP_GATEWAY_STATE=1)
# an online copy of the gateway SQLite store. The gateway store is already encrypted with
# GATEWAY_STATE_KEY; that key must be backed up SEPARATELY (password manager / KMS), never
# next to these files. Archives are encrypted with age for an offline recipient key.
set -euo pipefail

APP_ROOT=/var/www/whatsapp-workspace
DEST=${BACKUP_DIR:-/var/backups/whatsapp-workspace}
RECIPIENT=${BACKUP_AGE_RECIPIENT:?set BACKUP_AGE_RECIPIENT (age public key)}
STAMP=$(date +%Y%m%d-%H%M%S)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
umask 077
mkdir -p "$DEST"

# Credentials come from ~/.my.cnf (mode 0600), never from the command line.
mysqldump --single-transaction --routines --no-tablespaces whatsapp_workspace > "$WORK/database.sql"
tar -C "$APP_ROOT/shared/storage/app" -cf "$WORK/attachments.tar" private

if [ "${BACKUP_GATEWAY_STATE:-0}" = "1" ]; then
  sqlite3 "$APP_ROOT/shared/gateway/data/gateway.sqlite" ".backup '$WORK/gateway.sqlite'"
fi

tar -C "$WORK" -czf - . | age -r "$RECIPIENT" > "$DEST/backup-$STAMP.tar.gz.age"
find "$DEST" -name 'backup-*.age' -mtime +14 -delete
echo "Backup written: $DEST/backup-$STAMP.tar.gz.age"
