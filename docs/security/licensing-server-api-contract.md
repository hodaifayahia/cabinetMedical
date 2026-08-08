# Production licensing-server API contract

Drclick is the client of three separately configured endpoints:

- `MEDISMART_LICENSE_ACTIVATION_URL`
- `MEDISMART_LICENSE_STATUS_URL`
- `MEDISMART_LICENSE_DEACTIVATION_URL`

Each value must be an exact HTTPS URL, without credentials, query, fragment,
or a non-TLS port. The client does not follow redirects. Production should put
all three paths on one allowlisted origin with publicly trusted TLS and keep
the signing private key exclusively on the licensing server or an HSM. The
packaged verification key must be RSA 2048 bits or stronger; replacing or
rotating it requires a signed Drclick application update.

## Common request rules

Requests are JSON `POST`s with `Accept: application/json` and include:

- `product` and `application_version`;
- the installation UUID in `installation_id`;
- the privacy-preserving SHA-256 value in `machine_fingerprint_hash`;
- `Idempotency-Key`, a UUID reused by the client's automatic retry;
- `X-MediSmart-License-Protocol: 1`;
- `X-MediSmart-License-Operation`, one of `activate`, `refresh`, or
  `deactivate`.

The server must make each operation idempotent for an idempotency key and must
reject a key reused with a different request body. It should bound body sizes,
rate-limit by installation and entitlement, and return generic errors. Serial
numbers, signed certificates, and full fingerprint hashes must be redacted
from application, proxy, APM, and support logs.

The desktop suppresses Telescope recording around outbound licensing calls
and redacts the same fields defensively from request and response watchers.

Activation must also be naturally idempotent for the same entitlement and
installation across different idempotency keys, because a user may retry after
the first client process exits before receiving the response.

Activation additionally sends `serial`. Refresh and deactivation send
`license_id` and the current `license_certificate`. A signed certificate is
server-issued evidence, not a general-purpose bearer secret; it must never be
accepted for a different installation or fingerprint.

## Signed certificate

Activation and refresh return a successful JSON response containing
`license_certificate`. The certificate is a compact JSON envelope:

```json
{
  "algorithm": "RS256",
  "payload": "base64url-json-without-padding",
  "signature": "base64url-rsa-signature-without-padding"
}
```

The signature covers the exact ASCII `payload` segment. The decoded payload
must contain at least:

```json
{
  "license_id": "lic_123",
  "certificate_version": 7,
  "product": "medismart-desktop",
  "edition": "professional",
  "installation_id": "installation-uuid",
  "machine_fingerprint_hash": "64-lowercase-hex-characters",
  "issued_at": "2026-08-05T12:00:00Z",
  "expires_at": "2027-08-05T12:00:00Z",
  "offline_grace_days": 30,
  "status": "active",
  "features": {
    "remote_upload": true
  }
}
```

`certificate_version` is a strictly increasing positive integer scoped to a
license. Every changed refresh response, including suspension or revocation,
must increment it. `issued_at` must not move backwards. Replaying the exact
current certificate is an accepted no-op; another certificate with an equal
or lower version is rejected locally. A different license cannot replace the
current one until deactivation succeeds.

`issued_at` and `expires_at`, when present, are absolute RFC 3339 timestamps at
whole-second precision with `Z` or an explicit numeric offset. Relative dates,
timezone-free values, and overflowing calendar dates are rejected.

For a changed refresh response, `issued_at` must represent the current trusted
server time, not a cached response's creation time. This freshness rule is what
allows a newer online certificate to repair a false future clock anchor safely.

Allowed signed status values are `active`, `expired`, `suspended`, `revoked`,
and `device_limit_reached`. Feature values grant access only when exactly
boolean `true`. The server must sign the installation and fingerprint claims;
unsigned response fields never grant an entitlement.

## Operation responses

- Activation: `200` or `201` JSON with a signed certificate.
- Refresh: `200` JSON with the current or a newer signed certificate.
- Deactivation: any successful `2xx`, normally `204` with no body.

Deactivation must be repeatable. If the server committed deactivation but its
response was lost, a retry for that same license and installation must still
return success. Drclick removes only licensing rows and its clock anchor,
and only after confirmed server success. On timeout, rejection, invalid
response, or local transaction failure, it keeps the local certificate so the
operation can be retried. Patient records, documents, exports, and local
backups are never deleted or disabled by deactivation or license failure.

## Time and outage behavior

The client stores an encrypted, installation-local high-water clock anchor.
An active license with a missing or unreadable anchor fails closed for premium
features. A newer, valid signed certificate can repair an anchor after a
legitimate clock correction. Licensing-server outages leave the existing
certificate in place and normal expiry/offline-grace policy applies.

## Residual trust boundary

This scheme prevents stale certificate replacement while the client's newer
database state is present, but a full rollback of both the SQLite database and
system clock can also roll back the certificate and its database clock anchor.
Closing that gap requires a monotonic anchor outside portable backups, ideally
in OS-protected storage or a hardware-backed key, plus an online refresh.

A desktop administrator also controls the client process and filesystem. The
machine fingerprint is privacy-preserving binding material, not proof from a
TPM. A future high-assurance service should add a non-exportable device key
with challenge signing (or mTLS); the signed certificate must not be treated
as client authentication by itself.
