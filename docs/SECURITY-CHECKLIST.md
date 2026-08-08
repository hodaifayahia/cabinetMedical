# Drclick security checklist

> Historical audit backlog: checkbox state is preserved as evidence of the
> original review and is not a current implementation matrix. Several controls
> below have since been implemented while external signing/clean-VM controls
> remain blocked. Use [RELEASE-READINESS.md](RELEASE-READINESS.md) for the
> current release decision and evidence boundary.

Use this checklist before enabling LAN access, a public tunnel, cloud backup, licensing enforcement, restore, or installer distribution. A checked foundation item means code exists; it does not replace verification in the packaged Windows runtime.

## Release and runtime

- [ ] **Release blocker:** Launch the app with an explicit bundled PHP 8.3+ binary; never rely on the user's `PATH`.
- [ ] Verify required PHP extensions, including PDO SQLite, fileinfo, OpenSSL, intl, DOM/XML, ZIP, and image support.
- [ ] Store database, documents, logs, cache, runtime configuration, and backups in per-user AppData, never `Program Files`.
- [ ] Apply restrictive per-user Windows ACLs to AppData and recovery material.
- [x] Default Telescope and Inertia devtools off in application configuration and `.env.example`.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, and verify Telescope/Inertia devtools stay disabled and unreachable in the shipped build.
- [ ] Give every installation a unique `APP_KEY`; never commit or ship a shared key.
- [x] Treat an empty `MEDISMART_FINGERPRINT_PEPPER` as absent and fall back to `APP_KEY`.
- [ ] Validate at startup that the effective fingerprint key is non-empty, unique per installation, and protected.
- [ ] Synchronize and review Composer/npm lockfiles; run dependency audits from authoritative registries.
- [ ] Build the installer from an explicit allowlist. Exclude `.env`, live SQLite files, backups, patient documents, logs, devtools artifacts, verification scripts, patch rejects, tests, `node_modules`, and development-only packages.
- [ ] Code-sign the installer and application executable.
- [ ] Keep license and updater private signing keys off developer worktrees and installed clients.
- [ ] Sign updates and test rollback/recovery after interrupted updates.

## Authentication and authorization

- [x] Passwords use Laravel's hashed cast and Fortify authentication.
- [x] Login, two-factor, and passkey attempts are rate-limited.
- [x] Roles, permissions, and core medical policies exist.
- [ ] Replace open registration with a first-run-only owner setup and administrator-controlled staff creation.
- [ ] Decide whether email verification is required; implement `MustVerifyEmail` or remove misleading `verified` assumptions.
- [ ] Add an administrator PIN or password-confirmation flow for restore, backup export, Drive connection, license changes, and specialty correction.
- [x] Gate local backup, restore, Drive actions, and OAuth preparation with administrator-only `settings.manage`; keep the cookie-independent callback restricted to the exact supervised loopback origin and its durable one-time attempt.
- [x] Commit a specialty correction and its audit record atomically; a forced audit failure rolls the correction back.
- [x] Split `configuration.manage` and `settings.manage` into least-privilege branding, connectivity, backup, restore, Drive, licensing, and diagnostics permissions, with an additive role-preserving upgrade migration and permission-filtered navigation/payloads.
- [ ] Never run development seeders in production; verify no known-password test account is packaged.
- [ ] Make production password changes work safely offline when breach-check services are unavailable.
- [ ] Invalidate sessions, remember tokens, password-reset tokens, and CSRF state after restore or key migration.

## Loopback, LAN, and remote trust boundaries

- [ ] Bind the administrative desktop application to loopback only.
- [ ] If LAN uploads need a listener, expose only the temporary upload route set on that listener.
- [x] Refuse to select a public IPv4 address as the LAN upload target; only RFC1918 candidates can become the preferred address.
- [ ] Use a dedicated upload hostname and host-allowlist middleware for Cloudflare.
- [ ] Reject remote requests to login, registration, dashboard, patient, settings, admin, Telescope, Inertia devtools, signed clinical-document, and detailed health routes.
- [ ] Configure trusted hosts and trusted proxies explicitly; verify HTTPS detection behind Cloudflare.
- [ ] Do not trust loopback source IP alone. A local reverse proxy can forward a hostile internet request from `127.0.0.1`.
- [ ] Keep desktop session cookies off LAN/public upload origins; use stateless token-scoped upload requests.
- [ ] Set cookie `HttpOnly`, `SameSite`, domain, and `Secure` behavior deliberately for each origin.
- [x] Apply a per-response cryptographic CSP nonce to normal Laravel/Inertia documents, Laravel Vite tags, the theme bootstrap, print/OAuth styles, and `@fonts`; production script policy permits neither `unsafe-inline` nor `unsafe-eval`.
- [x] Restrict the browser-facing OnlyOffice and non-production Vite/HMR allowances to exact, canonical loopback origins. HMR also receives only its derived loopback WebSocket origin.
- [x] Keep `style-src-attr 'unsafe-inline'` as a narrow documented compromise for Vue runtime style bindings, charts, progress indicators, and local image previews; `style-src` still requires self, a nonce, or a validated development hot origin.
- [ ] Do not disable Windows Firewall or request router port forwarding automatically.
- [ ] Redact tunnel logs before storage, display, or clipboard copy.

