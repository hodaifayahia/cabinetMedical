<?php

/*
|--------------------------------------------------------------------------
| Cabinet Hub
|--------------------------------------------------------------------------
|
| A Cabinet Hub is this same Laravel application running on one always-on
| machine inside a cabinet's LAN, so the doctor and reception desktops keep
| working with no Internet connection. See ADR-002.
|
| The binding lives in configuration rather than in the database on purpose.
| It is appliance identity written once by the Hub installer, it must be
| readable before any query decides which tenant a request may touch, and it
| has to survive a database that is empty, restored, or wrong. A Hub that
| cannot state which cabinet it serves must refuse to serve any of them.
|
*/

return [

    /*
    | Whether this installation is a Cabinet Hub. The hosted control plane
    | leaves this false and keeps behaving exactly as it does today.
    */
    'enabled' => (bool) env('HUB_MODE', false),

    /*
    | Stable identifier for this Hub, generated once at installation. The
    | desktop pins it so a replaced or impersonating Hub is not silently
    | accepted (ADR-002, "Discovery is not trust").
    */
    'id' => env('HUB_ID'),

    /*
    | The single cabinet this Hub is the write authority for. Every other
    | cabinet is refused, whatever the database happens to contain.
    */
    'cabinet_id' => env('HUB_CABINET_ID'),

    /*
    | Stable LAN hostname the desktops connect to, e.g.
    | hub-cabinet-42.drclick.local. Advertised for diagnostics only; it grants
    | no trust by itself.
    */
    'hostname' => env('HUB_HOSTNAME'),

    /*
    | SHA-256 of the Hub's TLS SubjectPublicKeyInfo. The desktop compares the
    | certificate it negotiated against this value before trusting the Hub.
    */
    'tls_spki_sha256' => env('HUB_TLS_SPKI_SHA256'),

    /*
    | Pairing/handshake protocol version. Bumped when the shape of the
    | identity advertised by /health changes in a way older desktops cannot
    | read.
    */
    'protocol_version' => 1,

];
