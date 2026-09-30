#!/usr/bin/env bash
# Copy the newest nightly server backup to this PC.
#
# Runs in WSL, started every day by the Windows scheduled task that
# scripts/server/install-pc-pull-task.ps1 registers. Settings live in
# ~/.config/drclick-backup/pull.env (see docs/server-backups.md):
#
#   DRCLICK_SSH_TARGET=user@host      DRCLICK_SSH_PORT=65002
#   DRCLICK_SSH_KEY=~/.ssh/key        DRCLICK_REMOTE_APP_DIR=domains/<site>/backend-laravel
#   DRCLICK_PC_BACKUP_DIR="/mnt/c/Users/<you>/Documents/Drclick Backups/Serveur"
#
# The file is checked against the server's SHA-256 and test-decrypted with
# the passphrase (kept in step with the server's in ~/.config/drclick-backup/)
# before it counts, then the server is told so the back office shows the PC
# copy.
set -euo pipefail
umask 077

CONFIG="${DRCLICK_PULL_CONFIG:-$HOME/.config/drclick-backup/pull.env}"
# shellcheck source=/dev/null
[ -f "$CONFIG" ] && . "$CONFIG"

: "${DRCLICK_SSH_TARGET:?set DRCLICK_SSH_TARGET in $CONFIG}"
: "${DRCLICK_REMOTE_APP_DIR:?set DRCLICK_REMOTE_APP_DIR in $CONFIG}"
: "${DRCLICK_PC_BACKUP_DIR:?set DRCLICK_PC_BACKUP_DIR in $CONFIG}"
SSH_PORT="${DRCLICK_SSH_PORT:-22}"
SSH_KEY="${DRCLICK_SSH_KEY:-$HOME/.ssh/id_ed25519}"
SSH_KEY="${SSH_KEY/#\~/$HOME}"
REMOTE_BACKUP_DIR="${DRCLICK_REMOTE_BACKUP_DIR:-drclick-backups}"
REMOTE_KEY_FILE="${DRCLICK_REMOTE_KEY_FILE:-.config/drclick-backup/passphrase}"
LOCAL_KEY_FILE="${DRCLICK_LOCAL_KEY_FILE:-$HOME/.config/drclick-backup/server-passphrase}"
KEEP="${DRCLICK_PC_KEEP:-60}"
CIPHER=(-aes-256-cbc -pbkdf2 -iter 200000 -md sha256)

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

# Files a failed run must not leave behind (a full-size .part every night).
partial=()
on_exit() {
  local status=$?
  if [ "${#partial[@]}" -gt 0 ]; then rm -f "${partial[@]}"; fi
  # The Windows task shows success whatever happens (conhost --headless drops
  # the exit code), so this line is the PC-side record of a failed run.
  if [ "$status" -ne 0 ]; then log "FAILED (exit $status)"; fi
}
trap on_exit EXIT

ssh_opts=(-i "$SSH_KEY" -o BatchMode=yes -o ConnectTimeout=30)

# The host drops SSH connections now and then. Retry only a failed connection
# (exit 255): any other failure is real and must not be repeated blindly.
retry() {
  local attempt=0 status
  while :; do
    "$@" && return 0
    status=$?
    attempt=$((attempt + 1))
    if [ "$status" -ne 255 ] || [ "$attempt" -ge 4 ]; then
      return "$status"
    fi
    sleep $((attempt * 15))
  done
}
remote() { retry ssh "${ssh_opts[@]}" -p "$SSH_PORT" "$DRCLICK_SSH_TARGET" "$@"; }
fetch() { retry scp -q "${ssh_opts[@]}" -P "$SSH_PORT" "$DRCLICK_SSH_TARGET:$1" "$2"; }
key_sum() { sha256sum < "$1" | cut -d ' ' -f 1; }

mkdir -p "$DRCLICK_PC_BACKUP_DIR" "$(dirname "$LOCAL_KEY_FILE")"
# Left by a run that was killed mid-copy; the task never runs twice at once.
find "$DRCLICK_PC_BACKUP_DIR" -maxdepth 1 -type f -name 'drclick-server-*.part' -delete

latest="$(remote "ls -1t $REMOTE_BACKUP_DIR/drclick-server-*.gz.enc 2>/dev/null | head -n 1")"
latest="$(basename "$latest")"
if ! [[ "$latest" =~ ^drclick-server-[0-9]{8}-[0-9]{6}\.(sqlite|sql)\.gz\.enc$ ]]; then
  log "no backup found on the server"
  exit 1
fi

# The server's passphrase changes when it is lost and replaced on purpose
# (a new hosting account, DRCLICK_BACKUP_NEW_KEY=1). Compare fingerprints on
# every run, and keep the old passphrase: the older copies still need it.
server_key_sum="$(remote "sha256sum < $REMOTE_KEY_FILE" | cut -d ' ' -f 1)" || server_key_sum=''
if ! [[ "$server_key_sum" =~ ^[0-9a-f]{64}$ ]]; then
  log "cannot read the backup passphrase on the server ($REMOTE_KEY_FILE)"
  exit 1
fi
if [ ! -s "$LOCAL_KEY_FILE" ] || [ "$(key_sum "$LOCAL_KEY_FILE")" != "$server_key_sum" ]; then
  partial=("$LOCAL_KEY_FILE.part")
  fetch "$REMOTE_KEY_FILE" "$LOCAL_KEY_FILE.part"
  chmod 600 "$LOCAL_KEY_FILE.part"
  if [ -s "$LOCAL_KEY_FILE" ]; then
    previous="$LOCAL_KEY_FILE.until-$(date -u +%Y%m%d-%H%M%S)"
    mv "$LOCAL_KEY_FILE" "$previous"
    log "the server backup passphrase changed; the old one, needed for older copies, is now $previous"
  fi
  mv "$LOCAL_KEY_FILE.part" "$LOCAL_KEY_FILE"
  partial=()
  log "saved the server backup passphrase to $LOCAL_KEY_FILE; store it in the password manager too"
fi

dest="$DRCLICK_PC_BACKUP_DIR/$latest"
if [ ! -f "$dest" ]; then
  partial=("$dest.part" "$dest.sha256")
  fetch "$REMOTE_BACKUP_DIR/$latest" "$dest.part"
  fetch "$REMOTE_BACKUP_DIR/$latest.sha256" "$dest.sha256"
  expected="$(cut -d ' ' -f 1 "$dest.sha256")"
  actual="$(sha256sum "$dest.part" | cut -d ' ' -f 1)"
  if [ "$expected" != "$actual" ]; then
    log "checksum mismatch for $latest; nothing kept"
    exit 1
  fi
  if ! openssl enc -d "${CIPHER[@]}" -pass "file:$LOCAL_KEY_FILE" -in "$dest.part" | gzip -t; then
    log "$latest does not decrypt with $LOCAL_KEY_FILE; nothing kept"
    exit 1
  fi
  mv "$dest.part" "$dest"
  partial=()
  log "copied $latest"
else
  log "already have $latest"
fi

# The server's answer goes to the log: it says why when it refuses the copy.
if ! remote "cd $DRCLICK_REMOTE_APP_DIR && php artisan drclick:server-backup:pc-copy $latest"; then
  log "the server did not record the PC copy of $latest"
  exit 1
fi

find "$DRCLICK_PC_BACKUP_DIR" -maxdepth 1 -type f -name 'drclick-server-*.gz.enc' -printf '%T@ %p\n' \
  | sort -rn | tail -n +$((KEEP + 1)) | cut -d ' ' -f 2- \
  | while IFS= read -r old; do rm -f "$old" "$old.sha256"; done