## QR upload sessions and files

- [x] Generate upload tokens with cryptographically secure random bytes.
- [x] Store only a SHA-256 token hash.
- [x] Model expiry, revocation, completion, mode, allowed MIME types, file count, and aggregate size.
- [x] Cap all caller-supplied limits server-side; session options can only narrow configured count, individual-size, total-size, and MIME limits.
- [x] Persist a maximum individual file size on each session and cap it to that session's aggregate limit.
- [ ] Enforce per-file, count, and aggregate limits atomically in the eventual upload receiver.
- [ ] Add a dedicated upload rate limiter and constant-time token resolution behavior where practical.
- [ ] Prevent concurrent requests from exceeding count or total-size limits; enforce limits in a transaction/lock.
- [ ] Validate server-detected MIME and permitted extension; reject executable, script, and archive formats by default.
- [ ] Sanitize the original display name and generate a random stored filename.
- [ ] Store incoming files on a private quarantine disk outside the public root and executable directories.
- [ ] Calculate and verify SHA-256 before review or relay acknowledgement.
- [ ] Show clinic branding only; never disclose patient medical data on the phone page.
- [ ] Require an authorized desktop user to accept or reject each file.
- [ ] Convert only accepted files into the existing canonical `Document` workflow.
- [ ] Define rejected/quarantined retention and secure-deletion behavior.
- [ ] Audit creation, upload, completion, expiry, revocation, acceptance, and rejection without logging the raw token.
- [ ] Test path traversal, double extensions, MIME spoofing, aggregate-limit races, expiry races, and replay.

## SQLite and local data

- [x] Configure Laravel and `.env.example` defaults for WAL, a 5-second busy timeout, `NORMAL` synchronization, and deferred transactions.
- [ ] The active database now reports `journal_mode=wal`; still verify busy timeout, synchronization, transaction policy, sidecars, and web/queue/backup/upload concurrency in the packaged runtime.
- [ ] Keep transactions short and retry only safe transient `SQLITE_BUSY` operations.
- [ ] Ensure every application/maintenance connection enables foreign keys.
- [ ] Test crash recovery with `-wal` and `-shm` files present.
- [ ] Detect pending migrations in health/startup and refuse unsafe partial startup.
- [x] Smoke-test the three Phase 1 migrations forward against a disposable copy of the active 589 MB database without applying those migrations or data changes to the original.
- [x] Roll back only those three Phase 1 migrations with `--step=3` on the disposable database; all three reverted successfully.
- [ ] Time and rehearse the complete upgrade, backup, failure recovery, and post-migration verification on production-sized database and managed-storage copies in the packaged runtime.
- [ ] Establish a forward-only contract or repair the existing document-migration rollback chain; add a full migration-cycle test.
- [ ] Never run `migrate:fresh`, `db:wipe`, destructive rollback, or production seeders on clinic data.

## Backup and restore

