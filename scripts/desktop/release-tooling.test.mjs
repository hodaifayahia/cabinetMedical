import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
    validateCleanVmEvidence,
    validateUpdaterReleaseManifest,
} from './check-release-readiness.mjs';
import {
    REQUIRED_PHP_EXTENSIONS,
    assertReplacePolicy,
    assertWindowsHost,
    canonicalMigrationSetSha256,
    compareUtf8Bytes,
    inspectTree,
    isForbiddenReleaseSourcePath,
    normalizeReleasePath,
    parseCliArguments,
    requireCliArguments,
    sha256File,
    validateManifestInventory,
    validatePhpExtensions,
    validatePhpReviewManifest,
} from './release-resources.mjs';

async function temporaryDirectory(t) {
    const directory = await mkdtemp(
        path.join(os.tmpdir(), 'drclick-release-tooling-test-'),
    );
    t.after(async () => {
        await rm(directory, { recursive: true, force: true });
    });

    return directory;
}

const sha256 = (value) => createHash('sha256').update(value).digest('hex');

// ---------------------------------------------------------------------------
// CLI parsing
// ---------------------------------------------------------------------------

test('CLI parsing reads value and boolean options', () => {
    assert.deepEqual(
        parseCliArguments(
            ['--output', 'dist', '--replace', '--version', '1.2.3'],
            new Set(['replace']),
            new Set(['output', 'version']),
        ),
        { output: 'dist', replace: true, version: '1.2.3' },
    );
});

test('CLI parsing accepts any option name when no allowlist is given', () => {
    assert.deepEqual(parseCliArguments(['--anything', 'value']), {
        anything: 'value',
    });
});

test('CLI parsing refuses positional arguments', () => {
    assert.throws(
        () => parseCliArguments(['dist']),
        /unexpected positional argument: dist/,
    );
});

test('CLI parsing refuses duplicate options', () => {
    assert.throws(
        () => parseCliArguments(['--output', 'a', '--output', 'b']),
        /duplicate option: --output/,
    );
});

test('CLI parsing refuses unknown options when an allowlist is given', () => {
    assert.throws(
        () =>
            parseCliArguments(
                ['--outptu', 'dist'],
                new Set(),
                new Set(['output']),
            ),
        /unknown option: --outptu/,
    );
});

test('CLI parsing refuses an option with a missing value', () => {
    assert.throws(
        () => parseCliArguments(['--output']),
        /missing value for --output/,
    );
    assert.throws(
        () =>
            parseCliArguments(['--output', '--replace'], new Set(['replace'])),
        /missing value for --output/,
    );
});

test('CLI parsing never consumes a value for a boolean flag', () => {
    assert.throws(
        () => parseCliArguments(['--replace', 'yes'], new Set(['replace'])),
        /unexpected positional argument: yes/,
    );
});

test('required CLI arguments must be non-blank strings', () => {
    assert.doesNotThrow(() =>
        requireCliArguments({ output: 'dist' }, ['output']),
    );
    assert.throws(
        () => requireCliArguments({}, ['output']),
        /--output is required/,
    );
    assert.throws(
        () => requireCliArguments({ output: '   ' }, ['output']),
        /--output is required/,
    );
    assert.throws(
        () => requireCliArguments({ output: true }, ['output']),
        /--output is required/,
    );
});

test('staging refuses to run on a non-Windows host', () => {
    assert.doesNotThrow(() => assertWindowsHost('win32'));

    for (const platform of ['linux', 'darwin', 'freebsd']) {
        assert.throws(
            () => assertWindowsHost(platform),
            /controlled Windows build host/,
        );
    }
});

// ---------------------------------------------------------------------------
// Release paths
// ---------------------------------------------------------------------------

test('release paths are normalized to forward slashes', () => {
    assert.equal(
        normalizeReleasePath('app\\Http\\Kernel.php'),
        'app/Http/Kernel.php',
    );
    assert.equal(normalizeReleasePath('public/index.php'), 'public/index.php');
});

test('release paths refuse absolute and drive-qualified forms', () => {
    for (const candidate of [
        '/etc/passwd',
        'C:/Windows',
        'c:relative',
        '\\\\server\\share',
    ]) {
        assert.throws(
            () => normalizeReleasePath(candidate),
            /absolute path is not permitted/,
            candidate,
        );
    }
});

