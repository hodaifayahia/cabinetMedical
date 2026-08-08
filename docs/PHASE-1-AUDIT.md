# Drclick Phase 1 audit

> Historical snapshot: this audit is retained without rewriting its findings.
> It predates later native supervision, versioned backup/restore, OAuth, release
> staging, and CI work. See [RELEASE-READINESS.md](RELEASE-READINESS.md) and
> [WINDOWS-OPERATIONS.md](WINDOWS-OPERATIONS.md) for current evidence and
> remaining external blockers.

- Audit date: 2026-08-04
- Scope: Laravel/PHP backend, database, authentication, settings, documents, backup/Drive integration, and the newly added desktop-foundation code
- Architecture decision: [ADR-001](architecture/ADR-001-desktop-local-first-foundation.md)
- Security gates: [Security checklist](SECURITY-CHECKLIST.md)

## Executive outcome

The existing application is a substantial Laravel/Vue medical system and should be evolved rather than replaced. Phase 1 now contains additive schemas, models, and service boundaries for desktop settings, upload sessions, tunnel state, backup history, cloud-provider state, licensing, auditing, branding, and health checks.

Those additions are a foundation, not a completed desktop or remote-upload product. At documentation freeze, the three new migrations remain pending on the active SQLite database; their forward path succeeded on a disposable copy of that 589 MB live database, and no live schema migration or telemetry pruning was performed. Reopening the active database through Laravel did persist the newly configured `journal_mode=WAL`; this operational change is called out below. There is no public phone-upload route, upload file handler/review workflow, cloudflared supervisor, Tauri shell, `.msbackup` restore engine, queued Drive implementation, activation controller, or production license-server integration.

The most urgent operational findings are:

1. The PowerShell/Windows `php` command resolves to PHP 8.2.12 and cannot boot the project; WSL PHP 8.3.6 works.
2. The active SQLite database is approximately 589.4 MB, of which roughly 585 MB is retained Telescope telemetry and indexes (about 846,800 entries at freeze). When enabled in `local`, Telescope records all entries and skips sensitive-request redaction. Phase 1 now defaults both Telescope and Inertia devtools off, but no historical telemetry was pruned.
3. Managed private storage is approximately 1.72 GB, but the current legacy backup contains only SQLite.
4. Raw SQLite restore is not a safe or complete Drclick restore and remains only a compatibility path.
5. A portable restore must address `APP_KEY`-encrypted values and must not overwrite machine-bound installation, license, tunnel, or device state blindly.
6. The active database was initially observed in `delete` journal mode. After the Phase 1 configuration landed and Laravel reopened it during verification, a direct PDO probe reported persistent `journal_mode=wal`. The 5-second busy timeout, `NORMAL` synchronization, deferred transactions, WAL sidecar handling, and concurrent/crash behavior still require verification against the packaged runtime.
7. The directory supplied for this task has no `.git` metadata, so tracked status, diffs, history, and accidental inclusion of data files could not be verified with Git.

## Detected stack

| Layer | Declared/installed version | Evidence |
| --- | --- | --- |
| PHP | `^8.3`; WSL runtime 8.3.6 | `composer.json`, `php -v` |
| Laravel | 13.23.0 installed; `^13.17` declared | `composer.json`, Composer lock |
| Laravel Inertia adapter | 3.2.0 | Composer lock |
| Fortify | 1.37.3 | Composer lock |
| Spatie Permission | 8.3.0 | Composer lock |
| Spatie Backup | 10.3.1 | Composer lock |
| DomPDF | 3.1.2 | Composer lock |
| Filament | 5.7.3 | Composer lock |
| PHPUnit | 12.5.33 | Composer lock |
| Vue | 3.5.40 | npm lock |
| Inertia Vue adapter | 3.6.1 | npm lock |
| Vite | 8.1.5 | npm lock |
| TypeScript | 5.9.3 | npm lock |
| Database | SQLite | `.env`, `config/database.php` |

`composer check-platform-reqs --no-dev` passes in WSL. A bundled Windows runtime must still explicitly enable PDO SQLite and the extensions used by files, encryption, archives, images, internationalization, and document generation.

