# Drclick release-readiness contract

Status: current repository evidence as of 2026-08-05. This document is the
release decision record; older audit checklists remain historical evidence, not
proof that the current installer is ready.

## Current decision

The repository is **not yet a releasable Windows artifact**. The deterministic
quality workflows, fail-closed resource stager, migration contract, synthetic
fixture, and final readiness checker are implemented. A real installer still
requires approved external runtimes, production deployment values, a trusted
code-signing identity, and a clean-VM rehearsal. None of those values or
binaries is committed, downloaded by the staging script, or replaced with a CI
secret.

| Area                    | Current evidence                                                                                                                                                                                                                    | Release meaning                                                                                                                                                                                        |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| PHP/frontend quality    | `.github/workflows/tests.yml` uses PHP 8.3, Composer 2.8.9, Node 22.15.1, `composer.lock`, and `package-lock.json`; every requested gate is an explicit step                                                                        | Implemented in the workflow; a green GitHub run is still required for the release commit                                                                                                               |
| Windows native runtime  | `.github/workflows/desktop-runtime-windows.yml` runs Rust format, strict Clippy, runtime-core tests, Tauri boundary tests, resource negative tests, and the safe fixture                                                            | Implemented and platform-specific; it does not create or sign an installer                                                                                                                             |
| Browser workflow        | `.github/workflows/browser-smoke-windows.yml` runs the Laravel browser smoke on Windows                                                                                                                                             | Implemented; Playwright downloads only its package-pinned Chromium test browser                                                                                                                        |
| Release resources       | `scripts/desktop/release-resources.mjs` and the staging CLI require approved hashes, exact inventories, fresh clinic-free SQLite, production Composer/Vite output, and binary probes                                                | Implemented; real PHP, Composer PHAR, and cloudflared inputs are externally blocked                                                                                                                    |
| Migration trust         | `initial/migration-contract.json` binds app version, initial database hash, every ordered migration, the canonical set hash, and the fixed native helper; the packaged launcher verifies and consumes it before starting any writer | Native forward migration, safety snapshot, authenticated crash journal, post-validation, and rollback/recovery coordination are implemented; the exact signed upgrade still requires clean-VM evidence |
| CI resource fixture     | `npm run desktop:resources:fixture` creates inert `MZ` markers and a synthetic SQLite header and performs static validation without executing them                                                                                  | Fixture-only evidence; it proves validator composition, not binary provenance or runtime behavior                                                                                                      |
| Installer targets       | Tauri declares MSI and NSIS targets and embeds the WebView2 bootstrapper                                                                                                                                                            | Configuration only; supported installer type and behavior require clean-VM evidence                                                                                                                    |
| Tray lifecycle          | Native tray open/restore, close-to-tray, explicit quit, and ordered runtime shutdown are implemented                                                                                                                                | Code-level behavior is present; Windows notification-area behavior and process cleanup still require the exact-installer rehearsal                                                                     |
| Signed updater          | Release builds require an embedded HTTPS manifest endpoint, updater public key, protected Tauri signing inputs, and generated updater artifacts; the final checker binds the artifact, `.sig`, and static manifest                  | Implemented fail-closed in code; real keys, publication, and a signed-update rehearsal remain external blockers                                                                                        |
| Signing and publication | No signing certificate, timestamp credential, updater key, or publishing credential is in the repository                                                                                                                            | Correctly blocked outside the repository                                                                                                                                                               |
| Clean Windows VM        | The evidence schema and checker are implemented                                                                                                                                                                                     | No real evidence is committed; release remains blocked                                                                                                                                                 |

## Deterministic CI gates

The Linux quality job fails on:

1. strict Composer validation, locked install, and `composer audit --locked`;
2. locked `npm ci`;
3. release-resource negative tests and the non-executable synthetic fixture;
4. ESLint, the repository's scoped Prettier check, TypeScript, and Vitest;
5. the production Vite build; and
6. Pint, PHPStan, and the Laravel test suite.

