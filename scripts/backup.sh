#!/usr/bin/env bash
# PostgreSQL backup via pg_dump inside the db container
# Cron example (daily at 2am): 0 2 * * * /opt/mini_erp/scripts/backup.sh >> /var/log/erp_backup.log 2>&1
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${APP_DIR}/storage/backups/db"
COMPOSE="docker compose"
KEEP_DAYS=14

mkdir -p "$BACKUP_DIR"
cd "$APP_DIR"

# Read DB credentials from .env
source <(grep -E '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' .env)

TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
FILENAME="mini_erp_${TIMESTAMP}.sql.gz"
DEST="${BACKUP_DIR}/${FILENAME}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting backup → ${DEST}"

$COMPOSE exec -T db pg_dump \
    -U "${DB_USERNAME:-erp_user}" \
    -d "${DB_DATABASE:-mini_erp_db}" \
    --no-password \
    | gzip > "$DEST"

SIZE=$(du -sh "$DEST" | cut -f1)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup complete. Size: ${SIZE}"

# Remove backups older than KEEP_DAYS days
find "$BACKUP_DIR" -name "*.sql.gz" -mtime "+${KEEP_DAYS}" -delete
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Old backups cleaned (kept last ${KEEP_DAYS} days)."
