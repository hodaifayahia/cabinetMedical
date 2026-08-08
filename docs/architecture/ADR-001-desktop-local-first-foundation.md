# ADR-001: Desktop local-first foundation

- Status: Accepted for the Phase 1 foundation
- Date: 2026-08-04
- Decision owners: Drclick engineering
- Related: [Phase 1 audit](../PHASE-1-AUDIT.md), [security checklist](../SECURITY-CHECKLIST.md)

## Context

Drclick is an existing Laravel, Vue, and Inertia medical application. It already manages users, patients, consultations, documents, appointments, clinic identity, and SQLite data. The desktop product must preserve that working application while adding local-first Windows operation, temporary phone uploads, backup and recovery, optional cloud connectivity, licensing, and diagnostics.

The application contains medical data and must remain useful when the internet, tunnel, licensing server, or update service is unavailable. A packaging failure must not move mutable data into `Program Files`, expose the administrative application to a LAN or public tunnel, or make patient records depend on a subscription check.

Phase 1 therefore establishes stable Laravel-side boundaries and schemas without pretending that the Tauri process supervisor, cloudflared sidecar, public upload workflow, versioned restore engine, licensing server, or updater already exists.

## Decision

Drclick will remain a Laravel-backed modern monolith with Vue 3 and Inertia. SQLite remains the default local database. Tauri 2 will later be the Windows shell and privileged process supervisor; it will not replace Laravel business logic or expose a general shell to the webview.

The runtime ownership is:

| Component | Owns | Must not own |
| --- | --- | --- |
| Tauri desktop process | Writable application-data location, bundled PHP and cloudflared processes, dynamic loopback port, single-instance behavior, child-process logs, signed updater | Medical business rules, direct database mutation, unrestricted commands from Vue |
| Laravel | Authentication, authorization, settings, medical data, temporary upload sessions, review workflow, backup metadata, license verification, audit records, health aggregation | Windows process supervision, installer writes, private signing keys |
| Vue/Inertia | Authenticated desktop UI and mobile upload presentation | Secrets, permanent upload credentials, machine fingerprinting, process control |
| SQLite and managed storage | Local clinical records, operational state, accepted documents, quarantined uploads | Executables, updater keys, licensing-server private keys |
| Optional remote services | Minimum data required for upload relay, Drive backup, license status, or update delivery | The clinic database by default, unrestricted administrative access |

## Canonical data ownership

Phase 1 must avoid creating competing sources of truth. The canonical choices are:

| Concern | Canonical source | Compatibility or projection rule |
| --- | --- | --- |
| User identity and doctor display name | `users.name` | `doctor_profiles.doctor_name` is a compatibility projection and must not be edited independently. |
| Medical specialty and order identifier | `doctor_profiles.specialty` and `doctor_profiles.professional_identifier` | `specialty_code`, `specialty_locked_at`, and `medical_order_number` support desktop requirements. Existing readers remain valid while migrations are incremental. |
| Clinic name, contact details, address, logo, and footer | `cabinet_settings` | Duplicate fields added to `doctor_profiles` are projections for compatibility. All document output must read through `DocumentBrandingService`. |
| Generic desktop/runtime settings | `application_settings` | Keys are unique. Secrets use `encrypted_value`; non-secrets use `plain_value`. Clinical identity does not move into this key/value store. |
| Pending phone uploads | `upload_sessions` and `uploaded_documents` | A pending file remains quarantined. Acceptance creates or links the existing canonical `documents` record; rejection follows a retention/deletion policy. |
| Accepted patient documents | Existing `documents` model and private managed storage | The existing authenticated document panels continue to work after a reviewed QR upload is accepted. |
| Google Drive connection during the compatibility phase | Existing `drive_backup_connections` and `GoogleDriveBackup` adapter | `cloud_connections` is the future provider-neutral store. A later data migration/provider bridge must move records once; the application must not dual-write two token stores indefinitely. |
| Backup operation history | `backup_records` | This is operational metadata, not proof that an archive is valid. Archive manifest and checksum verification remain authoritative. |
| User/security audit trail | `audit_logs` | Records actor, action, subject, and redacted metadata. It must not contain raw tokens, certificates, passwords, or private keys. |
| Runtime diagnostics | `application_events` | Stores redacted system lifecycle events. It is not a substitute for the user-facing audit trail. |
| Installation, device, license, tunnel, and runtime identity | `application_settings`, `devices`, `licenses`, `license_activations`, and `tunnel_settings` | This state is machine-bound and is not blindly overwritten by a clinical-data restore. |

