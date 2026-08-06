import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import {
    assertRuntimeIsolation,
    e2eEnvironment,
    projectRoot,
} from './environment.mjs';

const fixtureScript = join(
    projectRoot,
    'tests/e2e/support/public-upload-fixture.php',
);

const runFixtureCommand = (operation) => {
    assertRuntimeIsolation();

    const result = spawnSync(
        process.env.PHP_BINARY ?? 'php',
        [fixtureScript, operation],
        {
            cwd: projectRoot,
            encoding: 'utf8',
            env: { ...process.env, ...e2eEnvironment },
            shell: false,
        },
    );

    if (result.error) {
        throw result.error;
    }

    if (result.status !== 0) {
        throw new Error(
            `Public upload fixture ${operation} failed: ${result.stderr.trim()}`,
        );
    }

    return result.stdout.trim();
};

export const createPublicUploadFixture = () => {
    const output = runFixtureCommand('create');
    const fixture = JSON.parse(output);

    if (
        typeof fixture !== 'object' ||
        fixture === null ||
        !/^[A-Za-z0-9_-]{22}$/u.test(fixture.selector) ||
        !/^[A-Za-z0-9_-]{43}$/u.test(fixture.verifier)
    ) {
        throw new Error(
            'The public upload fixture returned invalid credentials.',
        );
    }

    return {
        selector: fixture.selector,
        verifier: fixture.verifier,
    };
};

export const cleanupPublicUploadFixture = () => {
    runFixtureCommand('cleanup');
};