`composer validate --strict` currently reports that `composer.lock` is not synchronized with `composer.json`. `composer audit --locked` reported no known advisories at audit time.

## Effective local configuration

The active development environment uses:

- `APP_ENV=local`
- `APP_DEBUG=true`
- `APP_URL=http://localhost:8000`
- `DB_CONNECTION=sqlite` with no explicit `DB_DATABASE`, resolving to `database/database.sqlite`
- Database-backed sessions, cache, and queue
- Local private filesystem rooted at `storage/app/private`
- Public logo filesystem rooted at `storage/app/public`
- Database queue tables, but no application jobs or supervised worker
- An application key is configured; its value was not inspected or recorded
- Google OAuth client settings are not configured in the active `.env`

The production desktop must not inherit these development defaults. Phase 1 now makes Telescope and Inertia devtools opt-in and defaults both off in configuration and `.env.example`; the packaged build must additionally use production/no-debug settings and write mutable data only beneath its AppData location.

Phase 1 now defaults Laravel's SQLite connection to `journal_mode=WAL`, `busy_timeout=5000`, `synchronous=NORMAL`, and `transaction_mode=DEFERRED`, and documents the same values in `.env.example`. The active database was initially observed in `delete` mode and now reports `wal` after a Laravel verification connection; this confirms the persistent journal-mode transition, not that the shipped PHP process, queue worker, restore tooling, and maintenance connections all use the full policy correctly. Laravel enables foreign-key constraints for application connections, but every bundled or maintenance connection must do the same intentionally.

## Authentication and authorization

The backend uses Fortify with the Eloquent session guard and Spatie roles/permissions. Enabled Fortify features are registration, password reset, email verification, confirmed two-factor authentication, and passkeys. Login is throttled to five attempts per minute by normalized email and IP.

Important behavior:

- `App\Models\User` does not implement `MustVerifyEmail`; `verified` middleware therefore does not currently enforce verification.
- There is no local administrator PIN.
- Public registration creates an account without assigning a medical role. Desktop production should replace this with a first-run-only owner setup and administrator-controlled staff provisioning.
- `DatabaseSeeder` and `CabinetDoctorSeeder` use factory accounts whose known default password is `password`. Production must never run these seeders.
- Production password rules use Laravel's `uncompromised()` check, which requires an offline-safe failure policy for a local-first application.
- Phase 1 adds an administrator-only `settings.manage` guard to every local backup, restore, Drive, and OAuth callback route, with authorization coverage proving a Receptionist is denied. `configuration.manage` remains broad for other configuration screens, and backup/restore/Drive still share one sensitive permission; high-impact actions also need password or PIN confirmation.
- Filament exposes a separate `/admin` login for allowed roles.

All administrative routes must remain unavailable through a LAN upload listener or remote upload hostname.

## Existing application structure

The project already follows useful conventions:

- Domain controllers under `app/Http/Controllers/*`
- Actions under `app/Actions/*`
- Form Requests under `app/Http/Requests/*`
- Policies under `app/Policies/*`
- Typed enums for roles, permissions, and medical states
- Eloquent `#[Fillable]` attributes, `casts()` methods, typed relationships, and route-model binding
- PHPUnit feature tests using in-memory SQLite and a sync queue

New controllers should remain thin. `ConsultationController` is approximately 800 lines and already combines consultation, document, measurement, prescription, scheduling, and upload behavior; the public QR workflow should not expand it further.

The primary application routes are in `routes/web.php`, account settings are in `routes/settings.php`, and the detailed `/health` route is registered from `bootstrap/app.php`. Laravel's built-in `/up` route also remains.

## Existing settings and branding

Before the desktop foundation, settings were split across:

- `users.name` for the doctor's display name
- `doctor_profiles.specialty` and `professional_identifier`
- `cabinet_settings` for clinic name, contact details, city, logo, and footers
- `accounting_settings` for financial defaults
- `config/clinic.php` for initial defaults

Phase 1 adds doctor-profile projection fields, specialty lock metadata, `application_settings`, and `DocumentBrandingService`. The canonical choices are defined in ADR-001. `User`, `DoctorProfile`, and `CabinetSetting` remain authoritative; duplicated doctor-profile identity fields are transitional projections.

