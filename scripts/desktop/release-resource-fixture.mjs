import { mkdtemp, mkdir, rm, stat, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    DATABASE_MANIFEST,
    LARAVEL_MANIFEST,
    MIGRATION_CONTRACT,
    MIGRATION_HELPER_PATH,
    PHP_MANIFEST,
    PHP_REVIEW_PRODUCT,
    REQUIRED_PHP_EXTENSIONS,
    RESOURCE_MANIFEST,
    canonicalMigrationSetSha256,
    compareUtf8Bytes,
    inspectTree,
    sha256File,
    validateReleaseResources,
} from './release-resources.mjs';

async function writeJson(target, value) {
    await mkdir(path.dirname(target), { recursive: true });
    await writeFile(target, `${JSON.stringify(value, null, 2)}\n`, {
        encoding: 'utf8',
        flag: 'wx',
    });
}

async function writeFixtureFile(root, relative, contents = '<?php\n') {
    const target = path.join(root, ...relative.split('/'));
    await mkdir(path.dirname(target), { recursive: true });
    await writeFile(target, contents, { flag: 'wx' });
}

export async function createSafeReleaseResourceFixture(
    resourcesRoot,
    { applicationVersion = '0.0.0-fixture' } = {},
) {
    await mkdir(resourcesRoot, { recursive: false });
    await writeFixtureFile(
        resourcesRoot,
        'README.md',
        'Synthetic CI fixture. Contains no executable runtime or clinic data.\n',
    );

    const laravelRoot = path.join(resourcesRoot, 'laravel');
    await mkdir(laravelRoot, { recursive: false });
    const laravelFiles = [
        'artisan',
        'composer.json',
        'composer.lock',
        'config/queue.php',
        'routes/console.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'public/index.php',
        'vendor/autoload.php',
        'vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php',
        'vendor/laravel/framework/src/Illuminate/Queue/Worker.php',
        'vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleWorkCommand.php',
        'vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php',
        'vendor/laravel/framework/src/Illuminate/Console/Scheduling/Schedule.php',
        'app/Console/Commands/NativeApplyOfflineRestore.php',
        MIGRATION_HELPER_PATH,
        'app/Backups/OfflineRestoreExecutor.php',
        'app/Backups/PreparedRestore.php',
        'app/Backups/SupervisorOfflineRestoreGuard.php',
    ];

    for (const relative of laravelFiles) {
        const contents = relative.endsWith('.json')
            ? '{}\n'
            : relative === 'composer.lock'
              ? '{}\n'
              : relative === 'composer.json'
                ? '{}\n'
                : '<?php\n';
        await writeFixtureFile(laravelRoot, relative, contents);
    }

    await writeFixtureFile(
        laravelRoot,
        'public/build/manifest.json',
        `${JSON.stringify({
            'resources/js/app.ts': {
                file: 'assets/app.js',
                isEntry: true,
            },
        })}\n`,
    );
    await writeFixtureFile(
        laravelRoot,
        'public/build/assets/app.js',
        'export {};\n',
    );
    const laravelInventory = await inspectTree(laravelRoot);
    await writeJson(path.join(laravelRoot, LARAVEL_MANIFEST), {
        schema_version: 1,
        composer_lock_sha256: await sha256File(
            path.join(laravelRoot, 'composer.lock'),
        ),
        vite_manifest_sha256: await sha256File(
            path.join(laravelRoot, 'public', 'build', 'manifest.json'),
        ),
        directories: laravelInventory.directories,
        files: laravelInventory.files,
    });

    const phpRoot = path.join(resourcesRoot, 'php');
    await mkdir(phpRoot, { recursive: false });
    await writeFixtureFile(phpRoot, 'php.exe', Buffer.from('MZ-ci-fixture'));
    const phpInventory = await inspectTree(phpRoot);
    await writeJson(path.join(phpRoot, PHP_MANIFEST), {
        schema_version: 1,
        product: PHP_REVIEW_PRODUCT,
        version: '8.3.0',
        architecture: 'x64',
        extensions: [...REQUIRED_PHP_EXTENSIONS],
        required_extensions: [...REQUIRED_PHP_EXTENSIONS],
        review_manifest_sha256: 'a'.repeat(64),
        directories: phpInventory.directories,
        files: phpInventory.files,
    });

    const cloudflaredRoot = path.join(resourcesRoot, 'cloudflared');
    await mkdir(cloudflaredRoot, { recursive: false });
    await writeFixtureFile(
        cloudflaredRoot,
        'cloudflared.exe',
        Buffer.from('MZ-cloudflared-ci-fixture'),
    );
    await writeJson(path.join(cloudflaredRoot, 'cloudflared.manifest.json'), {
        schema_version: 1,
        version: '2026.8.0',
        sha256: await sha256File(path.join(cloudflaredRoot, 'cloudflared.exe')),
    });

    const initialRoot = path.join(resourcesRoot, 'initial');
    await mkdir(initialRoot, { recursive: false });
    await mkdir(path.join(initialRoot, 'storage'), { recursive: false });
    await writeFixtureFile(
        initialRoot,
        'database.sqlite',
        Buffer.from('SQLite format 3\0synthetic-ci-fixture'),
    );
    const databasePath = path.join(initialRoot, 'database.sqlite');
    const databaseSha256 = await sha256File(databasePath);
    const databaseStat = await stat(databasePath);
    await writeJson(path.join(initialRoot, DATABASE_MANIFEST), {
        schema_version: 1,
        sha256: databaseSha256,
        size: databaseStat.size,
        migration_count: 1,
        reference_seed_counts: {
            exams: 1,
            medications: 1,
            permissions: 1,
            roles: 1,
        },
        empty_table_count: 1,
    });
    const migrations = Object.entries(laravelInventory.files)
        .filter(([relative]) => relative.startsWith('database/migrations/'))
        .map(([migrationPath, sha256]) => ({ path: migrationPath, sha256 }))
        .sort((left, right) => compareUtf8Bytes(left.path, right.path));
    await writeJson(path.join(initialRoot, MIGRATION_CONTRACT), {
        schema_version: 1,
        application_version: applicationVersion,
        initial_database_sha256: databaseSha256,
        migration_helper: {
            path: MIGRATION_HELPER_PATH,
            sha256: laravelInventory.files[MIGRATION_HELPER_PATH],
        },
        migrations,
        migration_set_sha256: canonicalMigrationSetSha256(migrations),
    });

    const rootInventory = await inspectTree(resourcesRoot);
    await writeJson(path.join(resourcesRoot, RESOURCE_MANIFEST), {
        schema_version: 1,
        directories: rootInventory.directories,
        files: rootInventory.files,
    });

    return resourcesRoot;
}

async function main() {
    const temporaryRoot = await mkdtemp(
        path.join(os.tmpdir(), 'medismart-release-fixture-'),
    );
    const fixture = path.join(temporaryRoot, 'resources');

    try {
        await createSafeReleaseResourceFixture(fixture);
        await validateReleaseResources(fixture, {
            expectedApplicationVersion: '0.0.0-fixture',
        });
        process.stdout.write(
            'Synthetic release-resource fixture passed static validation; no packaged binary was executed.\n',
        );
    } finally {
        await rm(temporaryRoot, { recursive: true, force: true });
    }
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : null;

if (invokedPath === path.resolve(fileURLToPath(import.meta.url))) {
    main().catch((error) => {
        process.stderr.write(
            `Synthetic release-resource fixture failed: ${error.message}\n`,
        );
        process.exitCode = 1;
    });
}
