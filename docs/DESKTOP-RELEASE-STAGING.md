# Desktop release-resource staging (historical)

> **Superseded:** the current Tauri installer is a thin HTTPS client and does
> not consume the PHP/SQLite resource bundle described below. This document is
> retained for audit history only. Current shared-offline delivery is governed
> by [`ADR-002`](architecture/ADR-002-cabinet-hub-offline-lan.md) and requires a
> separately installed, acceptance-tested Cabinet Hub.

The Windows installer must be built only after the release-resource staging
gate succeeds. The gate creates a complete resource tree in a sibling temporary
directory, validates every staged byte, and publishes it to
`src-tauri/resources` only at the end. An existing resource tree is never
replaced unless `--replace` is present, and a failed build leaves it untouched.

The scripts do not download PHP or cloudflared. Supply approved files obtained
through the organization's controlled release process. Composer is also a
supplied, hash-pinned PHAR; its production install may access the configured
Composer package repository to fetch the exact packages in `composer.lock`.

## Controlled build prerequisites

Use a clean Windows x64 checkout with Node.js installed. Run `npm ci` against
the committed lock file before staging. The checkout must not contain any
dotenv input other than `.env.example`, and `public/hot` must not exist. This
prevents Vite from silently embedding workstation-specific `VITE_*` values.
The staging process also strips inherited `VITE_*` environment variables.

Supply all of the following from outside `src-tauri/resources`:

- an official PHP 8.3-or-newer Windows x64 runtime directory;
- a separately reviewed PHP runtime manifest and its approved SHA-256;
- a Composer PHAR and its approved SHA-256;
- an official Windows `cloudflared.exe` and its approved SHA-256.

Separately, the protected release-build environment must provide
`MEDISMART_UPDATER_ENDPOINT`, `MEDISMART_UPDATER_PUBLIC_KEY`,
`TAURI_SIGNING_PRIVATE_KEY`, and `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`.
These are not staged resource-tree inputs: the public endpoint/key are embedded
in the native binary, while the private key/password are consumed only to sign
the generated updater artifact. A release build fails before packaging when
any value is missing or invalid.

Do not put an active clinic database, a backup, a logo, an upload, an OAuth
credential, a tunnel token, an application key, or signing material anywhere
in those inputs. The staging code never opens or copies
`database/database.sqlite`, its WAL/SHM files, or anything under application
storage or backup locations.

## Review the supplied PHP runtime

The review helper inventories every file and directory, hashes every file, and
probes the runtime. It accepts only a Windows 64-bit CLI build in the PHP 8.x
series at version 8.3 or newer. Loaded INI files must remain inside the supplied
runtime directory. The required extension baseline includes SQLite/PDO,
OpenSSL, cURL, mbstring, intl, XML/DOM, GD, ZIP, Sodium, fileinfo, and the PHP
core extensions needed by Laravel and the Drclick workflows.

Create a review candidate outside the runtime directory:

```powershell
npm run desktop:resources:review-php -- `
  --php-runtime C:\controlled\php-8.3 `
  --output C:\reviewed\php-runtime.review.json
```

Review the runtime's provenance, candidate manifest, exact loaded-extension
set, and file hashes through the release approval process. Record the SHA-256
printed for the manifest. Staging checks that independent digest before it
trusts the review, compares the supplied directory with the exact reviewed
inventory, probes it, copies it, and probes the staged copy again.

Generating a candidate manifest is not proof that PHP came from an official
source. Provenance approval remains a separate human/release-system control.

## Stage a complete release

From the clean checkout on Windows:

```powershell
npm run desktop:resources:stage -- `
  --php-runtime C:\controlled\php-8.3 `
  --php-review C:\reviewed\php-runtime.review.json `
  --php-review-sha256 <approved-manifest-sha256> `
  --composer-phar C:\reviewed\composer.phar `
  --composer-sha256 <approved-composer-sha256> `
  --cloudflared C:\reviewed\cloudflared.exe `
  --cloudflared-sha256 <approved-cloudflared-sha256> `
  --replace
```

Omit `--replace` when the destination does not exist. The default source is the
repository root and the default destination is `src-tauri/resources`; use
`--source` and `--output` only for controlled alternate locations.

The stage performs these gates:

1. Validate every input path without following symlinks/reparse points and
   reject case-colliding, special, or non-portable Windows paths.
