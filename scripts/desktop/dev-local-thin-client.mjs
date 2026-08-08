import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const tauriCli = require.resolve('@tauri-apps/cli/tauri.js');
const result = spawnSync(
    process.execPath,
    [
        tauriCli,
        'dev',
        '--config',
        'src-tauri/tauri.local.conf.json',
        // Load the bundled redirect page directly. Tauri's temporary dev
        // server origin is intentionally not allowed by NavigationPolicy.
        '--no-dev-server',
    ],
    {
        env: {
            ...process.env,
            // Rust accepts this HTTP origin only in debug builds and only at
            // this exact loopback host, port, and root path.
            DRCLICKDZ_DEV_SERVER_URL: 'http://localhost:8000/',
        },
        stdio: 'inherit',
    },
);

if (result.error) {
    throw result.error;
}

if (result.signal) {
    console.error(`Tauri local development terminated by ${result.signal}.`);
    process.exit(1);
}

process.exit(result.status ?? 1);
