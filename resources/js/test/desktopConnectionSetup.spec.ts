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
) as { permissions: string[] };

test('the offline shell offers cloud, cabinet hub, retry, and an explicit local technician test', () => {
    const page = new DOMParser().parseFromString(offlinePage, 'text/html');

    expect(page.querySelector('#cloud-btn')?.textContent).toContain('Cloud');
    expect(page.querySelector('#hub-form')).not.toBeNull();
    expect(page.querySelector('#hub-url')).not.toBeNull();
    expect(page.querySelector('#retry-btn')).not.toBeNull();
    expect(page.querySelector('#local-btn')?.textContent).toContain(
        'test local',
    );
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
    expect(offlinePage).toContain('openLocalDrclickWhenAvailable');
    expect(offlinePage).toContain("url: localServerUrl");
});

test('connection setup is local-only and LAN HTTP remains forbidden', () => {
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
    ]);
    expect(remoteCapability.permissions).not.toContain(
        'allow-configure-local-mode',
    );
    expect(remoteCapability.permissions).not.toContain(
        'allow-configure-server-connection',
    );
    // Stricter than before: HTTP is no longer tolerated even for a local
    // test, so the rule is now simply "HTTPS or nothing". The LAN-HTTP
    // prohibition this test exists for is therefore still enforced, and the
    // Rust unit tests pin each rejected scheme.
    expect(rustConnection).toContain('Le serveur doit utiliser HTTPS.');
    expect(rustConnection).toContain('http://192.168.1.20:8000/');
    expect(rustConnection).toContain('http://localhost:8000/');
    expect(rustConnection).not.toContain('runtime-core');
});
