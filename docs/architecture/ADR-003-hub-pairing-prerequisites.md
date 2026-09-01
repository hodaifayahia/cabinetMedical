# ADR-003: Hub identity pinning must not ship before LAN PKI and offline recovery

- Status: proposed
- Date: 2026-08-31
- Scope: Drclick desktop shell, Cabinet Hub, cloud control plane
- Supersedes nothing; constrains the ordering of [ADR-002](ADR-002-cabinet-hub-offline-lan.md) stage 3

## Context

ADR-002 stage 3 calls for signed discovery, a pairing descriptor, pinned Hub
identity, an authority epoch, and certificate rotation. Stage 2 is now built:
a Hub is bound to one cabinet, fails closed when it cannot name that cabinet,
and can be activated entirely offline from a signed entitlement.

Stage 3 was designed before being built. Four independent designs were produced
against the real code — a minimal-trust-surface split verification, full
in-Rust dual-signature verification, a mutual live-attestation challenge, and an
anchored trust-on-first-use scheme — and each was scored by three adversarial
reviewers: a LAN attacker, an engineer responsible for a cabinet with no
Internet and no IT staff, and an engineer landing the change in a tree whose
Tauri shell is under concurrent rewrite.

No design scored above 4.7 out of 10. The failures were not stylistic and were
not confined to one design; the reviewers converged on the same two structural
problems.

## Decision

**Hub identity pinning will not be implemented until the two prerequisites
below are met.** The current presence-only check stays as it is, and the
`tls_spki_sha256` value advertised on `/health` remains unused, until pinning
can be introduced together with a way to recover from it.

This is a deliberate refusal to close a known hole, so it needs justifying.

## Why pinning first would make things worse

### 1. There is no LAN PKI, and pinning cannot be reached without one

Production LAN HTTPS requires a certificate the desktops already trust. The
shell's probe uses `reqwest` with `rustls-tls-native-roots` and the application
itself renders in WebView2 — both consult the Windows Trusted Root store. A
private CA would therefore have to be imported by hand on every desktop.

Until that exists, chain validation fails *before* any pin is consulted, and the
operator sees the existing generic message
("Le serveur ne répond pas ou son certificat HTTPS n'est pas valide") with no
diagnosis. There is also no certificate renewal command, so a certificate's own
expiry date becomes a pre-scheduled, guaranteed, total clinic outage — and
ADR-002 invariant 6 correctly forbids any "ignore certificate error" bypass to
escape it.

Pinning added on top of this changes nothing an attacker can do, because the
connection already fails.

### 2. Pinning without a break-glass turns a disk failure into a closed clinic

This is the decisive objection.

The Hub's hardware fails. The operator installs the replacement appliance and
restores the verified backup. The new box generates a new TLS key, so its SPKI
differs from the 32 bytes every desktop has pinned. Every desktop hard-fails —
which is *correct* under ADR-002 invariant 7, and is exactly what pinning is
for.

There is then no way out. Re-pairing fetches a descriptor that still names the
old SPKI. Raising the authority epoch to mint a new one requires the control
plane, and a cabinet that has never had Internet cannot reach it. Recovery
becomes a USB round trip to an Internet-connected machine, which in the
deployment Drclick actually targets is somewhere between a day and a week of a
clinic that cannot open a single patient record.

A security control whose failure mode is "the practice stops seeing patients,
and the only remedy is the network you do not have" is worse than the exposure
it removes. The break-glass has to be designed first, and it has to work on the
LAN alone with an operator-confirmed fingerprint.

## Secondary findings, all of which need answers before stage 3

- **The webview's TLS cannot be pinned at all.** Tauri 2.11.5 exposes no
  certificate-validation hook. Only the shell's `reqwest` probe can be pinned,
  so a pin would protect the health check while the session that actually
  carries medical data remains protected by the public root store alone. That
  asymmetry must be stated in any design, not glossed.
- **`TlsInfo` can legitimately be absent.** The peer certificate is read from a
  response extension that is empty on a pooled connection or if the client is
  ever built without `tls_info`. Treating absence as failure breaks pairing in
  the field; treating it as success is a total silent bypass. No design
  specified this branch.
- **The load-bearing comparison is currently untestable.** `src-tauri/Cargo.toml`
  has no `[dev-dependencies]` section, so exercising SPKI extraction needs a TLS
  listener with a known key — a new dependency that the "no new dependency"
  designs explicitly ruled out.
- **A signed descriptor proves the cloud once said something.** It does not
  prove the box answering holds the key. Only a live challenge-response or a
  channel binding to the observed certificate closes that, and the Hub has no
  identity keypair today — nothing in the repository generates one.
- **Domain separation is mandatory if the RSA licensing key is reused.** A
  descriptor and a cabinet entitlement would share an envelope and a key, so a
  `typ` claim must be checked before anything else or one artefact can be
  replayed into the other's code path.

## Required ordering

1. **LAN PKI** — outstanding. Certificate issuance for
   `hub-cabinet-<n>.<domain>`, trust distribution into the Windows Trusted Root
   store of every desktop, and a renewal command with an expiry alarm.
2. **Offline break-glass — done.** The authority record and `hub:adopt` landed
   with this ADR. Authority lives in the database, so it travels with a restored
   backup; a replacement Hub sees that the cabinet still belongs to the machine
   it is replacing and refuses to serve until an operator adopts it, which
   raises the epoch and fences the old box. It needs no control plane, so it
   works in a cabinet that has never been online. The epoch is advertised on
   `/health` ready for a desktop to pin as a monotone floor.
3. **Hub identity keypair** — outstanding. Generated at provisioning and held in
   protected storage, so a Hub can prove live possession rather than merely
   presenting something the cloud once signed.
4. **Then** the signed descriptor and the pinned identity — with the
   `TlsInfo`-absent branch specified and tested.

Step 2 was taken first, deliberately: it is the only one of the four that makes
the system safer on its own. A cabinet today is protected from a restored clone
silently becoming a second write authority, which was possible before and is a
worse failure than the LAN impersonation stage 3 addresses, because it corrupts
records rather than exposing them.

## Consequences

Drclick must not be advertised as shared-offline yet, which was already true
for other reasons recorded in [the Hub setup guide](../CABINET-HUB-SETUP.md).

The residual exposure is stated plainly so nobody mistakes this for safety: on a
cabinet LAN, a machine that answers `/health` with a well-formed hub block is
today accepted exactly like the real Hub, because
`validate_hub_identity()` checks only that the fields are present. Trust
currently rests on the operator typing the correct HTTPS URL and on the
certificate chain. That is the gap stage 3 exists to close, and it stays open
until closing it cannot also close the clinic.
