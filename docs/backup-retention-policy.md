# MediSmart local backup retention

## Ownership and fail-closed boundary

Retention owns only a completed `BackupRecord` whose `disk` is `local` and whose regular, single-link `.msbackup` file is a direct child of the canonical directory configured by `medismart.backups.managed_directory`. The configured directory and every path component must exist without symbolic links. A filename, database row, or old checksum on its own never grants deletion authority.

Each inventory pass reads all backup rows and recursively inventories the managed directory without following links. A file becomes planner input only after all of these checks succeed together:

- the row is completed and has a completion timestamp;
- `local_path` is byte-for-byte the canonical root plus the row filename;
- exactly one row owns the physical file;
- the file is regular, is not a symlink or hard-linked alias, and has not changed while locked and hashed;
- size and full SHA-256 match the row;
- a v1 ZIP passes the complete `MsBackupArchiveVerifier`, including manifest, entry, payload, and checksum verification; or
- a v2 encrypted archive has a valid envelope and exact length-prefixed frame layout, and its complete outer size and SHA-256 match the completed row.

This includes the verified v1 archives produced by the current scheduled-backup workflow and the completed portable v2 archives produced by the encrypted workflow. No passphrase is retained or requested by retention; v2 eligibility relies on its freshly hashed, previously completed outer artifact plus strict envelope/framing validation.

Anything ambiguous is protected, never guessed. This includes incomplete or non-local rows, missing files, row/file mismatches, unsupported or malformed archives, duplicate row ownership, files outside the exact root, symlinks, hard links, unowned files, legacy SQLite snapshots, prior retention tombstones, and files in `pre-restore-safety`. Pre-restore files and names beginning with `MediSmart-Pre-Restore-Safety-` are always protected. External row paths are neither opened nor counted as managed files.

Diagnostics expose a backup-record ID when applicable, a one-way relative file reference, a reason code, and byte count. They never expose a filesystem path. Protected physical bytes are counted once by device/inode and participate in the storage calculation.

## Deterministic planner

`BackupRetentionPlanner` remains a pure decision component. It does no filesystem, database, provider, or deletion work. Its untrusted input contract requires a safe managed ID/name, size, RFC 3339 creation time, full matching SHA-256 verification evidence, UUID record ID, `msbackup` format, and format version 1 or 2. Malformed and conflicting inputs are protected.

Retention counts select distinct UTC buckets:

- daily: UTC calendar date;
- weekly: ISO-8601 UTC week beginning Monday;
- monthly: UTC calendar month.

The keep set is the union of the enabled tiers. The newest verified logical backup is always kept, even when all tier counts are zero. Equal timestamps use the validated managed ID as a stable tie-breaker. Exact duplicate inputs collapse; ambiguous reuse of an identifier is protected.

An optional maximum-storage byte limit is applied after bucket selection. Current storage is verified eligible bytes plus protected physical bytes. If the projected keep set exceeds the limit, retained files are changed to candidates from oldest to newest. The newest backup is never selected. Protected files are never selected. Therefore `maximum_storage_satisfied` can remain false when protected storage plus the newest verified backup alone exceed the limit; this is an explicit safe outcome, not permission to widen cleanup.

Every plan includes normalized keep/candidate decisions, non-sensitive protected results, current/projected/protected byte totals, a deterministic `plan_sha256`, and explicit markers that planning is non-destructive and non-authorizing.

## Preview, confirmation, and apply

The internal service and hidden command are dry-run by default:

```text
php artisan medismart:backup:retention
php artisan medismart:backup:retention --dry-run
```

After a scheduled archive has been created and verified, the supervised backup command performs this same preview → short-lived token → apply sequence in-process. A retention failure remains fail-closed, records a warning event, and never changes the successful status of the new backup. The hidden command remains available for diagnostics and recovery; an operator can request a short-lived confirmation token explicitly:

```text
php artisan medismart:backup:retention --issue-confirmation
```

The HMAC token expires after five minutes and is bound to the exact plan SHA-256, complete database/filesystem inventory SHA-256, and canonical managed-root SHA-256. It contains no path. Apply requires all three independent signals: `--apply`, `--internal-confirm`, and the exact token:

```text
php artisan medismart:backup:retention --apply --internal-confirm --confirm=<token>
```

Apply obtains a process lock, creates an entirely fresh inventory and plan, and validates the token against them. Before each selected file it rebuilds and replans the complete remaining inventory, requires the originally authorized artifact to remain a candidate with the same normalized metadata fingerprint, then requeries the row and reruns path/format/size/SHA verification. Missing, expired, stale, malformed, changed, or conflicting state stops the operation before that candidate is touched.

## Filesystem/database transition

For an authorized candidate, apply conditionally transitions its row from `completed` to `retention_pending_delete`, atomically renames the exact file to a hidden direct-child `.pending.msbackup` tombstone, records that tombstone path, re-verifies the complete isolated artifact, unlinks it, and finally changes the row to `retention_deleted` with `local_path = null`. Size, SHA-256, timestamps, and the row itself remain as history. Successful deletion records both an audit log and an application event without a path.

Failures before unlink attempt to restore both the original filename and completed row. A crash can leave a pending tombstone, a pending row with a missing file, or another inconsistent protected entry. Later previews protect these states and fail closed; they are not automatically deleted or reconciled. Any recovery requires diagnosis and a new complete inventory plus confirmation. This avoids silently converting an interrupted filesystem/database transition into deletion authority.

Verification tests create their database, managed root, archives, safety copies, malformed files, links, and tombstones only beneath unique operating-system temporary directories. The lifecycle suite never points the service or command at the workspace backup directory and never applies retention to existing application data.