`ClinicIdentityController` now writes both the canonical records and compatibility projections. Other writers, including profile changes and seeders, can still make projections stale. The consultation workspace and `ClinicalDocumentManager` now read through `DocumentBrandingService`, and tests cover canonical identity, UTF-8 footer assembly, and DOCX propagation. Other print/PDF consumers still need an inventory and migration; the settings UI now describes this as an incremental migration rather than claiming synchronization everywhere, and its live footer preview matches the service's labelled, pipe-delimited format.

Specialty locking is enforced in the updated `DoctorProfile` model, including an administrator-only correction method that writes an audit record. Feature tests cover direct-model rejection, non-administrator rejection, and the audited administrator correction path. Future HTTP endpoints and import/maintenance paths still require authorization tests.

## Phase 1 implementation inventory

At documentation freeze, these new migrations exist and are pending on the active database:

- `2026_08_04_030000_add_desktop_identity_fields_to_doctor_profiles.php`
- `2026_08_04_040000_create_desktop_foundation_tables.php`
- `2026_08_04_050000_create_upload_and_backup_foundation_tables.php`

Only these three migrations were exercised on a disposable copy of the actual 589,414,400-byte database. They completed in batch 19 at approximately 876 ms (`030`), 30 ms (`040`), and 15 ms (`050`); `migrate:rollback --step=3` then reverted all three successfully on the disposable database. This focused result does not repair the unrelated older full-history rollback break, did not migrate or prune the live database, and does not replace an upgrade, concurrency, backup, and recovery rehearsal on the packaged runtime.

They add or extend:

- `doctor_profiles`
- `application_settings`
- `tunnel_settings`
- `licenses`
- `devices`
- `license_activations`
- `audit_logs`
- `application_events`
- `upload_sessions`
- `uploaded_documents`
- `backup_records`
- `cloud_connections`

Current code status:

| Area | Present now | Not yet complete |
| --- | --- | --- |
| Application settings | Typed plain/encrypted key-value model with unique keys | Policy, validation API, cache strategy, key-recovery behavior |
| Branding | `DocumentBrandingService`, compatibility projection fields, specialty lock | Migration of every document/PDF/print consumer; removal or durable synchronization of projections |
| QR sessions | Random 256-bit token, SHA-256 storage, expiry, per-file/file-count/total-size snapshots, server-configured upper bounds and MIME allowlist intersection, modes, lifecycle audit | No HTTP routes, rate limiter, file receiver, quarantine writes, review/accept/reject, notifications, or QR rendering endpoint |
| Networking | Shell-free hostname resolution, private-address preference, manual/selected values, and refusal to use a public IPv4 address as the LAN upload target | Stable adapter IDs, reliable Windows enumeration, VPN/inactive filtering, firewall/socket diagnostics, IP-change notifications |
| Tunnel | Encrypted token model, unique provider/mode row, desired/runtime state fields, status and basic redaction | Installation scoping if multiple installations ever share a store, executable/version detection, sidecar control, retry/health verification, credential validation, remote host allowlist |
| Backup | Legacy `VACUUM INTO` adapter wrapped with post-snapshot record, checksum, audit, and events; raw restore disabled by default | `.msbackup`, managed files, manifest, encryption, disk-space check, queue job, portable metadata, safe/atomic restore, rollback |
| Drive | Stable service wrapper around existing encrypted-token adapter; non-empty single-use OAuth state/code validation; failed-upload record cleanup | `cloud_connections` migration, PKCE/deep link, queued/resumable upload, verification, remote list/download/restore, disconnect, retention |
| Licensing | RS256 certificate verification, installation matching, local activation records, feature lookup | Provider interface/server calls, refresh/deactivation, revocation, device-limit flow, trusted time/clock rollback, middleware/UI |
| Machine identity | Random installation UUID, encrypted local seed, preserved first-seen time, and empty-pepper fallback to `APP_KEY` | Tauri-supplied privacy-reviewed signals, startup validation of a non-empty key/pepper, migration/restore policy |
| Audit/events | Immutable audit model, recursive key redaction, common free-text secret-pattern redaction, and redacted event messages | URL/JWT/OAuth/unknown-secret coverage, enforced redaction for every diagnostic field, retention, viewer/export policy |
| Health | DB/foundation/storage/queue/LAN/tunnel/license aggregation, minimal public shape, and loopback-plus-launcher-key detailed view | Exact pending-migration detection, storage write probe, worker/socket/process checks, key provisioning/rotation, verified tunnel state |