- [x] Use SQLite `VACUUM INTO` for the current consistent database snapshot.
- [x] Calculate snapshot size and SHA-256 and record an operation/audit event.
- [x] Create legacy backup metadata only after snapshotting, avoiding a stale `running` row inside the snapshot.
- [x] Disable raw SQLite restore by default behind `MEDISMART_ENABLE_LEGACY_SQLITE_RESTORE`.
- [ ] **Release blocker:** Replace raw SQLite export with a versioned `.msbackup` archive before calling backup complete.
- [ ] Include the consistent database snapshot, managed patient documents, clinical DOCX files, logo, manifest, and checksums.
- [ ] Exclude or sanitize cache, queue, Telescope, devtools, sessions, reset tokens, logs, and temporary files according to policy.
- [ ] Check free space before creating the temporary snapshot and final archive.
- [ ] Write to a temporary path, verify, then atomically rename.
- [ ] Use a documented authenticated-encryption format for portable/cloud archives; never invent cryptography.
- [ ] Define recovery-key storage and rotation.
- [ ] Resolve the `APP_KEY` portability policy for encrypted Drive tokens, two-factor secrets, tunnel tokens, and settings.
- [ ] Preserve or explicitly rebind machine-bound installation UUID, fingerprint seed, device, license activation, tunnel, adapter, port, and process state.
- [ ] Validate archive type and structure independently of filename.
- [ ] Prevent zip slip, symlink escape, absolute-path extraction, oversized-entry bombs, duplicate critical entries, and checksum substitution.
- [ ] Validate SQLite header, integrity, expected schema/tables, migration compatibility, and prohibited triggers/extensions.
- [ ] Require administrator password/PIN and explicit confirmation.
- [ ] Create and verify a pre-restore safety backup.
- [ ] Enter maintenance mode, stop queue workers/writers, close connections, and handle WAL/SHM sidecars.
- [ ] Restore into a temporary location, validate it, replace atomically, and implement automatic rollback.
- [ ] Invalidate restored authentication state and clear caches after success.
- [ ] Ensure restore failure leaves the original installation usable.
- [ ] Keep backup/export available when licensing is expired, suspended, revoked, or offline.
- [ ] Do not expose arbitrary raw `.sqlite3` restore in the normal production UI.

## Google Drive and other cloud storage

- [x] Existing access and refresh tokens use encrypted Eloquent casts.
- [x] OAuth state and authorization code must be non-empty, state is compared securely and consumed once before exchange, and the Drive scope is limited to `drive.file`.
- [x] Use installed-app OAuth with PKCE S256 and a cookie-independent system-browser callback; a public desktop client omits the optional client secret.
- [x] Request no broad identity scope; `drive.file` is the only OAuth scope and the Drive `about` endpoint supplies the displayed account email.
- [ ] Migrate once from `drive_backup_connections` to `cloud_connections`; avoid indefinite dual token stores.
- [x] Upload only encrypted, checksum-verified `.msbackup` files.
- [ ] Use queued/resumable transfers with bounded retries, timeouts, cancellation, and progress.
- [ ] Test the token and folder before displaying connected/active.
- [x] Implement disconnect and provider token revocation without logging credentials.
- [ ] Verify downloaded backup checksum/manifest before enabling restore.
- [ ] Enforce daily/weekly/monthly retention and never delete the last/newest verified backup.
- [x] Mock provider APIs in automated tests; never require a production Google account.

## Tunnel and relay

- [x] Tunnel token storage uses an encrypted cast and the model hides the token.
- [x] Enforce one tunnel-settings row per provider/mode in the current installation database.
- [ ] Add explicit installation scoping if more than one installation can ever share a settings store.
- [ ] Bundle and verify an approved cloudflared version; do not download/execute an arbitrary path.
- [ ] Start cloudflared without a general-purpose shell and never place its token in process logs or copied diagnostics.
- [ ] Verify stable hostname, credentials, connector state, and the upload URL before showing `active`.
- [ ] Use bounded exponential backoff and an explicit stopped/starting/active/degraded/failed state machine.
- [ ] Stop child processes cleanly and prevent orphan connectors.
- [ ] Keep a future relay behind a feature flag until the authenticated encrypted protocol and deletion acknowledgement exist.

## Licensing and machine identity

- [x] Verify signed license certificates with an asymmetric public key and RS256.
- [x] Check product and installation identifiers before local activation.
- [x] Keep the signing private key out of the client design.
- [x] Derive effective status, expiry, grace, edition, and feature grants from a freshly verified certificate; mutable SQLite projections cannot grant premium access.
- [ ] Validate all signed times, grace values, feature names, editions, and certificate size before persistence.
- [ ] Add an abstract provider and HTTPS activation/deactivation/refresh contract.
- [ ] Hash privacy-reviewed machine signals and collect no unnecessary hardware identifiers.
- [x] Preserve device `first_seen_at` on refresh.
- [ ] Validate the effective fingerprint pepper/key is non-empty before generating a fingerprint.
- [ ] Track trusted last-known server time and detect major clock rollback without permanent lockout.
- [ ] Handle server outage, revoked, suspended, expired, grace, and device-limit states distinctly.
- [ ] Never delete or hide patient data because of license state.
- [ ] Keep read, export, and backup available; gate only documented premium/write capabilities.
- [ ] Revalidate or rebind licensing after cross-machine clinical restore.

## Health, logs, and audit

