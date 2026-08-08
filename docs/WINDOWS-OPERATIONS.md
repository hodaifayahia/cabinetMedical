# Drclick Windows operator runbook

Status: operational behavior implemented in the repository as of 2026-08-05,
with unverified release boundaries called out explicitly. This runbook does not
make the current unsigned development build a production installer.

## Deployment boundary

Deploy only an installer that passed [the release-readiness
contract](RELEASE-READINESS.md). Drclick declares MSI and NSIS outputs, but a
clean-VM release decision has not yet selected or certified either format.
Verify the expected publisher in Windows before installation; do not teach
users to bypass SmartScreen or signature warnings.

Production deployment values are placeholders until the release owner supplies
them through the approved launcher/build process:

| Capability   | Deployment-owned input                                                                                         | Current behavior when absent                                                                                                |
| ------------ | -------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Core desktop | Approved PHP/Composer/cloudflared release inputs and exact hashes                                              | Real installer build is blocked; CI uses no executable substitute                                                           |
| Google Drive | Installed-app `GOOGLE_CLIENT_ID`; Drive API enabled; normally no client secret and no fixed redirect           | UI reports Drive unavailable; local backup remains independent                                                              |
| Licensing    | HTTPS activation/status/deactivation URLs and a public verification-key path                                   | Activation controls report provider unavailable; no private license key belongs on a client                                 |
| Named tunnel | Strict non-secret tunnel JSON plus a launcher-provisioned, DPAPI-protected connector token                     | Tunnel remains stopped; LAN/local operation remains independent                                                             |
| Signing      | Trusted certificate/private key and timestamp service held by release infrastructure                           | Installer is not a production artifact                                                                                      |
| Updates      | Signed updater endpoint/public key, protected signing inputs, static HTTPS publication, and rollback rehearsal | Native updater reports unavailable when unconfigured; local use continues and only an approved signed installer may be used |

The packaged app must not contain `.env`, a shared `APP_KEY`, access/refresh
tokens, a connector token, serials, backups, logos, clinic data, or signing
keys. In production the native launcher supplies dynamic loopback, writable
AppLocalData, installation identity, per-install key, worker/listener state,
and health credential. `.env.example` is a development/deployment catalogue,
not a file to copy into an installer.

## Writable data and logs

Production uses Tauri's per-user `AppLocalData` directory for application
identifier `dz.click.medismart` (normally below `%LOCALAPPDATA%`; confirm the
resolved directory during clean-VM certification). Its managed layout is:

```text
data/database.sqlite       active SQLite database
storage/                   managed documents, logos, backup and restore work
config/                    non-secret native settings and protected token files
logs/                      bounded native supervisor logs
runtime/                   transient state snapshots
cache/                     framework cache
tmp/                       temporary runtime files
```

Do not move any of those paths into `Program Files`, a synchronized cloud
folder, a network share, or a removable drive. Do not edit `runtime/*.json` or
`config/*.json` while Drclick is running.

Native logs include `desktop-supervisor.log` and dedicated queue, scheduler,
LAN, and cloudflared supervisor logs when those services are active. Each
native log is bounded and rotates to one `.log.1` file at approximately 5 MiB.
Laravel logs remain under managed `storage/logs`. The native logger redacts
known secrets and private AppData paths and omits raw tunnel/worker/scheduler
child output, but an operator must still review every file before sharing it.

## First launch

1. Start the signed desktop application. Do not start `php artisan serve` or a
   separate queue worker beside it.
2. On an empty installation, create the one-time owner account with full name,
   medical specialty, email, and a strong password. Registration closes after
   the first user is created.
3. Open **Configuration > Cabinet & documents**. Enter the practitioner and
   clinic identity, professional identifier, contact details, address, footer,
   and an approved PNG/JPEG/WebP logo. Save and verify a supported generated
   clinical document. A signed `custom_branding` entitlement is required to
   add, replace, or remove the logo or extra footer line; basic identity fields
   remain editable, and existing branding remains visible if the entitlement
   later becomes unavailable.
4. The initial specialty is intentionally locked. An owner may correct an
   input error only after password confirmation and explicit acknowledgement;
   the correction is audited.
5. Open **Configuration > Connexion & sauvegardes**. Review the observed status
   cards before changing preferences. A saved preference is not proof that a
   listener, worker, Drive account, tunnel, or license is healthy.
6. Create an encrypted local `.msbackup`, keep its recovery phrase separately,
   and verify that the backup history reports completion before entering real
   clinical data.

If first launch reports missing/tampered resources, migration recovery, or an
unhealthy local runtime, stop. Preserve the whole AppLocalData directory and
the signed installer hash; do not delete the database, rollback files, or
repeatedly reinstall over the evidence.

## Online and offline behavior

