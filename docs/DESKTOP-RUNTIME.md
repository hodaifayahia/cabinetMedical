# Drclick desktop runtime foundation (historical)

> **Superseded:** this document describes the removed bundled PHP/SQLite
> runtime and is retained only as implementation history. It is not the
> architecture shipped by the current installer.

## Current shipped boundary

The current Tauri application is a thin HTTPS client. It bundles no PHP
runtime, clinical database, queue worker, scheduler, LAN listener, or offline
restore engine. It opens the configured Drclick server and therefore requires
that server to be reachable. A hosted server provides shared same-cabinet data
while Internet connectivity is available; a separately installed Cabinet Hub
can provide the same server boundary on a cabinet LAN.

The Cabinet Hub installer/service, PostgreSQL deployment, signed pairing,
certificate pinning, and two-Windows-PC disconnected acceptance test are not
part of this repository's current desktop bundle. Shared offline/LAN operation
must not be advertised from this installer until those deliverables satisfy
the acceptance criteria in
[`ADR-002`](architecture/ADR-002-cabinet-hub-offline-lan.md).

The sections below document the former runtime only.

## Implemented boundary

The Tauri 2 shell in `src-tauri/` owns the local PHP process, database queue
worker, and Laravel scheduler. The Vue/Inertia webview receives no shell,
filesystem, generic process, or generic URL-opener capability. Its registered
commands are narrow adapters for content-bound offline restore, Google OAuth,
LAN adapter inspection/configuration, and signed-updater status/check/install.
None accepts a generic command, process, URL, or filesystem path.
Tauri's navigation guard accepts only its bundled startup page and the exact
`http://127.0.0.1:<selected-port>` origin chosen for the current run.

Startup is deliberately fail-closed:

1. Resolve the packaged resources (or the repository root in a debug build).
2. Create per-install `data`, `storage`, `cache`, `tmp`, `runtime`, `config`,
   and `logs` directories beneath Tauri's local application-data directory.
3. On a packaged first launch, copy a patient-free migrated SQLite template
   without overwriting an existing database and create a stable per-install
   Laravel key. On Windows the stored key is protected for the current user by
   DPAPI with UI disabled; a partial or unreadable identity is rejected rather
   than silently rotating the key and making encrypted application data
   unreadable.
4. In a packaged production run, verify the build-anchored Laravel, PHP,
   initial-database, and migration-contract manifests before inspecting the
   active database. Under the shared restore/migration lifecycle lock, finish
   or recover any interrupted migration and apply only the exact packaged
   forward migration set after creating a verified safety snapshot. A failed
   or ambiguous gate keeps every application process stopped.
5. Start the database queue worker and Laravel scheduler under independent
   bounded supervisors, then settle both initial observed states. If LAN upload
   settings are enabled, reserve the exact selected private IPv4 and managed
   high port without accepting application traffic yet.
6. Ask the operating system for an unused loopback port and immediately start
   PHP on `127.0.0.1` only.
7. Poll `/health` with a new random `X-MediSmart-Health-Key`. A public/minimal
   health response is rejected; database foundation, storage, and queue health
   must all be present and healthy.
8. When an enabled named tunnel is fully configured, strictly verify the
   authenticated response's `remote_upload_boundary` against that run's exact
   hostname and loopback origin. Only the resulting in-memory Rust capability
   can authorize a connector start; a missing or invalid attestation does not
   prevent local use but leaves the tunnel unavailable.
9. When LAN is configured, require the complete `lan_upload_boundary`
   attestation, start the restricted native proxy, and probe `/health` through
   its exact LAN origin. Only then report the listener active to PHP.
10. Open the Laravel window only after the authenticated local readiness check.
11. If PHP exits, hide the application window and make at most two bounded
    restart attempts. On application exit, request graceful termination and use
    a forced termination only after the five-second grace period.

The transient `runtime/desktop-state.json` contains only an allowlisted phase,
port, PID, retry count, timestamp, and stable error code. PHP stdout/stderr is
bounded and filtered before it reaches `logs/desktop-supervisor.log`; known
runtime credentials, upload tokens, bearer values, sensitive query values, and
private absolute paths are removed. These diagnostics are operational aids,
not a medical audit record.

