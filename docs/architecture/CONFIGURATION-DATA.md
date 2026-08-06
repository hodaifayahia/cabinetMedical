# MediSmart configuration data contract

- Status: Accepted; implemented incrementally with fail-closed capability gates
- Date: 2026-08-04
- Updated: 2026-08-05
- Related: [ADR-001](ADR-001-desktop-local-first-foundation.md), [current release readiness](../RELEASE-READINESS.md), [French configuration help](../AIDE-CONFIGURATION-FR.md), [Phase 1 audit](../PHASE-1-AUDIT.md), [security checklist](../SECURITY-CHECKLIST.md)

## Purpose

MediSmart needs configuration for the clinic, documents, uploads, connectivity, backups, Drive, licensing, updates, desktop behavior, and diagnostics. Those values do not all have the same owner or lifecycle. This contract prevents the Configuration interface, `.env`, the Tauri launcher, and SQLite from becoming competing sources of truth.

The Configuration interface must expose supported, typed settings only. It must never become an arbitrary key/value editor and must never edit `.env`, bundled resources, or files under `Program Files`.

## Implemented bootstrap configuration

The checked-in `.env.example` is the authoritative development template. A packaged desktop installation does not ask the user to edit these values: the Tauri supervisor generates or supplies installation-bound runtime values when it launches Laravel.