### Current Phase 1 automated coverage

The repository now includes focused feature tests for the foundation schema and encrypted settings, tunnel-token encryption/redaction, immutable/redacted audit metadata, QR token hashing/lifecycle/server-bound limits, rejection of public LAN targets, health disclosure, specialty locking and atomic audited correction, RS256 licensing/tamper resistance/grace/feature states, canonical document branding, DOCX branding propagation, OAuth state replay/missing-state rejection, failed Drive-upload bookkeeping, and backup-route authorization. These tests materially improve the foundation, but they do not cover a file receiver, concurrent upload limits, `.msbackup`, atomic restore, Drive transfer, cloudflared supervision, Tauri, or a complete packaged upgrade/recovery rehearsal.

### Final repository verification

The final WSL PHP 8.3 verification on 2026-08-04 produced:

- `php artisan test --compact`: **137 passed, 705 assertions**.
- `npm run lint:check`, `npm run format:check`, `npm run types:check`, and `npm run build`: passed. Vite emitted only the existing optional `fontaine` fallback-optimization notice.
- PHPStan over the new foundation services/models and the Phase 1 controllers, OAuth adapter, specialty model, and licensing code: no errors.
- Repository-wide PHPStan: still fails with 176 legacy errors. The pre-Phase-1 baseline already failed with 215 errors; this foundation did not make the repository-wide gate green.
- Pint over Phase 1 files: passed. Repository-wide Pint still reports 13 unrelated legacy style issues, down from the 14-file baseline after formatting the one Phase 1 controller.
- `composer check-platform-reqs --no-dev`: passed in WSL PHP 8.3.6.
- `composer audit --locked --no-dev`: no known advisories at verification time.
- `composer validate --strict`: `composer.json` is valid, but the existing lock-file content hash is not synchronized.

The packaged Windows PHP/Tauri runtime, production-sized managed storage, and real external providers remain separate release gates.

## Backup and restore audit

### What works today

`LocalSqliteBackup` uses SQLite `VACUUM INTO`, which provides a consistent database snapshot without copying a changing SQLite file directly. `BackupService` now calculates size and SHA-256 and creates audit/event records. Google access and refresh tokens use encrypted Eloquent casts.

### Legacy limitations

The generated artifact remains a raw `.sqlite3` file:

- It omits approximately 1.72 GB of managed private documents and public logos.
- It has no Drclick manifest, format identifier, component list, archive encryption, or per-file checksums.
- Restore validates only the 16-byte SQLite header.
- Restore does not run integrity checks, validate expected tables/schema, reject malicious triggers, enter maintenance mode, stop queue workers, handle WAL/SHM sidecars, extract to a temporary location, replace atomically, or roll back automatically.
- The safety snapshot created before restore is not connected to an automatic rollback path.
- Restoring the database also restores old sessions, password-reset tokens, telemetry, and future machine-bound rows unless explicitly handled.
- The web validation limit is now 2 GB, but effective upload size is also constrained by the bundled PHP `upload_max_filesize` and `post_max_size`; typical defaults are far below the current 589 MB snapshot. A desktop file-picker/privileged restore path should not depend on a browser multipart upload.
- The local download path leaves generated snapshots on disk unless lifecycle cleanup is added.
- Raw restore is now disabled by default behind `MEDISMART_ENABLE_LEGACY_SQLITE_RESTORE`; enabling it does not make the implementation safe or portable.