The supervised PHP process receives `MEDISMART_DESKTOP_SUPERVISED=true` and the
dynamic loopback origin in both `APP_URL` and `MEDISMART_LOCAL_URL`. It also receives
`MEDISMART_QUEUE_WORKER_STATUS=active` only while the native queue worker is
observed active and `MEDISMART_SCHEDULER_STATUS=active` only while the native
scheduler is stable and alive. Every other phase is represented as `stopped`.
These values are explicit runtime contracts. When LAN is configured it also
receives the native listener's exact `MEDISMART_LAN_UPLOAD_URL` and receives
`MEDISMART_LAN_LISTENER_STATUS=active` only after the attestation-gated proxy
passes a real health check through that origin. The loopback server never
qualifies as the LAN listener.

## Native startup migration gate

The production launcher consumes the exact `initial/migration-contract.json`
and component-manifest digests embedded by the release build. It re-hashes the
packaged Laravel/PHP inventories and fixed migration helper, rejects a database
with an unknown, reordered, missing, or release-newer migration history, and
uses the fixed PHP helper to require SQLite integrity, foreign-key integrity,
and required application tables. Debug runs deliberately leave the developer
database to the normal development workflow.

When an existing installation is behind the packaged migration set, the gate
first checks bounded free-space headroom. It creates a consistent SQLite safety
snapshot beneath `storage/app/private/migration-recovery/snapshots`, verifies
its state, size, header, and SHA-256, and records each phase in an atomically
written HMAC-authenticated journal bound to the installation UUID, application
version, migration-set digest, and contract digest. Only then does it run the
fixed forward-only Laravel migration command. Success requires a second full
inspection before the journal is committed and removed.

If migration or post-validation fails, the launcher restores and verifies the
exact safety snapshot before returning a stable failure. On the next launch an
authenticated interrupted journal is recovered before any worker or listener
can start. A missing, altered, escaped, or ambiguous recovery artifact fails
closed and leaves the clinic runtime offline for authorized support. Completed
safety snapshots are retained under a bounded newest-three policy; they are not
portable backups and do not replace the normal `.msbackup` workflow.

## System tray and application exit

The native tray is active. Closing either the startup or main Drclick window
hides it without stopping the local runtime. A left click, double-click, or
**Ouvrir Drclick** restores and focuses the available window. The explicit
**Quitter Drclick** tray item marks a real quit and lets the ordered shutdown
stop and join the tunnel, LAN listener, scheduler, queue worker, and main PHP
process. Start-at-login is not implemented and no corresponding preference is
exposed.

## Native LAN upload listener

The LAN listener is disabled by default. Its non-secret launcher contract is
the strict `config/lan-listener.json` file beneath Tauri's per-install local
application-data directory:

```json
{
    "schema_version": 1,
    "enabled": true,
    "selected_adapter_id": "adapter-v1:<64 lowercase hexadecimal characters>",
    "preferred_port": 43124,
    "firewall_diagnostics_enabled": true
}
```

`selected_adapter_id` must exactly match the native stable identifier derived
from the selected physical adapter's MAC address; the raw MAC is neither stored
nor exposed by the listener. Adapters without a usable physical identifier are
excluded. There is no automatic adapter fallback: the selected
interface must currently own a private RFC 1918 IPv4 address, must not be
loopback, and must not be an identifiable tunnel or virtual adapter. If an
interface has several eligible addresses, the lowest numeric address is chosen
deterministically. `preferred_port` is optional; when absent, the operating
system assigns a managed port, which must still be at least 1024. Unknown JSON
fields, symlinks, malformed identifiers, public/link-local addresses, and bind
failures leave LAN unavailable without exposing the loopback application.
The current discovery result is written as the non-secret, schema-v1
`runtime/lan-adapters.json` inventory (stable ID, display label, IPv4, and
interface index). The supervised PHP configuration page reads only this
bounded native inventory; it contains no raw MAC address and malformed,
oversized, symlinked, public-address, or unknown-field inventories fail closed.

