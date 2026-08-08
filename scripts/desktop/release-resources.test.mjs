import assert from 'node:assert/strict';
import {
    mkdtemp,
    mkdir,
    readFile,
    rm,
    symlink,
    writeFile,
} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
    validateCleanVmEvidence,
    validateUpdaterReleaseManifest,
} from './check-release-readiness.mjs';
import { createSafeReleaseResourceFixture } from './release-resource-fixture.mjs';
import {
    MIGRATION_HELPER_PATH,
    REQUIRED_PHP_EXTENSIONS,
    assertReplacePolicy,
    canonicalMigrationSetSha256,
    compareUtf8Bytes,
    inspectTree,
    isForbiddenReleaseSourcePath,
    sha256File,
    validateManifestInventory,
    validateMigrationContractData,
    validatePhpExtensions,
    validateReleaseResources,
    validateSeedInspection,
} from './release-resources.mjs';

async function temporaryDirectory(t) {
    const directory = await mkdtemp(
        path.join(os.tmpdir(), 'medismart-release-resource-test-'),
    );
    t.after(async () => {
        await rm(directory, { recursive: true, force: true });
    });

    return directory;
}

test('tree inspection fails closed on a symbolic-link escape', async (t) => {
    const root = await temporaryDirectory(t);
    const outside = await temporaryDirectory(t);
    await writeFile(path.join(outside, 'secret.txt'), 'must not be staged');

    try {
        await symlink(outside, path.join(root, 'escaped'), 'junction');
    } catch (error) {
        if (error?.code === 'EPERM' || error?.code === 'EACCES') {
            t.skip('the current Windows account cannot create test symlinks');

            return;
        }

        throw error;
    }

    await assert.rejects(inspectTree(root), /symbolic links|reparse points/i);
});

test('production allowlist excludes merge, editor, map, patch, and fixture artifacts', () => {
    for (const relative of [
        'app/Controller.php.orig',
        'app/Controller.php.rej',
        'app/change.patch',
        'app/change.diff',
        'app/.Controller.php.swp',
        'app/Controller.php~',
        'public/build/assets/app.js.map',
        'resources/views/__fixtures__/patient.blade.php',
        'resources/views/patient.test.php',
    ]) {
        assert.equal(isForbiddenReleaseSourcePath(relative), true, relative);
    }

    assert.equal(
        isForbiddenReleaseSourcePath(
            'app/Http/Controllers/PatientController.php',
        ),
        false,
    );
});

test('manifest validation refuses an unexpected staged file', async (t) => {
    const root = await temporaryDirectory(t);
    await writeFile(path.join(root, 'approved.txt'), 'approved');
    const approvedHash = await sha256File(path.join(root, 'approved.txt'));
    const manifest = {
        directories: [],
        files: { 'approved.txt': approvedHash },
    };
    await writeFile(path.join(root, 'unexpected.log'), 'contamination');
    await assert.rejects(
        validateManifestInventory(
            root,
            manifest,
            '__manifest__.json',
            'test manifest',
        ),
        /inventory mismatch.*unexpected\.log/i,
    );
});

test('manifest validation refuses a file hash mismatch', async (t) => {
    const root = await temporaryDirectory(t);
    await writeFile(path.join(root, 'approved.txt'), 'tampered after approval');
    const manifest = {
        directories: [],
        files: { 'approved.txt': '0'.repeat(64) },
    };
    await assert.rejects(
        validateManifestInventory(
            root,
            manifest,
            '__manifest__.json',
            'test manifest',
        ),
        /hash mismatch.*approved\.txt/i,
    );
});

test('PHP review refuses a missing required extension', () => {
    const withoutSqlite = REQUIRED_PHP_EXTENSIONS.filter(
        (extension) => extension !== 'pdo_sqlite',
    );
    assert.throws(
        () => validatePhpExtensions(withoutSqlite),
        /missing required extensions: pdo_sqlite/i,
    );
});

function cleanSeedInspection() {
    return {
        integrity: 'ok',
        journal_mode: 'delete',
        sensitive_configuration_rows: 0,
        counts: {
            migrations: 5,
            roles: 2,
            permissions: 8,
            medications: 4,
            exams: 3,
            cabinet_settings: 1,
            application_settings: 0,
            backup_records: 0,
            cloud_connections: 0,
            consultations: 0,
            documents: 0,
            failed_jobs: 0,
            jobs: 0,
            licenses: 0,
            prescriptions: 0,
            tunnel_settings: 0,
            upload_sessions: 0,
            users: 0,
            patients: 0,
            appointments: 0,
            encounters: 0,
        },
    };
}

