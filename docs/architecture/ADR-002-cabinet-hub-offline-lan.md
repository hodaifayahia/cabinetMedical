# ADR-002: Cabinet Hub for shared offline LAN operation

- Status: accepted direction; implementation is staged
- Date: 2026-08-08
- Scope: Drclick desktop, cabinet data plane, cloud control plane

## Context

The Drclick Windows application is a thin Tauri client. It currently loads one
HTTPS Laravel origin and intentionally contains no PHP runtime or clinical
database. This is the correct model for hosted use, but it cannot provide shared
clinical work when Internet access is unavailable unless another machine on the
cabinet LAN provides the application and database service.

Putting an independent database on every desktop would create concurrent writers,
conflicts, and split-brain medical records. Putting SQLite on an SMB share is also
rejected: network filesystem locking and durability are not a safe basis for
clinical data.

## Decision

Drclick will use one authoritative **Cabinet Hub** for every cabinet that needs
offline LAN operation.

The Hub is a separate service/appliance, not a runtime embedded in the Tauri
client. It runs the Laravel application and PostgreSQL on one always-on LAN host.
All doctor and staff desktops use their existing individual Drclick accounts and
connect to that Hub. Internet access is not required for LAN clinical work.

For a hub-enabled cabinet, the Hub remains the only clinical write authority both
online and offline. The cloud remains the control plane for cabinet registration,
seat approval, platform administration, licence issuance, updates, encrypted
backup custody, and hub presence. It must not accept competing clinical writes.

```text
                 Internet available
        +--------------------------------+
        | Drclick cloud control plane  |
        | licence / seats / updates / DR |
        +---------------+----------------+
                        | outbound authenticated sync
                        | and optional remote tunnel
    Cabinet LAN         |
  +---------------------+-------------------------------+
  |             +-------v------------------+            |
  |             | Drclick Cabinet Hub    |            |
  |             | Laravel + PostgreSQL     |            |
  |             | canonical clinical data |            |
  |             +-----+---------------+----+            |
  |                   | HTTPS         | HTTPS           |
  |             +-----v-----+   +-----v-----+            |
  |             | Doctor PC |   | Staff PC  |            |
  |             | Tauri only|   | Tauri only|            |
  |             +-----------+   +-----------+            |
  +-----------------------------------------------------+

  Internet unavailable: the lower LAN section continues unchanged.
```

## Non-negotiable invariants

1. There is exactly one active clinical write authority for a cabinet.
2. Tauri never bundles or supervises PHP and never stores a clinical database.
3. Desktops never open PostgreSQL or SQLite directly; they call Laravel over
   authenticated HTTPS.
4. A Hub is bound to exactly one cabinet and rejects another cabinet's users,
   data, licence, backups, and synchronization stream.
5. Discovery is not trust. mDNS/DNS-SD may locate a Hub, but a signed pairing
   descriptor and a pinned Hub identity establish trust.
6. LAN traffic containing medical or session data is encrypted. There is no
   production `http://192.168.x.x` exception and no “ignore certificate error”
   switch.
7. Loss of the Hub fails closed for writes. A desktop must never silently begin a
   new local database or promote a stale cloud copy.
8. Trial and lifetime licence semantics stay unchanged. The Hub receives a signed,
   cabinet-bound entitlement and may cache it for verified offline use.

## Identity, discovery, and pairing

Each Hub receives a stable UUID, an Ed25519 identity key held in protected storage,
and a certificate for its stable cabinet hostname. The cloud signs a short-lived
pairing descriptor containing at least:

- protocol version;
- cabinet ID and Hub ID;
- stable hostname and port;
- TLS SPKI fingerprint;
- authority epoch;
- expiry and one-time pairing nonce.

The installer or first-run screen can discover candidate Hubs with mDNS, but the
user must confirm the cabinet and a short code or QR descriptor. The desktop pins
the signed identity. A discovered hostname alone is never accepted.

Production LAN HTTPS uses the Drclick private PKI or an equivalent managed
certificate design. Certificate rotation is signed by the previous Hub identity
and the control plane. Exact loopback HTTP remains development-only.

## Data and synchronization