`DocumentBrandingService` is the transition seam. Existing controllers and document builders should be migrated to it incrementally. Until all writers are centralized, compatibility projections may become stale and must never be treated as more authoritative than the sources above.

The typed registry, storage scopes, supported setting catalogue, secret handling, and backup behavior are defined in the [configuration data contract](CONFIGURATION-DATA.md). The Configuration UI must follow that allowlist rather than exposing arbitrary `application_settings` rows.

## Trust boundaries

| Boundary | Trust level | Allowed surface | Required protection |
| --- | --- | --- | --- |
| Tauri webview to loopback Laravel | Trusted device, untrusted rendered content | Full authenticated desktop application | Session authentication, CSRF, authorization policies, CSP, fixed loopback host, per-install key |
| Phone on clinic LAN | Untrusted client | Temporary upload landing, file submission, completion | Hashed random token, expiry, rate limit, count/size/MIME limits, no patient data, private quarantine |
| Internet through Cloudflare | Hostile public network | Dedicated upload-only hostname and route allowlist | HTTPS, host middleware, token-only workflow, trusted-proxy configuration, no admin sessions or full health response |
| Tauri process boundary | Privileged local process | Narrow commands for status and child-process lifecycle | Least-privilege capabilities, validated arguments, no general shell from Vue |
| Google Drive | External storage | Encrypted, verified `.msbackup` objects only | OAuth 2.0, minimum scope, encrypted refresh token, queued/resumable transfer, retention safeguards |
| Licensing service | External authority | Serial, installation identifier, privacy-preserving fingerprint hash, signed certificate | HTTPS, asymmetric verification, embedded public key only, offline grace, clock-rollback handling |
| Local filesystem and backups | Sensitive data at rest | Database, managed documents, logs, archives | Per-user ACL, AppData location, authenticated encryption for portable/cloud archives, checksum and zip-slip defenses |

Loopback source IP alone does not establish trust. A reverse proxy or cloudflared connector can forward an internet request from `127.0.0.1`. Detailed health and administrative access must use explicit authentication, listener/host separation, or a launcher-held credential in addition to network origin.

## Security and resilience rules

- A QR contains only a short-lived random upload token. Laravel stores only its SHA-256 hash.
- Patient identifiers may be stored server-side on the upload session but are never encoded as permanent public authorization.
- Phone uploads remain outside the public web root and outside the accepted patient record until review.
- Tunnel and OAuth secrets use Laravel encrypted casts and never appear in copied diagnostics or logs.
- Every installation receives a unique `APP_KEY`. A shared product-wide key is prohibited.
- Cross-install restore must define how `APP_KEY`-encrypted values such as Drive tokens, two-factor secrets, and encrypted settings are rekeyed or intentionally cleared and reconnected.
- A restore preserves or explicitly rebinds machine-bound installation, license, device, tunnel, and runtime state. It also invalidates restored sessions and password-reset tokens.
- Licensing failure never deletes clinical data. Read, export, and backup remain available; only licensed premium capabilities are gated according to policy.
- Tunnel failure, internet loss, update failure, and queue failure must degrade optional functions without corrupting local records.
- A public health response contains no paths, network addresses, tunnel errors, queue counts, license details, or other operational internals.
- Detailed health requires both loopback origin and a launcher-held random credential; loopback alone is never authorization.
- Telescope and Inertia devtools remain opt-in and default off because either can retain medical data or secrets.
- SQLite application connections default to WAL, a deliberate busy timeout, and explicit synchronization/transaction settings; the packaged runtime must verify and test those settings.
- Raw SQLite restore remains disabled by default and expert-only until the versioned, validated, atomic `.msbackup` restore path exists.

