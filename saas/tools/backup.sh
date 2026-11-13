#!/bin/sh
# Run from saas/. Per-database consistent snapshots; this is NOT cross-database PITR.
set -eu
umask 077
: "${BACKUP_DIR:?Set a private absolute backup directory outside the web root}"
case "$BACKUP_DIR" in /*) ;; *) echo 'BACKUP_DIR must be absolute' >&2; exit 1;; esac
mkdir -p "$BACKUP_DIR"
target=$(mktemp -d "$BACKUP_DIR/sahdev-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")
docker compose exec -T db pg_dumpall -U postgres --globals-only > "$target/roles.sql"
docker compose exec -T db psql -U postgres -d postgres -Atc "SELECT datname FROM pg_database WHERE datname = 'sahdev' OR datname ~ '^sd_t_[a-f0-9]{32}$' ORDER BY datname" > "$target/databases.txt"
while IFS= read -r database; do
  docker compose exec -T db pg_dump -U postgres -Fc -d "$database" > "$target/$database.dump"
done < "$target/databases.txt"
echo "Backup created in $target. Encrypt and copy off-server, preserve APP_KEY separately, and test restoration before treating this as recovery coverage."
