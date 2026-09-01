# ADR-004: The desktop application owns the clinic's data again

- Status: accepted
- Date: 2026-08-31
- Supersedes: the 2025 thin-client change; narrows [ADR-002](ADR-002-cabinet-hub-offline-lan.md)
- Builds on: [ADR-001](ADR-001-desktop-local-first-foundation.md)

## Context

A 2025 change removed the bundled PHP/Laravel runtime from the Windows shell and
made it a thin client against one hosted HTTPS origin. The consequences were
visible in the shipped product:

- The installer was ~4 MB and the installed application ~7 MB, because it
  contained no PHP runtime, no Laravel application, and no database.
- Every byte of clinical data lived on the hosted service.
- The application could not be used at all without Internet access.

For a medical cabinet this is the wrong trade. Consultations continue during an
outage, and a clinic's records must not be unreachable because a connection is
down. ADR-001 had already established the local-first design and the supervision
core for it; that core (`src-tauri/runtime-core`, ~15k lines) survived the
change as an orphaned crate, referenced by nothing.

ADR-002 rejected a per-desktop database on the grounds that several PCs sharing
a cabinet would produce "concurrent writers, conflicts, and split-brain medical
records". That reasoning is sound for multi-PC cabinets. It is not a reason to
deny a single-PC cabinet a working offline application.

## Decision

The desktop shell resolves one question before opening a window: **which machine
owns this installation's clinical data?**

| Mode | Owner | Use |
| --- | --- | --- |
| `Local` | This PC | Default for a new installation. The shell supervises the bundled PHP/Laravel runtime against SQLite under the per-install application-data directory. Fully offline. |
| `Attach` | Another Drclick PC or a Cabinet Hub on the LAN | Cabinets where several machines share one record set. Preserves the ADR-002 invariant: exactly one write authority. |
| `Cloud` | The hosted control plane | Pre-2026 behaviour, kept so existing installations keep working. |

The mode is stored in `<app-local-data>/config/runtime-mode.json`. An
installation upgrading from a thin-client release is read from the legacy
`server.json` and keeps the origin it already used, so an upgrade never moves a
clinic onto an empty local database.

ADR-002 is narrowed, not discarded: a cabinet that needs several PCs on one
record set still uses a single write authority, reached through `Attach`.

## Consequences

- Release installers grow from ~4 MB to roughly 60–90 MB. `build.rs` fails a
  release build whose `resources/` payload is not staged, so the 7 MB shell
  cannot be shipped again by accident. `DRCLICK_ALLOW_EMPTY_PAYLOAD=1` is the
  deliberate, noisy escape hatch.
- The hosted service stops being the system of record for a `Local`
  installation. It remains the control plane for licensing, updates, and the
  mobile application.
- Two-way appointment sync (`app/Services/Sync`) becomes the only moment a
  `Local` installation needs the Internet, and it runs only when a clinician
  asks for it.

## Safety rules this decision depends on

- **Never guess the owner.** A `runtime-mode.json` that exists but cannot be
  parsed is an error, not a reason to default to `Local`. Defaulting would open
  a new empty database on a PC that was attached to a Hub, hide the clinic's
  records, and accept new ones into the wrong place.
- **Never silently fall back to the cloud.** If the local runtime fails to
  start, the shell reports it and offers the connection page. Quietly moving a
  clinic's data off the machine is not an acceptable recovery.
- **Packaged resources stay read-only.** Database, Laravel storage, caches,
  temp files, and logs are created under the writable application-data root,
  never inside `Program Files` (ADR-001).
- **One `APP_KEY` per installation**, generated on first run. A staged
  `resources/laravel/.env` fails the release build, because a shared key would
  make every clinic's encrypted data readable with the same secret.
- **Seeding never overwrites.** The empty migrated template is copied only when
  no database file exists.

## Building the payload

`npm run desktop:payload:stage -- --php-runtime <reviewed-php-dir>` produces
everything `resources/README.md` requires. The staged payload is roughly 155 MB
on disk before installer compression: ~75 MB PHP (35 MB of which is the ICU data
`intl` needs), ~80 MB Laravel including `--no-dev` vendor, and a ~1 MB database
template.

The payload is git-ignored. It is a build-machine artifact, rebuilt per release.

## Windows details that are load-bearing

Three of these were only found by booting the staged payload, and each one
breaks the application completely rather than subtly:

- **`extension_dir` must be absolute.** Windows PHP resolves a relative value
  against the working directory — which the supervisor sets to the Laravel root
  so `artisan` behaves — and with no value at all falls back to the compile-time
  `C:\php\ext`. The launcher therefore generates `php.ini` per installation and
  points PHP at it with `PHPRC`; none is staged beside `php.exe`.
- **The request router must not use `getcwd()`.** Laravel's bundled
  `server.php` does, so under supervision it looks for `index.php` one directory
  above `public/` and 500s every request. The launcher generates a router that
  reads `DOCUMENT_ROOT`, which is what `php -S -t` actually sets. It also drops
  the framework router's per-request stdout logging, because request paths carry
  patient identifiers.
- **`APP_*_CACHE` needs the drive-letter prefix registered.**
  `Application::normalizeCachePath()` treats a path as absolute only when it
  starts with `/` or `\`, so `C:\...\cache\services.php` is appended to the base
  path and Laravel tries to write inside the read-only installation directory.
  `bootstrap/app.php` registers the drive letters through
  `addAbsoluteCachePathPrefix()`. The launcher also sets `APP_SERVICES_CACHE`,
  which the supervisor did not export.

## Verified

The staged payload was installed to a directory and booted with the exact
environment the supervisor passes. `GET /health` returned
`{"status":"degraded","application":{"name":"Drclick","version":"0.1.1"}}` and
`GET /login` rendered in full. The installation directory was still read-only
afterwards — every generated cache landed under the application-data root.

Health reports `degraded` rather than `healthy` in that harness only because the
queue worker and scheduler were not started alongside it; the supervisor runs
both, and the connection probe accepts either state.
