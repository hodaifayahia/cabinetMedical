import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

const updaterPublicKey = requiredEnvironment('MEDISMART_UPDATER_PUBLIC_KEY');
const updaterEndpoint = requiredEnvironment('MEDISMART_UPDATER_ENDPOINT');

// Tauri's bundler needs the same updater configuration that build.rs embeds in
// the Rust binary. Supplying it as a CLI merge keeps deployment-specific keys
// and endpoints out of the committed application configuration.
const releaseConfig = JSON.stringify({
    plugins: {
        updater: {
            pubkey: updaterPublicKey,
            endpoints: [updaterEndpoint],
        },
    },
});

const tauriCli = require.resolve('@tauri-apps/cli/tauri.js');
const result = spawnSync(
    process.execPath,
    [tauriCli, 'build', '--config', releaseConfig, ...process.argv.slice(2)],
    {
        env: process.env,
        stdio: 'inherit',
    },
);

if (result.error) {
    throw result.error;
}

if (result.signal) {
    console.error(`Tauri build terminated by ${result.signal}.`);
    process.exit(1);
}

process.exit(result.status ?? 1);

function requiredEnvironment(name) {
    const value = process.env[name]?.trim();

    if (!value) {
        throw new Error(
            `Desktop release builds require ${name}; supply the signed-updater configuration through the release environment.`,
        );
    }

    return value;
}
