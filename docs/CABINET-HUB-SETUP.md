# Running a Cabinet Hub (offline LAN operation)

A **Cabinet Hub** is this same Laravel application running on one always-on
machine inside the cabinet's own network. The doctor's PC and the reception PC
both point their Drclick desktop at it, so clinical work continues with the
Internet disconnected.

This implements ADR-002 stage 2. Read [ADR-002](architecture/ADR-002-cabinet-hub-offline-lan.md)
first — the invariants there are not optional, and the "not yet built" section
at the bottom of this page is as important as the rest.

## What a Hub is, and is not

- It **is** the single clinical write authority for exactly one cabinet.
- It **is not** a control plane. Platform administrators have no access to it.
- Desktops hold **no** database. If the Hub is unreachable, writing stops. A
  desktop never starts a database of its own — that is what keeps two PCs from
  silently diverging into two different versions of a patient record.

## 1. Prepare the machine

One low-power always-on box with an SSD and a UPS. Not somebody's daily
workstation. It needs PHP 8.3+, a web server terminating HTTPS, and PostgreSQL
for production use (SQLite is fine for a single-desktop trial but is not the
supported shared configuration).

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=drclick
DB_USERNAME=drclick
DB_PASSWORD=...
```

PostgreSQL must listen on loopback only. Desktops never speak to the database;
they speak to Laravel over HTTPS (ADR-002 invariant 3).

## 2. Declare the Hub

```
HUB_MODE=true
HUB_ID=hub-<something-stable-and-unique>
HUB_CABINET_ID=<id of the cabinet this Hub serves>
HUB_HOSTNAME=hub-cabinet-42.drclick.local
HUB_TLS_SPKI_SHA256=<sha256 of the TLS SubjectPublicKeyInfo>
```

Then `php artisan config:clear`.

The binding lives in configuration rather than the database on purpose: the Hub
must be able to state which cabinet it serves *before* any query runs, and even
if the database is empty, restored, or wrong.

Verify it:

```
php artisan hub:status
```

A Hub that is declared but cannot name a valid cabinet, or that has not been
adopted, or whose cabinet belongs to another Hub, **refuses every request
except `/health`**. That is deliberate — serving the wrong cabinet's records is
worse than serving nothing (ADR-002 invariant 7).

## 2b. Adopt the Hub

Configuration alone grants no authority. An operator must adopt the Hub before
it will serve anyone:

```
php artisan hub:adopt --confirm
```

This records, in the database, that this `HUB_ID` holds clinical write
authority for the cabinet, starting at authority epoch 1. Until it runs, the
Hub refuses every request except `/health` and reports `hub_not_adopted`.

The epoch lives in the database rather than in `.env` deliberately. A
replacement Hub is brought up by restoring a backup, so the authority record
travels with the data. Holding it in configuration would mean a human pasting a
number and running `config:cache`, where a typo one way silently fences the
clinic and a typo the other way silently un-fences a machine that should have
stayed dead.

### Replacing a failed Hub

This is the recovery path, and it works on the LAN with no Internet.

1. Install the replacement appliance and restore the verified backup.
2. Give it its own `HUB_ID` — never reuse the dead machine's.
3. Run `php artisan hub:adopt`. With no `--confirm` it only *describes* what
   would happen, including which Hub currently holds authority.
4. Confirm the old Hub is permanently out of service, then run
   `php artisan hub:adopt --confirm`.

The epoch rises to 2 and the old `HUB_ID` is recorded as displaced. If the old
machine is ever plugged back in, it reads the same restored authority record,
sees that the cabinet belongs to another Hub, and refuses to serve —
`hub_displaced_by_another`. That is what stops a resurrected box from becoming a
second write authority and quietly forking the records.

**The warning the command prints is not boilerplate.** If you take over while
the old Hub is still running on the same network, two machines will accept
writes into two different databases for one cabinet, and the records will
diverge in ways no later sync can reconcile.

## 3. Activate the cabinet offline

The hosted flow e-mails a one-time code that is matched against a row only the
control plane can create. A Hub that has never been online has no such row, so
the customer is issued a **signed entitlement file** instead, carried on a USB
stick or read from e-mail on another machine.

### On the control plane — issue the entitlement

```
php artisan license:issue-entitlement \
    --owner=docteur@cabinet.dz \
    --plan=lifetime \
    --key=/secure/path/entitlement-signing-key.pem \
    --out=cabinet-42-entitlement.json