The Windows runtime job repeats the resource trust tests before compiling or
testing Rust. `actions/setup-node` keys its dependency cache from
`package-lock.json`. Actions are referenced by immutable commit SHA. There is no
cache or artifact containing `.env`, a database, a backup, a clinic logo,
OAuth material, a tunnel token, signing material, or a license serial.

Composer audit and dependency installation contact their configured package
registries. The browser workflow's Playwright install is a test dependency
download governed by `package-lock.json`; it is not copied into release
resources. The release stager itself never downloads or discovers an arbitrary
runtime from `PATH`.

## External release blockers

The release owner must supply or provision all of these outside the worktree:

- an approved official Windows x64 PHP 8.3+ directory and reviewed manifest;
- a hash-approved Composer PHAR;
- a hash-approved official `cloudflared.exe`;
- the production Google installed-app client ID, when Drive is offered;
- HTTPS license activation/status/deactivation endpoints and the public
  verification key, when licensing is offered;
- a named Cloudflare tunnel ID, hostname, and DPAPI-protected connector token,
  when remote upload is offered;
- a trusted Windows code-signing certificate/private key and RFC 3161 timestamp
  service;
- a protected Tauri updater signing key/password, its approved public key, and a
  static HTTPS publication origin for `latest.json`, updater bundles, and
  signatures; and
- a clean Windows VM with the chosen supported Windows/WebView2 baseline.

The release owner must never put a certificate or updater private key, tunnel
token, OAuth refresh/access token, backup phrase, license serial, `APP_KEY`,
clinic database, or patient document in a workflow file, command line, release
resource directory, diagnostic bundle, or clean-VM evidence JSON.

## Installer and signing boundary

Build only from a clean Windows x64 checkout after staging real resources. Sign
the final MSI or NSIS installer through the organization's signing service,
then test that exact signed byte sequence. Signing an executable while leaving
its installer unsigned, accepting an untrusted/self-signed status, omitting a
trusted timestamp, or testing a differently hashed installer does not satisfy
this gate.

The private key is release-infrastructure state, never an application setting.
The repository contains no signing command because certificate storage,
hardware/service authentication, publisher subject, digest policy, and
timestamp URL are deployment-owned controls.

The controlled build environment must also set `MEDISMART_UPDATER_ENDPOINT`,
`MEDISMART_UPDATER_PUBLIC_KEY`, `TAURI_SIGNING_PRIVATE_KEY`, and
`TAURI_SIGNING_PRIVATE_KEY_PASSWORD`. The first two are public release inputs
embedded in the native binary. The latter two are protected signing inputs and
must never be written to `.env`, a workflow file, application resources, logs,
diagnostics, or the installed machine state. A release build intentionally
fails before packaging when any of them is absent.

The static `latest.json` selected target must contain the exact approved
HTTPS `.zip` artifact URL and exact generated `.sig` text. The final checker
binds the installer, resource manifest, updater artifact, manifest, and
signature hashes to schema-v2 clean-VM evidence. This metadata gate does not
possess the updater private key and does not replace the installed client's
cryptographic signature verification.

The supported manual install path is also runtime-bound: after a pending update
is found, Laravel requires recent-password confirmation, creates and verifies a
current `.msbackup`, and signs a five-minute authorization for that target
version, backup record/hash, launcher installation UUID, and nonce. The native
updater rejects any mismatch before download. Automatic download is not
implemented; automatic checks require the signed `automatic_updates`
entitlement, while manual checks/install remain available in a correctly
configured release.

## Required clean-VM rehearsal

Use a VM snapshot with no previous Drclick data. Test the exact signed
installer hash and record at least:

- installer success and expected publisher display;
- one-time owner setup and clinic/document configuration;
- restart without internet, without a system PHP, Node, Composer, or
  cloudflared on `PATH`;