## Phase 1 scope

Phase 1 includes:

- Auditing the existing runtime, routes, authentication, settings, documents, and backup behavior.
- Additive migrations and models for application settings, specialty locking, upload sessions, quarantine metadata, tunnel state, backup records, provider-neutral cloud connections, licensing/device state, audit logs, and application events.
- Laravel service boundaries for networking, QR session lifecycle, tunnel status/redaction, legacy backup compatibility, Drive compatibility, license certificate verification, machine identity, branding, and application health.
- Preservation of existing clinic identity, direct document upload, raw SQLite download, default-off expert restore compatibility, and Google Drive behavior while safer replacements are built.
- A detailed health aggregation service with a minimal disclosure policy.
- Safe defaults for Telescope/Inertia devtools, SQLite WAL/busy-timeout configuration, and legacy-restore gating.
- Focused feature tests plus forward/three-step rollback smoke tests for the additive Phase 1 migrations on a disposable copy of the live-sized database.
- Documentation of trust boundaries, migration commands, deferred work, and known risks.

Phase 1 does not claim completion of:

- Tauri packaging, bundled PHP, dynamic-port process supervision, tray behavior, single-instance enforcement, or signed updates.
- LAN adapter enumeration by stable adapter identifier, firewall diagnostics, socket reachability, or a second listener.
- A public/mobile upload controller, upload middleware, file quarantine pipeline, desktop review UI, notifications, or accepted-document conversion.
- cloudflared discovery, version checks, process start/stop/retry, named-tunnel provisioning, or upload-only host enforcement.
- A central relay service.
- A versioned `.msbackup` archive, archive encryption, full managed-storage inclusion, safe extraction, atomic restore, or rollback engine.
- Queued/resumable Drive upload, listing, download, restore, disconnect, revocation, or retention.
- License-server activation requests, refresh/deactivation, feature middleware, reliable last-known-server time, or clock-rollback policy.
- Installer ACLs, disaster-recovery automation, Playwright/Vitest/Tauri integration tests, or production deployment.

## Consequences

The application can evolve without a rewrite, and current medical workflows remain available. New code has explicit seams for later Tauri and provider integrations. Temporary upload and machine-bound state have schemas before public exposure.

The incremental approach also carries temporary duplication and compatibility adapters. `doctor_profiles` contains projected branding fields while existing models remain canonical, and both `drive_backup_connections` and `cloud_connections` exist until a deliberate migration. These transitions require documented ownership and tests to avoid divergence.

SQLite will serve the local-first workload, but concurrent web, queue, and phone-upload writes require WAL, a tested busy timeout, short transactions, and consistent snapshot behavior. Database queues must be supervised by the desktop runtime rather than assumed healthy because the `jobs` table exists.

## Alternatives rejected

- Replacing Laravel with a separate Node backend or replacing Vue/Inertia with a greenfield frontend.
- Electron as the desktop shell.
- A permanent authenticated dashboard URL inside a QR code.
- Exposing the entire local application through a Cloudflare Tunnel.
- Treating a serial string as the complete licensing mechanism.
- Treating a copied SQLite file as a complete, portable, safely restorable Drclick backup.
- Shipping one `APP_KEY`, OAuth secret, tunnel token, update private key, or license private key to every installation.

## Required follow-up decisions

1. Define the exact AppData directory layout and how Laravel receives absolute database, storage, cache, and log paths.
2. Define the authenticated mechanism by which Tauri updates the selected dynamic port and reports adapter/process state.
3. Choose the clinical-versus-machine-state boundary for `.msbackup` and document the rekey/reconnect behavior.
4. Migrate all document and print consumers to `DocumentBrandingService`.
5. Migrate Drive connection data once, then retire the legacy token store.
6. Add host-aware remote route middleware before any tunnel is started.
7. Establish SQLite WAL, busy timeout, backup, restore, and queue-worker tests against a realistic multi-gigabyte data set.