The socket is bound only to that one address, never `0.0.0.0`. Laravel first
starts with the exact LAN origin and listener status `stopped`. Its authenticated
loopback health response must contain the complete schema-v1
`lan_upload_boundary` attestation for `public_upload_v1`; Rust converts a full
match into a private in-memory capability. The native worker then proxies a
public `/health` request through the LAN socket. Only a healthy response changes
the PHP-facing status to `active`; that immutable environment change refreshes
only the loopback PHP child and does not consume its crash retry budget.

The proxy accepts only:

- `GET /health`
- `GET /upload/<22-character-selector>`
- `POST /upload/<selector>/authorize`
- `POST /upload/<selector>/files`
- `POST /upload/<selector>/complete`

Every other path or method fails closed. The incoming `Host` must exactly equal
the selected IPv4 and port. Absolute-form targets, query strings, encoded path
separators, forwarding/proxy headers, transfer encoding, upgrades, and the
native health key are rejected. Request heads, form bodies, multipart bodies,
responses, and all socket/backend operations have code-owned bounds and
timeouts. File bodies are streamed rather than accumulated in memory. The
proxy forwards only an allowlist of browser headers, preserves Laravel cookies
and CSRF headers, never follows redirects, and permits only same-origin upload
redirects.

Accepted private-LAN connections run in a fixed pool of four workers. A fifth
connection receives a prompt `503` instead of occupying unbounded threads.
Header, body, backend, response-write, and total connection lifetimes have
absolute deadlines in addition to inactivity timeouts. Reconfiguration and
shutdown close every tracked client socket and join both the accept worker and
all connection workers. Slow-header, slow-body, saturation, and post-saturation
recovery behavior is covered by the Rust runtime tests.

Laravel remains the authoritative security layer for the selector/verifier,
token hash and expiry, audience binding, CSRF cookie, throttles, file count,
individual/total byte quotas, MIME inspection, quarantine storage, completion,
and audit records. The native 128 MiB request ceiling is only a coarse outer
limit above Laravel's default 100 MiB session quota. No administrative,
configuration, backup, clinical, Livewire, generic proxy, shell, opener, or
filesystem route is exposed.

`runtime/lan-listener-state.json` contains only schema version, phase, origin,
selected adapter identifier, timestamp, and a stable error code. During a PHP
refresh the socket and already verified active contract remain reserved while
new requests receive unavailable responses until the new loopback backend is
attested. A fatal accept failure immediately clears the backend and active
status, then permits at most two exact-address rebind attempts with bounded
backoff. Shutdown clears the backend, interrupts every active client socket,
and joins all LAN workers before the scheduler, queue worker, or main process
can enter the offline-restore critical section.

The configuration page first persists the user's requested preference in
SQLite, then invokes the exact schema-v1
`apply_lan_listener_configuration` command. Only the supervised loopback main
window has permission to call it; no path, raw adapter identity, shell,
filesystem, generic proxy, or firewall-rule surface is accepted. The native
side validates and atomically writes `config/lan-listener.json`, reserves the
exact adapter/port, and increments the immutable PHP contract generation. A
new QR origin is not published until the refreshed Laravel child attests that
exact origin and the local health probe passes. Rejection leaves the listener
closed and the page persistently distinguishes the requested preference from
the verified runtime state with an actionable error. The companion
`list_lan_adapters` command returns only the bounded inventory and stable
runtime observation.

Native discovery reconciles the selected adapter every two seconds. If its
private IPv4 disappears or changes, the old listener and QR origin are closed,
the PHP environment is refreshed, and the replacement address must pass a new
origin-bound attestation before becoming active. There is never an automatic
fallback to another adapter. `firewall_diagnostics_enabled` exposes only the
result of the bounded local listener health probe. A successful result is not
proof that a phone can cross Windows Firewall, and Drclick never creates,
removes, or modifies firewall rules.

`GET /upload/<selector>` is a self-contained Blade document at
`resources/views/uploads/public.blade.php`. Its nonce-only CSP, inline CSS and
inline JavaScript require no `/build` or `/storage` requests; authorize, files,
and complete are same-origin JSON or multipart fetches. The native listener
therefore keeps the exact upload-only route set without adding a generic public
asset surface.

