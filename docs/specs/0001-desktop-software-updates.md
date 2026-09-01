# 0001 · Desktop software updates

**Status**: Assumed
**Date**: 2026-09-01
**Authorized by**: hodaifayhia, during /develop

## Owed decision

How a version published in the back office reaches desktop installations. Four
separate choices sit inside that: where the artifact lives, who signs a release,
what serves the update manifest, and how a running copy learns a new version
exists.

## Assumption built on

**Signing stays on the build machine.** The Tauri build already emits a detached
minisign signature next to the installer. The operator uploads the installer and
that signature together, and the server stores and serves the signature without
ever holding the private key. The shell verifies against the public key compiled
into itself, so a tampered artifact on the server produces a refused update
rather than a bad install. This also means a stolen server database gives an
attacker nothing they could sign with.

**One artifact serves both paths.** A published release becomes the file that the
public download page and the updater both read, so someone downloading for the
first time can never receive an older build than an existing install is being
offered.

**Polling, not push.** The project has no broadcasting service configured, and
adding one would be a further process to run and secure for a message that is
never urgent to the second. The shell checks shortly after launch and then every
fifteen minutes, which makes a publish reach everyone who is working.

**Publishing is a database row.** `desktop_releases` records version, channel,
platform, artifact, checksum, signature and who published it, so the back office
reports what is actually being served rather than that a button was pressed.

## Code area

- `database/migrations/2026_09_01_000000_create_desktop_releases_table.php`
- `app/Models/DesktopRelease.php`
- `app/Services/DesktopReleaseService.php`
- `app/Http/Controllers/DesktopUpdateManifestController.php`
- `app/Http/Controllers/DesktopUpdateArtifactController.php`
- `app/Services/DesktopDownloadService.php`
- `app/Filament/Pages/SoftwareVersion.php`
- `resources/js/lib/desktopUpdateWatcher.ts`
- `resources/js/components/DesktopUpdateBanner.vue`
- `routes/web.php`

## Requirements

- Publishing a version in the back office makes it the version every installed
  copy is offered.
- A copy that is open when a version is published is offered it without the
  person going looking for it.
- A first time download receives the newest published build.
- An unpublished release is neither offered nor downloadable.
- The served bytes are the bytes that were checksummed at publish time.
- An artifact whose signature does not verify is never installed.

## Known gaps

- The manifest serves one platform and one channel. Another operating system, or
  a beta channel, is another row and a wider query, not a redesign.
- Nothing rolls a bad release back yet. Publishing an earlier version again is
  the current answer, and the shell will refuse it because the version is not
  newer, so a real rollback needs its own decision.
- The install still happens from the settings page, on purpose. Nothing restarts
  the app under a clinic mid consultation.

## Ratify

These decisions were recorded by /develop, not deliberated. Run
`/architect desktop software updates` to deliberate and ratify them. Until then
they stay flagged as owed. This does not block marking the feature done.
