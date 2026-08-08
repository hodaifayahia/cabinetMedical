<?php

return [
    'version' => env('MEDISMART_VERSION', '0.1.0-dev'),

    'development' => [
        // Explicit local opt-in only. Production ignores this setting even if
        // a hostile environment attempts to enable it.
        'seed_demo_user' => (bool) env('MEDISMART_SEED_DEMO_USER', false),
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
        'default_idle_lock_minutes' => (int) env('MEDISMART_IDLE_LOCK_MINUTES', 15),
        'maximum_idle_lock_minutes' => (int) env('MEDISMART_MAXIMUM_IDLE_LOCK_MINUTES', 60),
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
        'public_key_path' => env('MEDISMART_LICENSE_PUBLIC_KEY_PATH'),
        'fingerprint_pepper' => env('MEDISMART_FINGERPRINT_PEPPER') ?: env('APP_KEY'),
        'clock_rollback_tolerance_hours' => (int) env('MEDISMART_LICENSE_CLOCK_TOLERANCE_HOURS', 6),
    ],
];