test('release paths refuse traversal, empty segments, and Windows-hostile names', () => {
    for (const candidate of [
        '../secret',
        'app/../../secret',
        './app',
        'app//Kernel.php',
        'app/',
        'trailing-dot.',
        'trailing-space ',
        'CON',
        'con.txt',
        'lpt1.log',
        'nul',
        'aux.php',
        'com9',
        'what?.php',
        'pipe|name',
        'colon:name',
        'quote".php',
        'ctrl\u0001char',
    ]) {
        assert.throws(
            () => normalizeReleasePath(candidate),
            /non-portable Windows path/,
            JSON.stringify(candidate),
        );
    }
});

test('release paths refuse non-string or empty input', () => {
    for (const candidate of ['', null, undefined, 7]) {
        assert.throws(
            () => normalizeReleasePath(candidate),
            /non-empty string/,
        );
    }
});

test('reserved-name detection does not reject longer ordinary names', () => {
    for (const candidate of [
        'console.php',
        'comment.txt',
        'auxiliary',
        'com10',
        'nulls',
    ]) {
        assert.equal(normalizeReleasePath(candidate), candidate);
    }
});

test('forbidden source detection covers editor, backup, and test artifacts', () => {
    for (const relative of [
        'app/Model.php.bak',
        'app/Model.php.old',
        'app/Model.php.save',
        'app/Model.php.tmp',
        'app/Model.php.temp',
        'app/.Model.php.swo',
        'app/#Model.php#',
        'tests/Feature/LoginTest.php',
        'app/test/Helper.php',
        'resources/js/__tests__/x.ts',
        'database/fixtures/seed.sql',
        'resources/js/lib/http.spec.ts',
        'resources/js/lib/http.test.js',
        'APP\\MODEL.PHP.ORIG',
    ]) {
        assert.equal(isForbiddenReleaseSourcePath(relative), true, relative);
    }
});

test('forbidden source detection allows ordinary production files', () => {
    for (const relative of [
        'app/Http/Controllers/TestimonialController.php',
        'app/Support/Attestation.php',
        'public/build/assets/app.js',
        'resources/views/latest.blade.php',
        'app/Contest/Entry.php',
    ]) {
        assert.equal(isForbiddenReleaseSourcePath(relative), false, relative);
    }
});

// ---------------------------------------------------------------------------
// Byte ordering and digests
// ---------------------------------------------------------------------------

test('UTF-8 byte comparison orders by bytes, not locale', () => {
    assert.equal(compareUtf8Bytes('a', 'a'), 0);
    assert.ok(compareUtf8Bytes('B', 'a') < 0);
    assert.ok(compareUtf8Bytes('z', 'é') < 0);
    assert.ok(compareUtf8Bytes('abc', 'ab') > 0);
    assert.deepEqual(['é', 'b', 'A', 'a'].sort(compareUtf8Bytes), [
        'A',
        'a',
        'b',
        'é',
    ]);
});

test('the canonical migration digest is stable and order-sensitive', () => {
    const first = {
        path: 'database/migrations/0001_a.php',
        sha256: 'a'.repeat(64),
    };
    const second = {
        path: 'database/migrations/0002_b.php',
        sha256: 'b'.repeat(64),
    };
    const expected = sha256(
        `${first.path}\u0000${first.sha256}\n${second.path}\u0000${second.sha256}\n`,
    );

    assert.equal(canonicalMigrationSetSha256([first, second]), expected);
    assert.notEqual(canonicalMigrationSetSha256([second, first]), expected);
    assert.equal(canonicalMigrationSetSha256([]), sha256(''));
});

test('the canonical migration digest separates path and hash unambiguously', () => {
    assert.notEqual(
        canonicalMigrationSetSha256([{ path: 'ab', sha256: 'c' }]),
        canonicalMigrationSetSha256([{ path: 'a', sha256: 'bc' }]),
    );
});

test('file digests are lowercase SHA-256 of the raw bytes', async (t) => {
    const root = await temporaryDirectory(t);
    const file = path.join(root, 'payload.bin');
    await writeFile(file, Buffer.from([0, 1, 2, 255]));

    assert.equal(await sha256File(file), sha256(Buffer.from([0, 1, 2, 255])));
});

// ---------------------------------------------------------------------------
// Tree inspection and manifests
// ---------------------------------------------------------------------------

