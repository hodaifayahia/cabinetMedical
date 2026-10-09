import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioural tests for the connection-setup page bundled inside the Tauri
 * shell (src-tauri/frontend/index.html). The inline script is executed against
 * a fake `window` so navigation and the Tauri bridge can be observed without
 * leaving jsdom.
 */

const offlinePage = readFileSync(
    resolve(process.cwd(), 'src-tauri/frontend/index.html'),
    'utf8',
);
const script = offlinePage.match(/<script>([\s\S]*?)<\/script>/)?.[1] ?? '';

type FakeWindow = {
    __DRCLICK_CLOUD_SERVER_URL?: string;
    __DRCLICK_SERVER_URL?: string;
    __DRCLICK_LOCAL_ERROR?: string;
    __DRCLICK_RUNTIME_MODE?: string;
    __TAURI_INTERNALS__?: { invoke: ReturnType<typeof vi.fn> };
    location: { href: string; replace: ReturnType<typeof vi.fn> };
};

const CLOUD = 'https://cloud.example.test/';

const createWindow = (overrides: Partial<FakeWindow> = {}): FakeWindow => ({
    __DRCLICK_CLOUD_SERVER_URL: CLOUD,
    location: { href: 'tauri://localhost/index.html', replace: vi.fn() },
    ...overrides,
});

const runPage = (fakeWindow: FakeWindow) => {
    new Function('window', script)(fakeWindow);
};

const flush = async () => {
    for (let index = 0; index < 6; index += 1) {
        await Promise.resolve();
    }
};

const element = <T extends HTMLElement>(id: string) =>
    document.getElementById(id) as T;

const nativeBridge = (
    handlers: Record<string, (payload: { url: string }) => unknown>,
) => ({
    invoke: vi.fn((command: string, payload: { url: string }) => {
        const handler = handlers[command];

        if (!handler) {
            return Promise.reject(new Error(`unexpected ${command}`));
        }

        return handler(payload);
    }),
});