Core consultations and local records run from the supervised local runtime and
must restart without internet. “Navigateur connecté” is only browser network
observation; the installation cards are the service-health authority.

- Local backup and local use do not require internet.
- LAN QR upload requires the clinic's private local network, not internet.
- Drive connection/upload, license activation/refresh/deactivation, named
  tunnel operation, and signed update checks/installation require internet and their
  deployment configuration.
- An optional-service outage must not be “fixed” by exposing the loopback
  administration server on LAN or by enabling router port forwarding.

## System tray and exit

Closing the startup or main window hides Drclick in the Windows notification
area; it does not stop Laravel, the queue worker, scheduler, LAN listener, or
tunnel. Left-click/double-click the Drclick icon, or choose **Ouvrir
Drclick**, to restore the window. Use **Quitter Drclick** from that menu for
the normal ordered shutdown. Do not use Task Manager unless the process is
unresponsive and the incident is being preserved for support. Launch at login
is not implemented.

## LAN and QR uploads

Use a trusted private clinic network:

1. In **Connexion & sauvegardes**, select the physical private-network adapter,
   enable local reception, optionally request firewall diagnostics, and save.
2. Wait for the page to report the native LAN listener as active and verified.
   Drclick binds only the selected private IPv4 address and a managed high
   port; it does not bind the administration interface to the network.
3. Set the QR mode to **Réseau local**, choose the expiry and file limits, then
   create a temporary link. The phone must be on the same trusted network.
4. The phone may send only accepted PDF/JPEG/PNG files. Each file remains in
   private quarantine until an authorized desktop user accepts or rejects it.
5. Revoke the session when finished. Never reuse or copy a QR verifier into a
   ticket or diagnostic message.

Drclick never creates, removes, or changes Windows Firewall rules. If local
health passes but the phone cannot connect, check that Windows classified the
network as Private and that the approved signed Drclick executable is
allowed only on the intended Private profile. Do not open a permanent broad
port, allow Public networks, disable the firewall, or forward a router port.
Adapter/address changes deliberately close the old listener and require a new
verification before another QR origin appears.

## Named tunnel

The configuration page observes a named Cloudflare tunnel but does not
provision one. A trusted deployment tool must write the strict
`config/cloudflare-tunnel.json` settings and protect `config/cloudflared.token`
with Windows DPAPI for the current user. Do not paste a plaintext connector
token into `.env`, JSON, logs, PowerShell history, or a support message.

Remote upload becomes available only when the license allows it and the native
supervisor verifies the exact upload hostname, local upload-only origin,
Cloudflare effective ingress, connector readiness, and public health. An
“unavailable” tunnel is the expected fail-closed state when any condition is
missing. There is no relay service in this release.
The signed `remote_relay` entitlement is only a prerequisite and does not
provision a provider; relay mode therefore remains disabled even when a
certificate contains that feature.

## Local and automatic backup

Use **Sauvegarde immédiate vérifiée > Archive Drclick (.msbackup)**. The
encrypted archive contains a consistent SQLite snapshot, managed documents,
logos, versioned manifest, and SHA-256 inventory. Enter and confirm a recovery
phrase of at least 12 characters. Drclick does not retain or recover that
phrase; store it in the clinic's approved password/recovery system, separate
from the archive.

If Sodium is unavailable, the UI may offer an unencrypted local archive. Treat
that file as clinical data and keep it only on approved encrypted storage; a
production release should ship the reviewed Sodium extension.

Automatic backup requires the supervised scheduler. Set time and retention in
the UI, save, and verify later completion in **Historique des sauvegardes**.
Do not treat the configured schedule or a downloaded filename as proof of a
successful, verified backup.

## Google Drive backup

Drive requires the deployment client ID, the `drive.file` scope, an eligible
license, Sodium, internet, and the supervised queue worker.

1. Confirm the administrator password, choose **Connecter Google Drive**, and
   complete consent in the system browser. The embedded app never receives a
   generic open-URL capability.
2. Return to Drclick and run the connection test. The exact dynamic loopback
   callback is created by the launcher; do not configure a LAN/public callback.
3. Enter the Drive folder name and a recovery phrase, then queue an encrypted
   backup. Verify both local completion and Drive confirmation.
4. A downloaded Drive archive is retained only after size, ownership metadata,
   manifest, and checksum validation. Remote deletion is refused unless Drive
   still contains another strictly newer managed archive whose metadata is
   fetched again and exactly matches a completed local upload record by record
   ID, remote ID, size, and SHA-256. A successful deletion is permanent on
   Drive and does not delete a separate local copy.
5. Disconnecting removes local OAuth credentials; existing Drive files remain.

OAuth access/refresh tokens are encrypted local application data. They are
never backup phrases and must never be copied to diagnostics.

## Supervised restore

