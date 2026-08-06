import { spawnSync } from 'node:child_process';
import { closeSync, mkdirSync, openSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import {
    assertRuntimeIsolation,
    backupDirectory,
    databasePath,
    e2eEnvironment,
    laravelStorageDirectory,
    projectRoot,
    runtimeDirectory,
} from './environment.mjs';

assertRuntimeIsolation();

rmSync(laravelStorageDirectory, { force: true, recursive: true });

mkdirSync(join(runtimeDirectory, 'cache'), { recursive: true });
mkdirSync(join(runtimeDirectory, 'views'), { recursive: true });
mkdirSync(backupDirectory, { recursive: true });
mkdirSync(laravelStorageDirectory, { recursive: true });

for (const sqliteFile of [
    databasePath,
    `${databasePath}-shm`,
    `${databasePath}-wal`,
]) {
    rmSync(sqliteFile, { force: true });
}

closeSync(openSync(databasePath, 'wx'));

const artisan = (...arguments_) => {
    const result = spawnSync(
        process.env.PHP_BINARY ?? 'php',
        ['artisan', ...arguments_],
        {
            cwd: projectRoot,
            env: { ...process.env, ...e2eEnvironment },
            shell: false,
            stdio: 'inherit',
        },
    );

    if (result.error) {
        throw result.error;
    }

    if (result.status !== 0) {
        throw new Error(
            `php artisan ${arguments_.join(' ')} failed with exit code ${result.status ?? 'unknown'}.`,
        );
    }
};

artisan('migrate', '--force', '--no-interaction');
artisan('db:seed', '--force', '--no-interaction');