`BackupService` now takes the `VACUUM INTO` snapshot before creating the completed `backup_records` row, avoiding a permanently stale `running` record inside the snapshot. Consequently, the snapshot intentionally does not contain its own completion record; the future `.msbackup` manifest must carry authoritative portable metadata. Both legacy `BackupStarted` and `BackupCompleted` events are necessarily written after the snapshot, so they are not real-time supervision signals. On legacy restore, the pre-restore audit/event rows are written to the database that is then replaced, so durable restore journaling and automatic rollback cannot rely only on that database.

`schema_version` is currently the count of migration rows. That is useful diagnostic data but is not a durable backup-format or compatibility version. `.msbackup` needs an independently managed schema/format version.

`config/backup.php` belongs to Spatie Backup but is not used by the current controller flow. It includes `base_path()` and would collect `.env`, logs, database, and private storage while the archive password is unset and verification is disabled. Do not invoke it in production until its source, exclusions, encryption, and verification policy are redesigned.

### `APP_KEY` and restore portability

Drive tokens, Fortify two-factor secrets, tunnel tokens, encrypted application settings, and other encrypted casts depend on `APP_KEY`. A database restored onto a fresh installation with a different key cannot decrypt those values.

The product must use a unique key per installation; shipping a shared key is not an acceptable workaround. The `.msbackup` design must choose one of these explicit policies:

1. Export the application data key inside an authenticated-encrypted envelope protected by a user/recovery key, then re-protect it on restore.
2. Re-encrypt selected portable secrets during backup/restore.
3. Exclude or clear non-portable integrations and require the user to reconnect Drive and reconfigure two-factor authentication.

The chosen policy must be tested before cross-install restore is offered.

### Machine-bound state

Installation UUID, machine seed/fingerprint, device records, local license activation, tunnel credentials, dynamic port, adapter selection, and process state belong to the current installation. A clinical restore from another computer must preserve, rebind, or deliberately replace each class of state; it must never copy these rows accidentally as an undocumented side effect of replacing SQLite.

## QR, LAN, and tunnel audit

The baseline had no medical-upload QR code, upload sessions, network service, or tunnel implementation. Phase 1 now has models and service scaffolding but no public upload route.

Before a LAN or remote route is enabled:

- Preserve the current rule that a session request can only narrow server-configured file-count, individual-size, total-size, and MIME limits; it cannot expand them.
- Enforce the persisted maximum individual file size as well as the aggregate limit in the eventual file receiver.
- Preserve the current refusal to select a public manual/detected IPv4 address as the LAN upload target.
- Persist a stable adapter identifier from Tauri, not only the current IP address.
- Add a dedicated upload rate limiter and token middleware.
- Validate server-detected MIME, extension, count, individual size, aggregate size, and filename; reject scripts, executables, and archives.
- Store random server-side names on the private disk, calculate SHA-256, and mark `pending_review` or `quarantined`.
- Keep all patient information off the phone landing page.
- Convert accepted uploads into the existing `Document` workflow only after an authorized desktop review.
- Restrict a remote hostname to the upload route set. Signed clinical-document links, `/admin`, `/telescope`, login/registration, settings, and detailed health must not pass through the upload tunnel.

`TunnelService` is state and redaction scaffolding only. It does not start or verify cloudflared. A process entering `running` is not evidence that the public upload URL is healthy.

## Licensing audit

`LicenseService` verifies an RS256 envelope with a configured public key and checks the product and installation identifier. No private signing key is present in the client design. Effective state, edition, expiry, offline grace, signed terminal state, and feature grants are freshly derived from the certificate; mutable projection columns cannot grant or extend access, and invalid/tampered material fails closed. Local records support active, grace, expired, suspended, revoked, device-limit, and invalid states.

Remaining requirements include a provider contract, HTTPS activation request, serial entitlement/device-limit checks, refresh and deactivation, server-outage handling, reliable last-known server time, major clock-rollback detection, feature middleware, and user-facing recovery states. Local system time alone must not become the final expiry authority.

`config/medismart.php` now treats an empty `MEDISMART_FINGERPRINT_PEPPER` as absent and falls back to `APP_KEY`, and device refresh preserves `first_seen_at`. Production setup must nevertheless validate at startup that the selected pepper/key is non-empty and unique per installation; an empty or shared `APP_KEY` must never become a fingerprint HMAC key.

