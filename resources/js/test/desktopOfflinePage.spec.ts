import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { afterEach, expect, test, vi } from 'vitest';

const offlinePage = readFileSync(
    resolve(process.cwd(), 'src-tauri/frontend/index.html'),
    'utf8',
);
const applicationShell = readFileSync(
    resolve(process.cwd(), 'resources/views/app.blade.php'),
    'utf8',
);
const desktopIcon = readFileSync(
    resolve(process.cwd(), 'src-tauri/icons/source.svg'),
    'utf8',
);
const tauriConfig = JSON.parse(
    readFileSync(resolve(process.cwd(), 'src-tauri/tauri.conf.json'), 'utf8'),
) as {
    productName: string;
    mainBinaryName: string;
    identifier: string;
    bundle: {
        windows: {
            wix: { language: string };
            nsis: { languages: string[] };
        };
    };
};

async function flushPromises(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
});

test('the desktop bundle exposes Drclick while preserving its installed identity', () => {
    expect(tauriConfig.productName).toBe('Drclick');
    expect(tauriConfig.mainBinaryName).toBe('Drclick');
    expect(tauriConfig.identifier).toBe('dz.click.medismart');
    expect(tauriConfig.bundle.windows.wix.language).toBe('fr-FR');
    expect(tauriConfig.bundle.windows.nsis.languages).toEqual(['French']);
});

test('the offline shell and icon use the Drclick brand', () => {
    const parsedPage = new DOMParser().parseFromString(
        offlinePage,
        'text/html',
    );
    const visibleShell = [
        parsedPage.querySelector('#spinner')?.textContent,
        parsedPage.querySelector('#offline-card')?.textContent,
    ].join(' ');

    expect(parsedPage.title).toBe('Drclick');
    expect(visibleShell).toContain('Drclick');
    expect(visibleShell).not.toContain('MediSmart');
    expect(
        [...parsedPage.querySelectorAll<HTMLElement>('.mark')].map(
            (mark) => mark.textContent,
        ),
    ).toEqual(['D', 'D']);
    expect(desktopIcon).toContain('<title id="title">Drclick</title>');
});

test('desktop entry points bypass the public landing page', () => {
    expect(offlinePage).toContain('function authenticationEntryUrl(url)');
    expect(offlinePage).toContain("target.pathname = '/login'");
    expect(applicationShell).toContain('globalThis.isTauri');
    expect(applicationShell).toContain("window.location.replace('/login')");
});

test('the offline retry button remains clickable during automatic backoff', async () => {
    vi.useFakeTimers();

    const parsedPage = new DOMParser().parseFromString(
        offlinePage,
        'text/html',
    );
    document.body.innerHTML = parsedPage.body.innerHTML;

    const fetchMock = vi
        .fn<typeof fetch>()
        .mockRejectedValue(new TypeError('server unavailable'));
    vi.stubGlobal('fetch', fetchMock);

    const script = offlinePage.match(/<script>([\s\S]*?)<\/script>/)?.[1];
    expect(script).toBeDefined();

    Function(script!)();
    await flushPromises();
    await flushPromises();

    const retryButton = document.querySelector<HTMLButtonElement>('#retry-btn');
    expect(retryButton).not.toBeNull();
    expect(retryButton!.disabled).toBe(false);

    retryButton!.click();
    expect(fetchMock).toHaveBeenCalledTimes(2);

    await flushPromises();
    await flushPromises();
});
