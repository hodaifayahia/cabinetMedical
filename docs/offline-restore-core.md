# MediSmart offline restore core

## Current safety boundary

Restore preparation and the native lifecycle adapter are implemented, while active-data replacement remains intentionally unavailable to ordinary web and Laravel apply paths. A Laravel request cannot prove that the Tauri-managed PHP server, queue workers, and document writers have stopped. Only the desktop lifecycle owner may start the hidden native apply command.

No controller or route invokes `OfflineRestoreExecutor`.

The supported administrator UI procedure and manual-recovery stop conditions
are documented in [the Windows operator runbook](WINDOWS-OPERATIONS.md#supervised-restore).

## Administrator HTTP preparation

`POST /app/configuration/backup/restore/prepare` is the only browser-facing native restore entry point. It is independent of the disabled legacy raw-SQLite restore route and requires an authenticated, verified Administrator or Super Administrator with both configuration permissions and a recent password confirmation.

The request is `multipart/form-data` with exactly an uploaded `backup` file and a `passphrase` (plus the normal `_token` CSRF field when applicable). The client filename must be a safe `.msbackup` basename. Server paths and caller-selected operation IDs are rejected. Upload size is bounded by `MEDISMART_BACKUP_RESTORE_UPLOAD_MAX_BYTES` and a non-configurable 25 GiB ceiling.

On success, the endpoint returns only the native authorization artifact and a normalized summary containing creation/application/schema versions, component names/counts/sizes, and aggregate counts/sizes. It returns no archive path, staging path, inventory, source filename, archive hash, or secret. Validation, authentication, decryption, installation mismatch, and archive failures all return the same French `422` JSON message. Successful and failed preparation responses are non-cacheable. Only successful preparation writes a non-secret audit summary.

The passphrase is removed from request and validator data and zeroed where Sodium is available. Preparation runs while the framework-managed upload temporary file is still alive, and no HTTP preparation path applies active data.

## Abandoned preparation retention

The supervised Laravel scheduler runs `medismart:restore:prune-preparations` daily at 03:30. `MEDISMART_PREPARED_RESTORE_RETENTION_HOURS` controls the age threshold, defaults to 168 hours, and is fail-closed outside the supported 24-to-8760-hour range.

Pruning acquires the same private filesystem lifecycle lock used by the native PHP apply command. It considers only a matched pair consisting of a direct, canonical lowercase UUID workspace and the same UUID journal. The journal must pass its hash-chain checks, its final event must still be `ready_for_offline_apply`, its plan hash must match, its workspace may contain only the exact plan and staged inventory, every staged checksum must still match, no link or multiply-linked file may exist, and every journal/workspace node must be older than the retention cutoff. Eligibility and filesystem identity are re-evaluated immediately before deletion.

Recent preparations, in-progress preparation/apply states, completed or incomplete rollback, manual-recovery and pending-restart states, malformed or mismatched pairs, orphans, links, unexpected nodes, and entries that change during evaluation are retained. The command records only aggregate counts and the retention window in the immutable audit log; it never records an operation UUID, path, filename, plan hash, archive hash, or secret. A busy lifecycle lock skips without mutation, while an invalid configuration/root or deletion failure returns a failure status for operator attention.

## CLI workflow

Prepare and authenticate an encrypted v2 backup:

```text
php artisan medismart:restore:prepare /absolute/path/backup.msbackup
```

The command requests the recovery passphrase through hidden terminal input. Never put the passphrase in a command argument, environment variable, shell history, log, or journal.

Inspect a prepared operation without mutation:

```text
php artisan medismart:restore:inspect <operation-uuid>
```

Inspection does not print archive, staging, rollback, or absolute filesystem paths. Exit statuses are:

- `0`: ready, safely rolled back, or otherwise not in an interrupted apply state.
- `1`: missing, incompatible, or malformed preparation data.
- `2`: native recovery attention is required. Laravel and all writers must remain stopped.

The public operator-facing apply command still fails without mutation:

```text
php artisan medismart:restore:apply <operation-uuid>
```

It stays disabled by design. The hidden `medismart:restore:native-apply` command requires both a native-only environment marker and a bounded capability delivered through the child process's standard input. It is not a controller, route, scheduled command, or normal shell workflow.

## Native ownership bridge

`src-tauri/runtime-core/src/offline_restore.rs` provides the desktop coordination contract and PHP child launcher. Its required order is:

1. The desktop process stops and joins the Laravel server, queue worker, and every other local writer.
2. It acquires an `ExclusiveRestoreProcessLease` whose checks continue to prove those writers are absent.
3. It starts a random loopback lease endpoint and sends its 256-bit secret to only the dedicated PHP child through piped standard input. The secret is never an argument, environment variable, result, journal entry, or log field.
4. The PHP guard authenticates the lease before apply, before each target swap, during rollback, and before final validation. The Rust endpoint also calls the native ownership check for every response.
5. PHP creates a unique v1 safety archive under the private pre-restore backup directory. The archive creator verifies it before publication, and `OfflineRestoreExecutor` independently verifies its SHA-256, manifest, and installation identity before the first rename.
6. PHP calls the existing executor and returns exactly one fixed French JSON status record. Rust requires the status, fixed message, and exit code to agree.
7. Refused or successfully rolled-back operations may resume the previous runtime. A successful apply may restart only through `start_restored_runtime_and_verify`. The concrete Tauri owner rebuilds fresh queue, scheduler, and Laravel supervisors and accepts the restart only after the new keyed detailed health response succeeds. Ambiguous output, lost ownership, a failed health check, timeout without clean rollback, or manual-recovery status keeps the runtime offline.

The native bridge never deletes a source preparation, safety archive, journal, staging workspace, or rollback target. The registered Tauri command accepts only the strict protocol/version/operation UUID/plan SHA-256 artifact emitted by preparation. It resolves managed paths itself, rechecks the plan, ready journal, inventory paths, sizes, hashes, unexpected files, and SQLite header before shutdown, then stops and joins tunnel, optional-LAN slot, scheduler, queue, and Laravel in order. It exposes no archive path, executable, arguments, shell, or generic filesystem primitive. The normal web apply command remains disabled.

## Preparation guarantees

Preparation:

1. Authenticates and decrypts the v2 XChaCha20-Poly1305 envelope with the user-held passphrase.
2. Runs the existing v1 archive/checksum verifier.
3. Requires the archive installation ID to match the active installation while inner Laravel-encrypted secrets remain source-`APP_KEY`-bound.
4. Extracts with streaming reads and never calls `ZipArchive::extractTo`.
5. Accepts only `database.sqlite3` and these four managed roots:
   - `storage/private/clinical-documents`
   - `storage/private/patient-documents`
   - `storage/private/medical-models`
   - `storage/public/cabinet`
6. Rechecks each extracted size and SHA-256 and persists a private staging inventory.
7. Runs full SQLite `integrity_check`, foreign-key validation, required-table checks, and manifest migration checks.
8. Requires an exact migration-set match with the running build. Forward migrations are denied until individual migrations have been audited for staging execution.
9. Removes the decrypted inner archive after verified staging.

Workspaces and plans use owner-only permissions on POSIX systems. A failed preparation removes its temporary workspace but retains its non-secret journal.

## Apply primitive

The internal executor is CLI-only and requires the authenticated native guard plus the concrete verified safety provider. Before its first rename it:

- verifies exclusive supervisor ownership;
- requires a fresh safety-backup callback and independently verifies that v1 archive, its SHA-256, and installation identity;
- rechecks the complete staged inventory;
- requires every staged/active pair to be on the same filesystem;
- disconnects and purges Laravel's database connection.

Each active target is renamed to a deterministic operation-specific rollback path before its staged replacement is renamed into place. On an ordinary exception or lost ownership, changed targets are rolled back in reverse order. Successful apply deliberately retains original targets and the safety archive until Tauri restarts the application, verifies health, and explicitly confirms cleanup.

## Recovery journal

The append-only JSONL journal is stored under `storage/app/private/restore-journals`. Records contain only operation state, target identifiers, counts, hashes, and reason codes. They do not contain passphrases, archive paths, OAuth/tunnel credentials, `APP_KEY`, or exception messages. Every append is flushed and `fsync`ed where supported.

An intent record is persisted before each rename. Target names and rollback filenames are deterministic, allowing a future Tauri recovery routine to reconcile a crash without guessing.

## Known crash-recovery limitation

Five filesystem targets cannot form one operating-system atomic transaction. In-process failures are rolled back deterministically, but power loss or process termination can occur between a rename and its following journal append.

The journal and retained rollback paths preserve recovery evidence, but automatic crash mutation is not enabled yet. If inspection returns exit status `2`:

1. Keep the Laravel server, queues, and all document writers stopped.
2. Do not delete staging, safety-backup, or `.rollback` data.
3. Let the future Tauri recovery routine inspect journal intent and actual filesystem state.
4. If native recovery is unavailable, preserve the whole application-data directory and escalate to supervised manual recovery.

Do not attempt recovery from a web request or restart the normal application against a partially swapped database.

If the database, private storage, and public storage are on different volumes, the current executor refuses apply because same-filesystem atomic rename guarantees are unavailable.