test('tree inspection lists sorted directories and hashes every file', async (t) => {
    const root = await temporaryDirectory(t);
    await mkdir(path.join(root, 'b', 'nested'), { recursive: true });
    await mkdir(path.join(root, 'a'));
    await writeFile(path.join(root, 'b', 'nested', 'two.txt'), 'two');
    await writeFile(path.join(root, 'one.txt'), 'one');

    assert.deepEqual(await inspectTree(root), {
        directories: ['a', 'b', 'b/nested'],
        files: {
            'b/nested/two.txt': sha256('two'),
            'one.txt': sha256('one'),
        },
    });
});

test('tree inspection honours its exclusion set', async (t) => {
    const root = await temporaryDirectory(t);
    await writeFile(path.join(root, 'manifest.json'), '{}');
    await writeFile(path.join(root, 'kept.txt'), 'kept');

    const tree = await inspectTree(root, {
        exclude: new Set(['manifest.json']),
    });

    assert.deepEqual(Object.keys(tree.files), ['kept.txt']);
});

test('tree inspection refuses a non-portable file name', async (t) => {
    const root = await temporaryDirectory(t);
    await writeFile(path.join(root, 'bad?.txt'), 'x');

    await assert.rejects(inspectTree(root), /non-portable Windows path/);
});

test('tree inspection refuses names that collide on a case-insensitive disk', async (t) => {
    const root = await temporaryDirectory(t);
    await writeFile(path.join(root, 'Readme.txt'), 'one');

    try {
        await writeFile(path.join(root, 'README.txt'), 'two', { flag: 'wx' });
    } catch (error) {
        if (error?.code === 'EEXIST') {
            t.skip('the test file system is case-insensitive');

            return;
        }

        throw error;
    }

    await assert.rejects(
        inspectTree(root),
        /case-insensitive release path collision/,
    );
});

test('tree inspection refuses a root that is not a directory', async (t) => {
    const root = await temporaryDirectory(t);
    const file = path.join(root, 'file.txt');
    await writeFile(file, 'x');

    await assert.rejects(inspectTree(file), /must be a real directory/);
    await assert.rejects(
        inspectTree(path.join(root, 'missing')),
        /is unavailable/,
    );
});

test('manifest validation accepts an exact inventory while skipping the manifest itself', async (t) => {
    const root = await temporaryDirectory(t);
    await mkdir(path.join(root, 'sub'));
    await writeFile(path.join(root, 'sub', 'a.txt'), 'a');
    await writeFile(path.join(root, '__manifest__.json'), '{}');

    await assert.doesNotReject(
        validateManifestInventory(
            root,
            { directories: ['sub'], files: { 'sub/a.txt': sha256('a') } },
            '__manifest__.json',
            'test manifest',
        ),
    );
});

test('manifest validation reports a missing file', async (t) => {
    const root = await temporaryDirectory(t);

    await assert.rejects(
        validateManifestInventory(
            root,
            { directories: [], files: { 'gone.txt': sha256('x') } },
            null,
            'test manifest',
        ),
        /file inventory mismatch \(unexpected: none; missing: gone\.txt\)/,
    );
});

test('manifest validation reports a directory mismatch', async (t) => {
    const root = await temporaryDirectory(t);
    await mkdir(path.join(root, 'extra'));

    await assert.rejects(
        validateManifestInventory(
            root,
            { directories: [], files: {} },
            null,
            'test manifest',
        ),
        /directory inventory mismatch \(unexpected: extra; missing: none\)/,
    );
});

test('manifest validation refuses malformed inventory shapes before touching disk', async (t) => {
    const root = await temporaryDirectory(t);

    for (const [manifest, pattern] of [
        [{ directories: 'a', files: {} }, /directories must be an array/],
        [{ directories: [], files: [] }, /files must be an object/],
        [{ directories: [], files: { 'a.txt': 'ABC' } }, /lowercase SHA-256/],
        [
            { directories: ['dir', 'DIR'], files: {} },
            /duplicate or non-canonical directory: DIR/,
        ],
        [
            { directories: [], files: { 'a\\b.txt': sha256('x') } },
            /duplicate or non-canonical file/,
        ],
        [
            { directories: ['same'], files: { same: sha256('x') } },
            /duplicate or non-canonical file: same/,
        ],
    ]) {
        await assert.rejects(
            validateManifestInventory(root, manifest, null, 'test manifest'),
            pattern,
        );
    }
});