PostgreSQL is the production Hub database. It is reached only by local Hub
processes. Clinical files remain in Hub-managed private storage and are referenced
by immutable content checksums.

Cloud projection is built as an explicit protocol, not database-file replication:

- UUIDv7 public/sync identifiers on synchronized aggregates;
- a transactional outbox committed with each clinical change;
- cloud inbox deduplication by immutable event ID;
- monotonic per-cabinet sequence/cursor and resumable batches;
- idempotent consumers and schema-versioned payloads;
- tombstones instead of untracked hard deletes;
- checksum-addressed, chunked, resumable encrypted document transfer;
- acknowledgements retained until durable cloud commit;
- an authority epoch/fencing token on every batch.

Existing numeric primary keys may remain internal. No cloud synchronization is
enabled until every synchronized aggregate has an explicit ID, deletion policy,
authorization policy, and replay test.

Clinical edits in the cloud are disabled for a hub-enabled cabinet. Remote users
reach the authoritative Hub through an outbound authenticated tunnel while it is
online. When the Hub is unreachable, the cloud may later expose a clearly labelled
read-only emergency snapshot, but it cannot accept writes.

## Failure behavior

| Failure | Required behavior |
| --- | --- |
| Internet unavailable | Full doctor/staff work continues on the LAN; events and encrypted backups queue locally. |
| Cloud control plane unavailable | Existing paired clients and cached valid entitlement continue on the Hub. New provisioning and licence changes wait. |
| Doctor desktop unavailable | Other paired desktops continue; desktops hold no authoritative data. |
| Hub unavailable or LAN partitioned | Writes stop. Show a precise Hub/LAN diagnostic; never create a divergent local database. |
| Forced Hub replacement | Restore a verified backup, increment the authority epoch in the control plane, and fence the old Hub permanently. |
| Certificate or identity mismatch | Refuse connection and require an audited repair/re-pair flow. |

## Availability and recovery

The recommended production host is a dedicated, low-power cabinet appliance with
an SSD, UPS, automatic security updates, and no routine user desktop workload.
Every Hub must have:

- encrypted local backups with tested restore;
- encrypted off-site backup when Internet is available;
- PostgreSQL base backups plus WAL archiving for point-in-time recovery;
- storage, database, queue, certificate, backup, clock, and sync health checks;
- an audited replacement/failover procedure.

A second warm-standby Hub is optional. Promotion is always explicit and fenced by
a new authority epoch; automatic dual-primary failover is prohibited.

## Delivery stages

1. **Connected desktop correction** — expose an actionable Cloud/Hub connection
   screen, strictly validate endpoints, persist only a safe connection profile,
   and keep the current hosted mode working.
2. **Standalone Cabinet Hub MVP** — separate installer/appliance, PostgreSQL,
   one-cabinet binding, HTTPS identity, health supervision, roles/seats/PIN login,
   licence cache, backup/restore, and two-PC LAN acceptance tests with Internet
   physically disconnected.
3. **Pairing and operations** — signed discovery, certificate rotation, diagnostics,
   UPS/shutdown handling, update channels, and recovery drills.
4. **Cloud projection** — UUIDs, outbox/inbox, tombstones, documents, fencing,
   replay/chaos tests, monitoring, and encrypted off-site retention.
5. **Remote access and standby** — outbound tunnel to the authoritative Hub,
   read-only emergency snapshot, and optional manually promoted standby.

## Explicitly rejected alternatives

- Reintroducing a PHP runtime inside every Tauri desktop.
- Copying or sharing SQLite through SMB, OneDrive, Google Drive, or Dropbox.
- Independent per-PC databases with “last write wins”.
- Automatic cloud/Hub dual-primary writes.
- Trusting “same Wi-Fi”, source IP, mDNS, or a self-signed certificate bypass.
- Promising offline operation from the current thin installer without a running
  Hub.

## Acceptance criteria for the offline claim

Drclick may be advertised as shared-offline only after an automated and manual
test demonstrates: two different Windows desktops, two different cabinet users,
Internet physically blocked, concurrent appointments/patients/payments/documents,
restart and power-loss recovery, backup restore to replacement hardware, no
cross-cabinet access, no plaintext medical traffic, and deterministic cloud replay
after reconnection.
