# Desktop release resources

This directory is a staging area, not a place for mutable clinic data.
Release builds intentionally fail until the following allowlisted resources
have been prepared:

- `laravel/`: the production Laravel application, Composer dependencies, and
  built Vite assets. It must not contain `.env`, development databases, logs,
  backups, uploaded documents, Telescope data, Node dependencies, tests, or
  private signing material. Queue supervision additionally requires the
  reviewed `config/queue.php`, the database jobs migration, and Laravel's
  `Queue/Console/WorkCommand.php` and `Queue/Worker.php`; the release gate
  refuses an application staging tree without them. Scheduler supervision also
  requires `routes/console.php` and Laravel's reviewed
  `Console/Scheduling/ScheduleWorkCommand.php`, `ScheduleRunCommand.php`, and
  `Schedule.php`. Native restore additionally requires
  `Console/Commands/NativeApplyOfflineRestore.php` and the reviewed
  `Backups/OfflineRestoreExecutor.php`, `PreparedRestore.php`, and
  `SupervisorOfflineRestoreGuard.php` bridge classes.
- `php/`: the reviewed Windows PHP runtime headed by `php.exe`, with only the
  extensions required by DrClickDz.
- `cloudflared/`: an approved official Windows `cloudflared.exe` and
  `cloudflared.manifest.json`. The strict manifest contains `schema_version: 1`,
  the exact reported `version`, and the lowercase SHA-256 of the executable.
  Release builds compare the staged bytes with that digest, and the launcher
  repeats the hash check and a bounded `--version` probe before supervision.
- `initial/database.sqlite`: an empty, migrated SQLite template containing no
  clinic or patient data.
- `initial/storage/`: optional non-sensitive initial mutable files.

At runtime these packaged resources remain read-only. The supervisor creates
the database, Laravel storage, temporary files, and logs under Tauri's
per-install local application-data directory. Never stage the active
`database/database.sqlite` from a development or clinic installation here.

The native queue worker always runs the database connection with the exact
priority list `backups,default`. Do not stage a supervisor configuration,
command wrapper, queue credential, or alternate executable. Installation
secrets and writable database/storage locations are supplied only through the
child environment at runtime and are never command-line arguments.

The native scheduler independently runs the fixed command
`php artisan schedule:work --no-interaction --quiet`. Do not stage a wrapper,
alternate scheduler executable, cron task, or Task Scheduler entry. The same
installation secrets and writable paths are inherited through its hardened
environment, and its transient process state belongs only under the launcher's
runtime directory.

Obtain cloudflared from the official Cloudflare release channel on the
controlled build machine, verify its provenance before creating the manifest,
and pin the reviewed bytes for that installer build. Do not stage a tunnel
token, credentials file, local Cloudflare configuration, origin certificate,
or `.env` file. Connector credentials are installation-specific and belong
only in the launcher's protected-secret store.

The optional phone-upload listener is a native, attestation-gated reverse proxy
configured only by the per-install `config/lan-listener.json` documented in
`docs/DESKTOP-RUNTIME.md`. Never stage a clinic adapter name, IP address, port,
or mutable listener settings in release resources. It binds one explicitly
selected private IPv4 and exposes only the fixed public upload route set; it is
not a generic Laravel proxy and does not alter firewall or router settings.
