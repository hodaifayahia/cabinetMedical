import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const supportDirectory = dirname(fileURLToPath(import.meta.url));
const requestedPort = process.env.PLAYWRIGHT_PORT ?? '4173';

if (!/^\d+$/u.test(requestedPort)) {
    throw new Error('PLAYWRIGHT_PORT must be a numeric TCP port.');
}

export const appPort = Number.parseInt(requestedPort, 10);

if (appPort < 1024 || appPort > 65535) {
    throw new Error('PLAYWRIGHT_PORT must be between 1024 and 65535.');
}

export const projectRoot = resolve(supportDirectory, '../../..');
export const runtimeDirectory = resolve(
    projectRoot,
    'storage/framework/testing/playwright',
);
export const databasePath = join(runtimeDirectory, 'database.sqlite');
export const backupDirectory = join(runtimeDirectory, 'backups');
export const laravelStorageDirectory = join(
    runtimeDirectory,
    'laravel-storage',
);
export const appUrl = `http://localhost:${appPort}`;
export const lanUploadUrl = `http://127.0.0.1:${appPort}`;

export const e2eEnvironment = Object.freeze({
    APP_CONFIG_CACHE: join(runtimeDirectory, 'cache/config.php'),
    APP_DEBUG: 'false',
    APP_ENV: 'e2e',
    APP_EVENTS_CACHE: join(runtimeDirectory, 'cache/events.php'),
    APP_FALLBACK_LOCALE: 'fr',
    APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    APP_LOCALE: 'fr',
    APP_PACKAGES_CACHE: join(runtimeDirectory, 'cache/packages.php'),
    APP_ROUTES_CACHE: join(runtimeDirectory, 'cache/routes.php'),
    APP_SERVICES_CACHE: join(runtimeDirectory, 'cache/services.php'),
    APP_TIMEZONE: 'Africa/Algiers',
    APP_URL: appUrl,
    BCRYPT_ROUNDS: '4',
    BROADCAST_CONNECTION: 'null',
    CACHE_PREFIX: 'medismart_e2e',
    CACHE_STORE: 'array',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    DB_FOREIGN_KEYS: 'true',
    DB_URL: '',
    FILESYSTEM_DISK: 'local',
    GOOGLE_CLIENT_ID: '',
    GOOGLE_CLIENT_SECRET: '',
    INERTIA_DEVTOOLS_ENABLED: 'false',
    LARAVEL_STORAGE_PATH: laravelStorageDirectory,
    LOG_CHANNEL: 'stderr',
    LOG_LEVEL: 'warning',
    MAIL_MAILER: 'array',
    MEDISMART_BACKUP_MANAGED_DIRECTORY: backupDirectory,
    MEDISMART_DESKTOP_SUPERVISED: 'true',
    MEDISMART_ENABLE_LEGACY_SQLITE_RESTORE: 'false',
    MEDISMART_LAN_LISTENER_STATUS: 'active',
    MEDISMART_LAN_UPLOAD_URL: lanUploadUrl,
    MEDISMART_LOCAL_URL: appUrl,
    MEDISMART_REMOTE_UPLOAD_URL: '',
    MEDISMART_SEED_DEMO_USER: 'false',
    QUEUE_CONNECTION: 'sync',
    SESSION_COOKIE: 'medismart_e2e_session',
    SESSION_DRIVER: 'database',
    SESSION_ENCRYPT: 'true',
    TELESCOPE_ENABLED: 'false',
    VIEW_COMPILED_PATH: join(runtimeDirectory, 'views'),
});

export const assertRuntimeIsolation = () => {
    const expectedRuntimeDirectory = resolve(
        projectRoot,
        'storage/framework/testing/playwright',
    );

    if (runtimeDirectory !== expectedRuntimeDirectory) {
        throw new Error('The Playwright runtime directory is not isolated.');
    }

    if (databasePath !== join(expectedRuntimeDirectory, 'database.sqlite')) {
        throw new Error('The Playwright database path is not isolated.');
    }

    if (backupDirectory !== join(expectedRuntimeDirectory, 'backups')) {
        throw new Error('The Playwright backup path is not isolated.');
    }

    if (
        laravelStorageDirectory !==
        join(expectedRuntimeDirectory, 'laravel-storage')
    ) {
        throw new Error('The Playwright Laravel storage path is not isolated.');
    }
};