- non-ASCII Windows username/path behavior;
- notification-area close/reopen, explicit tray quit, and process cleanup
  after normal exit and forced reboot;
- private-network LAN selection, QR creation, upload quarantine/review, and the
  observed Windows Firewall prompt/rule behavior;
- encrypted local `.msbackup` creation and a complete supervised restore drill;
- upgrade from the previous supported signed version, including the
  pre-process startup-migration safety snapshot and post-validation path plus a
  controlled rollback/recovery exercise, or an explicit first-release
  not-applicable result;
- update from the previous supported version through the published HTTPS
  manifest, proving that the embedded key accepts the exact signed updater
  artifact and that the mandatory pre-update backup succeeds, or the same
  explicit first-release not-applicable result;
- uninstall/reinstall behavior for AppLocalData and the approved data-retention
  policy; and
- startup/log behavior with WebView2 unavailable, damaged resources, a port
  collision, and an intentionally unavailable optional service.

Drive, license, or named-tunnel production acceptance must be done in a
separate controlled environment with dedicated test accounts. Never place live
tokens in the generic VM evidence.

## Hash-bound evidence

After the rehearsal, create a minimal JSON record with no free-text notes or
secrets. All hashes are lowercase SHA-256:

```json
{
    "schema_version": 2,
    "application_version": "0.1.0",
    "installer_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "resource_manifest_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
    "updater_artifact_sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
    "updater_manifest_sha256": "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd",
    "updater_signature_sha256": "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee",
    "tested_at": "2026-08-05T12:00:00.000Z",
    "windows": "Windows 11 24H2 clean VM",
    "checks": {
        "install": "pass",
        "first_launch": "pass",
        "offline_restart": "pass",
        "local_backup_restore": "pass",
        "signed_update": "not-applicable-first-release",
        "upgrade": "not-applicable-first-release",
        "uninstall_data_policy": "pass"
    }
}
```

For an upgrade release, both `upgrade` and `signed_update` must be `pass`. Their
first-release results must match. Evidence older than 30 days, from a different
version, installer, resource manifest, updater artifact, updater manifest, or
signature, with an unknown field, or with any non-passing required check is
rejected.

On the controlled Windows release host, run:

```powershell
npm run desktop:release:readiness -- `
  --resources C:\release\resources `
  --expected-version 0.1.0 `
  --installer C:\release\Drclick-signed.exe `
  --updater-manifest C:\release\latest.json `
  --updater-artifact C:\release\Drclick_0.1.0_x64-setup.nsis.zip `
  --updater-signature C:\release\Drclick_0.1.0_x64-setup.nsis.zip.sig `
  --updater-target windows-x86_64-nsis `
  --updater-artifact-url https://updates.example.dz/Drclick_0.1.0_x64-setup.nsis.zip `
  --clean-vm-evidence C:\release\clean-vm-evidence.json
```

The checker is read-only. It validates the exact resource inventories with real
binary probes, checks the signed installer with Windows Authenticode, requires
a signer and trusted timestamp, validates that the approved static HTTPS URL
and exact generated `.sig` value are in the selected Tauri manifest target,
and binds clean-VM evidence to every inspected hash. The installed Tauri client,
not this metadata checker, performs the cryptographic updater signature check
during the signed-update rehearsal. The checker does not build, sign, upload,
or publish. A passing check is necessary but does not authorize publication;
the release owner still applies the organization's approval and distribution
controls.

## Related records

- [Desktop release-resource staging](DESKTOP-RELEASE-STAGING.md)
- [Windows operator runbook](WINDOWS-OPERATIONS.md)
- [Desktop runtime boundary](DESKTOP-RUNTIME.md)
- [Configuration data contract](architecture/CONFIGURATION-DATA.md)
- [Historical security checklist](SECURITY-CHECKLIST.md)
- [Historical Phase 1 audit](PHASE-1-AUDIT.md)
