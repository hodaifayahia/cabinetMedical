<?php

return [
    'version' => env('MEDISMART_VERSION', '0.1.0-dev'),

    'development' => [
        // Explicit local opt-in only. Production ignores this setting even if
        // a hostile environment attempts to enable it.
        'seed_demo_user' => (bool) env('MEDISMART_SEED_DEMO_USER', false),
        // Read through config, not env(), so a config-cached install still sees
        // it. Only consulted by PlatformAdminSeeder, which refuses to run in
        // production at all.
        'platform_admin_password' => (string) env('PLATFORM_ADMIN_PASSWORD', ''),
    ],

    'runtime' => [
        'local_url' => env('MEDISMART_LOCAL_URL', env('APP_URL', 'http://127.0.0.1')),
        // Native listener supervision supplies this exact advertised origin.
        // Laravel validates it but never interprets it as proof that a socket
        // is active.
        'lan_upload_url' => env('MEDISMART_LAN_UPLOAD_URL'),
        'lan_adapters_file' => env('MEDISMART_LAN_ADAPTERS_FILE'),
        'remote_upload_url' => env('MEDISMART_REMOTE_UPLOAD_URL'),
        // The native process atomically publishes a secret-free, HMAC
        // authenticated tunnel lifecycle record at this fixed path. Laravel
        // never treats database runtime fields as evidence that the connector
        // is currently reachable.
        'native_tunnel_status_path' => env('MEDISMART_NATIVE_TUNNEL_STATUS_PATH'),
        'native_tunnel_status_maximum_age_ms' => (int) env('MEDISMART_NATIVE_TUNNEL_STATUS_MAXIMUM_AGE_MS', 15_000),
        'native_tunnel_status_future_tolerance_ms' => (int) env('MEDISMART_NATIVE_TUNNEL_STATUS_FUTURE_TOLERANCE_MS', 2_000),
        // Retained for unsupervised browser development. Supervised QR
        // generation never derives an audience from this port alone.
        'lan_port' => (int) env('MEDISMART_LAN_PORT', 8000),
        'desktop_supervised' => (bool) env('MEDISMART_DESKTOP_SUPERVISED', false),
        // Set only for the native "poste principal" LAN listener (ADR-005):
        // that PHP process admits private-network peers on this exact port.
        'lan_host_enabled' => (bool) env('MEDISMART_LAN_HOST_ENABLED', false),
        'lan_host_port' => (int) env('MEDISMART_LAN_HOST_PORT', 0),
        // The native installation identity is the authority in supervised
        // builds. Laravel mirrors it into its internal setting instead of
        // generating a second, incompatible machine identity.
        'installation_id' => env('MEDISMART_DESKTOP_INSTALLATION_ID'),
        'queue_worker_status' => env('MEDISMART_QUEUE_WORKER_STATUS', 'stopped'),
        'scheduler_status' => env('MEDISMART_SCHEDULER_STATUS', 'stopped'),
        // Only the native supervisor may report this as "active" after a
        // dedicated non-loopback listener has passed its health check.
        'lan_listener_status' => env('MEDISMART_LAN_LISTENER_STATUS', 'stopped'),
    ],

    'hosted' => [
        // Patient records live on each cabinet's own PC (desktop app), not on
        // the online service: the hosted website only manages accounts,
        // licences, AI credits and the mobile app. Set to true only to reopen
        // the clinical screens online (they never close on a desktop or Hub).
        'clinical_enabled' => (bool) env('MEDISMART_HOSTED_CLINICAL', false),
    ],

    'online_service' => [
        // The hosted Drclick service a local desktop links to under
        // Configuration › Service en ligne. Only pre-fills that form; the
        // address actually used is the one stored when the link is made.
        'url' => env('MEDISMART_ONLINE_SERVICE_URL'),
        // Whether this installation links to the online service at all: an
        // installed desktop does, the online service itself never does.
        'linkable' => (bool) env('MEDISMART_ONLINE_SERVICE_LINKABLE', env('MEDISMART_DESKTOP_SUPERVISED', false)),
    ],

    'updates' => [
        // Set only by a release shell whose HTTPS endpoint and updater public
        // key were embedded at build time. Browser development remains
        // explicitly unavailable instead of pretending that updates work.
        'signed_updater_configured' => (bool) env('MEDISMART_SIGNED_UPDATER_CONFIGURED', false),
        'allowed_channels' => ['stable'],
        'install_authorization_ttl_seconds' => 300,
    ],

    'desktop_download' => [
        // Optional public release URL used by the website download button.
        // Leave empty for private/internal builds and place the installer file
        // under storage/app/private/desktop instead.
        'url' => env('MEDISMART_DESKTOP_DOWNLOAD_URL'),
        // Absolute path, or a filename relative to storage/app/private/desktop.
        'installer_path' => env('MEDISMART_DESKTOP_INSTALLER_PATH', 'DrClickDz-Desktop-Setup.exe'),
    ],

    'health' => [
        // Supplied by the desktop launcher. Full diagnostics are never
        // authorized by loopback address alone.
        'details_key' => env('MEDISMART_HEALTH_DETAILS_KEY'),
    ],

    'security' => [
        // Three hours of *inactivity*, not of elapsed time: any pointer move,
        // key press, scroll or touch restarts the countdown, so a screen the
        // doctor is working in never locks. A consulting room is a supervised
        // space and re-authenticating mid-consultation costs more than it
        // protects; the lock exists for a workstation genuinely left alone.
        //
        // Keep session.lifetime at or above this. Laravel expires the session
        // on its own inactivity window, so a shorter one signs the user out
        // before the lock is ever reached and the setting looks ignored.
        'default_idle_lock_minutes' => (int) env('MEDISMART_IDLE_LOCK_MINUTES', 180),
        'maximum_idle_lock_minutes' => (int) env('MEDISMART_MAXIMUM_IDLE_LOCK_MINUTES', 180),
    ],

    'backups' => [
        // Retention only owns completed BackupRecord archives directly inside
        // this canonical, non-symlink directory. Nested safety archives and
        // every unowned or malformed entry remain protected.
        'managed_directory' => env('MEDISMART_BACKUP_MANAGED_DIRECTORY', storage_path('app/private/backups')),
        // Raw SQLite restore is an expert-only compatibility bridge until the
        // checksummed .msbackup restoration phase is complete.
        'legacy_restore_enabled' => (bool) env('MEDISMART_ENABLE_LEGACY_SQLITE_RESTORE', false),
        'remote_download_max_bytes' => (int) env('MEDISMART_BACKUP_REMOTE_MAX_BYTES', 25 * 1024 * 1024 * 1024),
        'restore_upload_max_bytes' => (int) env('MEDISMART_BACKUP_RESTORE_UPLOAD_MAX_BYTES', 25 * 1024 * 1024 * 1024),
        'prepared_restore_retention_hours' => (int) env('MEDISMART_PREPARED_RESTORE_RETENTION_HOURS', 168),
        // Archives above this size go to Google Drive with the resumable
        // protocol, in chunks (a multiple of 256 KiB), instead of a single
        // multipart request.
        'drive_resumable_threshold_bytes' => (int) env('MEDISMART_DRIVE_RESUMABLE_THRESHOLD_BYTES', 5 * 1024 * 1024),
        'drive_upload_chunk_bytes' => (int) env('MEDISMART_DRIVE_UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024),
    ],

    'http' => [
        // CA bundle (PEM) for outgoing HTTPS calls such as Google Drive. The
        // bundled PHP for Windows has no certificate store of its own, so the
        // desktop shell points this at the bundle it ships. Null: PHP default.
        'ca_bundle' => env('MEDISMART_CA_BUNDLE'),
    ],

    'uploads' => [
        'expires_after_minutes' => (int) env('MEDISMART_UPLOAD_EXPIRY_MINUTES', 15),
        'maximum_files' => (int) env('MEDISMART_UPLOAD_MAX_FILES', 10),
        'maximum_individual_bytes' => (int) env('MEDISMART_UPLOAD_MAX_FILE_BYTES', 20 * 1024 * 1024),
        'maximum_total_bytes' => (int) env('MEDISMART_UPLOAD_MAX_TOTAL_BYTES', 100 * 1024 * 1024),
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],
    ],

    'licensing' => [
        'product' => env('MEDISMART_LICENSE_PRODUCT', 'medismart-desktop'),
        'activation_url' => env('MEDISMART_LICENSE_ACTIVATION_URL'),
        'status_url' => env('MEDISMART_LICENSE_STATUS_URL'),
        'deactivation_url' => env('MEDISMART_LICENSE_DEACTIVATION_URL'),
        // Every signed licensing artefact is verified against this RSA public
        // key, with no network access. The default lives under config/ so it
        // ships inside the desktop payload; a build without the file simply
        // cannot verify (and so cannot be activated by) an entitlement.
        'public_key_path' => env('MEDISMART_LICENSE_PUBLIC_KEY_PATH') ?: 'config/licensing/entitlement-public.pem',
        // Online service only: the private key that signs the entitlement an
        // installed desktop receives when it redeems its activation code. It
        // must never be configured on a desktop (VerificationKey refuses any
        // private key, and CabinetEntitlementIssuer refuses to run on a Hub).
        'entitlement_signing_key_path' => env('MEDISMART_ENTITLEMENT_SIGNING_KEY_PATH'),
        'entitlement_signing_key_passphrase' => env('MEDISMART_ENTITLEMENT_SIGNING_KEY_PASSPHRASE'),
        'fingerprint_pepper' => env('MEDISMART_FINGERPRINT_PEPPER') ?: env('APP_KEY'),
        'clock_rollback_tolerance_hours' => (int) env('MEDISMART_LICENSE_CLOCK_TOLERANCE_HOURS', 6),
    ],
];