| Variable                                                          | Owner                            | Safe/default state                                             | Purpose                                                                                                                                                                                                                                         |
| ----------------------------------------------------------------- | -------------------------------- | -------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_NAME`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_TIMEZONE`   | release                          | `MediSmart`, `fr`, `fr`, `Africa/Algiers`                      | Product identity and regional defaults.                                                                                                                                                                                                         |
| `APP_KEY`                                                         | Tauri installation               | generated per installation; never shared                       | Encrypts local Laravel secrets. The Windows build protects it with DPAPI.                                                                                                                                                                       |
| `APP_URL`, `MEDISMART_LOCAL_URL`                                  | Tauri runtime                    | dynamic `http://127.0.0.1:{port}`                              | Exact loopback origin selected after port allocation.                                                                                                                                                                                           |
| `MEDISMART_LAN_UPLOAD_URL`                                        | Tauri runtime                    | blank until the selected native listener is bound and attested | Exact direct-phone origin: HTTP, literal selected private IPv4, and explicit dynamic high port. It is independently attested and never authorizes administration routes.                                                                        |
| `MEDISMART_DESKTOP_SUPERVISED`                                    | Tauri runtime                    | `false` outside the desktop shell                              | Enables only behavior whose native supervisor is actually present.                                                                                                                                                                              |
| `MEDISMART_DESKTOP_INSTALLATION_ID`                               | Tauri installation/runtime       | blank outside supervision; installation UUID inside it         | Makes the launcher-owned installation identity authoritative for licensing and update authorization. Laravel may mirror it, but never creates a competing identity in a supervised run.                                                         |
| `MEDISMART_SIGNED_UPDATER_CONFIGURED`                             | Tauri runtime                    | `false` outside a configured release shell                     | Read-only observation that the running native shell contains an approved HTTPS updater endpoint and public key. It is not a user feature switch.                                                                                                |
| `MEDISMART_LAN_LISTENER_STATUS`                                   | Tauri runtime                    | `stopped`                                                      | May be `active` only after a dedicated non-loopback upload listener passes health checks.                                                                                                                                                       |
| `MEDISMART_QUEUE_WORKER_STATUS`                                   | Tauri runtime                    | `stopped`                                                      | May be `active` only while the supervised backup queue worker is healthy. Drive upload controls fail closed otherwise.                                                                                                                          |
| `MEDISMART_SCHEDULER_STATUS`                                      | Tauri runtime                    | `stopped`                                                      | May be `active` only while the native Laravel scheduler is supervised and stable. Automatic-backup settings fail closed otherwise.                                                                                                              |
| `MEDISMART_REMOTE_UPLOAD_URL`                                     | installation/deployment          | blank                                                          | Exact allowlisted HTTPS hostname for the named upload tunnel; blank disables remote QR sessions.                                                                                                                                                |
| `MEDISMART_HEALTH_DETAILS_KEY`                                    | Tauri installation               | random, blank outside supervision                              | Authorizes detailed loopback health data. It is never returned to Vue.                                                                                                                                                                          |
| `MEDISMART_UPLOAD_*`                                              | release hard limits              | 15 minutes, 10 files, 20 MiB/file, 100 MiB/session             | Absolute QR upload ceilings. Stored settings may only choose values inside these limits.                                                                                                                                                        |
| `MEDISMART_ENABLE_LEGACY_SQLITE_RESTORE`                          | deployment                       | `false`                                                        | Expert compatibility switch. Normal restore accepts only verified `.msbackup` archives.                                                                                                                                                         |
| `MEDISMART_BACKUP_REMOTE_MAX_BYTES`                               | release hard limit               | 25 GiB                                                         | Refuses an oversized Drive artifact before streaming it into private storage.                                                                                                                                                                   |
| `MEDISMART_LICENSE_PRODUCT`                                       | release                          | `medismart-desktop`                                            | Signed-certificate product audience.                                                                                                                                                                                                            |
| `MEDISMART_LICENSE_ACTIVATION_URL`                                | deployment                       | blank                                                          | HTTPS activation endpoint. A blank or non-HTTPS value disables activation.                                                                                                                                                                      |
| `MEDISMART_LICENSE_STATUS_URL`                                    | deployment                       | blank                                                          | HTTPS signed-certificate refresh endpoint. A failed refresh never replaces the current certificate.                                                                                                                                             |
| `MEDISMART_LICENSE_DEACTIVATION_URL`                              | deployment                       | blank                                                          | HTTPS device-release endpoint. The local certificate is removed only after server confirmation.                                                                                                                                                 |
| `MEDISMART_LICENSE_PUBLIC_KEY_PATH`                               | release/deployment               | blank in source                                                | Read-only RS256 verification key. The private signing key never ships.                                                                                                                                                                          |
| `MEDISMART_FINGERPRINT_PEPPER`                                    | Tauri installation               | derived from per-install key when blank                        | Protects the privacy-preserving machine fingerprint hash.                                                                                                                                                                                       |
| `MEDISMART_LICENSE_CLOCK_TOLERANCE_HOURS`                         | release                          | 6                                                              | Major rollback threshold for the encrypted local time anchor; signed online refresh permits recovery.                                                                                                                                           |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | deployment                       | blank client ID; optional secret; launcher-derived callback    | Installed-app OAuth bootstrap only. Public desktop clients omit the secret, and an optional redirect is accepted only as an exact assertion of the supervised loopback callback. User access and refresh tokens are stored encrypted in SQLite. |
| `GOOGLE_DRIVE_SCOPE`                                              | release                          | `drive.file`                                                   | Restricts MediSmart to files it creates or opens through the app.                                                                                                                                                                               |
| `SESSION_DRIVER`, `SESSION_ENCRYPT`, `QUEUE_CONNECTION`           | release/runtime                  | database, `true`, database                                     | Encrypted durable sessions and persistent queued work for the supervised desktop process.                                                                                                                                                       |
| `MEDISMART_UPDATER_ENDPOINT`, `MEDISMART_UPDATER_PUBLIC_KEY`      | protected release build          | required only while compiling a release                        | Exact HTTPS manifest endpoint and Tauri updater public key embedded in the signed native binary. They are not Laravel settings and cannot be changed by the webview.                                                                            |
| `TAURI_SIGNING_PRIVATE_KEY`, `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` | protected release infrastructure | absent from source, `.env`, resources, and installed state     | Create updater signatures during a controlled release build. Their presence is required by the fail-closed release build, but their values are never logged or shipped.                                                                         |

