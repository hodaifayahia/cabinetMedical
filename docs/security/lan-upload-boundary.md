# Supervised LAN upload boundary

`MEDISMART_LAN_UPLOAD_URL` is the one exact origin reserved for phone uploads
on the local network. It is separate from the loopback administration origin
in `APP_URL` / `MEDISMART_LOCAL_URL` and from the optional public tunnel origin
in `MEDISMART_REMOTE_UPLOAD_URL`.

Laravel accepts the LAN authority only while
`MEDISMART_DESKTOP_SUPERVISED=true`, and only when the configured value is a
canonical HTTP or HTTPS origin whose host is a literal private, link-local, or
loopback IP address and whose port is explicitly present in the range
1024-65535. The value must already be in this canonical no-trailing-slash form.
Credentials, paths, queries, fragments, DNS names,
wildcard bind addresses, public IPs, default/low ports, and malformed values
fail closed. A typical value supplied by the implemented native listener
supervisor is:

```dotenv
MEDISMART_LAN_UPLOAD_URL=http://192.168.1.40:43124
```

This origin is an audience, not a bind address. A listener may bind an
operating-system-selected interface, but it must send the exact reachable IP
and port above to Laravel. It must never use `0.0.0.0` as the advertised
origin.

## Request policy

The global request boundary runs in Laravel's proxy-normalization slot, before
CORS, routing, sessions, authentication, or authorization. On the exact LAN
authority it permits only:

- `GET /health`;
- `GET /upload/{22-character-selector}`;
- `POST /upload/{22-character-selector}/authorize`;
- `POST /upload/{22-character-selector}/files`; and
- `POST /upload/{22-character-selector}/complete`.

Every other method or path returns the same empty, non-cacheable 404. This
includes application, login, registration, administration, configuration,
backup/restore, Drive, Telescope, clinical-document, framework-health, and
CORS-preflight paths. Rejections occur before a session can start, so they do
not redirect or set cookies.

LAN traffic must arrive directly from a private, link-local, or loopback socket
peer. `Forwarded`, every `X-Forwarded-*` header, and common proxy client-IP
headers are forbidden, including when the peer is loopback. The raw `Host`,
direct request scheme, and direct request port must reconstruct exactly the
configured origin. An authority shared with the loopback administration
audience fails closed as ambiguous.

The health-details key is removed on LAN requests. LAN `/health` therefore has
the same minimal public shape as remote `/health`; detailed database, storage,
license, URL, and boundary data remains available only through the exact
direct-loopback administration origin.

Local QR generation uses this same validated origin in supervised mode, and a
local upload token is accepted only on a direct request to it. It is rejected
on the loopback administration and remote tunnel origins. Remote tokens remain
subject to their independent proxy, license, and active-tunnel checks and are
rejected on the LAN origin.

## Schema-v1 attestation

A keyed, direct-loopback `/health` response contains a strict
`lan_upload_boundary` object:

```json
{
    "schema_version": 1,
    "status": "ready",
    "origin": "http://192.168.1.40:43124",
    "route_set": "public_upload_v1",
    "upload_routes_only": true,
    "exact_origin_enforced": true,
    "explicit_high_port_enforced": true,
    "direct_private_peer_enforced": true,
    "forwarding_headers_rejected": true,
    "local_tokens_bound_to_lan_origin": true
}
```

`ready` attests only that this PHP enforcement contract is configured and that
the exact route set and middleware controls are present. It does **not** claim
that a LAN socket is bound, reachable, healthy, firewall-authorized, or owned
by a native supervisor. No listener-state field exists in this schema.

The implemented native supervisor validates the complete object, matches
`origin` to the value it supplied, binds one selected private adapter/address,
starts the dedicated upload-only proxy, and probes `/health` through that
socket. Only then does it report the listener active and refresh Laravel with
the verified runtime state. The configuration UI applies a strict non-secret
schema-v1 request through the dedicated Tauri command; it cannot provide a
path, raw MAC, shell command, generic proxy target, or firewall rule. Adapter
changes close and re-attest the old origin. See [the desktop runtime
contract](../DESKTOP-RUNTIME.md#native-lan-upload-listener).

The existing `remote_upload_boundary` attestation and its trusted-loopback
proxy semantics are independent and unchanged.

## Trust limitation

Private address space proves network scope, not device identity. Any device on
the permitted LAN can reach the upload landing route when the listener is
active. Possession of the short-lived QR verifier, server-side expiry and size
limits, per-selector throttling, quarantine, and mandatory desktop review
remain required defenses. The PHP boundary itself neither opens a port nor
changes Windows Firewall.