test('replacement policy allows a fresh destination and refuses an existing one without --replace', async (t) => {
    const root = await temporaryDirectory(t);
    const output = path.join(root, 'out');

    await assert.doesNotReject(assertReplacePolicy(output, false));

    await mkdir(output);

    await assert.rejects(
        assertReplacePolicy(output, false),
        /refusing replacement without --replace/,
    );
    await assert.doesNotReject(assertReplacePolicy(output, true));
});

test('replacement policy refuses to replace a plain file', async (t) => {
    const root = await temporaryDirectory(t);
    const output = path.join(root, 'out');
    await writeFile(output, 'not a directory');

    await assert.rejects(
        assertReplacePolicy(output, true),
        /must be a real directory/,
    );
});

// ---------------------------------------------------------------------------
// PHP runtime review
// ---------------------------------------------------------------------------

test('the required extension list is sorted, unique, and frozen', () => {
    assert.ok(Object.isFrozen(REQUIRED_PHP_EXTENSIONS));
    assert.deepEqual([...REQUIRED_PHP_EXTENSIONS].sort(), [
        ...REQUIRED_PHP_EXTENSIONS,
    ]);
    assert.equal(
        new Set(REQUIRED_PHP_EXTENSIONS).size,
        REQUIRED_PHP_EXTENSIONS.length,
    );
});

test('PHP extensions accept a sorted superset of the required list', () => {
    const extensions = [...REQUIRED_PHP_EXTENSIONS, 'bcmath'].sort();

    assert.deepEqual(validatePhpExtensions(extensions), extensions);
});

test('PHP extensions refuse malformed lists', () => {
    const required = [...REQUIRED_PHP_EXTENSIONS];

    assert.throws(
        () => validatePhpExtensions('curl'),
        /must be an array of extension names/,
    );
    assert.throws(
        () => validatePhpExtensions([...required.slice(0, -1), 7]),
        /must be an array of extension names/,
    );
    assert.throws(
        () => validatePhpExtensions([...required].reverse()),
        /lowercase, unique, and sorted/,
    );
    assert.throws(
        () => validatePhpExtensions([...required, 'zlib']),
        /lowercase, unique, and sorted/,
    );
    assert.throws(
        () =>
            validatePhpExtensions(
                required.map((name) => (name === 'curl' ? 'CURL' : name)),
            ),
        /lowercase, unique, and sorted/,
    );
});

test('PHP extensions name every missing required extension', () => {
    const without = REQUIRED_PHP_EXTENSIONS.filter(
        (name) => name !== 'openssl' && name !== 'zip',
    );

    assert.throws(
        () => validatePhpExtensions(without),
        /missing required extensions: openssl, zip/,
    );
});

function phpReview(overrides = {}) {
    return {
        schema_version: 1,
        product: 'php-windows-runtime',
        version: '8.3.12',
        architecture: 'x64',
        extensions: [...REQUIRED_PHP_EXTENSIONS],
        directories: ['ext'],
        files: {
            'php.exe': sha256('php'),
            'ext/php_intl.dll': sha256('intl'),
        },
        ...overrides,
    };
}

test('PHP review manifests accept the reviewed x64 8.x runtime', () => {
    for (const version of ['8.3.0', '8.4.1', '8.10.2', '8.3.12-nts']) {
        assert.doesNotThrow(
            () => validatePhpReviewManifest(phpReview({ version })),
            version,
        );
    }
});

test('PHP review manifests refuse unsupported PHP versions', () => {
    for (const version of [
        '8.2.9',
        '7.4.33',
        '9.0.0',
        '8.3',
        'php-8.3.1',
        83,
    ]) {
        assert.throws(
            () => validatePhpReviewManifest(phpReview({ version })),
            /PHP 8\.3 or newer/,
            String(version),
        );
    }
});

test('PHP review manifests refuse the wrong schema, product, or architecture', () => {
    assert.throws(
        () => validatePhpReviewManifest(phpReview({ schema_version: 2 })),
        /unsupported schema or product/,
    );
    assert.throws(
        () => validatePhpReviewManifest(phpReview({ product: 'php' })),
        /unsupported schema or product/,
    );
    assert.throws(
        () => validatePhpReviewManifest(phpReview({ architecture: 'x86' })),
        /x64 Windows build/,
    );
});

