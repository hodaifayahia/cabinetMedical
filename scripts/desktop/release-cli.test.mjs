import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { appendFile, mkdtemp, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { createSafeReleaseResourceFixture } from './release-resource-fixture.mjs';
import { validateReleaseResources } from './release-resources.mjs';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));

function runScript(name, args = [], env = {}) {
    const baseEnvironment = { ...process.env };
    delete baseEnvironment.MEDISMART_UPDATER_PUBLIC_KEY;
    delete baseEnvironment.MEDISMART_UPDATER_ENDPOINT;

    const result = spawnSync(
        process.execPath,
        [path.join(scriptDirectory, name), ...args],
        {
            encoding: 'utf8',
            env: { ...baseEnvironment, ...env },
            timeout: 60_000,
        },
    );

    return {
        status: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
    };
}

async function temporaryDirectory(t) {
    const directory = await mkdtemp(
        path.join(os.tmpdir(), 'drclick-release-cli-test-'),
    );
    t.after(async () => {
        await rm(directory, { recursive: true, force: true });
    });

    return directory;
}

async function fixture(t) {
    const root = await temporaryDirectory(t);
    const resources = path.join(root, 'resources');
    await createSafeReleaseResourceFixture(resources);

    return resources;
}

// ---------------------------------------------------------------------------
// build-thin-client.mjs
// ---------------------------------------------------------------------------

test('thin-client build refuses to start without the updater public key', () => {
    const result = runScript('build-thin-client.mjs');

    assert.notEqual(result.status, 0);
    assert.match(
        result.stderr,
        /Desktop release builds require MEDISMART_UPDATER_PUBLIC_KEY/,
    );
});

test('thin-client build treats a blank updater key as missing', () => {
    const result = runScript('build-thin-client.mjs', [], {
        MEDISMART_UPDATER_PUBLIC_KEY: '   ',
        MEDISMART_UPDATER_ENDPOINT: 'https://updates.example.test/latest.json',
    });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /require MEDISMART_UPDATER_PUBLIC_KEY/);
});

test('thin-client build refuses to start without the updater endpoint', () => {
    const result = runScript('build-thin-client.mjs', [], {
        MEDISMART_UPDATER_PUBLIC_KEY: 'public-key',
    });

    assert.notEqual(result.status, 0);
    assert.match(
        result.stderr,
        /Desktop release builds require MEDISMART_UPDATER_ENDPOINT/,
    );
});

// ---------------------------------------------------------------------------
// validate-release-resources.mjs
// ---------------------------------------------------------------------------

test('resource validation prints its usage with --help', () => {
    const result = runScript('validate-release-resources.mjs', ['--help']);

    assert.equal(result.status, 0);
    assert.match(result.stdout, /desktop:resources:validate/);
    assert.match(result.stdout, /--probe-binaries/);
});

test('resource validation refuses unknown options and positional arguments', () => {
    const unknown = runScript('validate-release-resources.mjs', [
        '--resource',
        'x',
    ]);

    assert.equal(unknown.status, 1);
    assert.match(
        unknown.stderr,
        /Desktop release resource validation failed: unknown option: --resource/,
    );

    const positional = runScript('validate-release-resources.mjs', ['x']);

    assert.equal(positional.status, 1);
    assert.match(positional.stderr, /unexpected positional argument: x/);
});

test('resource validation fails for a missing resource directory', async (t) => {
    const root = await temporaryDirectory(t);
    const result = runScript('validate-release-resources.mjs', [
        '--resources',
        path.join(root, 'missing'),
    ]);

    assert.equal(result.status, 1);
    assert.match(result.stderr, /Desktop release resource validation failed:/);
});

test('resource validation accepts the synthetic fixture end to end', async (t) => {
    const resources = await fixture(t);
    const result = runScript('validate-release-resources.mjs', [
        '--resources',
        resources,
        '--expected-version',
        '0.0.0-fixture',
    ]);

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /complete and uncontaminated/);
});

test('resource validation refuses the fixture for another release version', async (t) => {
    const resources = await fixture(t);

    await assert.rejects(
        validateReleaseResources(resources, {
            expectedApplicationVersion: '1.0.0',
        }),
    );
});

