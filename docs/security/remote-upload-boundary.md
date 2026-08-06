# Verified remote upload boundary

The desktop PHP listener is a private loopback service. The launcher supplies
its exact dynamic origin in both `APP_URL` and `MEDISMART_LOCAL_URL`. An
optional named Cloudflare tunnel may target that same listener, while its one
public origin is supplied only through `MEDISMART_REMOTE_UPLOAD_URL` and must
be an HTTPS hostname with no path, query, credentials, or custom port.

The optional direct-phone origin in `MEDISMART_LAN_UPLOAD_URL` is a third,
independent audience governed by the
[supervised LAN upload boundary](lan-upload-boundary.md). It never changes the
loopback administration or trusted remote-proxy rules described here.

Laravel accepts the following request audiences when the supervised boundary
is enabled:

- the exact direct loopback origin, without forwarding headers;
- the exact configured public hostname when the immediate socket peer is
  loopback, raw `X-Forwarded-Proto` is exactly `https`, and Laravel also sees
  the effective request as HTTPS;
- no other `Host` value.

Only `GET /health` and the four `public_upload_v1` upload routes are exposed on
the public hostname. This check is global and runs before route, session, and
authentication middleware, so application, authentication, administration,
configuration, backup, Drive, Telescope, and clinical-document routes return
404 without redirects or session cookies. Forwarded client IP and protocol are
trusted only from `127.0.0.1` and `::1`; forwarded host, port, and prefix are
never trusted.

The per-run `X-MediSmart-Health-Key` authorizes detailed diagnostics only on a
direct request to the exact loopback origin. It is removed from public-host
requests. A successful detailed response contains the
`remote_upload_boundary` schema used by the launcher to attest the active
middleware, proxy policy, and exact route set. The public health response stays
minimal and contains no attestation or operational details.

Remote upload tokens remain independently bound to the remote audience and
still require a valid `remote_upload` license feature plus a configured,
active named tunnel whose hostname matches the configured public hostname.
Local upload tokens are rejected on the public hostname. Remote tokens are
rejected on both the loopback administration origin and the separately
verified LAN origin.

## Trust limitation

Loopback identifies the local machine, not a unique process. Any other local
process running as the same user can connect to the listener and can supply
proxy-shaped headers. The exact host, route, token-audience, license, and tunnel
checks limit that exposure, but process-level proxy authentication would
require a future launcher-to-Laravel secret or an OS-authenticated transport.