test('PHP review manifests must contain php.exe and exactly the known keys', () => {
    assert.throws(
        () =>
            validatePhpReviewManifest(
                phpReview({ files: { 'php-cgi.exe': sha256('x') } }),
            ),
        /does not contain php\.exe/,
    );
    assert.throws(
        () => validatePhpReviewManifest({ ...phpReview(), signed: true }),
        /must contain exactly/,
    );
    assert.throws(
        () => validatePhpReviewManifest(null),
        /must be a JSON object/,
    );
});

// ---------------------------------------------------------------------------
// Updater manifest
// ---------------------------------------------------------------------------

const SIGNATURE = 'Q'.repeat(44);
const ARTIFACT = 'https://updates.example.test/Drclick-1.2.3.nsis.zip';

function updaterManifest(overrides = {}) {
    return {
        version: '1.2.3',
        notes: 'Correctifs.',
        pub_date: '2026-08-05T10:00:00.000Z',
        platforms: {
            'windows-x86_64-nsis': { url: ARTIFACT, signature: SIGNATURE },
        },
        ...overrides,
    };
}

function updaterInputs(overrides = {}) {
    return {
        applicationVersion: '1.2.3',
        target: 'windows-x86_64-nsis',
        artifactUrl: ARTIFACT,
        signature: SIGNATURE,
        ...overrides,
    };
}

test('updater manifests accept every approved Windows target', () => {
    for (const target of [
        'windows-x86_64-nsis',
        'windows-x86_64-msi',
        'windows-aarch64-nsis',
        'windows-aarch64-msi',
    ]) {
        assert.doesNotThrow(() =>
            validateUpdaterReleaseManifest(
                updaterManifest({
                    platforms: {
                        [target]: { url: ARTIFACT, signature: SIGNATURE },
                    },
                }),
                updaterInputs({ target }),
            ),
        );
    }
});

test('updater manifests refuse unapproved targets', () => {
    for (const target of [
        'linux-x86_64',
        'darwin-aarch64',
        'windows-i686-nsis',
        '__proto__',
    ]) {
        assert.throws(
            () =>
                validateUpdaterReleaseManifest(
                    updaterManifest(),
                    updaterInputs({ target }),
                ),
            /approved Windows bundle/,
            target,
        );
    }
});

test('updater manifests refuse a version that differs from the release', () => {
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({ version: '1.2.4' }),
                updaterInputs(),
            ),
        /version does not match/,
    );
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({ version: 'v1.2.3' }),
                updaterInputs({ applicationVersion: 'v1.2.3' }),
            ),
        /version does not match/,
    );
});

test('updater manifests bound release notes', () => {
    assert.doesNotThrow(() =>
        validateUpdaterReleaseManifest(
            updaterManifest({ notes: 'x'.repeat(20_000) }),
            updaterInputs(),
        ),
    );
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({ notes: 'x'.repeat(20_001) }),
                updaterInputs(),
            ),
        /bounded string/,
    );
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({ notes: null }),
                updaterInputs(),
            ),
        /bounded string/,
    );
});

test('updater manifests require a parseable publication date', () => {
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({ pub_date: 'yesterday' }),
                updaterInputs(),
            ),
        /pub_date must be an ISO-8601 timestamp/,
    );
});

test('updater manifests refuse extra keys at either level', () => {
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                { ...updaterManifest(), channel: 'beta' },
                updaterInputs(),
            ),
        /updater manifest must contain exactly/,
    );
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({
                    platforms: {
                        'windows-x86_64-nsis': {
                            url: ARTIFACT,
                            signature: SIGNATURE,
                            with_elevated_task: true,
                        },
                    },
                }),
                updaterInputs(),
            ),
        /updater target windows-x86_64-nsis must contain exactly/,
    );
});

test('updater manifests refuse a missing platform entry', () => {
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({ platforms: {} }),
                updaterInputs(),
            ),
        /must be a JSON object/,
    );
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({ platforms: [] }),
                updaterInputs(),
            ),
        /platforms must be an object/,
    );
});