test('resource validation detects a tampered staged file', async (t) => {
    const resources = await fixture(t);
    await appendFile(
        path.join(resources, 'laravel', 'public', 'index.php'),
        '// injected\n',
    );

    await assert.rejects(
        validateReleaseResources(resources, {
            expectedApplicationVersion: '0.0.0-fixture',
        }),
        /hash mismatch|manifest/i,
    );
});

test('resource validation detects an unexpected staged file', async (t) => {
    const resources = await fixture(t);
    await writeFile(path.join(resources, 'laravel', '.env'), 'APP_KEY=leak\n');

    await assert.rejects(
        validateReleaseResources(resources, {
            expectedApplicationVersion: '0.0.0-fixture',
        }),
        /inventory mismatch|\.env/i,
    );
});

// ---------------------------------------------------------------------------
// stage-release-resources.mjs and create-php-runtime-review.mjs
// ---------------------------------------------------------------------------

test('resource staging prints its usage with --help', () => {
    const result = runScript('stage-release-resources.mjs', ['--help']);

    assert.equal(result.status, 0);
    assert.match(result.stdout, /Windows-only/);
    assert.match(result.stdout, /--cloudflared-sha256/);
});

test('resource staging requires every reviewed input', () => {
    const result = runScript('stage-release-resources.mjs', []);

    assert.equal(result.status, 1);
    assert.match(
        result.stderr,
        /Desktop release staging refused: --php-runtime is required/,
    );
});

test('resource staging refuses a duplicated option', () => {
    const result = runScript('stage-release-resources.mjs', [
        '--php-runtime',
        'a',
        '--php-runtime',
        'b',
    ]);

    assert.equal(result.status, 1);
    assert.match(result.stderr, /duplicate option: --php-runtime/);
});

test('PHP runtime review prints its usage with --help', () => {
    const result = runScript('create-php-runtime-review.mjs', ['--help']);

    assert.equal(result.status, 0);
    assert.match(result.stdout, /desktop:resources:review-php/);
});

test('PHP runtime review requires its runtime and output paths', () => {
    const result = runScript('create-php-runtime-review.mjs', [
        '--php-runtime',
        'C:\\php',
    ]);

    assert.equal(result.status, 1);
    assert.match(
        result.stderr,
        /PHP runtime review refused: --output is required/,
    );
});

test(
    'PHP runtime review refuses to run outside the Windows build host',
    { skip: process.platform === 'win32' },
    () => {
        const result = runScript('create-php-runtime-review.mjs', [
            '--php-runtime',
            'php',
            '--output',
            'review.json',
        ]);

        assert.equal(result.status, 1);
        assert.match(result.stderr, /controlled Windows build host/);
    },
);

// ---------------------------------------------------------------------------
// check-release-readiness.mjs
// ---------------------------------------------------------------------------

test('release readiness requires every publication input', () => {
    const result = runScript('check-release-readiness.mjs', [
        '--resources',
        'resources',
    ]);

    assert.equal(result.status, 1);
    assert.match(
        result.stderr,
        /Release-readiness check failed: --expected-version is required/,
    );
});

test('release readiness refuses unknown options', () => {
    const result = runScript('check-release-readiness.mjs', ['--force', 'yes']);

    assert.equal(result.status, 1);
    assert.match(result.stderr, /unknown option: --force/);
});

test(
    'release readiness refuses to run outside the Windows build host',
    { skip: process.platform === 'win32' },
    () => {
        const args = [
            'resources',
            'expected-version',
            'installer',
            'updater-manifest',
            'updater-artifact',
            'updater-signature',
            'updater-target',
            'updater-artifact-url',
            'clean-vm-evidence',
        ].flatMap((name) => [`--${name}`, 'value']);
        const result = runScript('check-release-readiness.mjs', args);

        assert.equal(result.status, 1);
        assert.match(result.stderr, /controlled Windows build host/);
    },
);

test('importing the readiness module does not run its CLI', async () => {
    const module = await import('./check-release-readiness.mjs');

    assert.equal(typeof module.validateUpdaterReleaseManifest, 'function');
    assert.equal(typeof module.validateCleanVmEvidence, 'function');
});