## Database queue worker

The shell supervises one fixed Laravel database worker for the exact priority
list `backups,default`. Its code-owned command is equivalent to:

```text
php artisan queue:work database --queue=backups,default --sleep=1 --tries=3 --backoff=60 --timeout=60 --no-interaction --quiet
```

The application root, SQLite database, writable storage/cache directories,
installation ID, and `APP_KEY` are inherited through the child environment;
none is appended to the command line. The worker receives
`QUEUE_CONNECTION=database`. A 750-millisecond stability window must pass
before the worker is reported active. An exit immediately changes the
conservative PHP-facing observation to `stopped`, followed by at most two
restart attempts with one- and two-second backoff. Shutdown sends the same
graceful process-group request used by the PHP supervisor, waits up to five
seconds, and then forces termination if necessary.

Because a PHP environment cannot be modified in place, the loopback PHP child
is restarted whenever the observed worker status differs from the value it was
launched with. The replacement does not become ready until it contains the new
truthful `MEDISMART_QUEUE_WORKER_STATUS`. This refresh does not consume PHP's
own crash retry budget. The transient `runtime/queue-worker-state.json` stores
only schema version, phase, active/stopped status, PID, retry count, timestamp,
and a stable error code. Worker stdout/stderr is drained to prevent process
blocking but is never persisted verbatim; stable lifecycle messages go to the
bounded/redacted `logs/queue-worker-supervisor.log` instead.

Queue consumption enables the existing queued Drive-upload job. It runs beside
the scheduler and is shut down only after the scheduler has stopped accepting
new scheduled work.

## Laravel scheduler

The shell separately supervises the exact code-owned command:

```text
php artisan schedule:work --no-interaction --quiet
```

It invokes the packaged PHP executable directly and never launches through
`cmd`, a batch file, cron, or Windows Task Scheduler. The scheduler inherits the
same production application root, SQLite database, writable storage/cache and
temporary directories, installation identity, `APP_KEY`, database queue
connection, and production logging restrictions as the queue worker. These
values are environment-only. The scheduler subprocess receives
`MEDISMART_SCHEDULER_STATUS=active` so scheduled commands can verify their own
supervised execution context; its conservative view of the separate queue
worker remains `stopped`.

A 750-millisecond stability window must pass before the native supervisor
reports the scheduler active. It permits one initial launch and at most two
restarts with one- and two-second bounded backoff. An exit changes the observed
status to `stopped` immediately. Shutdown requests graceful process-group
termination, waits five seconds, and forces termination only after that grace
period. Tauri retains and joins the scheduler and queue thread handles during
runtime shutdown.

The immutable environment of an existing PHP listener cannot be updated. If
either the queue-worker or scheduler observation changes, only the loopback
Laravel child is restarted with both current values. This runtime-contract
refresh does not stop or restart the queue worker or scheduler and does not
consume Laravel's crash retry budget. The transient
`runtime/scheduler-state.json` contains only schema version, phase,
active/stopped status, PID, retry count, timestamp, and a stable error code.
Scheduler stdout and stderr are drained to prevent blocking but omitted from
logs; only stable lifecycle messages are written to the bounded/redacted
`logs/scheduler-supervisor.log`.

## Cloudflare sidecar foundation (attestation-gated and disabled by default)

The shell now contains a separate supervisor for an approved, named,
remotely-managed Cloudflare Tunnel connector. It never invokes `--url` as a
quick-tunnel command and rejects `trycloudflare.com` hostnames. The fixed child
command ends in `tunnel ... run`; the connector token is supplied only through
the inherited `TUNNEL_TOKEN` environment and is filtered from bounded child
logs. No token, credentials-file path, raw command, or private filesystem path
is written to `runtime/tunnel-state.json`.