Clinical data must remain readable and exportable when licensing fails. License tables and activations are excluded from portable clinical restore unless an explicit reactivation policy says otherwise.

## Health and observability audit

`ApplicationHealthService` reports application version/environment, database connectivity and migration count, foundation-table readiness, storage writability/free space, queue table counts, LAN candidates, tunnel state, URLs, and license state. `/health` returns details only when the caller is loopback **and** supplies the configured launcher-held `X-MediSmart-Health-Key`; all other callers receive the reduced shape. Tests cover both paths and exclude named secret fields.

Outstanding concerns:

- The launcher key still needs secure generation, delivery, rotation, comparison/logging tests, and exclusion from Telescope/proxy diagnostics. Loopback remains a required second condition because a local reverse proxy can forward hostile traffic.
- Healthy now requires database connectivity, the selected foundation tables, writable private storage, and queue-table availability. It still does not detect an exact pending migration set, a stopped database queue worker, a failed write probe, an unreachable listener, or an unverified tunnel URL.
- Tunnel, network, and license lookups are guarded so missing Phase 1 tables degrade the response rather than throwing from those probes. Backup/settings routes still require the schema to be migrated before use.
- Paths, errors, hostnames, queue counts, and license details remain operationally sensitive and must stay behind the launcher credential or explicit authenticated diagnostics.

Audit logging now redacts sensitive keys recursively and common bearer/command/assignment patterns embedded in string values. `ApplicationEvent` applies the same filtering to messages/context, `BackupService` redacts persisted failure text, and tunnel status applies token-pattern redaction before display. Coverage is still incomplete: URLs and query parameters, JWTs, OAuth codes, unknown credential formats, medical free text, and direct writes to tunnel/Drive `last_error` are not guaranteed safe at the model boundary. User-facing controller errors also need a generic-message policy. Redaction must be centralized and tested before any diagnostic field is stored, returned, or copied.

## Telescope and medical-data exposure

The active database is dominated by Telescope:

| Object | Approximate size |
| --- | ---: |
| `telescope_entries` | 442.3 MB |
| Telescope indexes and tags | 142+ MB |
| Entire SQLite database | 589.4 MB |

`AppServiceProvider` can register Telescope in `local`, and `TelescopeServiceProvider` records every local entry while deliberately skipping sensitive-request hiding when the tool is enabled. Historical use therefore created a potentially PHI-rich request/query/response archive that is copied into every raw database backup. Phase 1 changes `config/telescope.php` and `.env.example` to opt-in `TELESCOPE_ENABLED=false`; Inertia devtools are likewise explicitly default-off.

Production desktop configuration must still use `APP_ENV=production`, `APP_DEBUG=false`, with both tools disabled and excluded from the remote surface. Any supervised development opt-in needs explicit redaction and a short retention policy. Do not purge the current telemetry until retention/legal requirements and a verified safety backup have been considered; no purge was performed during this audit.

## Additional security and compatibility concerns

- `.env` and the active database are mode `0644` in the current WSL environment. Desktop data needs per-user Windows ACLs.
- The nested `database/.gitignore` ignores `*.sqlite*`, including `database/database.sqlite`. No Git metadata was available to verify tracked state or history, so release packaging must still use an explicit allowlist.
- The current app name defaults vary among `Laravel`, `ClickDZ Clinic`, and `Drclick`; session cookie naming and branding should be unique and consistent.
- Fixed `localhost:8000` assumptions occur in app URL, Google redirect, OnlyOffice callback URL, public storage URL, and passkey allowed origins. Dynamic ports require a consistent runtime-origin strategy.
- Passkey allowed origins include the exact configured origin. A changed host or port must be reflected safely.
- Cloudflare proxy trust and trusted-host policy are not configured.
- Session secure-cookie behavior cannot be shared casually between an HTTP LAN origin and HTTPS public hostname. Public uploads should be token-only and must not receive the desktop administrative session.
- Logos are stored on the public disk. That is appropriate only for non-sensitive branding assets with validated MIME/dimensions and a controlled serving path.
- `compose.onlyoffice.yml` still uses `onlyoffice/documentserver:latest`, but Phase 1 now binds its published port to `127.0.0.1:8088`. Pin an immutable supported image/digest and preserve loopback or isolated-network exposure before packaging.
- Root verification scripts such as `verify_dossier*.php` and `verify_docs.php` bootstrap the live application and mutate records. Patch artifacts (`*.orig`, `*.rej`) also remain. Neither class belongs in a production package.
- A full migration rollback currently fails in the older document-model migration chain because `2026_08_04_020000_remove_document_models.php` removes structures that the `2026_08_02_000000_add_word_document_fields.php` rollback expects. A fresh forward migration succeeded in-memory and the three Phase 1 migrations rolled back cleanly on the disposable live-data copy, but full-history rollback safety is not established.