test('updater artifact URLs must be static HTTPS zip files', () => {
    for (const url of [
        'http://updates.example.test/Drclick.zip',
        'https://user:pass@updates.example.test/Drclick.zip',
        'https://updates.example.test:8443/Drclick.zip',
        'https://updates.example.test/Drclick.zip#fragment',
        'https://updates.example.test/Drclick.exe',
    ]) {
        assert.throws(
            () =>
                validateUpdaterReleaseManifest(
                    updaterManifest({
                        platforms: {
                            'windows-x86_64-nsis': {
                                url,
                                signature: SIGNATURE,
                            },
                        },
                    }),
                    updaterInputs({ artifactUrl: url }),
                ),
            /static HTTPS \.zip URL/,
            url,
        );
    }
});

test('updater artifact URLs must parse', () => {
    assert.throws(
        () =>
            validateUpdaterReleaseManifest(
                updaterManifest({
                    platforms: {
                        'windows-x86_64-nsis': {
                            url: 'not a url',
                            signature: SIGNATURE,
                        },
                    },
                }),
                updaterInputs({ artifactUrl: 'not a url' }),
            ),
        /valid HTTPS URL/,
    );
});

test('updater manifests accept an uppercase .ZIP extension', () => {
    const url = 'https://updates.example.test/DRCLICK.ZIP';

    assert.doesNotThrow(() =>
        validateUpdaterReleaseManifest(
            updaterManifest({
                platforms: {
                    'windows-x86_64-nsis': { url, signature: SIGNATURE },
                },
            }),
            updaterInputs({ artifactUrl: url }),
        ),
    );
});

test('updater signatures must be bounded base64', () => {
    for (const signature of [
        'Q'.repeat(36),
        'Q'.repeat(41),
        'Q'.repeat(4100),
        `${'Q'.repeat(40)}!!!!`,
        `${'Q'.repeat(40)}====`,
        42,
    ]) {
        assert.throws(
            () =>
                validateUpdaterReleaseManifest(
                    updaterManifest({
                        platforms: {
                            'windows-x86_64-nsis': {
                                url: ARTIFACT,
                                signature,
                            },
                        },
                    }),
                    updaterInputs({ signature }),
                ),
            /bounded Tauri base64 signature/,
            String(signature).slice(0, 12),
        );
    }
});

test('updater signatures accept base64 padding', () => {
    const signature = `${'Q'.repeat(42)}==`;

    assert.doesNotThrow(() =>
        validateUpdaterReleaseManifest(
            updaterManifest({
                platforms: {
                    'windows-x86_64-nsis': { url: ARTIFACT, signature },
                },
            }),
            updaterInputs({ signature }),
        ),
    );
});

// ---------------------------------------------------------------------------
// Clean-VM evidence
// ---------------------------------------------------------------------------

const NOW = Date.parse('2026-08-05T11:00:00.000Z');

function evidence(overrides = {}) {
    return {
        schema_version: 2,
        application_version: '1.2.3',
        installer_sha256: 'a'.repeat(64),
        resource_manifest_sha256: 'b'.repeat(64),
        updater_artifact_sha256: 'c'.repeat(64),
        updater_manifest_sha256: 'd'.repeat(64),
        updater_signature_sha256: 'e'.repeat(64),
        tested_at: '2026-08-05T10:00:00.000Z',
        windows: 'Windows 11 Pro 24H2',
        checks: {
            install: 'pass',
            first_launch: 'pass',
            offline_restart: 'pass',
            local_backup_restore: 'pass',
            signed_update: 'pass',
            upgrade: 'pass',
            uninstall_data_policy: 'pass',
        },
        ...overrides,
    };
}

function bindings(overrides = {}) {
    return {
        applicationVersion: '1.2.3',
        installerSha256: 'a'.repeat(64),
        resourceManifestSha256: 'b'.repeat(64),
        updaterArtifactSha256: 'c'.repeat(64),
        updaterManifestSha256: 'd'.repeat(64),
        updaterSignatureSha256: 'e'.repeat(64),
        now: NOW,
        ...overrides,
    };
}

test('clean-VM evidence accepts a complete passing upgrade run', () => {
    assert.doesNotThrow(() => validateCleanVmEvidence(evidence(), bindings()));
});

test('clean-VM evidence refuses an unsupported schema', () => {
    assert.throws(
        () =>
            validateCleanVmEvidence(
                evidence({ schema_version: 1 }),
                bindings(),
            ),
        /unsupported schema/,
    );
});

