# Building a Drclick desktop release

Current process for the local-first installer described in
[ADR-004](architecture/ADR-004-local-first-desktop-restored.md). It replaces
[`DESKTOP-RELEASE-STAGING.md`](DESKTOP-RELEASE-STAGING.md), which documents the
retired thin-client and tunnel model.

## 1. Stage the payload

```
npm run desktop:payload:stage -- --php-runtime <reviewed-php-dir> --force
```

Produces `src-tauri/resources/{php,laravel,initial}` — about 155 MB. The
directory is git-ignored: it is a build-machine artifact, rebuilt per release.
[`src-tauri/resources/README.md`](../src-tauri/resources/README.md) describes
what each part must and must not contain, and the script asserts most of it.

Release builds fail without it. `src-tauri/build.rs` checks five paths and
refuses to emit an installer that looks fine but can only work online — the
regression that shipped a 7 MB shell.

## 2. Supply the release environment

Four variables, all required, none committed:

| Variable | Meaning |
| --- | --- |
| `TAURI_SIGNING_PRIVATE_KEY` | Contents of the minisign private key used to sign update artifacts |
| `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` | Its password. **Must be non-empty** — see below |
| `MEDISMART_UPDATER_PUBLIC_KEY` | The matching public key, compiled into the binary |
| `MEDISMART_UPDATER_ENDPOINT` | HTTPS update feed, e.g. `https://…/desktop-updates/` |

`build.rs` validates all four before compiling and fails the build with a clear
message if any is missing or malformed.

### The signing key must have a password

`tauri signer generate` will happily produce a passwordless key, and Tauri will
sign with one. Drclick refuses to: an unprotected key that leaks is immediately
usable to forge an update for every clinic. Generate with a password and pass it
in `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`; a set-but-empty value is rejected and
says so.

### The public key lives in two places and they must agree

`MEDISMART_UPDATER_PUBLIC_KEY` is compiled into the binary by `build.rs` and
used by the signed-updater commands in `src/updates.rs`. Separately,
`plugins.updater.pubkey` in
[`src-tauri/tauri.local.conf.json`](../src-tauri/tauri.local.conf.json) is what
`tauri-plugin-updater` checks at runtime. That file currently pins the
production key `90B4A98A4210CDC8`.

Signing with a key that does not match the pinned one produces an installer
that builds and bundles cleanly but whose updates are rejected at runtime.
Tauri prints a warning when it notices — treat it as an error:

```
Warn The updater secret key from `TAURI_SIGNING_PRIVATE_KEY` does not match
     the public key from `plugins > updater > pubkey`.
```

### The signing key is not interchangeable

Every installed client verifies updates against the public key that was
compiled into *it*. The currently shipped 0.1.1 clients carry
`minisign public key: 90B4A98A4210CDC8`.

**Signing a release with a different key orphans every existing installation
from the update channel.** Those machines will reject the update silently and
stay on their current version until someone reinstalls by hand.

So before building a release, establish which case applies:

- **The original private key is available.** Use it. Existing installs continue
  to update normally.
- **It is lost.** A new keypair is unavoidable, but the rollout is then a
  manual reinstall for every clinic, not an update. Plan that distribution
  before building, not after.

## 3. Build

```
npm run desktop:build        # or: tauri build --config src-tauri/tauri.local.conf.json
```

Only NSIS is produced. The MSI target was removed from `bundle.targets`:
WiX's `light.exe` fails on the ~16,900-file payload, and leaving `msi` in the
list makes every release build fail at the bundling step. Re-adding it requires
solving that first.

Expect roughly a 37 MB installer. The thin client was 4 MB; the difference is
the application itself.

## 4. Versioning

Bump **both** `src-tauri/Cargo.toml` and `src-tauri/tauri.conf.json`. The
runtime reports `CARGO_PKG_VERSION` to Laravel through `MEDISMART_VERSION`, and
the updater compares the `tauri.conf.json` value, so a mismatch produces an
installer that misreports itself.

A version must never be reused. 0.1.1 shipped as the thin client; the
local-first build is 0.2.0 precisely so the two cannot be confused.

## 5. Before distributing

This release changes where clinical data lives, so it is not a routine update:

- A **fresh** install becomes its own database and works offline.
- An **upgraded** install keeps the origin it already used — `runtime_mode`
  reads the legacy `server.json` so nobody is silently moved onto an empty
  local database.

Rehearse on a clean VM: install, confirm the application opens without a
network, create a patient, restart, and confirm the record survives. Then
confirm an upgrade over an existing 0.1.1 install still reaches the hosted
service.