Production packaging must override `APP_ENV=production` and `APP_DEBUG=false`. Runtime status variables are observations from the supervisor, not switches that the Configuration page is allowed to write.

## Storage and ownership

| Owner                                                                                                                    | Stores                                                                                                                                                                                 | Rules                                                                                                                                                                                                                                                                                           |
| ------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Release/build configuration                                                                                              | Product identifier, provider API origins, remote route allowlist, license verification public key, updater endpoint and public key, bundled executable names, absolute security limits | Shipped with the signed application. Read-only in the UI. Private license and updater signing keys never ship.                                                                                                                                                                                  |
| Tauri installation configuration in AppLocalData for identifier `dz.click.medismart` (normally beneath `%LOCALAPPDATA%`) | Installation UUID, writable database/storage/log paths, selected LAN adapter/listener settings, and protected tunnel credential                                                        | Machine-bound. Written atomically by the launcher or its narrow native configuration boundary. The exact resolved Windows directory is certified during clean-VM testing. No medical data or reusable bearer tokens. Tray close/quit behavior is currently code-owned, not a stored preference. |
| Dedicated SQLite domain tables                                                                                           | Clinic and doctor identity, accounting, catalogues, schedules, tunnel metadata, Drive connection metadata, licenses, activations, backup history                                       | Remain canonical for their domain. Do not duplicate these values in `application_settings`.                                                                                                                                                                                                     |
| `application_settings`                                                                                                   | Allowlisted scalar or JSON overrides that do not deserve a domain table                                                                                                                | Keys and types come from a code-owned registry. Store only overrides, not a second copy of every default. Exactly one of `plain_value` and `encrypted_value` may be populated.                                                                                                                  |
| Protected secret storage                                                                                                 | Tunnel token, OAuth tokens, backup key material, launcher health credential, machine seed                                                                                              | Values are encrypted at rest and write-only from the UI. The per-install Laravel `APP_KEY` must be generated on first launch and protected by Windows DPAPI or Credential Manager; one shared product key is prohibited.                                                                        |
| Memory/runtime status                                                                                                    | Selected dynamic port, current IP and URLs, PIDs, process and queue health, active QR URL, transient errors                                                                            | Observations, not editable configuration. Do not restore or persist them as portable settings. Redact them from public health responses.                                                                                                                                                        |

License claims and enforced security policy always win over local settings. A database value cannot enable an unlicensed feature, expose administrative routes remotely, disable redaction, lower an immutable upload hard limit, or bypass review of phone uploads.

## Authorization boundaries

The configuration page is shared for usability, but authorization is not shared. Route middleware, controller payload filtering, form write filtering, and navigation all use the same least-privilege permissions:

| Permission                          | Permitted scope                                                                                                | Recent password confirmation                                                               |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `configuration.branding.manage`     | Clinic identity, document header/footer, logo, and audited specialty correction                                | Required for specialty correction.                                                         |
| `configuration.connectivity.manage` | LAN/remote upload preferences, adapter choice, updater preferences, QR session creation, and quarantine review | Not for ordinary preferences; file review remains authenticated and audited.               |
| `configuration.backups.manage`      | Local backup policy, verified local export, and backup history                                                 | Required for an immediate export.                                                          |
| `configuration.restore.manage`      | Verified `.msbackup` preparation and supervised offline apply                                                  | Always required; the controller also restricts restore preparation to administrator roles. |
| `configuration.drive.manage`        | OAuth connection, remote listing/test/upload/download/delete, and disconnect                                   | Required for credential or archive-changing actions.                                       |
| `configuration.licensing.manage`    | Activate, refresh, or deactivate the signed installation license                                               | Always required.                                                                           |
| `configuration.diagnostics.view`    | Redacted runtime/network diagnostics and the explicit safe clipboard allowlist                                 | Read-only.                                                                                 |

