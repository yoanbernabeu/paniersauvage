#!/bin/sh
# Daily PostgreSQL backup of the production stack (run on the VPS, from the deployment directory).
#
#   ./docker/backup-database.sh
#
# Cron example (every night at 03:15, see docs/DEPLOY.md):
#   15 3 * * * cd /opt/panier-sauvage && ./docker/backup-database.sh >> backups/backup.log 2>&1
#
# Settings (environment variables):
#   BACKUP_DIR             target directory (default: ./backups)
#   BACKUP_RETENTION_DAYS  dumps older than this are deleted (default: 14)
#   ENV_FILE               compose env file (default: .env.prod)
#   COMPOSE_FILE_PATH      compose file (default: compose.prod.yaml)
set -eu

BACKUP_DIR="${BACKUP_DIR:-./backups}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
ENV_FILE="${ENV_FILE:-.env.prod}"
COMPOSE_FILE_PATH="${COMPOSE_FILE_PATH:-compose.prod.yaml}"

umask 077
mkdir -p "$BACKUP_DIR"

target="$BACKUP_DIR/panier-sauvage-$(date +%Y%m%d-%H%M%S).dump"

# Custom format (-Fc): compressed, restorable with pg_restore (see docs/DEPLOY.md).
if ! docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE_PATH" exec -T database \
    sh -c 'pg_dump --format=custom --no-owner --username="$POSTGRES_USER" "$POSTGRES_DB"' > "$target.partial"; then
    rm -f "$target.partial"
    echo "$(date '+%F %T') backup FAILED" >&2
    exit 1
fi

if [ ! -s "$target.partial" ]; then
    rm -f "$target.partial"
    echo "$(date '+%F %T') backup FAILED (empty dump)" >&2
    exit 1
fi

mv "$target.partial" "$target"
find "$BACKUP_DIR" -name 'panier-sauvage-*.dump' -type f -mtime "+$BACKUP_RETENTION_DAYS" -delete

echo "$(date '+%F %T') backup written to $target ($(du -h "$target" | cut -f1))"