## Incremental implementation plan

### Stage 1: Runtime and data safety

- Resolve the Windows PHP mismatch and pin the bundled PHP 8.3+ binary and extensions.
- Synchronize Composer metadata after reviewing dependency changes.
- Move database, storage, logs, cache, and mutable configuration to per-user AppData.
- Set production environment/debug/Telescope defaults.
- Establish unique per-install key and fingerprint-pepper provisioning.
- Test WAL, busy timeout, database queue, and snapshots on realistic data.

Exit criterion: the existing application starts with the bundled runtime, writes only to AppData, and passes tests without internet.

### Stage 2: Apply and validate the foundation schema

- Take a verified safety snapshot and managed-file copy while writers are stopped.
- Apply the three additive migrations.
- Verify expected tables, specialty backfill, foreign keys, encrypted casts, and migration status.
- Retain and expand the new targeted tests for settings encryption, specialty locking, audit redaction, license verification, health disclosure, branding, QR lifecycle, and backup authorization.

Exit criterion: forward upgrade succeeds against a production-sized copy and all existing tests remain green.

### Stage 3: Canonical settings and branding

- Route every document, PDF, print view, and preview through `DocumentBrandingService`.
- Introduce dedicated branding/settings permissions and password/PIN confirmation for sensitive actions.
- Decide when compatibility projection fields can be removed or enforce durable synchronization.
- Correct locale/UTF-8 defaults and remove fake printable fallbacks.

Exit criterion: every generated document uses persisted clinic/doctor fields and specialty cannot be changed outside the audited correction path.

### Stage 4: Secure local QR upload

- Add dedicated upload controllers, requests, middleware, rate limits, routes, and mobile page.
- Implement private quarantine, MIME/extension/size/count/hash checks, review, acceptance, rejection, expiry, and audit/events.
- Integrate accepted files with existing `Document` records.
- Add adapter selection supplied by Tauri and LAN reachability/firewall diagnostics.

Exit criterion: an unauthenticated phone can perform only a token-scoped upload, and no file reaches a patient record without desktop approval.

### Stage 5: Desktop and tunnel supervision

- Add Tauri process ownership, dynamic loopback port, health handshake, process retry/shutdown, least-privilege capabilities, and logs.
- Add cloudflared sidecar/version/status handling and upload-only host middleware.
- Verify the generated public upload URL before reporting `active`.

Exit criterion: the desktop survives child-process failures and a public hostname cannot reach administrative routes.

### Stage 6: Versioned backup and Drive

- Build an authenticated-encrypted `.msbackup` job with manifest, independent format/schema version, consistent SQLite snapshot, managed files, and checksums.
- Implement temporary extraction, zip-slip protection, compatibility checks, pre-restore safety backup, maintenance/queue shutdown, atomic replacement, rollback, session invalidation, and machine-state preservation.
- Move Drive to the provider-neutral connection model, PKCE/deep link, queued/resumable transfer, verification, listing, download, and retention.

Exit criterion: a multi-gigabyte encrypted backup restores on a clean supported installation and survives injected failures without losing the active installation.

### Stage 7: Licensing, updater, and recovery

- Add production license provider/contract, activation/deactivation, refresh, trusted-time handling, feature middleware, and offline grace.
- Add signed Tauri updater and code-signed installer.
- Complete disaster-recovery, diagnostics, security, and user-help documentation.

Exit criterion: optional commercial services can fail without deleting, hiding, or corrupting patient data.