Raw SQLite restore is intentionally hidden. Restore only an encrypted,
verified `.msbackup` from the Windows desktop UI:

1. Close clinical work on every workstation/device using the installation.
2. Confirm the administrator password. Select the archive and enter its
   recovery phrase.
3. Choose **Vérifier l'archive**. Check its creation date, Drclick version,
   schema, components, file count, and size. At this point active data has not
   changed.
4. Acknowledge replacement and choose **Appliquer et redémarrer Drclick**.
   Do not close the app, power off, or start another PHP/queue process.
5. The native owner stops all writers, creates and verifies a safety backup,
   revalidates the staged inventory, performs same-volume swaps, and restarts
   only after keyed health succeeds. Verify patients, recent documents, clinic
   logo, and backup history after restart.

If the UI says manual recovery is required, leave Drclick and every writer
stopped. Preserve AppLocalData, the source `.msbackup`, recovery phrase, safety
archives, journals, staging, and rollback files. Escalate through the approved
support process. Never rename/delete recovery files or run a Laravel/web apply
command.

## License operation

Activation requires the deployment HTTPS service and public verification key.
After password confirmation, enter the issued serial and activate. The UI
distinguishes active, offline grace, expired/revoked/suspended, server
unavailable, clock warning, and not-activated states. Use **Actualiser** when
online; correct Windows time before retrying a clock warning.

Choose **Désactiver cet appareil** only while online and before intentionally
moving a license. A failed deactivation leaves the local license unchanged.
License state must not be used to delete or hide clinic data; local backup and
recovery remain safety operations even when a premium network feature is
unavailable.

The feature gates are deliberately narrow. `multi_user` controls creation of
additional accounts, but existing accounts remain listable and manageable.
`custom_branding` controls changes to the logo and extra footer line, but
existing branding continues to render. `remote_relay` cannot enable anything
without the still-absent central relay provider. `automatic_updates` controls
automatic checks only; it does not authorize automatic download.

## Redacted diagnostics

Start with **État réel de l'installation > Informations techniques > Copier
les informations**. That allowlisted summary omits OAuth/tunnel secrets and
masks the QR verifier. Add only the minimum relevant rotated supervisor log
after opening and reviewing it.

Never send any of these as “diagnostics”:

- `data/database.sqlite`, `-wal`, or `-shm`;
- `storage/`, a `.msbackup`, a recovery phrase, logo, upload, or document;
- `config/cloudflared.token`, OAuth rows/tokens, `.env`, `APP_KEY`, health key,
  license serial/certificate, or signing material;
- a QR URL/verifier; or
- an unredacted screenshot containing clinic/patient identity.

Record the application version, Windows version, exact signed installer
SHA-256, time of failure, stable error code, and reproduction steps without
medical free text. Keep diagnostics under the clinic's retention/access policy.

## Disaster recovery

The primary recovery artifact is the newest independently stored, verified
encrypted `.msbackup` plus its separately held phrase. Periodically rehearse a
restore on a controlled non-production installation.

If the active installation is damaged, stop Drclick fully before preserving
AppLocalData. Copying only `database.sqlite` while writers or SQLite WAL files
are active is not a valid backup. Preserve the entire directory read-only for
forensics, reinstall only the approved signed version, and use the supervised
restore flow. If schema/migration compatibility is refused, do not downgrade
or bypass the check; escalate with the artifact hashes.

## Upgrade and uninstall

The signed updater implementation is available only in a release compiled with
the approved HTTPS endpoint/public key and protected Tauri signing inputs. An
unconfigured build truthfully reports it unavailable. In a configured build,
an operator may check manually; installation cannot begin until recent-password
confirmation creates a current verified `.msbackup` and the native shell
validates the short-lived authorization bound to that backup, installation, and
pending version. Automatic checks additionally require
`automatic_updates`; automatic download remains disabled.

On first start after an upgrade, the native forward-migration gate runs before
PHP, queue, scheduler, LAN, or tunnel. It verifies the packaged migration
contract and active database, creates and hashes a safety snapshot, journals
the operation, applies only the exact forward set, and performs post-validation.
It restores the verified snapshot on a controlled failure and keeps the runtime
offline when recovery is ambiguous. Downgrades remain unsupported.

Neither implementation substitutes for release evidence. Until the exact
signed installer/updater artifact and a previous-version migration have passed
the hash-bound clean-VM rehearsal, use only the release owner's approved
upgrade path after an independently stored verified encrypted backup.

Uninstall/reinstall data behavior has not yet been certified on a clean VM.
Before uninstalling, make and independently store a verified encrypted backup.
Do not assume uninstall deletes clinical data, and do not manually erase the
AppLocalData directory merely to make reinstall succeed. The supported choice
to preserve or securely destroy data must be recorded by clean-VM evidence and
the clinic's decommissioning policy before production release.
