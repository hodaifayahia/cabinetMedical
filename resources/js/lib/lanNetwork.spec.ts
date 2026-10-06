import { invoke, isTauri } from '@tauri-apps/api/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    connectToLanHost,
    discoverLanHosts,
    lanHostLabel,
    lanHostStatus,
    normalizeLanHostAddress,
    openLanFirewall,
    readableNativeError,
    returnToLocalMode,
    runtimeModeStatus,
    setLanHost,
} from '@/lib/lanNetwork';

vi.mock('@tauri-apps/api/core', () => ({
    invoke: vi.fn(),
    isTauri: vi.fn(),
}));

const mockedInvoke = vi.mocked(invoke);
const mockedIsTauri = vi.mocked(isTauri);

describe('normalizeLanHostAddress', () => {
    it.each([
        ['192.168.1.10', 'http://192.168.1.10:47850/'],
        ['  192.168.1.10  ', 'http://192.168.1.10:47850/'],
        ['192.168.1.10:48000', 'http://192.168.1.10:48000/'],
        ['http://192.168.1.10:47850/', 'http://192.168.1.10:47850/'],
        ['http://192.168.1.10:47850/login', 'http://192.168.1.10:47850/'],
        ['CABINET-PC', 'http://cabinet-pc:47850/'],
        ['cabinet-pc.local:47850', 'http://cabinet-pc.local:47850/'],
        ['https://hub.cabinet.dz', 'https://hub.cabinet.dz/'],
    ])('reads %s as %s', (input, expected) => {
        expect(normalizeLanHostAddress(input)).toBe(expected);
    });

    it.each(['', '   ', 'ftp://192.168.1.10', 'http://user:pw@pc/', 'a b'])(
        'refuses %j',
        (input) => {
            expect(normalizeLanHostAddress(input)).toBeNull();
        },
    );
});

describe('native LAN commands', () => {
    beforeEach(() => {
        mockedInvoke.mockReset();
        mockedIsTauri.mockReset();
    });

    it('does not ask for the runtime mode outside the desktop shell', async () => {
        mockedIsTauri.mockReturnValue(false);

        await expect(runtimeModeStatus()).resolves.toBeNull();
        expect(mockedInvoke).not.toHaveBeenCalled();
    });

    it('treats a refused runtime-mode command as unknown', async () => {
        mockedIsTauri.mockReturnValue(true);
        mockedInvoke.mockRejectedValue('not allowed');

        await expect(runtimeModeStatus()).resolves.toBeNull();
    });

    it('uses the exact command names granted by the capabilities', async () => {
        mockedInvoke.mockResolvedValue(null);

        await lanHostStatus();
        await setLanHost(true, 47850);
        await setLanHost(false);
        await openLanFirewall();
        await discoverLanHosts();
        await connectToLanHost('http://192.168.1.10:47850/');
        await returnToLocalMode();

        expect(mockedInvoke.mock.calls).toEqual([
            ['lan_host_status'],
            ['set_lan_host', { enabled: true, port: 47850 }],
            ['set_lan_host', { enabled: false, port: null }],
            ['open_lan_firewall'],
            ['discover_lan_hosts'],
            ['connect_to_lan_host', { url: 'http://192.168.1.10:47850/' }],
            ['use_local_mode'],
        ]);
    });
});

describe('display helpers', () => {
    it('labels a host by its address', () => {
        expect(lanHostLabel('http://192.168.1.10:47850/')).toBe('192.168.1.10');
        expect(lanHostLabel(null)).toBe('');
        expect(lanHostLabel('not a url')).toBe('not a url');
    });

    it('keeps native French errors and falls back otherwise', () => {
        expect(readableNativeError('Port occupé.')).toBe('Port occupé.');
        expect(readableNativeError(new Error('Refusé.'))).toBe('Refusé.');
        expect(readableNativeError({ code: 1 }, 'Échec.')).toBe('Échec.');
        expect(readableNativeError('  ')).toBe('L’opération a échoué.');
    });
});