- [x] A health aggregation service reports database, storage, queue-table, LAN, tunnel, URL, and license state.
- [x] Return full diagnostics only to loopback callers presenting the launcher-held health key; return a minimal shape otherwise.
- [ ] Provision, rotate, and redact the health key safely and test it behind every supported proxy/listener topology.
- [x] Detailed health detects pending migration files and performs a real create/write/flush/delete probe in private storage.
- [ ] Complete native queue-worker heartbeat, listener socket reachability, and verified tunnel-URL reconciliation in detailed health.
- [ ] Never return secrets, executable paths, raw errors, tokens, fingerprints, patient data, or full license certificates.
- [ ] Disable and remove Telescope/Inertia-devtools routes and recording from production packages.
- [ ] Redact request bodies, query bindings, headers, cookies, passwords, reset codes, medical content, and uploaded filenames in development diagnostics.
- [ ] Apply short, documented retention to telemetry and logs.
- [x] Audit records are immutable through Eloquent and audit/application-event metadata uses recursive key-name redaction.
- [x] Common bearer, command-line token, and secret-assignment patterns are redacted from string values; application-event messages/context and backup failure text use that redactor.
- [x] Redact credential-bearing URL parameters, URL user-info, authorization/cookie headers, JWT-shaped values, OAuth codes/state, health keys, and QR selectors before audit/event/tunnel persistence.
- [ ] Keep extending allowlist-based diagnostics so uploaded filenames, unknown credential formats, and medical free text are never accepted as technical log fields.
- [x] Enforce redaction before persisting tunnel/Drive `last_error`, and return generic user-facing errors while retaining safe internal diagnostics.
- [ ] Restrict audit viewing/export and define tamper/retention policy.
- [ ] Ensure copied technical diagnostics use an explicit safe allowlist.

## OnlyOffice and generated content

- [x] Clinical-document download URLs are temporary and signed.
- [x] OnlyOffice callbacks verify a signed URL, optional JWT, document key, size, and trusted origin.
- [ ] Keep signed clinical-document routes outside the public upload tunnel allowlist.
- [x] Pin OnlyOffice `9.4.0.1` to the reviewed multi-architecture manifest digest in `compose.onlyoffice.yml`; upgrades require an explicit version-and-digest review.
- [x] Bind the current OnlyOffice published port to `127.0.0.1:8088`; preserve loopback or isolated-network exposure in deployment.
- [x] Sanitize persisted clinical rich HTML with a server-side element/attribute/CSS allowlist; allow only HTTPS/mail links, reject remote images, validate embedded raster data, and paste/drop plain text in the editor.
- [ ] Apply consistent UTF-8 encoding and fonts; test French accents without emoji-dependent PDF output.
- [x] Route the consultation workspace and clinical-document builder through `DocumentBrandingService`, with canonical/UTF-8/DOCX integration tests.
- [ ] Route every print/document header and footer through `DocumentBrandingService`.

## Tauri and updater

- [ ] Define least-privilege Tauri capabilities; web content receives no general shell or unrestricted filesystem access.
- [ ] Validate resource and writable paths before process startup.
- [ ] Select an unused loopback port and communicate it without exposing secrets.
- [ ] Prevent duplicate application instances.
- [ ] Supervise PHP, queue worker, and cloudflared with bounded restart policies and clean shutdown.
- [ ] Keep process logs in AppData with redaction and retention.
- [ ] Verify `/health` through a launcher-authenticated channel before opening the main window.
- [ ] Test startup failure, port collision, child crash, forced shutdown, update interruption, and recovery screens.

## Test and release gates

- [x] Focused Phase 1 feature tests cover schema/encryption, tunnel redaction, QR token lifecycle, health disclosure, specialty correction, license signatures/grace, branding/DOCX propagation, and backup authorization.
- [ ] Extend backend coverage to upload/file-validation races, exhaustive redaction, license edge states, health failure modes, and authorization for every future endpoint.
- [ ] Backup tests cover manifest/checksums, encryption, malformed archives, zip slip, compatibility, rollback, sessions, machine-state preservation, and multi-gigabyte data.
- [ ] Frontend tests cover every status/error state without trusting `navigator.onLine` as service health.
- [ ] End-to-end tests cover desktop QR creation, mobile upload, review/acceptance, address changes, tunnel failure, backup/restore, development license, and mocked Drive.
- [ ] CI uses deterministic lockfile installs and fails on lint, format, static analysis, tests, production build, dependency advisories, and migration-cycle checks.
- [ ] A clean Windows VM installation test verifies ACLs, offline startup, upgrade, uninstall-with-data policy, backup, restore, and signed update.
- [ ] Complete a threat-model and privacy review before processing real patient uploads through LAN, tunnel, relay, or cloud storage.
