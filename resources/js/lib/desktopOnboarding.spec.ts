import { isTauri } from '@tauri-apps/api/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    hasCompletedDesktopOnboarding,
    listenForPlatformOnboardingLocation,
    markDesktopOnboardingComplete,
    markDesktopOnboardingForPlatformLocation,
} from './desktopOnboarding';

vi.mock('@tauri-apps/api/core', () => ({
    isTauri: vi.fn(),
}));

const mockedIsTauri = vi.mocked(isTauri);

describe('desktop onboarding profile state', () => {
    beforeEach(() => {
        window.localStorage.clear();
        mockedIsTauri.mockReturnValue(false);
    });

    it('does not persist onboarding state in the hosted browser', () => {
        markDesktopOnboardingComplete();

        expect(hasCompletedDesktopOnboarding()).toBe(false);
        expect(window.localStorage.length).toBe(0);
    });

    it('remembers completion in a Tauri installation profile', () => {
        mockedIsTauri.mockReturnValue(true);

        expect(hasCompletedDesktopOnboarding()).toBe(false);

        markDesktopOnboardingComplete();

        expect(hasCompletedDesktopOnboarding()).toBe(true);
    });

    it('fails safely when persistent storage is unavailable', () => {
        mockedIsTauri.mockReturnValue(true);
        const getItem = vi
            .spyOn(Storage.prototype, 'getItem')
            .mockImplementation(() => {
                throw new DOMException('storage unavailable');
            });
        const setItem = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw new DOMException('storage unavailable');
            });

        expect(() => markDesktopOnboardingComplete()).not.toThrow();
        expect(hasCompletedDesktopOnboarding()).toBe(false);

        getItem.mockRestore();
        setItem.mockRestore();
    });

    it('marks only a verified same-origin Filament location visit', () => {
        mockedIsTauri.mockReturnValue(true);

        markDesktopOnboardingForPlatformLocation(
            new CustomEvent('inertia:location', {
                detail: { url: new URL('/admin', window.location.origin) },
            }),
        );

        expect(hasCompletedDesktopOnboarding()).toBe(true);

        window.localStorage.clear();
        markDesktopOnboardingForPlatformLocation(
            new CustomEvent('inertia:location', {
                detail: { url: 'https://example.test/admin' },
            }),
        );

        expect(hasCompletedDesktopOnboarding()).toBe(false);
    });

    it('listens on the document where Inertia dispatches location events', () => {
        mockedIsTauri.mockReturnValue(true);
        const stop = listenForPlatformOnboardingLocation();

        document.dispatchEvent(
            new CustomEvent('inertia:location', {
                detail: { url: new URL('/admin', window.location.origin) },
            }),
        );

        expect(hasCompletedDesktopOnboarding()).toBe(true);

        stop();
        window.localStorage.clear();
        document.dispatchEvent(
            new CustomEvent('inertia:location', {
                detail: { url: new URL('/admin', window.location.origin) },
            }),
        );

        expect(hasCompletedDesktopOnboarding()).toBe(false);
    });
});
