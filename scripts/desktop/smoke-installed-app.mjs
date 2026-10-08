#!/usr/bin/env node
// Installs the freshly built Windows installer and starts it the way a new
// clinic PC does (offline mode, empty database), then waits for the bundled
// Laravel, queue worker and scheduler to report ready.
//
// Offline mode had never been started from a real installation in CI, and
// two startup bugs reached doctors (invalid worker bounds, then a Windows
// verbatim path PHP could not use). Each would have failed here.
//
// Once ready it also checks the two halves of the update path: a force-closed
// app must not leave its PHP helpers running, and reinstalling over a PHP
// still running from the install folder (left by an older version) must stop
// it instead of failing to replace the locked PHP files.
//
// Runs on Windows only. build-thin-client.mjs calls it on GitHub Actions.

import { spawn, spawnSync } from 'node:child_process';
import {
    existsSync,
    readdirSync,
    readFileSync,
    rmSync,
    statSync,
} from 'node:fs';
import { dirname, join } from 'node:path';

const READY_MARKERS = [
    'Authenticated Laravel readiness check passed',
    'Queue worker is active',
    'Laravel scheduler is active',
];
const TIMEOUT_MS = 6 * 60 * 1000;
const POLL_MS = 2000;

if (process.platform !== 'win32') {
    console.log('Installed-app smoke test skipped: Windows only.');
    process.exit(0);
}

const localAppData = process.env.LOCALAPPDATA;

if (!localAppData) {
    fail('LOCALAPPDATA is not set.');
}

const bundleDirectory = join(
    'src-tauri',
    'target',
    'release',
    'bundle',
    'nsis',
);
const installer = readdirSync(bundleDirectory).find((name) =>
    name.endsWith('-setup.exe'),
);

if (!installer) {
    fail(`No NSIS installer found in ${bundleDirectory}.`);
}

const dataDirectory = join(localAppData, 'dz.click.medismart');
const logDirectory = join(dataDirectory, 'logs');

// A fresh PC: no previous data, so the app starts in offline mode.
rmSync(dataDirectory, { recursive: true, force: true });

console.log(`Installing ${installer} silently…`);
const install = spawnSync(join(bundleDirectory, installer), ['/S'], {
    stdio: 'inherit',
});

if (install.status !== 0) {
    fail(`The installer exited with code ${install.status}.`);
}

const executable = [
    join(localAppData, 'Drclick', 'Drclick.exe'),
    join(localAppData, 'Programs', 'Drclick', 'Drclick.exe'),
    join(
        process.env.ProgramFiles ?? 'C:\\Program Files',
        'Drclick',
        'Drclick.exe',
    ),
].find((candidate) => existsSync(candidate));

if (!executable) {
    fail('Drclick.exe was not found after installation.');
}

console.log(`Starting ${executable}…`);
const app = spawn(executable, [], { detached: true, stdio: 'ignore' });
app.unref();

const startedAt = Date.now();
let outcome = null;

while (Date.now() - startedAt < TIMEOUT_MS) {
    await sleep(POLL_MS);

    const log = readLogs();

    if (/\[ERROR\]/.test(log) || /PHP Fatal error/.test(log)) {
        outcome = 'error';
        break;
    }

    if (READY_MARKERS.every((marker) => log.includes(marker))) {
        outcome = 'ready';
        break;
    }
}

const seconds = Math.round((Date.now() - startedAt) / 1000);
const installDirectory = dirname(executable);

if (outcome === 'ready') {
    // Force-close the app alone, as Task Manager or the installer does: its
    // PHP helpers must end with it.
    spawnSync('taskkill', ['/F', '/IM', 'Drclick.exe'], { stdio: 'ignore' });
    await sleep(3000);
    const orphans = installHelpers();
    stopApp();
    printLogs();

    if (orphans.length > 0) {
        fail(
            `PHP helpers outlived the force-closed app:\n${orphans.join('\n')}`,
        );
    }

    console.log(`Installed app started offline and is ready (${seconds} s).`);
    console.log('Its PHP helpers ended with the force-closed app.');
    checkReinstallStopsLeftoverPhp();
    process.exit(0);
}

stopApp();
printLogs();

fail(
    outcome === 'error'
        ? 'The installed app reported an error while starting offline (see the logs above).'
        : `The installed app was not ready within ${TIMEOUT_MS / 60000} minutes (see the logs above).`,
);

// An older version could leave php.exe running from the install folder,
// holding its files. Reinstalling must stop it rather than fail on them.
function checkReinstallStopsLeftoverPhp() {
    const php = join(installDirectory, 'php', 'php.exe');

    if (!existsSync(php)) {
        fail(`${php} was not found after installation.`);
    }

    const leftover = spawn(php, ['-r', 'sleep(300);'], {
        detached: true,
        stdio: 'ignore',
    });
    leftover.unref();

    console.log('Reinstalling over a leftover php.exe…');
    const reinstall = spawnSync(join(bundleDirectory, installer), ['/S'], {
        stdio: 'inherit',
    });
    const remaining = installHelpers();
    stopApp();

    if (reinstall.status !== 0) {
        fail(`Reinstalling exited with code ${reinstall.status}.`);
    }

    if (remaining.length > 0) {
        fail(
            `The installer left PHP running from the install folder:\n${remaining.join('\n')}`,
        );
    }

    console.log('Reinstalling stopped the leftover php.exe.');
}

// PHP or cloudflared processes running from the install folder.
function installHelpers() {
    const result = spawnSync(
        'powershell.exe',
        [
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            'Get-Process -Name php,php-cgi,cloudflared -ErrorAction SilentlyContinue | ' +
                'Where-Object { $_.Path -and $_.Path.StartsWith($env:DRCLICK_SMOKE_DIR, [StringComparison]::OrdinalIgnoreCase) } | ' +
                'ForEach-Object { "$($_.Id) $($_.Path)" }',
        ],
        {
            encoding: 'utf8',
            env: { ...process.env, DRCLICK_SMOKE_DIR: `${installDirectory}\\` },
        },
    );

    return result.stdout
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
}

function sleep(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function readLogs() {
    if (!existsSync(logDirectory)) {
        return '';
    }

    return readdirSync(logDirectory)
        .filter((name) => name.endsWith('.log'))
        .map((name) => readFileSync(join(logDirectory, name), 'utf8'))
        .join('\n');
}

function printLogs() {
    if (!existsSync(logDirectory)) {
        console.log(`No log was written to ${logDirectory}.`);

        return;
    }

    for (const name of readdirSync(logDirectory).filter((entry) =>
        entry.endsWith('.log'),
    )) {
        const path = join(logDirectory, name);
        const lines = readFileSync(path, 'utf8').split(/\r?\n/);
        console.log(
            `==== ${name} (${statSync(path).size} bytes, last 60 lines)`,
        );
        console.log(lines.slice(-60).join('\n'));
    }
}

function stopApp() {
    for (const image of ['Drclick.exe', 'php.exe', 'php-cgi.exe']) {
        spawnSync('taskkill', ['/F', '/T', '/IM', image], { stdio: 'ignore' });
    }
}

function fail(message) {
    console.error(`::error::${message}`);
    process.exit(1);
}