describe('desktop connection-setup page', () => {
    let fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        vi.useFakeTimers();
        const parsed = new DOMParser().parseFromString(
            offlinePage,
            'text/html',
        );
        document.body.innerHTML = parsed.body.innerHTML;
        fetchMock = vi
            .fn<typeof fetch>()
            .mockRejectedValue(new TypeError('offline'));
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('extracts a non-empty inline script', () => {
        expect(script).toContain("'use strict'");
    });

    it('shows the server it is trying to reach', () => {
        runPage(
            createWindow({ __DRCLICK_SERVER_URL: 'https://hub.cabinet.dz/' }),
        );

        expect(element('current-server').textContent).toBe(
            'https://hub.cabinet.dz/',
        );
    });

    it('falls back to the compiled cloud origin when no server was saved', () => {
        runPage(createWindow());

        expect(element('current-server').textContent).toBe(CLOUD);
    });

    it('enters through the login page when the native probe succeeds', async () => {
        const fakeWindow = createWindow({
            __TAURI_INTERNALS__: nativeBridge({
                probe_server_connection: () =>
                    Promise.resolve({ url: 'https://cabinet.example/' }),
            }),
        });

        runPage(fakeWindow);
        await flush();

        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).toHaveBeenCalledWith(
            'probe_server_connection',
            { url: CLOUD },
        );
        expect(fakeWindow.location.replace).toHaveBeenCalledWith(
            'https://cabinet.example/login',
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('keeps a non-root path returned by the probe', async () => {
        const fakeWindow = createWindow({
            __TAURI_INTERNALS__: nativeBridge({
                probe_server_connection: () =>
                    Promise.resolve({ url: 'https://cabinet.example/app/' }),
            }),
        });

        runPage(fakeWindow);
        await flush();

        expect(fakeWindow.location.replace).toHaveBeenCalledWith(
            'https://cabinet.example/app/',
        );
    });

    it('uses the probed server when the probe result has no URL', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_SERVER_URL: 'https://hub.cabinet.dz/',
            __TAURI_INTERNALS__: nativeBridge({
                probe_server_connection: () => Promise.resolve({}),
            }),
        });

        runPage(fakeWindow);
        await flush();

        expect(fakeWindow.location.replace).toHaveBeenCalledWith(
            'https://hub.cabinet.dz/login',
        );
    });

    it('falls back to a no-cors health fetch outside the native shell', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 200 }));
        const fakeWindow = createWindow();

        runPage(fakeWindow);
        await flush();

        const [url, init] = fetchMock.mock.calls[0]!;

        expect(String(url)).toBe(`${CLOUD}health`);
        expect(init).toMatchObject({ method: 'GET', mode: 'no-cors' });
        expect(init?.signal).toBeInstanceOf(AbortSignal);
        expect(fakeWindow.location.replace).toHaveBeenCalledWith(
            `${CLOUD}login`,
        );
    });

    it('aborts the fallback health check after eight seconds', async () => {
        let signal: AbortSignal | undefined;
        fetchMock.mockImplementation((_url, init) => {
            signal = init?.signal ?? undefined;

            return new Promise(() => undefined);
        });

        runPage(createWindow());
        await flush();

        expect(signal?.aborted).toBe(false);
        await vi.advanceTimersByTimeAsync(7_999);
        expect(signal?.aborted).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        expect(signal?.aborted).toBe(true);
    });

    it('shows the setup card with the native error text when the server is unreachable', async () => {
        const fakeWindow = createWindow({
            __TAURI_INTERNALS__: nativeBridge({
                probe_server_connection: () =>
                    Promise.reject('Le serveur ne répond pas.'),
            }),
        });

        runPage(fakeWindow);
        await flush();

        expect(element('offline-card').classList.contains('visible')).toBe(
            true,
        );
        expect(element('spinner').classList.contains('visible')).toBe(false);
        expect(element('connection-error').textContent).toBe(
            'Le serveur ne répond pas.',
        );
        expect(fakeWindow.location.replace).not.toHaveBeenCalled();
    });

    it.each([
        [
            'an Error message',
            new Error('Certificat refusé.'),
            'Certificat refusé.',
        ],
        ['a blank string', '   ', 'Impossible de vérifier ce serveur.'],
        [
            'a blank Error message',
            new Error('  '),
            'Impossible de vérifier ce serveur.',
        ],
        [
            'a non-error value',
            { code: 7 },
            'Impossible de vérifier ce serveur.',
        ],
        ['undefined', undefined, 'Impossible de vérifier ce serveur.'],
    ])('describes %s readably', async (_label, failure, expected) => {
        runPage(
            createWindow({
                __TAURI_INTERNALS__: nativeBridge({
                    probe_server_connection: () => Promise.reject(failure),
                }),
            }),
        );
        await flush();

        expect(element('connection-error').textContent).toBe(expected);
    });

    it('retries automatically with exponential backoff capped at thirty seconds', async () => {
        runPage(createWindow());
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const expectedDelays = [2_000, 4_000, 8_000, 16_000, 30_000, 30_000];

        for (const [index, delay] of expectedDelays.entries()) {
            await vi.advanceTimersByTimeAsync(delay - 1);
            expect(fetchMock).toHaveBeenCalledTimes(index + 1);
            await vi.advanceTimersByTimeAsync(1);
            expect(fetchMock).toHaveBeenCalledTimes(index + 2);
        }
    });

    it('a manual retry resets the backoff delay', async () => {
        runPage(createWindow());
        await flush();
        await vi.advanceTimersByTimeAsync(2_000);
        await vi.advanceTimersByTimeAsync(4_000);
        expect(fetchMock).toHaveBeenCalledTimes(3);

        element<HTMLButtonElement>('retry-btn').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(4);

        await vi.advanceTimersByTimeAsync(2_000);
        expect(fetchMock).toHaveBeenCalledTimes(5);
    });

    it('a manual retry cancels the pending automatic retry', async () => {
        runPage(createWindow());
        await flush();

        await vi.advanceTimersByTimeAsync(1_000);
        element<HTMLButtonElement>('retry-btn').click();
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);

        // The original 2 s timer would have fired at t=2000.
        await vi.advanceTimersByTimeAsync(1_500);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('reports a local-mode start failure without probing any server', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'Le service local n’a pas démarré.',
        });

        runPage(fakeWindow);
        await flush();
        await vi.advanceTimersByTimeAsync(60_000);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(element('offline-card').classList.contains('visible')).toBe(
            true,
        );
        expect(element('connection-error').textContent).toBe(
            'Le service local n’a pas démarré.',
        );
    });

    it('hides the cloud option when no hosted origin was compiled in', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_CLOUD_SERVER_URL: '',
            __DRCLICK_LOCAL_ERROR: 'stop',
            __TAURI_INTERNALS__: nativeBridge({}),
        });

        runPage(fakeWindow);
        const cloud = element<HTMLButtonElement>('cloud-btn');

        expect(cloud.hidden).toBe(true);

        cloud.click();
        await flush();

        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).not.toHaveBeenCalled();
    });

    it('configures the cloud server through the native command', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __TAURI_INTERNALS__: nativeBridge({
                configure_server_connection: ({ url }) =>
                    Promise.resolve({ url }),
            }),
        });

        runPage(fakeWindow);
        element<HTMLButtonElement>('cloud-btn').click();
        await flush();

        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).toHaveBeenCalledWith(
            'configure_server_connection',
            { url: CLOUD },
        );
        expect(fakeWindow.location.replace).toHaveBeenCalledWith(
            `${CLOUD}login`,
        );
    });

    it('configures a cabinet hub from the form and disables actions while busy', async () => {
        let resolveConfigure: (value: { url: string }) => void = () =>
            undefined;
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __TAURI_INTERNALS__: nativeBridge({
                configure_server_connection: () =>
                    new Promise((resolvePromise) => {
                        resolveConfigure = resolvePromise;
                    }),
            }),
        });

        runPage(fakeWindow);
        element<HTMLInputElement>('hub-url').value = 'https://hub.cabinet.dz';
        element<HTMLFormElement>('hub-form').dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await flush();

        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).toHaveBeenCalledWith(
            'configure_server_connection',
            { url: 'https://hub.cabinet.dz' },
        );
        expect(element<HTMLButtonElement>('retry-btn').disabled).toBe(true);
        expect(element<HTMLButtonElement>('cloud-btn').disabled).toBe(true);
        expect(element<HTMLButtonElement>('hub-btn').disabled).toBe(true);
        expect(element('connection-error').textContent).toBe('');

        resolveConfigure({ url: 'https://hub.cabinet.dz/' });
        await flush();

        expect(fakeWindow.location.replace).toHaveBeenCalledWith(
            'https://hub.cabinet.dz/login',
        );
    });

    it('prevents the native form submission', () => {
        runPage(createWindow({ __DRCLICK_LOCAL_ERROR: 'stop' }));
        const event = new Event('submit', { cancelable: true });

        element<HTMLFormElement>('hub-form').dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it('shows a configuration error and re-enables the actions', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __TAURI_INTERNALS__: nativeBridge({
                configure_server_connection: () =>
                    Promise.reject('Le serveur doit utiliser HTTPS.'),
            }),
        });

        runPage(fakeWindow);
        element<HTMLInputElement>('hub-url').value = 'http://192.168.1.20';
        element<HTMLFormElement>('hub-form').dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await flush();

        expect(element('connection-error').textContent).toBe(
            'Le serveur doit utiliser HTTPS.',
        );
        expect(element<HTMLButtonElement>('retry-btn').disabled).toBe(false);
        expect(element<HTMLButtonElement>('hub-btn').disabled).toBe(false);
        expect(fakeWindow.location.replace).not.toHaveBeenCalled();
    });

    it('explains that configuration needs the Windows app when the bridge is missing', async () => {
        const fakeWindow = createWindow({ __DRCLICK_LOCAL_ERROR: 'stop' });

        runPage(fakeWindow);
        element<HTMLButtonElement>('cloud-btn').click();
        await flush();

        expect(element('connection-error').textContent).toBe(
            'Cette configuration est disponible dans l’application Windows Drclick.',
        );
        expect(element<HTMLButtonElement>('cloud-btn').disabled).toBe(false);
    });

    it('treats a bridge without an invoke function as absent', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 200 }));
        const fakeWindow = createWindow({
            __TAURI_INTERNALS__: { invoke: 'nope' } as never,
        });

        runPage(fakeWindow);
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('stops the automatic retry while a configuration is in progress', async () => {
        const fakeWindow = createWindow({
            __TAURI_INTERNALS__: nativeBridge({
                probe_server_connection: () => Promise.reject('down'),
                configure_server_connection: () => new Promise(() => undefined),
            }),
        });

        runPage(fakeWindow);
        await flush();
        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).toHaveBeenCalledTimes(1);

        element<HTMLButtonElement>('cloud-btn').click();
        await flush();
        await vi.advanceTimersByTimeAsync(60_000);

        const commands = fakeWindow.__TAURI_INTERNALS__!.invoke.mock.calls.map(
            (call) => call[0],
        );

        expect(commands).toEqual([
            'probe_server_connection',
            'configure_server_connection',
        ]);
    });

    it('ignores a retry click while a configuration is in progress', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __TAURI_INTERNALS__: nativeBridge({
                probe_server_connection: () => Promise.reject('down'),
                configure_server_connection: () => new Promise(() => undefined),
            }),
        });

        runPage(fakeWindow);
        element<HTMLButtonElement>('cloud-btn').click();
        await flush();

        // The button is disabled while busy; re-enable it to prove the
        // click handler itself also refuses to probe concurrently.
        const retry = element<HTMLButtonElement>('retry-btn');

        expect(retry.disabled).toBe(true);
        retry.disabled = false;
        retry.click();
        await flush();

        expect(
            fakeWindow.__TAURI_INTERNALS__!.invoke.mock.calls.map(
                (call) => call[0],
            ),
        ).toEqual(['configure_server_connection']);
    });
    it('returns an attached PC to local mode and waits for the native restart', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __DRCLICK_RUNTIME_MODE: 'attach',
            __TAURI_INTERNALS__: nativeBridge({
                configure_local_mode: () => Promise.resolve(true),
            }),
        });

        runPage(fakeWindow);
        expect(element('local-option').hidden).toBe(false);
        element<HTMLButtonElement>('local-btn').click();
        await flush();

        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).toHaveBeenCalledWith(
            'configure_local_mode',
            undefined,
        );
        expect(element('spinner').classList.contains('visible')).toBe(true);
        expect(element('spinner-label').textContent).toBe(
            'Redémarrage de Drclick…',
        );
        expect(fakeWindow.location.replace).not.toHaveBeenCalled();
    });

    it('hides the local-mode option when this PC already owns its data', () => {
        runPage(
            createWindow({
                __DRCLICK_LOCAL_ERROR: 'stop',
                __DRCLICK_RUNTIME_MODE: 'local',
            }),
        );

        expect(element('local-option').hidden).toBe(true);
    });

    it('keeps hidden options hidden despite their display style', () => {
        expect(offlinePage).toMatch(
            /\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/,
        );
    });

    it('shows that Drclick is starting and opens the cabinet once the local runtime is ready', async () => {
        let ready = false;
        const fakeWindow = createWindow({
            __DRCLICK_RUNTIME_MODE: 'local',
            __TAURI_INTERNALS__: nativeBridge({
                runtime_mode_status: () =>
                    Promise.resolve({
                        mode: 'local',
                        url: ready ? 'http://127.0.0.1:50660/desktop' : null,
                        local_error: null,
                    }),
            }),
        });

        runPage(fakeWindow);
        await flush();

        expect(element('spinner').classList.contains('visible')).toBe(true);
        expect(element('spinner-label').textContent).toBe(
            'Démarrage de Drclick…',
        );
        expect(fakeWindow.location.replace).not.toHaveBeenCalled();

        ready = true;
        await vi.advanceTimersByTimeAsync(1_000);

        expect(fakeWindow.location.replace).toHaveBeenCalledWith(
            'http://127.0.0.1:50660/desktop',
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('shows a local start failure reported while the page waits', async () => {
        runPage(
            createWindow({
                __DRCLICK_RUNTIME_MODE: 'local',
                __TAURI_INTERNALS__: nativeBridge({
                    runtime_mode_status: () =>
                        Promise.resolve({
                            mode: 'local',
                            url: null,
                            local_error:
                                'L’application locale n’a pas répondu à temps au démarrage.',
                        }),
                }),
            }),
        );
        await flush();

        expect(element('offline-card').classList.contains('visible')).toBe(
            true,
        );
        expect(element('connection-error').textContent).toBe(
            'L’application locale n’a pas répondu à temps au démarrage.',
        );
        expect(element('current-server').textContent).toBe(
            'Sur ce PC (mode autonome)',
        );
    });

    it('retries a local start failure by restarting Drclick', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __DRCLICK_RUNTIME_MODE: 'local',
            __TAURI_INTERNALS__: nativeBridge({
                configure_local_mode: () => Promise.resolve(true),
            }),
        });

        runPage(fakeWindow);
        element<HTMLButtonElement>('retry-btn').click();
        await flush();

        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).toHaveBeenCalledWith(
            'configure_local_mode',
            undefined,
        );
        expect(element('spinner-label').textContent).toBe(
            'Redémarrage de Drclick…',
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('waits for the restart after choosing a poste principal over LAN HTTP', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __TAURI_INTERNALS__: nativeBridge({
                configure_server_connection: () =>
                    Promise.resolve({ url: 'http://192.168.1.10:47850/' }),
            }),
        });

        runPage(fakeWindow);
        element<HTMLInputElement>('hub-url').value =
            'http://192.168.1.10:47850';
        element<HTMLFormElement>('hub-form').dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await flush();

        expect(fakeWindow.location.replace).not.toHaveBeenCalled();
        expect(element('spinner-label').textContent).toBe(
            'Redémarrage de Drclick…',
        );
    });

    it('lists discovered postes principaux and joins one in a click', async () => {
        const fakeWindow = createWindow({
            __DRCLICK_LOCAL_ERROR: 'stop',
            __TAURI_INTERNALS__: nativeBridge({
                discover_lan_hosts: () =>
                    Promise.resolve([
                        {
                            name: 'CABINET-PC',
                            url: 'http://192.168.1.10:47850/',
                        },
                    ]),
                configure_server_connection: ({ url }) =>
                    Promise.resolve({ url }),
            }),
        });

        runPage(fakeWindow);
        element<HTMLButtonElement>('discover-btn').click();
        await flush();

        const choices = element('discover-results').querySelectorAll('button');

        expect(choices).toHaveLength(1);
        expect(choices[0]!.textContent).toBe(
            'CABINET-PC — http://192.168.1.10:47850/',
        );

        choices[0]!.click();
        await flush();

        expect(fakeWindow.__TAURI_INTERNALS__!.invoke).toHaveBeenCalledWith(
            'configure_server_connection',
            { url: 'http://192.168.1.10:47850/' },
        );
    });

    it('explains how to find the address when no poste principal answers', async () => {
        runPage(
            createWindow({
                __DRCLICK_LOCAL_ERROR: 'stop',
                __TAURI_INTERNALS__: nativeBridge({
                    discover_lan_hosts: () => Promise.resolve([]),
                }),
            }),
        );
        element<HTMLButtonElement>('discover-btn').click();
        await flush();

        expect(element('connection-error').textContent).toContain(
            'Aucun poste principal trouvé',
        );
        expect(element<HTMLButtonElement>('discover-btn').disabled).toBe(false);
    });
});