test('SQLite template validation refuses a seeded user', () => {
    const inspection = cleanSeedInspection();
    inspection.counts.users = 1;
    assert.throws(
        () => validateSeedInspection(inspection, 5),
        /contains user, clinical, credential, or runtime data in users/i,
    );
});

test('SQLite template validation refuses seeded clinical data', () => {
    const inspection = cleanSeedInspection();
    inspection.counts.patients = 1;
    assert.throws(
        () => validateSeedInspection(inspection, 5),
        /contains user, clinical, credential, or runtime data in patients/i,
    );
});

test('SQLite template validation refuses preconfigured clinic identity', () => {
    const inspection = cleanSeedInspection();
    inspection.sensitive_configuration_rows = 1;
    assert.throws(
        () => validateSeedInspection(inspection, 5),
        /generic cabinet row with no clinic identity or document branding/i,
    );
});

test('existing resource tree is untouched without explicit replace', async (t) => {
    const root = await temporaryDirectory(t);
    const output = path.join(root, 'resources');
    await mkdir(output);
    await writeFile(path.join(output, 'sentinel.txt'), 'keep me');
    await assert.rejects(
        assertReplacePolicy(output, false),
        /refusing replacement without --replace/i,
    );
    assert.equal(
        await readFile(path.join(output, 'sentinel.txt'), 'utf8'),
        'keep me',
    );
});

function migrationFixture() {
    const migrations = [
        {
            path: 'database/migrations/2026_01_01_000000_create_alpha_table.php',
            sha256: 'a'.repeat(64),
        },
        {
            path: 'database/migrations/2026_01_02_000000_create_beta_table.php',
            sha256: 'b'.repeat(64),
        },
    ];
    const laravelFiles = Object.fromEntries([
        [MIGRATION_HELPER_PATH, 'c'.repeat(64)],
        ...migrations.map((migration) => [migration.path, migration.sha256]),
    ]);
    const contract = {
        schema_version: 1,
        application_version: '1.2.3',
        initial_database_sha256: 'd'.repeat(64),
        migration_helper: {
            path: MIGRATION_HELPER_PATH,
            sha256: laravelFiles[MIGRATION_HELPER_PATH],
        },
        migrations,
        migration_set_sha256: canonicalMigrationSetSha256(migrations),
    };
    const bindings = {
        applicationVersion: '1.2.3',
        initialDatabaseSha256: contract.initial_database_sha256,
        migrationCount: migrations.length,
        laravelFiles,
    };

    return { bindings, contract };
}

test('migration contract refuses reordered migrations even with a recomputed set hash', () => {
    const { bindings, contract } = migrationFixture();
    contract.migrations.reverse();
    contract.migration_set_sha256 = canonicalMigrationSetSha256(
        contract.migrations,
    );

    assert.throws(
        () => validateMigrationContractData(contract, bindings),
        /strict lexical order|does not match the Laravel inventory/i,
    );
});

test('migration contract ordering matches Rust UTF-8 byte ordering', () => {
    const { bindings, contract } = migrationFixture();
    const first = contract.migrations[0];
    const second = contract.migrations[1];
    first.path = 'database/migrations/2026_01_01_000000_create_0_table.php';
    second.path = 'database/migrations/2026_01_01_000000_create__table.php';
    bindings.laravelFiles = Object.fromEntries([
        [MIGRATION_HELPER_PATH, contract.migration_helper.sha256],
        ...contract.migrations.map((migration) => [
            migration.path,
            migration.sha256,
        ]),
    ]);

    assert.ok(compareUtf8Bytes(first.path, second.path) < 0);
    assert.ok(first.path.localeCompare(second.path, 'en') > 0);
    contract.migration_set_sha256 = canonicalMigrationSetSha256(
        contract.migrations,
    );

    assert.doesNotThrow(() =>
        validateMigrationContractData(contract, bindings),
    );
});

test('migration contract refuses a mismatched fixed helper hash', () => {
    const { bindings, contract } = migrationFixture();
    contract.migration_helper.sha256 = 'e'.repeat(64);

    assert.throws(
        () => validateMigrationContractData(contract, bindings),
        /helper hash does not match the Laravel inventory/i,
    );
});

test('migration contract refuses a missing or extra Laravel migration', () => {
    const { bindings, contract } = migrationFixture();
    contract.migrations.pop();
    contract.migration_set_sha256 = canonicalMigrationSetSha256(
        contract.migrations,
    );

    assert.throws(
        () => validateMigrationContractData(contract, bindings),
        /count does not match/i,
    );
});