2. Check the independent PHP-review, Composer, and cloudflared SHA-256 values
   before executing any supplied file. Both executables must have a Windows PE
   header. The exact cloudflared version is parsed from a bounded `--version`
   call and written to the strict manifest expected by the Rust build gate.
3. Copy only the Laravel production allowlist, install the locked Composer set
   with `--no-dev --no-scripts --no-plugins --classmap-authoritative`, run
   Composer's platform check, and reject any installed dev package or dev
   binary.
4. Run Wayfinder explicitly with the reviewed staged PHP, isolated storage,
   and a throwaway empty SQLite file. Its generated routes/actions must exactly
   match the committed frontend trees (line endings aside). The Vite build then
   removes exactly the Wayfinder plugin instance, preventing its default shell
   command from resolving system PHP or the source clinic database.
5. Run that isolated production Vite build with dotenv loading disabled,
   validate `public/build/manifest.json`, and reject source maps, `hot`, or
   missing referenced assets.
6. Create a brand-new zero-byte SQLite file inside the temporary stage, run
   production migrations and seeders, inspect all table counts, and reject
   WAL/SHM/journal sidecars. The source database is never used.
7. Emit a strict migration contract that binds the canonical application
   version, fresh database hash, fixed native helper hash, and every staged
   migration path/hash in exact lexical order.
8. Hash the exact Laravel, PHP, initial-database, cloudflared, and whole-resource
   inventories. Validate the completed temporary tree, including binary probes,
   SQLite inspection, and migration cross-checks, before publishing it.

## Laravel allowlist

The application resource contains:

- `artisan`, `composer.json`, and `composer.lock`;
- PHP files under `app`, `config`, `routes`, migrations, and seeders;
- `bootstrap/app.php`, `bootstrap/providers.php`, and an empty
  `bootstrap/cache` directory;
- JSON reference datasets under `database/data`;
- Blade/PHP views under `resources/views`;
- the validated Vite build plus the current public entry point, icons, and
  published Filament CSS, JavaScript, and fonts;
- the Composer `--no-dev` vendor tree.

This includes the current `config/filesystems.php`, `public/index.php`, all
current migrations/routes/config, the queue worker and scheduler framework
classes, the native restore bridge, and the fixed
`app/Console/Commands/NativeMigrationGate.php` inspection/snapshot helper
required by the native release contracts.

The allowlist excludes `.env*`, source databases and SQLite sidecars, storage,
logs, caches, uploads, backups, recovery work, `node_modules`, application
tests, source maps, merge leftovers (`.orig`/`.rej`), and common credential or
private-key files. A global exact-inventory manifest prevents an unexpected
file from being added after staging.

## Initial configuration and seed data

The packaged SQLite database is a schema/reference template, not a clinic
snapshot. Production mode and `MEDISMART_SEED_DEMO_USER=false` are forced.
Only these categories may be non-empty after seeding:

- roles, permissions, and their role/permission mapping;
- medication and examination reference catalogues;
- generic bilan, consultation-fee, act, and payment-method defaults;
- the generic cabinet-settings singleton used by first-run configuration.

Every other migrated table must be empty, including users, doctor profiles,
patients, appointments, encounters, clinical documents, prescriptions,
payments/accounting state, sessions, passkeys, jobs, uploads, backups, cloud
connections, tunnel settings, licenses/devices, OAuth attempts, and audit/event
records. Required reference tables must be non-empty, migration count must
match the staged migrations, SQLite integrity must be `ok`, and journal mode
must be `DELETE`.

Clinic identity, practitioner identity, phone/e-mail/address, document logo and
footer, license data, OAuth credentials, and all other installation-specific
configuration belong to the first-run/configuration workflow. They must never
be copied from the build workstation into this template.

## Migration trust contract

`initial/migration-contract.json` uses schema v1 and contains exactly:

```json
{
    "schema_version": 1,
    "application_version": "0.1.0",
    "initial_database_sha256": "<64 lowercase hexadecimal characters>",
    "migration_helper": {
        "path": "app/Console/Commands/NativeMigrationGate.php",
        "sha256": "<64 lowercase hexadecimal characters>"
    },
    "migrations": [
        {
            "path": "database/migrations/0001_01_01_000002_create_jobs_table.php",
            "sha256": "<64 lowercase hexadecimal characters>"
        }
    ],
    "migration_set_sha256": "<64 lowercase hexadecimal characters>"
}
```