The human-readable `tunnel-state.json` file is diagnostic only. In an
installed build, every lifecycle transition and each five-second successful
runtime readiness check also atomically publishes
`runtime/tunnel-public-status.json` with private file permissions. That exact
schema-v1 payload is HMAC-authenticated with the per-launch health key and is
bound to the runtime UUID, installation UUID, application version, configured
hostname, verified cloudflared version, exact loopback origin, phase,
timestamp, and monotonically increasing sequence. The supervised PHP child
receives only the fixed status path and the already-required runtime identity
values. Laravel rejects unknown fields, bad types/signatures, identity or
hostname drift, replay, future timestamps, and evidence older than 15 seconds.
Database `runtime_state` values can never manufacture an active tunnel.

The non-secret launcher settings contract is a strict
`config/cloudflare-tunnel.json` beneath Tauri's per-install local application
data directory:

```json
{
    "schema_version": 1,
    "enabled": false,
    "provider": "cloudflare",
    "management": "remote",
    "tunnel_id": "00000000-0000-0000-0000-000000000000",
    "upload_hostname": "uploads.clinic.example"
}
```

An enabled record requires a real non-nil tunnel ID and an exact lowercase DNS
hostname; URL strings, IP addresses, wildcards, localhost, unknown fields, and
quick-tunnel hostnames are refused. The connector credential belongs in
`config/cloudflared.token`, written by a trusted launcher provisioning path. On
Windows that file is DPAPI-protected for the current user. There is deliberately
no plaintext token or `.env` fallback and no UI/native command for provisioning
it in this increment.

For an otherwise valid enabled record, the shell supplies
`MEDISMART_REMOTE_UPLOAD_URL=https://<upload-hostname>` to the supervised PHP
child. The hostname comes only from the validated JSON settings above; the
dynamic listener origin continues to come from the operating-system-selected
loopback port. The keyed direct-loopback health response must then contain this
complete schema-v1 object:

```json
{
    "schema_version": 1,
    "status": "ready",
    "hostname": "uploads.clinic.example",
    "listener_origin": "http://127.0.0.1:43123",
    "route_set": "public_upload_v1",
    "upload_routes_only": true,
    "exact_host_enforced": true,
    "trusted_proxy_enforced": true,
    "forwarded_https_enforced": true,
    "local_tokens_rejected_on_remote_host": true
}
```

The Rust deserializer rejects a missing, duplicate, or unknown extra field, a
wrong type or constant, a non-lowercase or mismatched hostname, a stale
listener origin, and any false control. A match mints a private, non-serializable
`VerifiedRemoteUploadBoundary` capability bound to the exact hostname and
listener origin. The desktop tunnel preparation path consumes it once before
calling the connector supervisor. No preference, `.env` switch, persisted
snapshot, or independent static Boolean can manufacture this capability.

Before starting, the launcher verifies the bundled executable against
`cloudflared.manifest.json` and runs a five-second bounded `--version` probe.
It supplies the exact dynamically selected `http://127.0.0.1:<port>` Laravel
origin through `TUNNEL_URL`, binds cloudflared metrics to a separate dynamic
loopback port, and requires local `/ready`. It also verifies `/diag/tunnel`
matches the configured named-tunnel UUID and inspects cloudflared's effective
`/config`: exactly one ingress rule may target the selected loopback origin,
that rule must use the configured upload hostname, its fallback must be
`http_status:404`, and WARP routing or additional origins are rejected. Finally,
the exact `https://<upload-hostname>/health` endpoint must return the current
application version without following redirects. These checks continue while
the process runs, so a remotely pushed route change stops the connector.
Startup is bounded to 45 seconds, restart attempts are capped at two, and
shutdown requests graceful termination for five seconds before a forced kill.

Cloudflare's remotely delivered ingress can replace local CLI ingress after the
connector establishes a session. Consequently, the attestation gate precedes
every start, while the effective-config and public-readiness checks continue to
guard the established connector. Missing settings report
`stopped/tunnel_disabled`. An enabled connector with a missing, malformed,
extra-field, mismatched, or stale attestation reports
`unavailable/tunnel_origin_attestation_unavailable`, and cloudflared is not
started. Local Laravel readiness remains independent of remote exposure.

## Google OAuth system-browser boundary