test('complete synthetic resource tree passes static validation without external binaries', async (t) => {
    const root = await temporaryDirectory(t);
    const resourcesRoot = path.join(root, 'resources');

    await createSafeReleaseResourceFixture(resourcesRoot);
    await validateReleaseResources(resourcesRoot, {
        expectedApplicationVersion: '0.0.0-fixture',
    });
});

function cleanVmEvidence() {
    return {
        schema_version: 2,
        application_version: '1.2.3',
        installer_sha256: 'a'.repeat(64),
        resource_manifest_sha256: 'b'.repeat(64),
        updater_artifact_sha256: 'c'.repeat(64),
        updater_manifest_sha256: 'd'.repeat(64),
        updater_signature_sha256: 'e'.repeat(64),
        tested_at: '2026-08-05T10:00:00.000Z',
        windows: 'Windows 11 24H2 clean VM',
        checks: {
            install: 'pass',
            first_launch: 'pass',
            offline_restart: 'pass',
            local_backup_restore: 'pass',
            signed_update: 'not-applicable-first-release',
            upgrade: 'not-applicable-first-release',
            uninstall_data_policy: 'pass',
        },
    };
}

function cleanVmBindings(overrides = {}) {
    return {
        applicationVersion: '1.2.3',
        installerSha256: 'a'.repeat(64),
        resourceManifestSha256: 'b'.repeat(64),
        updaterArtifactSha256: 'c'.repeat(64),
        updaterManifestSha256: 'd'.repeat(64),
        updaterSignatureSha256: 'e'.repeat(64),
        now: Date.parse('2026-08-05T11:00:00.000Z'),
        ...overrides,
    };
}

function updaterManifest(signature = 'Q'.repeat(44)) {
    return {
        version: '1.2.3',
        notes: 'Correctifs de sécurité.',
        pub_date: '2026-08-05T10:00:00.000Z',
        platforms: {
            'windows-x86_64-nsis': {
                url: 'https://updates.example.test/Drclick-1.2.3.nsis.zip',
                signature,
            },
        },
    };
}

test('release readiness accepts an exact static signed updater contract', () => {
    assert.doesNotThrow(() =>
        validateUpdaterReleaseManifest(updaterManifest(), {
            applicationVersion: '1.2.3',
            target: 'windows-x86_64-nsis',
            artifactUrl:
                'https://updates.example.test/Drclick-1.2.3.nsis.zip',
            signature: 'Q'.repeat(44),
        }),
    );
});

test('release readiness refuses updater signature or publication URL drift', () => {
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(updaterManifest(), {
                applicationVersion: '1.2.3',
                target: 'windows-x86_64-nsis',
                artifactUrl:
                    'https://updates.example.test/Drclick-1.2.3.nsis.zip',
                signature: 'R'.repeat(44),
            }),
        /signature does not match/i,
    );

    const manifest = updaterManifest();
    manifest.platforms['windows-x86_64-nsis'].url += '?temporary=1';

    assert.throws(
        () =>
            validateUpdaterReleaseManifest(manifest, {
                applicationVersion: '1.2.3',
                target: 'windows-x86_64-nsis',
                artifactUrl: manifest.platforms['windows-x86_64-nsis'].url,
                signature: 'Q'.repeat(44),
            }),
        /static HTTPS/i,
    );
});

test('release readiness refuses evidence bound to a different installer', () => {
    assert.throws(
        () =>
            validateCleanVmEvidence(
                cleanVmEvidence(),
                cleanVmBindings({
                    installerSha256: 'c'.repeat(64),
                }),
            ),
        /does not bind the inspected installer/i,
    );
});

test('release readiness refuses a failed clean-VM check', () => {
    const evidence = cleanVmEvidence();
    evidence.checks.offline_restart = 'fail';

    assert.throws(
        () => validateCleanVmEvidence(evidence, cleanVmBindings()),
        /offline_restart did not pass/i,
    );
});

test('release readiness refuses evidence bound to another updater artifact', () => {
    assert.throws(
        () =>
            validateCleanVmEvidence(
                cleanVmEvidence(),
                cleanVmBindings({ updaterArtifactSha256: 'f'.repeat(64) }),
            ),
        /does not bind the inspected updater artifact/i,
    );
});

test('release readiness keeps first-release updater and upgrade results consistent', () => {
    const evidence = cleanVmEvidence();
    evidence.checks.signed_update = 'pass';

    assert.throws(
        () => validateCleanVmEvidence(evidence, cleanVmBindings()),
        /same first-release result/i,
    );
});
