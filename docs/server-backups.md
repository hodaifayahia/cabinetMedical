# Online service backups

The online service's database holds every clinic's data (accounts, patient
bookings, synced appointments, AI usage). Every night one encrypted copy of it
leaves the hosting twice: to the platform's Google Drive and to the operator's
Windows PC. The admin panel page **Paramètres › Sauvegardes serveur**
(`/admin/server-backups`) shows the last copy in each place and flags what is
missing with a red **Requis** badge.

This is separate from the desktop backups (Configuration › Sauvegarde on each
clinic's PC), which protect one clinic's local SQLite database.

## How it works

| Step | Where | What |
|---|---|---|
| 1 | hPanel cron, 01:30 UTC | `scripts/server/nightly-backup.sh` |
| 2 | script | Consistent snapshot: `sqlite3 .backup`, or `mariadb-dump --single-transaction` |
| 3 | script | `gzip` + AES-256-CBC (`openssl enc -pbkdf2 -iter 200000 -md sha256`), then a decrypt/`gzip -t` round trip |
| 4 | script | Keeps 14 days in `~/drclick-backups/` with a `.sha256` next to each file |
| 5 | `php artisan drclick:server-backup:record` | Logs the run and uploads the file to Google Drive (folder « Drclick serveur », newest 30 kept, SHA-256 in each file's description) |
| 6 | Windows task, 09:00 and after logon | `scripts/server/pull-backup-to-pc.sh` copies the newest file, checks SHA-256, test-decrypts, then runs `drclick:server-backup:pc-copy` over SSH |

Why a shell script and not the Laravel scheduler: Hostinger shared hosting
disables `proc_open`, `exec` and `popen` for PHP. `php artisan schedule:run`
starts every scheduled command through `proc_open`, so on this host it cannot
run anything, and PHP cannot run `mariadb-dump` either. Cron runs the shell
script directly; PHP only reads the connection
(`drclick:server-backup:env`), records the result and talks to Google.

**The PC copy is the one that matters most.** The server holds the Drive grant,
and the `drive.file` scope lets it delete every copy it made. Someone who takes
over the hosting account can wipe the Drive folder, `~/drclick-backups` and the
database together; only the PC copy is out of the server's reach. That is why
the page turns red when the PC has taken no copy for a week.

## What the page flags

| Problem | Level |
|---|---|
| Google not configured, or no Drive account connected | Requis |
| The last backup failed to reach Drive, was made while no Drive was connected, or is still « En attente » an hour later (the upload was cut off) | Requis |
| No backup for 30 hours | Requis |
| No PC copy for 72 hours (or none yet) | À vérifier |
| No PC copy for 7 days (counted from the first backup when the PC never took one) | Requis |

On the PC, the scheduled task always reports success in Task Scheduler
(`conhost --headless` drops the exit code). A failed run ends with a
`FAILED (exit N)` line in `~/.local/state/drclick-backup/pull.log` inside WSL.

## The passphrase and APP_KEY

The first run creates `~/.config/drclick-backup/passphrase` on the server
(mode 600). The Drive copy is useless without it, which is the point: Google
never holds the key. The PC pull keeps a copy in
`~/.config/drclick-backup/server-passphrase` inside WSL and checks it against
the server's on every run.

**Store the passphrase in a password manager, together with the production
`APP_KEY`** (ideally the whole production `.env`). If the server and the PC
were both lost, the Drive copies could not be opened without the passphrase,
and a restored database is only partly readable without the same `APP_KEY`
(see [Restore](#restore)).

Once backups exist, the nightly script does not replace a missing passphrase.
It stops, logs why, and the page shows the missed backup. Put the file back
from the password manager or from the PC's `server-passphrase`. Only when
every copy of it is gone, run the script once with `DRCLICK_BACKUP_NEW_KEY=1`
to start a new one. The PC notices on its next run: it keeps the old
passphrase as `server-passphrase.until-<date>` (the older copies still need
it) and fetches the new one. Store the new one in the password manager too.

## Setup on Hostinger (once)

1. **Cron jobs** (hPanel › Avancé › Tâches Cron, type « Personnalisé »). The
   Hostinger API cannot create cron jobs on this account, so this is manual:

   ```
   30 1 * * *   bash $HOME/domains/drclickdz.com/backend-laravel/scripts/server/nightly-backup.sh >> $HOME/drclick-backup.log 2>&1
   * * * * *    cd $HOME/domains/drclickdz.com/backend-laravel && php artisan queue:work --stop-when-empty --max-time=50 > /dev/null 2>&1
   ```

   The second line is the queue worker: without it queued jobs (activation
   emails, Drive uploads from the desktop flow) never run on this host.

2. **First backup now**, over SSH, so the passphrase exists before the PC is
   set up (the PC's first pull needs a backup to copy):

   ```bash
   bash $HOME/domains/drclickdz.com/backend-laravel/scripts/server/nightly-backup.sh
   ```

3. **Google Drive.** In Google Cloud Console (same project as the desktop
   client if there is one):
   - OAuth consent screen: External, scope `.../auth/drive.file`, publishing
     status **In production**. In "Testing", Google expires the refresh token
     after 7 days and nightly uploads stop.
   - Credentials › OAuth client ID › **Web application**, authorised redirect
     URI exactly `https://drclickdz.com/admin/server-backups` (the page shows
     the exact value while Google is not configured).
   - Put `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in the server `.env`.
   - Open Sauvegardes serveur › **Connecter Google Drive** and approve.

## Setup on the operator's PC (once)

Inside WSL, with the deploy key authorised for the account (hPanel › Avancé ›
Accès SSH shows the SSH user and host):

```bash
mkdir -p ~/.config/drclick-backup ~/.local/bin
cp scripts/server/pull-backup-to-pc.sh ~/.local/bin/drclick-pull-server-backup
chmod +x ~/.local/bin/drclick-pull-server-backup
cat > ~/.config/drclick-backup/pull.env <<'ENV'
DRCLICK_SSH_TARGET=u165892118@<ssh-host>
DRCLICK_SSH_PORT=65002
DRCLICK_SSH_KEY=~/.ssh/clickdz_hostinger_deploy
DRCLICK_REMOTE_APP_DIR=domains/drclickdz.com/backend-laravel
DRCLICK_PC_BACKUP_DIR="/mnt/c/Users/<you>/Documents/Drclick Backups/Serveur"
ENV
# Save the server's host key once: the task runs with BatchMode, so an
# unknown or changed host key makes every run fail.
ssh -i ~/.ssh/clickdz_hostinger_deploy -p 65002 u165892118@<ssh-host> true
drclick-pull-server-backup   # copies the first backup and saves the passphrase
```

Then from Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\server\install-pc-pull-task.ps1
```

The task runs daily at 09:00 and ten minutes after logon, and catches up when
the PC was off. Its log is `~/.local/state/drclick-backup/pull.log` in WSL.

## When the domain or the hosting account changes

A domain change in hPanel renames `domains/<site>/`. Update every place that
names it:

- the hPanel cron lines (on the new account, when the account changed);
- `DRCLICK_REMOTE_APP_DIR` in the PC's `pull.env`, and `DRCLICK_SSH_TARGET`
  for a new account, then save the new host key with one interactive `ssh`
  (see above);
- `APP_URL` in the server `.env`;
- the redirect URI in Google Cloud. Only a new connection needs it: the
  connected account keeps working, since refreshing a token does not use it.

For a new hosting account, also copy `~/.config/drclick-backup/passphrase` to
it before its first nightly run, so every copy keeps one passphrase. Without
it the new account starts its own, and the PC keeps both.

## Restore

The restored app must run with the production `APP_KEY`. Application
settings, hosted licence codes, the Google and cloud connection tokens and
two-factor secrets are encrypted with it. Under another key they cannot be
read: encrypted settings break and administrators with two-factor
authentication cannot log in.

```bash
KEY=~/.config/drclick-backup/server-passphrase     # or the server's passphrase file
F=drclick-server-20260925-013000.sql.gz.enc

sha256sum -c "$F.sha256"
```

- **A copy from Drive** has no `.sha256` next to it. Compare `sha256sum "$F"`
  with the SHA-256 in the file's description (Drive › Détails), or skip the
  check: gunzip's own CRC check below fails on a damaged file.
- **A passphrase from the password manager**: write it in WSL with
  `printf '%s\n' '<passphrase>' > key` and use `KEY=key`. Do not re-create the
  file in Notepad: a Windows line ending makes openssl fail with
  « bad decrypt », which looks like a damaged backup.

MariaDB copies (`.sql.gz.enc`):

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 -pass "file:$KEY" -in "$F" | gunzip > restore.sql
```

Create an empty database, then `mariadb -u <user> -p <database> < restore.sql`,
point `DB_DATABASE` at it, and check the site before dropping the old one.

SQLite copies (`.sqlite.gz.enc`), where the output is the database file itself:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 -pass "file:$KEY" -in "$F" | gunzip > restore.sqlite
sqlite3 restore.sqlite 'PRAGMA integrity_check;'   # ok
sqlite3 restore.sqlite 'SELECT count(*) FROM users;'
```

An empty file also passes the integrity check, so the user count is the real
test. Then stop traffic and move the file over `database/database.sqlite`
(keep the old file).

Test a restore now and then. A backup nobody has ever restored is a guess.