```

`--plan=trial --days=7` issues a time-limited trial instead. `--hub=<HUB_ID>`
binds the entitlement to one Hub; leaving it off lets the customer apply it on
any Hub of their own.

The command round-trips what it signed through the verifier before writing the
file, so a signing key that does not pair with the deployed public key is caught
here rather than in a cabinet with no Internet and no way to diagnose it.

Two guards matter:

- The private signing key is named explicitly on the command line. It is never
  read from the licensing configuration a client install ships, and
  `VerificationKey` refuses to load a private key at all.
- **A Hub cannot issue its own entitlement.** `license:issue-entitlement`
  refuses to run when `HUB_MODE=true`. If it did not, offline activation would
  be self-service and the licence would mean nothing.

### On the Hub — apply it

```
php artisan hub:activate /path/to/entitlement.json
```

The Hub verifies the RSA signature against the public key it already ships
(`MEDISMART_LICENSE_PUBLIC_KEY_PATH`) and needs no network whatsoever. It
refuses an entitlement that is tampered with, signed by the wrong key, issued
for another product, expired, bound to a different Hub, already used, or of an
unknown plan.

Entitlement payload (v1), signed as `{"algorithm":"RS256","payload":"<base64url>","signature":"<base64url>"}`:

| Claim | Meaning |
| --- | --- |
| `entitlement_version` | `1`. A newer version is refused, never guessed at. |
| `entitlement_id` | Unique; applying the same one twice is refused. |
| `product` | Must match `medismart.licensing.product`. |
| `owner_email` | Identifies the cabinet by its owner, as `/join` already does. |
| `plan` | `trial` (7 days) or `lifetime`. |
| `issued_at` / `expires_at` | `expires_at: null` means lifetime. |
| `hub_id` | Optional. When set, the entitlement only works on that Hub. |

## 4. Point the desktops at it

Each desktop's connection screen takes the Hub URL. The desktop reads `/health`
before anyone signs in and refuses a Hub that cannot name its cabinet or that
speaks a newer protocol than the desktop understands:

```json
{
  "status": "healthy",
  "hub": {
    "mode": "hub",
    "protocol_version": 1,
    "hub_id": "hub-cabinet-42",
    "cabinet_id": 7,
    "hostname": "hub-cabinet-42.drclick.local",
    "tls_spki_sha256": "…",
    "ready": true,
    "reason": null
  }
}
```

This block is readable without credentials by design — a desktop has to know
*which* cabinet's Hub it reached before it can authenticate against it. It
carries no patient or member data.

## 5. Give the reception desk an account

On a Hub, `/register` is refused: a Hub is the authority for one cabinet and
must not provision a second. Reception uses **Rejoindre un cabinet** (`/join`)
with the doctor's e-mail address, and the doctor approves them from staff
management. This works with no Internet, and it works while the cabinet is
still awaiting activation.

## Server-side rendering

Inertia SSR calls a local Node process. On a Hub that process must run locally
or SSR must be disabled — it must never point at a remote renderer, or page
rendering will hang whenever the Internet is down.

```
'inertia.ssr.enabled' => false
```

## Not yet built

Stage 2 is not complete, and Drclick must not be advertised as shared-offline
until ADR-002's acceptance criteria are met on real hardware. Still outstanding:

- **Signed pairing and identity pinning** (stage 3). The desktop reads and
  displays the Hub identity but does not pin it, and `tls_spki_sha256` is
  advertised but never compared — so a machine on the LAN answering `/health`
  with a well-formed hub block is accepted exactly like the real Hub. Trust
  today rests on the operator typing the right HTTPS URL and on the certificate
  chain.

  This is deliberately still open. Four designs were evaluated and all were
  rejected: pinning cannot be reached before a LAN PKI exists, and pinning
  without an offline break-glass turns a Hub disk failure into a clinic that
  cannot open until someone drives a USB stick to an Internet connection. See
  [ADR-003](architecture/ADR-003-hub-pairing-prerequisites.md) for the required
  ordering.
- **Certificate rotation and the private PKI** for LAN HTTPS — the prerequisite
  for everything above. Note that a private CA must reach the Windows Trusted
  Root store of every desktop, because both `reqwest` and WebView2 read it, and
  that Tauri 2 offers no way to pin the webview's own TLS connection at all.
- **Cloud projection** (stage 4): outbox/inbox, tombstones, fencing tokens.
  Nothing a Hub records reaches the hosted service yet.
- **Backup/restore to replacement hardware**, tested.
- **Manual acceptance run**: two physical Windows desktops, Internet physically
  disconnected, restart and power-loss recovery, and proof that no plaintext
  medical traffic crosses the LAN.

The automated half of the acceptance criteria lives in
`tests/Feature/Hub/OfflineLanAcceptanceTest.php`.
