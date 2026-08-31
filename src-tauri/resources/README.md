# Desktop release resources

This directory is a staging area, not a place for mutable clinic data. It holds
the read-only payload that makes the installer a working offline application.
Everything here is produced by:

```
node scripts/desktop/stage-local-payload.mjs --php-runtime <reviewed-php-dir> [--force]
```

The whole directory is git-ignored: it is a build-machine artifact, rebuilt per
release, never committed.

Release builds fail until it is present. `src-tauri/build.rs` checks for each
item below and refuses to produce an installer that looks fine but can only work
online — the regression that shipped a 7 MB shell.

## What must be staged

- **`php/`** — a reviewed Windows PHP runtime headed by `php.exe`, with `ext/`.
  Use the official `windows.php.net` NTS x64 build matching the `php`
  constraint in `composer.json`, verified against the published
  `sha256sum.txt`. Only the extensions the application uses are kept:
  `curl`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`,
  `pdo_sqlite`, `sodium`, `sqlite3`, `zip`. `intl` is required by
  `filament/support` and brings the ICU data files with it.

  Do **not** stage a `php.ini` here. The launcher generates one per
  installation, because `extension_dir` must be an absolute path that is only
  known once the install directory is known. A relative value resolves against
  the supervisor's working directory, and an absent one falls back to the
  compile-time `C:\php\ext`; either way no extension loads.

- **`laravel/`** — the production Laravel application, its Composer
  dependencies (`--no-dev`), and the built Vite assets. It must not contain
  `.env`, development databases, logs, backups, uploaded documents, Telescope
  data, Node dependencies, tests, `public/hot`, or private signing material.

  `bootstrap/cache/` must contain nothing but `.gitignore`. A `services.php` or
  `packages.php` generated on the build machine records that machine's absolute
  provider paths; shipped to a clinic PC, Laravel cannot register its own
  providers and every request fails with `Class "view" does not exist`. The
  staging script asserts this and scrubs the directory.

- **`initial/database.sqlite`** — an empty, migrated SQLite template containing
  no clinic or patient data. The staging script builds it from scratch and
  refuses to stage one holding rows in any clinical table. Never stage the
  active `database/database.sqlite` from a development or clinic installation.

- **`initial/storage/`** — optional, non-sensitive initial mutable files.

## What is deliberately no longer staged

The local-first architecture in [ADR-003](../../docs/architecture/ADR-003-local-first-desktop-restored.md)
runs no tunnel, no LAN upload listener, and no Composer on the clinic's
machine. Staging a `cloudflared.exe` or a `composer.phar` would put unused
network and code-execution binaries next to a patient database, so the build
gate does not ask for them and the staging script does not copy them.

## Runtime ownership

Packaged resources stay read-only. The launcher creates the database, Laravel
storage, framework caches, temporary files, and logs under Tauri's per-install
local application-data directory, and points Laravel at them through
`DB_DATABASE`, `LARAVEL_STORAGE_PATH`, and the `APP_*_CACHE` variables.

Installation secrets and writable locations are supplied only through the child
environment at runtime; they are never command-line arguments and never staged
here. Every installation generates its own `APP_KEY` on first run — a staged
`resources/laravel/.env` fails the release build, because one shared key would
make every clinic's encrypted data readable with the same secret.
