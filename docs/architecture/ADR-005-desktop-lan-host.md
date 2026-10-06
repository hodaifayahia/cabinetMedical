# ADR-005: The doctor's desktop serves the cabinet LAN ("poste principal")

- Status: accepted
- Date: 2026-10-06
- Builds on: [ADR-004](ADR-004-local-first-desktop-restored.md)
- Narrows: [ADR-002](ADR-002-cabinet-hub-offline-lan.md) (the separate HTTPS
  Cabinet Hub stays supported, but is no longer the only way to share data)

## Context

ADR-004 made each desktop the owner of its own SQLite database, which is right
for a one-PC cabinet. Cabinets with a doctor and an assistant asked for the
obvious next step, in their words: *"if I install the app on my PC and on my
assistant's PC, on the same network, and I add her as a user, she must sign in
and see the same database — and it must keep working when the Internet or the
website is down."*

ADR-002 answers this with a dedicated Cabinet Hub (PostgreSQL, HTTPS with a
pinned certificate, signed pairing). That remains the target for larger
cabinets, but none of it ships today, and asking a two-person cabinet to run a
server is the barrier the clinics are describing. Independent per-PC databases
are still rejected: they diverge (ADR-002).

## Decision

1. **One owner, as before.** The doctor's PC stays in `local` mode and keeps
   the only database. Turning on Configuration › Réseau local makes it the
   *poste principal*: the shell starts a second supervised PHP listener on
   `0.0.0.0:47850` against the same SQLite database, storage and key. The
   doctor's own window keeps the loopback listener, so enabling or disabling
   sharing never changes its origin (localStorage, PIN enrolment) and the
   doctor is never queued behind the assistant's requests.
2. **The other PCs are thin clients.** A *poste secondaire* is a normal Drclick
   installation switched to `attach` mode with `http://<host>:47850/`. Plain
   HTTP is accepted only for hosts that cannot be on the Internet (RFC 1918 and
   link-local IPv4, IPv6 ULA/link-local, bare computer names, `.local`); every
   other origin still requires HTTPS. Switching modes restarts the app.
3. **Same accounts and permissions.** The assistant signs in with her own
   account, created by the doctor in Personnel on the poste principal, within
   the cabinet's seat limit (two by default: doctor + one user). Sessions,
   CSRF, roles, the session lock and audit logging are unchanged.
4. **Laravel decides who is on the LAN.** `LanHostBoundary` admits the whole
   application on the LAN listener only when the TCP peer is private,
   link-local, ULA or loopback; the `Host` is a private IP, a bare computer name
   or a `.local` name with the exact port; no forwarding header is present; and
   the scheme is HTTP. Everything else is the existing 404 boundary. Google
   OAuth and detailed health remain loopback-only; the LAN listener never sees
   the native health key.
5. **Native capabilities follow the origin.** Sharing, joining, the firewall
   helper and the backup-folder picker are granted to `http://127.0.0.1:*`
   only. Pages served by the poste principal on a poste secondaire may only
   read the mode, return that PC to local mode, and use the signed updater.
6. **Discovery is a convenience, not trust.** The host answers a UDP broadcast
   (`DRCLICK_DISCOVER v1`, port 47851) from private peers with its name and
   port; the client still probes `/health` and every user still signs in.
7. **Microphone.** WebView2 is started with media prompts auto-accepted and a
   `PermissionRequested` handler that grants the microphone to the owning
   origin; on a poste secondaire, that LAN origin is also treated as a secure
   context so `getUserMedia` works over HTTP.

## Consequences and accepted trade-offs

- **No transport encryption on the LAN.** Traffic between the PCs (including
  passwords at sign-in and clinical data) is plain HTTP on the cabinet's own
  network. Anyone able to capture that network — an unsecured Wi-Fi, a
  compromised device on the same switch — can read or alter it. This is the
  same exposure as most cabinet software that shares a database over SMB, and
  is accepted for small cabinets on a private network; the user guide asks for
  a password-protected Wi-Fi or cable. The HTTPS Hub (ADR-002) remains the
  answer when that is not acceptable.
- **Not usable from outside.** Public peers and public host names are refused
  even if a router forwards the port; DNS rebinding through a public name is
  refused by the `Host` allow-list.
- **The poste principal must be on.** When it is off or Drclick is closed, the
  other PCs show the connection page (retry, or go back to their own, separate
  database). Closing the window keeps Drclick running in the tray.
- **Throughput.** PHP's built-in server is single-threaded on Windows
  (`PHP_CLI_SERVER_WORKERS` is ignored there), so all postes secondaires share
  one sequential listener. Fine for one or two assistants; a busier cabinet
  should use the Hub.
- **Firewall.** Windows asks once whether `php.exe` may accept connections; a
  non-administrator cannot answer yes. The settings page offers a UAC-elevated
  `netsh` rule (TCP 47850, UDP 47851, private/domain profiles only), and the
  network must be classified as Private.
- **Host-only operations.** Backups, restores and updates act on the poste
  principal. Requests that crossed the LAN boundary are marked
  (`LanHostBoundary::isLanClientRequest()`), so screens can hide or refuse
  machine-level actions on a poste secondaire.
- **No merge.** A PC that worked alone and then joins the poste principal does
  not merge its own records; it simply stops using them (they stay on disk and
  come back with "Revenir au mode autonome").
