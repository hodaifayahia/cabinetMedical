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
    expect(offlinePage).toContain(
        'https://seagreen-turkey-468004.hostingersite.com/',
    );
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
    expect(localCapability.permissions).toEqual([
        'allow-probe-server-connection',
        'allow-configure-server-connection',
    ]);
    expect(remoteCapability.permissions).not.toContain(
        'allow-configure-server-connection',
    );
    expect(rustConnection).toContain(
        'HTTP est limité à localhost:8000 pour le test local',
    );
    expect(rustConnection).toContain('http://192.168.1.20:8000/');
    expect(rustConnection).not.toContain('runtime-core');
});