The backend prepares Google Drive OAuth state and PKCE material at
`POST /app/configuration/backup/google/prepare` and returns an
`authorization_url`. Vue may pass that value to the single-purpose native
command; it must not call a generic opener API:

```ts
import { invoke } from '@tauri-apps/api/core';

const result = await invoke<{ opened: boolean }>(
    'open_google_oauth_authorization',
    { authorizationUrl: prepared.authorization_url },
);
```

The command is available only to the remote-loopback `main` window ACL. It
requires the current keyed-healthy supervised port and accepts only the exact
`https://accounts.google.com/o/oauth2/v2/auth` endpoint with no credentials,
fragment, or non-default port. Its query must contain exactly one of each
backend-owned field: a bounded client ID, 43-character state and PKCE
challenge, `response_type=code`, `code_challenge_method=S256`, the exact
`drive.file` scope, `access_type=offline`, `prompt=consent`, and a redirect URI
equal to the current `http://127.0.0.1:<selected-port>` origin plus
`/app/configuration/backup/google/callback`. Duplicate, missing, or additional
query fields fail closed.

Only after validation does Rust call the official Tauri opener integration.
Its automatic JavaScript link handling is disabled, and the capability grants
no `opener:*`, shell, or filesystem permission, so the webview cannot use the
plugin's generic URL/path commands. Success returns only `{ "opened": true }`;
failure returns a stable `code` and fixed French `message_fr`, never the URL or
provider details.

## Native offline restore lifecycle

The registered `apply_prepared_offline_restore` Tauri command accepts exactly
one strict JSON artifact:

```json
{
    "protocol": "medismart-offline-restore-authorization",
    "version": 1,
    "operation_id": "9b82c22e-4eef-47ad-b2db-2f2c904d69d2",
    "plan_sha256": "<64 lowercase hexadecimal characters>"
}
```

There is no archive path, executable, argument list, or generic filesystem
input. Unknown fields are rejected. Tauri's generated application ACL grants
this one command only to the `main` window on loopback HTTP origins; the
navigation guard separately binds that window to the current selected port.
Rust resolves the operation only beneath
the current installation's managed `storage/app/private/restore-work` and
`restore-journals` directories. Before stopping a process it rejects symlinks
or escaped paths, validates the bounded plan and ready journal formats, binds
both to the supplied digest, restricts every inventory path to the database and
four managed document roots, checks totals and portable names, hashes every
staged file, rejects unexpected files, and verifies the SQLite header. PHP
repeats the full plan, journal, inventory, archive/schema, and SQLite checks
under the native exclusive lease.

After preflight, one serialized lifecycle transition freezes PHP dependency
refreshes and stops/joins the tunnel, the reserved optional-LAN slot, scheduler,
queue worker, and main Laravel process in that deterministic order. The lease
is valid only for that stopped runtime generation and is revoked before any
restart. Rust then invokes only the fixed hidden command
`php artisan medismart:restore:native-apply <operation-uuid> --no-interaction`;
the rotating lease secret travels over child stdin and never through the
artifact, arguments, environment, response, snapshot, or log.

An applied restore starts fresh queue, scheduler, and Laravel supervisor
instances. Success is returned only after Laravel passes the new keyed detailed
health check; the tunnel can resume only from the new run's origin-bound
attestation. A refused apply or completed rollback similarly restarts and
verifies the previous runtime. Ambiguous native output, incomplete rollback,
lost ownership, or failed post-restore health leaves all writers offline and
returns only a stable code, fixed French operator message, and safe runtime
state. The source preparation, automatic safety backup, staging data, journal,
and rollback targets are never deleted by the adapter.

Preparation emits the non-secret artifact after authentication and staging.
The ordinary `medismart:restore:apply` command and all web/controller apply
paths remain disabled; adding a restoration wizard must preserve administrator
confirmation before passing this artifact to Tauri.

## Signed updater boundary

A release build fails before packaging unless the protected build environment
provides the exact HTTPS updater endpoint, the Tauri updater public key, and
the private signing key/password used only to generate updater artifacts. The
endpoint and public key are compiled into the native shell; the private key and
password are neither bundled nor exposed to Laravel or Vue. Debug builds have
no configured updater.