The application version comes from `src-tauri/tauri.conf.json` and must be
canonical SemVer. Every migration must have the normalized lowercase portable
Laravel migration shape and be in strict lexical order. The list must exactly
match the Laravel release manifest by path, case, hash, and count, and its count
must match the initial database manifest. The helper path is fixed and its hash
must match that same Laravel inventory. Missing, extra, reordered, case-colliding,
linked, or tampered input fails validation.

The set digest is SHA-256 over the ordered UTF-8 records. Each record is the
migration path, one NUL byte, its 64-character lowercase SHA-256 text, and one
line-feed byte. This removes JSON whitespace/property-order ambiguity from the
runtime comparison.

The contract is consumed by the implemented native startup-migration gate
before Laravel, the queue worker, the scheduler, LAN, or the tunnel can start.
At runtime the launcher revalidates the exact packaged inventories and helper,
inspects the existing clinic database, creates and hashes a consistent safety
snapshot when migrations are pending, records an installation-bound
authenticated recovery journal, runs only the exact forward set, and requires
post-migration SQLite validation. A failure restores the verified snapshot
when possible; ambiguous recovery keeps all writers offline.

This implementation does not make an installer with changed migrations
release-ready by itself. The exact signed build still needs the clean-Windows
upgrade, interrupted-recovery, and rollback rehearsal required by
[the release-readiness contract](RELEASE-READINESS.md).

## Validate and test

Static validation hashes the exact staged inventory and does not execute an
external binary, so it is safe to run on CI hosts that are not Windows:

```powershell
npm run desktop:resources:validate
npm run desktop:resources:test
npm run desktop:resources:fixture
```

The fixture creates only inert marker bytes and a synthetic SQLite header. It
never executes a fixture binary and contains no clinic data, token, key, or
downloaded artifact. It proves that all component manifests compose under the
static validator; it is not evidence that a real PHP/cloudflared distribution
is trusted or executable.

On the controlled Windows host, repeat the PHP probe and inspect the SQLite
contents as well:

```powershell
npm run desktop:resources:validate -- --probe-binaries
```

The test suite proves refusal of symlink escape, unexpected files, hash
mismatch, missing PHP extensions, seeded user/clinical rows, implicit
replacement, reordered/missing migration entries, helper tampering, and
clean-VM evidence bound to another installer or containing a failed check. It
also validates the complete safe fixture. Run static validation immediately
before `npm run desktop:build`.

## Build and bind the signed updater outputs

`src-tauri/tauri.conf.json` enables `createUpdaterArtifacts`. Run
`npm run desktop:build` only with the protected updater variables described
above and the final validated resource tree. Preserve the generated Windows
`.zip` updater artifact and its exact `.sig` beside the selected signed MSI
or NSIS installer; do not copy either artifact into
`src-tauri/resources`.

Prepare a static HTTPS `latest.json` whose release version matches the Tauri
application version and whose selected
`windows-(x86_64|aarch64)-(nsis|msi)` target contains exactly the approved
artifact URL and the generated signature text. The final checker rejects
credentials, query strings, fragments, non-default ports, a non-`.zip` URL,
signature drift, unknown manifest fields, target/version mismatch, and
clean-VM evidence bound to different updater bytes.

The metadata checker intentionally does not possess the updater private key and
does not cryptographically verify the signature. The installed Tauri updater
does that with its embedded public key during the required signed-update
rehearsal. Do not publish the manifest or artifact until that exact hash-bound
rehearsal and the organization's release approval have passed.

## Output contract

The completed tree contains exactly `README.md`, `laravel/`, `php/`,
`cloudflared/`, `initial/`, and `release-resources.manifest.json`. Nested
manifests make each component independently auditable; the root manifest binds
all files and directories together. `initial/storage/` is intentionally empty.
All database, logs, caches, uploads, documents, backups, and secrets are created
later under the launcher's per-install writable directories.

Tauri currently bundles the four component directories, including their nested
manifests and `initial/migration-contract.json`. The root README and
`release-resources.manifest.json` are staging/build-time evidence and are not
currently mapped into the installed resources. The final release-readiness
checker therefore binds its clean-VM evidence to the root-manifest hash before
packaging and separately requires the signed installer hash. Do not claim that
an installed client can reconstruct or validate the omitted root manifest.

After building and signing, follow [the release-readiness
contract](RELEASE-READINESS.md); static staging success alone is not approval
to distribute an installer.