The additive `2026_08_05_020000_add_granular_configuration_permissions` migration preserves existing role behavior: a role that had `configuration.manage` receives branding, while only a role that had both legacy broad permissions receives the sensitive permissions. A role with `settings.manage` alone is not broadened. Page data is also filtered: diagnostics-only users receive no patient choices, upload review rows, Drive account identity, license installation hint, or backup filenames.

## Configuration registry

Create a code-owned registry for every `application_settings` key. Each definition must contain:

- stable key and group;
- French label and help text;
- value type and nullable behavior;
- safe default and source of the effective value;
- enum values or numeric/string limits;
- server-side validation rules;
- scope: `release`, `installation`, `clinic`, or `user`;
- sensitivity and redaction policy;
- required permission and whether recent password/PIN confirmation is required;
- whether changing the value requires a Laravel, listener, tunnel, or desktop restart;
- audit policy; and
- backup/restore policy: `portable`, `machine_bound`, `reconnect`, or `excluded`.

The UI may read and update only registered, editable keys. Unknown keys must be rejected. Defaults remain in code; SQLite stores only deliberate overrides. A later additive migration should add setting scope and actor attribution if those are stored on each row, while preserving the current unique `key` constraint.

## Configuration catalogue

### Clinic and document identity

Keep these values in their current canonical models, not in generic settings:

| Data                                                                 | Canonical owner    | Notes                                                                                                                            |
| -------------------------------------------------------------------- | ------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| Doctor name                                                          | `users.name`       | `doctor_profiles.doctor_name` remains a compatibility projection until it is removed.                                            |
| Specialty, specialty code, lock time, professional/order number      | `doctor_profiles`  | Specialty is locked after setup and enforced by backend authorization. A correction requires an audited administrative workflow. |
| Clinic name, phone, email, city, full address, logo and extra footer | `cabinet_settings` | All document rendering reads through `DocumentBrandingService`.                                                                  |
| Currency and operational timezone                                    | `cabinet_settings` | Use ISO currency codes and IANA timezone identifiers. Default to `DZD` and `Africa/Algiers`.                                     |
| Appointment duration and stock/expiry warning defaults               | `cabinet_settings` | Domain screens may expose these values, but there is only one writer/source of truth.                                            |

Registered document preferences may include:

- `documents.paper_size`: enum, default `A4`;
- `documents.pdf_font`: allowlisted bundled font, default chosen for French and Arabic glyph coverage;
- `documents.show_logo`: boolean, default `true`;
- `documents.show_header`: boolean, default `true`; and
- `documents.show_footer`: boolean, default `true`.

Logo validation must enforce MIME type, dimensions, and size. A signature or stamp image, if added later, is a protected asset and must never be placed in a public directory.

The implemented `custom_branding` entitlement authorizes adding, replacing,
or removing the logo and changing the extra footer line. It does not gate the
doctor name, professional identifier, clinic name, phone, e-mail, city, or
address. If the entitlement becomes unavailable, the existing logo/footer
remain readable and continue to render on documents; only a branding mutation
is refused by both the page and controller.

### Regional and display preferences

Suggested registered keys are:

- `regional.locale`: enum, default `fr`;
- `regional.timezone`: IANA timezone, default `Africa/Algiers`;
- `regional.date_format`: enum, default `dd/MM/yyyy`;
- `regional.time_format`: enum, default `HH:mm`; and
- `regional.first_day_of_week`: integer enum `1` through `7`, default `6` only if confirmed by product policy.

Formatting preferences do not change how dates, times, or money are stored. Timestamps remain unambiguous and monetary values remain integer minor units.

### QR upload policy

Suggested keys and safe defaults are:

- `uploads.default_mode`: `local`, `remote`, or `relay`; default `local`;
- `uploads.session_ttl_minutes`: default `15`;
- `uploads.maximum_files`: default `10`;
- `uploads.maximum_individual_bytes`: default `20 MiB`;
- `uploads.maximum_total_bytes`: default `100 MiB`;
- `uploads.allowed_mime_types`: default PDF, JPEG, and PNG;
- `uploads.allowed_extensions`: default `pdf`, `jpg`, `jpeg`, and `png`;
- `uploads.require_desktop_review`: enforced `true`;
- `uploads.attempts_per_minute`: a conservative rate limit; and
- `uploads.pending_retention_hours` and `uploads.rejected_retention_hours`: explicit retention values approved by product/privacy policy.

Every editable size, count, expiry, and rate value is bounded by an immutable server hard limit. Executables, scripts, macro-enabled files, and archives remain forbidden even if a stored allowlist is tampered with. The MIME type and extension must both be acceptable, and uploads remain quarantined until review.

### Local connectivity

Suggested installation-scoped keys are:

- `connectivity.lan_enabled`: default `false` as an explicit clinic opt-in to the implemented upload-only listener;
- `connectivity.selected_adapter_id`: stable adapter identifier, nullable;
- `connectivity.port_strategy`: enforced `dynamic`;
- `connectivity.preferred_port`: optional hint within the launcher-owned range; and
- `connectivity.firewall_diagnostics_enabled`: default `true`.

Persist the adapter identifier, not only its current IP. Do not persist the active dynamic port, current IP, generated local URL, or QR URL as settings. The UI must never disable Windows Firewall or request router port forwarding.

### Remote tunnel and relay

Keep provider, tunnel ID/name, stable upload hostname, desired state, runtime state, health timestamps, redacted error, and encrypted token in `tunnel_settings`. Add only policy values that do not duplicate that table, such as:

- upload-only routing, enforced `true`;
- full remote application access, enforced `false` unless a separate reviewed product mode exists;
- automatic start, default `false` until configured and licensed;
- health interval, retry limit, and bounded backoff; and
- relay enabled, default `false`, behind the `remote_relay` feature gate.

The relay API origin is deployment configuration. Any installation credential is secret and encrypted. Tunnel and relay status are diagnostics, not settings. The implemented gate first requires a valid signed `remote_relay` claim, but no central relay provider is configured in this release; therefore even a licensed relay request remains unavailable and creates no upload session.

### Backup and restore

Suggested registered keys are:

- `backups.automatic_enabled`: default `false` as an explicit clinic opt-in and
  accepted only while the native scheduler is observed active;
- `backups.schedule_time`: local clinic time;
- `backups.verify_after_create`: enforced `true` and not user-editable;
- `backups.encryption_enabled`: enforced `true` for portable or cloud archives and not user-editable;
- `backups.retention_daily`: default `7`;
- `backups.retention_weekly`: default `4`;
- `backups.retention_monthly`: default `12`;
- `backups.maximum_storage_bytes`: nullable safety ceiling; and
- `backups.drive_auto_upload`: reserved, non-editable, and `false` until a supervised unattended secret policy exists.

`MEDISMART_PREPARED_RESTORE_RETENTION_HOURS` is a deployment-owned lifecycle value rather than a clinic preference. It defaults to 168 hours and may be set only from 24 through 8760 hours. It governs cleanup of old, intact `ready_for_offline_apply` staging pairs that were never applied; recovery, rollback, pending-restart, linked, malformed, mismatched, and recent artifacts are always retained.

The configuration screen exposes only values that currently change behavior. Mandatory verification and portable encryption are shown as enforced security properties rather than switches. Manual Google Drive uploads are queued and encrypted; an automatic Drive switch must not be exposed until the desktop vault can provide an unattended encryption secret without placing a recovery phrase in Laravel settings.

The launcher owns the absolute default local destination. The UI may select only an approved writable destination and must validate free space and permissions. `backup_records` stores operation history; the archive manifest and checksums determine validity.

A portable backup includes clinic/domain configuration, registered portable overrides, managed documents, templates, logos, and required catalogues. It excludes sessions, cache, queued jobs, PIDs, current network state, transient errors, and launcher runtime data. Machine-bound license, device, and tunnel state is preserved from the target installation rather than blindly overwritten. Portable secrets must be re-wrapped under authenticated archive encryption or cleared so the user reconnects the service.

