import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test } from 'vitest';

const root = process.cwd();
const offlinePage = readFileSync(
    resolve(root, 'src-tauri/frontend/index.html'),
    'utf8',
);
const rustConnection = readFileSync(
    resolve(root, 'src-tauri/src/connection.rs'),
    'utf8',
);
const rustShell = readFileSync(resolve(root, 'src-tauri/src/lib.rs'), 'utf8');
const localCapability = JSON.parse(
    readFileSync(
        resolve(root, 'src-tauri/capabilities/connection-setup.json'),
        'utf8',
    ),
) as {
    local: boolean;
    remote?: unknown;
    permissions: string[];
};
const remoteCapability = JSON.parse(
    readFileSync(
        resolve(root, 'src-tauri/capabilities/desktop-windows.json'),
        'utf8',
    ),
) as { permissions: string[]; remote?: { urls?: string[] } };

const remoteCapabilityUrls = remoteCapability.remote?.urls ?? [];

type Capability = {
    local: boolean;
    remote?: { urls?: string[] };
    permissions: string[];
};
const readCapability = (name: string) =>
    JSON.parse(
        readFileSync(
            resolve(root, `src-tauri/capabilities/${name}.json`),
            'utf8',
        ),
    ) as Capability;
const localRuntimeCapability = readCapability('desktop-local-runtime');
const lanClientCapability = readCapability('desktop-lan-client');

test('the offline shell offers cloud, cabinet hub, and retry', () => {
    const page = new DOMParser().parseFromString(offlinePage, 'text/html');

    expect(page.querySelector('#cloud-btn')?.textContent).toContain('Cloud');
    expect(page.querySelector('#hub-form')).not.toBeNull();
    expect(page.querySelector('#hub-url')).not.toBeNull();
    expect(page.querySelector('#retry-btn')).not.toBeNull();
    expect(page.querySelector('#local-btn')?.textContent).toContain(
        'Revenir au mode autonome',
    );
    expect(page.querySelector('#discover-btn')).not.toBeNull();
    expect(page.body.textContent).toContain('deux ou trois PC sans Internet');
});

test('server selection is verified and persisted by narrow native commands', () => {
    // The hosted origin is a build input (DRCLICK_CLOUD_SERVER_URL), injected
    // by the Rust shell. Pinning the literal here would re-couple the page to
    // one deployment, so assert the wiring instead of the value.
    expect(offlinePage).toContain('window.__DRCLICK_CLOUD_SERVER_URL');
    expect(offlinePage).not.toContain('hostingersite.com');
    expect(rustShell).toContain('__DRCLICK_CLOUD_SERVER_URL');
    expect(rustShell).toContain('option_env!("DRCLICK_CLOUD_SERVER_URL")');
    expect(offlinePage).toContain("invoke('probe_server_connection'");
    expect(offlinePage).toContain("invoke('configure_server_connection'");
    expect(rustShell).toContain('mod connection;');
    expect(rustShell).toContain('probe_server_connection');
    expect(rustShell).toContain('configure_server_connection');
    expect(rustConnection).toContain('.join("health")');
    expect(rustConnection).toContain('health.application.name != "Drclick"');
    expect(rustConnection).toContain('persist_server_url');

    // A poste secondaire whose poste principal is down must be able to go
    // back to its own data (ADR-005), and to look for the host on the LAN.
    expect(offlinePage).toContain("invoke('configure_local_mode'");
    expect(offlinePage).toContain("invoke('discover_lan_hosts'");
    expect(rustShell).toContain('lan::schedule_restart(&app)');
});

test('every place that names the hosted origin agrees with the compiled default', () => {
    // The origin is a build input (DRCLICK_CLOUD_SERVER_URL), but three files
    // still spell it out and Tauri cannot template any of them: the CSP, the
    // local CSP/updater overlay, and the capability that decides which remote
    // origins may invoke native commands. If they drift apart the app still
    // compiles and then fails at runtime — a blank window, or a webview that
    // silently cannot call Tauri. Fail the test instead.
    const compiled = rustShell.match(
        /DEFAULT_CLOUD_SERVER_URL: &str = "([^"]+)"/u,
    )?.[1];

    expect(compiled).toBeDefined();

    const origin = new URL(compiled!).origin;

    const tauriConfig = readFileSync(
        resolve(root, 'src-tauri/tauri.conf.json'),
        'utf8',
    );
    const localConfig = readFileSync(
        resolve(root, 'src-tauri/tauri.local.conf.json'),
        'utf8',
    );

    expect(tauriConfig).toContain(origin);
    expect(localConfig).toContain(origin);
    expect(JSON.stringify(remoteCapabilityUrls)).toContain(origin);
});

test('connection setup is local-only and plain HTTP is limited to the LAN', () => {
    expect(localCapability.local).toBe(true);
    expect(localCapability).not.toHaveProperty('remote');
    // The connection page also owns local-mode selection now. The security
    // claim is unchanged: these commands stay on the local-only capability and
    // never reach the remote one (asserted next).
    expect(localCapability.permissions).toEqual([
        'allow-probe-server-connection',
        'allow-configure-server-connection',
        'allow-configure-local-mode',
        'allow-runtime-mode-status',
        'allow-discover-lan-hosts',
    ]);
    expect(remoteCapability.permissions).not.toContain(
        'allow-configure-local-mode',
    );
    expect(remoteCapability.permissions).not.toContain(
        'allow-configure-server-connection',
    );
    // HTTPS stays mandatory for every origin that could be on the Internet.
    // Plain HTTP is accepted only for a poste principal on the cabinet LAN
    // (private IPv4, bare computer name, .local); the Rust unit tests pin
    // each accepted and rejected form.
    expect(rustConnection).toContain('Le serveur doit utiliser HTTPS.');
    expect(rustConnection).toContain('fn is_private_lan_host');
    expect(rustConnection).toContain('http://192.168.1.20:47850/');
    expect(rustConnection).toContain('http://8.8.8.8:47850/');
    expect(rustConnection).toContain('http://localhost:8000/');
    expect(rustConnection).not.toContain('runtime-core');
});

test('LAN commands are split between the loopback and LAN origins', () => {
    const lanCommands = [
        'allow-set-lan-host',
        'allow-open-lan-firewall',
        'allow-connect-to-lan-host',
        'allow-pick-backup-folder',
    ];

    // Sharing this PC's data, joining another PC and choosing a backup folder
    // belong to this PC's own runtime only.
    expect(localRuntimeCapability.local).toBe(false);
    expect(localRuntimeCapability.remote?.urls).toEqual(['http://127.0.0.1:*']);

    for (const permission of lanCommands) {
        expect(localRuntimeCapability.permissions).toContain(permission);
        expect(lanClientCapability.permissions).not.toContain(permission);
        expect(remoteCapability.permissions).not.toContain(permission);
    }

    // Pages served by the poste principal may only read the mode, return
    // this PC to local mode and use the signed updater.
    expect(lanClientCapability.permissions).toEqual([
        'allow-runtime-mode-status',
        'allow-lan-host-status',
        'allow-use-local-mode',
        'allow-signed-updater-status',
        'allow-check-for-signed-update',
        'allow-install-signed-update',
    ]);

    for (const pattern of lanClientCapability.remote?.urls ?? []) {
        expect(pattern.startsWith('http://')).toBe(true);
        expect(pattern).not.toBe('http://*:*');
    }
});
