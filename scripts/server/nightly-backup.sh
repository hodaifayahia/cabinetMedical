#!/usr/bin/env bash
# Nightly encrypted backup of the online service's database.
#
# Run from an hPanel cron job (Hostinger disables proc_open/exec for PHP, so
# Laravel's scheduler cannot start it and PHP cannot run the dump itself):
#
#   30 1 * * * bash $HOME/domains/<site>/backend-laravel/scripts/server/nightly-backup.sh >> $HOME/drclick-backup.log 2>&1
#
# 1. A consistent snapshot: sqlite3 .backup, or mariadb-dump --single-transaction.
# 2. gzip + AES-256 (openssl, PBKDF2) with the passphrase in $KEY_FILE,
#    created on first run. Keep a copy of that passphrase off the server:
#    without it no backup can be read. The PC pull script copies it. Once
#    backups exist, a missing passphrase stops the run rather than being
#    replaced (DRCLICK_BACKUP_NEW_KEY=1 starts a new one on purpose).
# 3. Decrypt/gunzip round trip before the file counts as written.
# 4. `php artisan drclick:server-backup:record` logs the run and sends the file
#    to the platform Google Drive. Local files older than $KEEP_DAYS go.
#
# Restore: docs/server-backups.md.
set -euo pipefail
umask 077

APP_DIR="${DRCLICK_APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
BACKUP_DIR="${DRCLICK_BACKUP_DIR:-$HOME/drclick-backups}"
KEY_FILE="${DRCLICK_BACKUP_KEY_FILE:-$HOME/.config/drclick-backup/passphrase}"
KEEP_DAYS="${DRCLICK_BACKUP_KEEP_DAYS:-14}"
PHP_BIN="${DRCLICK_PHP:-php}"
CIPHER=(-aes-256-cbc -pbkdf2 -iter 200000 -md sha256)

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

mkdir -p "$BACKUP_DIR" "$(dirname "$KEY_FILE")"
if [ ! -s "$KEY_FILE" ]; then
  # Backups already here were made with a passphrase that is now missing. A
  # silent new one would split the copies between two keys, and the PC and the
  # password manager would hold only the old one. Fail instead: the back office
  # then shows the missed backup.
  if compgen -G "$BACKUP_DIR/drclick-server-*.gz.enc" > /dev/null && [ "${DRCLICK_BACKUP_NEW_KEY:-0}" != 1 ]; then
    log "the backup passphrase $KEY_FILE is missing but $BACKUP_DIR holds backups made with it;" \
      "put it back (password manager, or ~/.config/drclick-backup/server-passphrase on the PC)," \
      "or run once with DRCLICK_BACKUP_NEW_KEY=1 to start a new one"
    exit 1
  fi
  openssl rand -base64 48 > "$KEY_FILE"
  log "created a new backup passphrase at $KEY_FILE; copy it somewhere safe"
fi
chmod 600 "$KEY_FILE"

cd "$APP_DIR"
db_env="$("$PHP_BIN" artisan drclick:server-backup:env)"
eval "$db_env"
unset db_env

stamp="$(date -u +%Y%m%d-%H%M%S)"
work="$(mktemp -d "$BACKUP_DIR/.work-XXXXXX")"
trap 'rm -rf "$work"' EXIT

case "$DRCLICK_DB_DRIVER" in
  sqlite)
    ext=sqlite
    raw="$work/dump.sqlite"
    sqlite3 "$DRCLICK_DB_DATABASE" ".backup \"$raw\""
    [ "$(sqlite3 "$raw" 'PRAGMA integrity_check;')" = "ok" ] || { log "integrity check failed"; exit 1; }
    ;;
  mysql|mariadb)
    ext=sql
    raw="$work/dump.sql"
    dump_bin="$(command -v mariadb-dump || command -v mysqldump)"
    # Credentials go in an option file only this user can read (umask 077),
    # inside the private work directory, never on the command line. Process
    # substitution is not an option: the hosting's cagefs has no /dev/fd.
    options="$work/client.cnf"
    escaped_password="$(printf '%s' "$DRCLICK_DB_PASSWORD" | sed 's/\\/\\\\/g; s/"/\\"/g')"
    printf '[client]\nuser="%s"\npassword="%s"\nhost="%s"\nport=%s\n' \
      "$DRCLICK_DB_USERNAME" "$escaped_password" "$DRCLICK_DB_HOST" "$DRCLICK_DB_PORT" > "$options"
    unset escaped_password
    "$dump_bin" \
      --defaults-extra-file="$options" \
      --single-transaction --quick --hex-blob --no-tablespaces \
      --default-character-set=utf8mb4 \
      "$DRCLICK_DB_DATABASE" > "$raw"
    rm -f "$options"
    tail -n 1 "$raw" | grep -q '^-- Dump completed' || { log "dump did not complete"; exit 1; }
    ;;
  *)
    log "unsupported database driver: $DRCLICK_DB_DRIVER"
    exit 1
    ;;
esac
unset DRCLICK_DB_PASSWORD

name="drclick-server-$stamp.$ext.gz.enc"
gzip -9 -c "$raw" | openssl enc "${CIPHER[@]}" -salt -pass "file:$KEY_FILE" -out "$work/$name"
openssl enc -d "${CIPHER[@]}" -pass "file:$KEY_FILE" -in "$work/$name" | gzip -t
mv "$work/$name" "$BACKUP_DIR/$name"
(cd "$BACKUP_DIR" && sha256sum "$name" > "$name.sha256")
log "wrote $BACKUP_DIR/$name ($(stat -c %s "$BACKUP_DIR/$name") bytes)"

find "$BACKUP_DIR" -maxdepth 1 -type f -name 'drclick-server-*' -mtime +"$KEEP_DAYS" -delete

if "$PHP_BIN" artisan drclick:server-backup:record "$BACKUP_DIR/$name"; then
  log "recorded"
else
  log "recorded, but the Google Drive copy failed; the server copy is kept"
  exit 2
fi