### Google Drive

Keep provider, account email, folder ID/name, connection status, and timestamps in `cloud_connections` after the planned one-time migration from the legacy Drive store. Access and refresh tokens stay encrypted and write-only.

The folder name is user-editable; automatic Drive upload remains a reserved
false setting until an unattended secret policy exists. OAuth client/bootstrap
configuration is deployment-owned. The UI supports connect, test,
select/create folder, manual queued upload, list, download, verify, restore,
disconnect, and guarded deletion without ever requesting a Gmail password or
returning tokens to Vue.

Deletion is fail-closed. Before deleting the selected managed Drive object, the
service first requires the target itself to match a completed local
`backup_records` row by record ID, remote file ID, safe filename, size, and
SHA-256. It then requires another strictly newer object in the same exact
managed folder, validates its complete MediSmart v2 metadata, matches it to the
same exact local-record contract, and re-fetches both target and replacement
before issuing DELETE. Provider name/app-properties alone, a foreign or
unrecorded upload, an older/equal artifact, metadata drift, or an unavailable
listing protects the target from deletion.

The supervised daily Drive-retention command consumes the same daily, weekly,
monthly, and maximum-storage policy shown in Configuration. It first builds a
deterministic plan from a complete paginated inventory of exact managed v2
artifacts. Before every deletion it fetches a new inventory, requires the same
metadata fingerprint and policy decision, and then invokes the guarded
deletion proof above. A malformed item, cursor loop, changed candidate,
unavailable cache lock, missing newer local proof, or provider error stops the
run closed. Only record IDs, policy values, reason codes, and plan hashes enter
audit/application events; Drive tokens and provider payloads do not.

The implemented connection bootstrap uses a ten-minute, durable, actor/cabinet-bound attempt, hash-only state storage, an encrypted PKCE verifier, and a direct system-browser callback on the exact supervised loopback origin. The UI receives only the provider authorization URL. See [the installed-app OAuth contract](../security/google-drive-installed-app-oauth.md).

### Licensing

Product ID, licensing API origin, timeouts, and the public verification key are release/deployment configuration. The signed certificate and derived status live in `licenses`; installation/device activation records live in their dedicated tables.

Edition, expiry, offline grace, terminal status, and features come only from a valid signed certificate and are read-only. Serial activation is a masked action, not a stored general preference. Refresh rejects a different license or an older signed certificate. A material local clock rollback disables premium features until the clock is corrected or a fresh signed certificate re-anchors time; records, exports, and local backups remain available. The licensing private key never exists on the client.

Implemented feature claims have these deliberately narrow effects:

| Signed claim        | Enforced behavior                                                                                                                                                                     |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `multi_user`        | Required only to create an additional staff account. Existing accounts remain listable, editable, and removable subject to ordinary authorization and last-owner protections.         |
| `custom_branding`   | Required to upload/remove the clinic logo or change the extra footer line. Existing branding remains rendered, and ordinary clinic/practitioner identity remains editable.            |
| `remote_relay`      | Required before a relay session is considered, but insufficient while the central relay provider is absent; relay remains unavailable.                                                |
| `automatic_updates` | Required for automatic signed-update checks only. It does not enable automatic download, and it does not remove the manual check/backup-bound install path from a configured release. |

### Updates

Suggested registered preferences are:

- `updates.auto_check`: default `true`;
- `updates.channel`: `stable`, or `beta` only when the build supports it;
- `updates.check_interval_hours`: a bounded interval;
- `updates.auto_download`: reserved, non-editable, and enforced `false` until a durable native download queue with progress/cancellation exists; and
- `updates.backup_before_install`: enforced `true` when a schema-changing update is installed.