## Migration and environment commands

Run commands with the PHP 8.3+ binary that will actually ship. On this workstation, plain PowerShell `php` is not suitable; use WSL PHP or an explicit bundled executable.

### Preflight

```text
php -v
php artisan --version
composer validate --strict
composer check-platform-reqs --no-dev
php artisan migrate:status
```

Resolve the Composer lock mismatch deliberately. After reviewing `composer.json` and desired versions, refresh lock metadata with the appropriate Composer workflow; do not run an unrestricted dependency update as an incidental migration step.

### Safe forward migration

1. Stop the web server, queue workers, and any process that can write SQLite.
2. Create and verify a consistent SQLite safety snapshot and a separate copy of managed storage.
3. Test the migration against a disposable copy first.
4. On the intended database, run:

```text
php artisan config:clear
php artisan migrate --force
php artisan migrate:status
```

5. Start the application and verify login, clinic identity, patients, documents, backup metadata, `/up`, and the authenticated detailed health view.

Never run `migrate:fresh`, `db:wipe`, destructive rollback, or `db:seed` against a clinic database.

### Verification

```text
composer lint:check
composer types:check
php artisan test
npm run lint:check
npm run format:check
npm run types:check
npm run build
```

Keep Phase 1 tests on temporary/in-memory data and mocked tunnel, Drive, and license providers. Add race, file-receiver, archive/restore, provider, process-supervision, and production-sized migration coverage before enabling the corresponding capability.

### Production environment requirements

Provision these values without committing secrets:

```text
APP_NAME=Drclick
APP_ENV=production
APP_DEBUG=false
APP_KEY=<unique per installation>
APP_URL=<launcher-managed loopback origin>
APP_TIMEZONE=Africa/Algiers
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
SESSION_ENCRYPT=true
TELESCOPE_ENABLED=false
INERTIA_DEVTOOLS_ENABLED=false
MEDISMART_HEALTH_DETAILS_KEY=<launcher-held random diagnostic key>
MEDISMART_ENABLE_LEGACY_SQLITE_RESTORE=false
DB_CONNECTION=sqlite
DB_DATABASE=<absolute writable AppData path>
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=WAL
DB_SYNCHRONOUS=NORMAL
DB_TRANSACTION_MODE=DEFERRED
QUEUE_CONNECTION=database

MEDISMART_VERSION=<release version>
MEDISMART_LOCAL_URL=<launcher-managed loopback origin>
MEDISMART_REMOTE_UPLOAD_URL=<optional controlled upload origin>
MEDISMART_LAN_PORT=<launcher-managed LAN port>
MEDISMART_UPLOAD_EXPIRY_MINUTES=15
MEDISMART_UPLOAD_MAX_FILES=10
MEDISMART_UPLOAD_MAX_FILE_BYTES=<approved limit>
MEDISMART_UPLOAD_MAX_TOTAL_BYTES=<approved limit>
MEDISMART_LICENSE_PRODUCT=medismart-desktop
MEDISMART_LICENSE_PUBLIC_KEY_PATH=<public key only>
MEDISMART_FINGERPRINT_PEPPER=<non-empty protected per-install value>
```

The launcher must override dynamic values without rewriting application files under `Program Files`. Never rotate `APP_KEY` on an existing installation until encrypted database values have been exported, re-encrypted, or intentionally cleared.

Google OAuth, tunnel credentials, archive recovery material, and signing keys require separate secret-handling procedures. Private license and updater signing keys never belong in the installed application or repository.

## Audit limitations

- The supplied directory is not a Git worktree. `git status`, tracked-file checks, blame, history, and reliable before/after diffs were unavailable.
- Other agents were implementing Phase 1 concurrently. This report reconciles files present at the time it was written; migration status and test coverage should be rechecked immediately before release.
- No source files were changed during the original backend audit, and these documentation edits did not alter application source. Telescope was enabled during part of the original audit, so Artisan diagnostics may have added historical entries before the new default-off setting landed. No destructive cleanup or live migration was attempted.
- No real Google account, production tunnel, license server, Tauri runtime, or Windows installer was exercised.