The loopback `main` window may call only three updater commands: read bounded
status, check the embedded endpoint, and install the update already held as the
native pending candidate. The webview cannot replace the endpoint or public
key. A manual installation first passes recent-password confirmation in
Laravel, creates and verifies a current `.msbackup`, and receives a five-minute
HMAC authorization bound to the exact target version, completed backup record
and SHA-256, installation UUID, issuance/expiry times, and nonce. Rust rejects
unknown fields, altered signatures, expiry, another installation, or any
version other than its pending signed update before download begins.

Artifact download and signature verification are performed by Tauri's updater
plugin with the embedded public key. `updates.auto_download` is reserved and
forced off: there is no background download queue, progress UI, or cancellation
claim. Automatic checks require the valid signed `automatic_updates`
entitlement, while an operator may still run a manual check and the mandatory
backup-bound install when the signed updater is configured. Real endpoint
publication, release signing material, and a previous-version Windows rehearsal
remain external release controls.

## Development checks

Normal browser development is unchanged:

```text
npm run dev
```

The native checks are:

```text
npm run desktop:core:test
npm run desktop:queue:test
npm run desktop:restore:test
npm run desktop:scheduler:test
npm run desktop:tunnel:test
cargo check --manifest-path src-tauri/Cargo.toml --all-targets
cargo clippy --manifest-path src-tauri/Cargo.toml --all-targets -- -D warnings
cargo test --manifest-path src-tauri/Cargo.toml --all-targets
```

`npm run desktop:dev` starts the native shell. In debug builds it uses the
current repository and system `php`, so it does not copy or replace the active
development SQLite database or Laravel storage. `MEDISMART_DESKTOP_APP_ROOT`
and `MEDISMART_PHP_BINARY` are debug-only overrides.

## Release staging gate

The repository intentionally does not contain Windows PHP/cloudflared
distributions or a copy of the clinic database. Before
`npm run desktop:build`, run the hash-pinned fail-closed staging pipeline in
[DESKTOP-RELEASE-STAGING.md](DESKTOP-RELEASE-STAGING.md) on a controlled
Windows build machine. The Rust release build fails when PHP, Laravel runtime
files, built assets, the empty migrated SQLite seed, cloudflared, or its
matching SHA-256/version manifest are missing. Queue-specific release checks
also require Laravel's database queue
configuration, jobs migration, work command, and worker implementation.
Scheduler-specific checks require `routes/console.php` plus Laravel's
`ScheduleWorkCommand.php`, `ScheduleRunCommand.php`, and `Schedule.php`.
Restore-specific checks require the hidden native apply command and the
prepared-plan, executor, and supervisor-guard classes. Do not work around this
check: it prevents a superficially successful but unusable installer.
Migration-specific checks bind the fixed helper, component manifests, initial
database, exact ordered migration set, and their embedded release-build hashes.
The same release build refuses missing updater endpoint/public-key or protected
Tauri signing inputs and is configured to emit updater artifacts.

A release rehearsal must additionally verify the PHP extensions required by
Composer, a new installation, an upgrade using a safety backup, non-ASCII
Windows user paths, WebView2 availability, process cleanup, and installer code
signing. The generated installer is not a production artifact until those
checks pass. The required evidence and timestamped Authenticode boundary are in
[RELEASE-READINESS.md](RELEASE-READINESS.md); installation, backup, restore,
firewall, diagnostics, and data-retention procedures are in
[WINDOWS-OPERATIONS.md](WINDOWS-OPERATIONS.md).

## Explicitly not active yet

- Cloudflare token and named-tunnel provisioning UI (a trusted launcher path
  must pre-provision the validated settings and DPAPI-protected credential)
- start-at-login behavior
- production installer code signing, updater publication, and their clean-VM
  rehearsal with real release credentials
- a central secure-relay provider (the `remote_relay` license claim alone
  cannot make relay upload available)

Related settings/actions must stay disabled or absent until their runtime state
is genuinely supervised and verified.