The update endpoint and public verification key are release configuration. The private signing key never ships. Release builds fail closed if either public value or the protected Tauri signing inputs are absent. The native updater accepts only its embedded HTTPS endpoint and always verifies the downloaded artifact with Tauri's signature verifier.

Manual installation is explicit. Laravel first creates and verifies a current `.msbackup`, then issues a five-minute HMAC authorization bound to the exact target version, backup record/hash, launcher installation UUID, and nonce. The native command rejects altered, expired, cross-installation, or wrong-version authorizations before downloading. The authorization contains no backup path, application key, endpoint, or private signing data. Automatic checks additionally require the signed `automatic_updates` license entitlement; manual security checks and backed-up installation remain available when the signed updater itself is present.

### Desktop, security, and diagnostics

Close-to-tray is currently enforced native behavior rather than a stored
preference: closing the startup/main window hides it, tray open restores it,
and explicit tray quit triggers ordered runtime shutdown. Launch-at-login is
not implemented and must not be exposed as a working control. Notification,
idle-lock, and log-retention preferences may be added only when a real consumer
and enforceable bounds exist.

The diagnostics page may display redacted application, PHP, cloudflared, schema, storage, queue, listener, tunnel, backup, and license health. Detailed diagnostics require authorization and a launcher-held credential. Copying diagnostics removes secrets, patient data, absolute user paths where unnecessary, and raw provider errors.

## Settings interface

Keep the current domain configuration pages and add these groups incrementally:

1. **Clinic & documents** — identity, locked specialty, logo, header/footer, and print preview.
2. **Catalogues** — medications, bilan categories, exams, acts, and payment methods in domain tables.
3. **Finance** — currency, fees, receipt numbering, fiscal settings, and accounting policy.
4. **Scheduling** — availability, default duration, closures, and time off.
5. **Uploads & connectivity** — QR policy, LAN adapter, tunnel/relay mode, verified URLs, and redacted technical status.
6. **Backup & Drive** — schedule, retention, encryption status, history, restore, and Drive connection.
7. **License** — edition, features, expiry/grace, activation, refresh, and deactivation.
8. **Updates** — policy and signed updater status.
9. **Security & diagnostics** — lock policy, audit/log retention, health, and redacted copy action.

Do not combine every concern into `ClinicIdentity.vue`. The implemented
`ConnectivityAndBackup.vue` currently groups QR policy, native LAN, backup,
Drive, license actions, update status, and redacted installation diagnostics so
their capability dependencies are visible together. A later information-
architecture change may split license, updates, or diagnostics without changing
their canonical owners. Controls for unfinished operations remain disabled with
an explicit capability reason rather than appearing to work.

Each field displays its effective value, whether it comes from a default or override, validation help, and any restart requirement. An administrator can reset a supported override to its code default. Secret inputs are always blank/masked and indicate only whether a secret is configured.

## Write and audit flow

All configuration changes must:

1. pass authentication, granular authorization, and recent password/PIN confirmation when sensitive;
2. use a Form Request or equivalent server-side validator;
3. resolve the key through the registry and enforce immutable hard limits;
4. update the canonical domain model or registered setting atomically;
5. invalidate relevant caches and restart only the affected supervised service;
6. emit a domain event and a redacted audit record; and
7. report success only after the new effective state is verified.

Audit metadata may contain the setting key, actor, reason, and non-sensitive before/after values. It must never contain a password, PIN, raw upload token, OAuth/tunnel token, machine seed, backup key, signed license certificate, or private key.

## Environment file boundary

`.env.example` contains bootstrap placeholders only: application environment, locale/timezone, SQLite/queue/cache/session/log defaults, public provider endpoints or public-key paths, and public OAuth client configuration where appropriate.

The packaged application uses `APP_ENV=production` and `APP_DEBUG=false`. The launcher supplies absolute writable AppData paths and the active loopback origin. The UI never rewrites `.env`, and `.env.example` never contains serials, OAuth tokens, tunnel tokens, passwords, private client credentials, backup recovery material, updater private keys, or license private keys.