test('clean-VM evidence refuses another application version', () => {
    assert.throws(
        () =>
            validateCleanVmEvidence(
                evidence({ application_version: '1.2.2' }),
                bindings(),
            ),
        /application version does not match/,
    );
});

test('clean-VM evidence refuses uppercase or short digests', () => {
    assert.throws(
        () =>
            validateCleanVmEvidence(
                evidence({ installer_sha256: 'A'.repeat(64) }),
                bindings({ installerSha256: 'A'.repeat(64) }),
            ),
        /installer hash must be a lowercase SHA-256 digest/,
    );
    assert.throws(
        () =>
            validateCleanVmEvidence(
                evidence({ updater_signature_sha256: 'e'.repeat(63) }),
                bindings(),
            ),
        /updater signature hash must be a lowercase SHA-256 digest/,
    );
});

test('clean-VM evidence must bind every inspected artifact', () => {
    for (const [binding, pattern] of [
        ['resourceManifestSha256', /resource manifest/],
        ['updaterManifestSha256', /updater manifest/],
        ['updaterSignatureSha256', /updater signature/],
    ]) {
        assert.throws(
            () =>
                validateCleanVmEvidence(
                    evidence(),
                    bindings({ [binding]: 'f'.repeat(64) }),
                ),
            pattern,
            binding,
        );
    }
});

test('clean-VM evidence must name the tested Windows release', () => {
    for (const windows of ['', '   ', null]) {
        assert.throws(
            () => validateCleanVmEvidence(evidence({ windows }), bindings()),
            /identify the tested Windows release/,
        );
    }
});

test('clean-VM evidence timestamps must be valid, recent, and not in the future', () => {
    assert.throws(
        () =>
            validateCleanVmEvidence(
                evidence({ tested_at: 'last week' }),
                bindings(),
            ),
        /tested_at must be an ISO-8601 timestamp/,
    );
    assert.throws(
        () =>
            validateCleanVmEvidence(
                evidence({ tested_at: '2026-08-05T11:05:01.000Z' }),
                bindings(),
            ),
        /in the future/,
    );
    assert.doesNotThrow(() =>
        validateCleanVmEvidence(
            evidence({ tested_at: '2026-08-05T11:05:00.000Z' }),
            bindings(),
        ),
    );
    assert.doesNotThrow(() =>
        validateCleanVmEvidence(
            evidence({ tested_at: '2026-07-06T11:00:00.000Z' }),
            bindings(),
        ),
    );
    assert.throws(
        () =>
            validateCleanVmEvidence(
                evidence({ tested_at: '2026-07-06T10:59:59.000Z' }),
                bindings(),
            ),
        /older than 30 days/,
    );
});

test('clean-VM evidence must report exactly the required checks', () => {
    const missing = evidence();
    delete missing.checks.uninstall_data_policy;

    assert.throws(
        () => validateCleanVmEvidence(missing, bindings()),
        /checks must contain exactly/,
    );

    const extra = evidence();
    extra.checks.smoke = 'pass';

    assert.throws(
        () => validateCleanVmEvidence(extra, bindings()),
        /checks must contain exactly/,
    );
});

test('clean-VM evidence only allows "not applicable" for the first-release update checks', () => {
    const firstRelease = evidence();
    firstRelease.checks.signed_update = 'not-applicable-first-release';
    firstRelease.checks.upgrade = 'not-applicable-first-release';

    assert.doesNotThrow(() =>
        validateCleanVmEvidence(firstRelease, bindings()),
    );

    const install = evidence();
    install.checks.install = 'not-applicable-first-release';

    assert.throws(
        () => validateCleanVmEvidence(install, bindings()),
        /check install did not pass/,
    );

    const upgradeOnly = evidence();
    upgradeOnly.checks.upgrade = 'not-applicable-first-release';

    assert.throws(
        () => validateCleanVmEvidence(upgradeOnly, bindings()),
        /same first-release result/,
    );
});

test('clean-VM evidence refuses a skipped check', () => {
    const skipped = evidence();
    skipped.checks.local_backup_restore = 'skipped';

    assert.throws(
        () => validateCleanVmEvidence(skipped, bindings()),
        /local_backup_restore did not pass/,
    );
});
