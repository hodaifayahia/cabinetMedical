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
const STORAGE_KEY = 'drclickdz.desktop-onboarding.complete.v1';

const locationEvent = (detail: unknown) =>
    new CustomEvent('inertia:location', { detail });

describe('desktop onboarding (extended)', () => {
    beforeEach(() => {
        window.localStorage.clear();
        mockedIsTauri.mockReturnValue(true);
    });

    it('persists the literal string "true" under the versioned key', () => {
        markDesktopOnboardingComplete();

        expect(window.localStorage.getItem(STORAGE_KEY)).toBe('true');
    });

    it.each(['1', 'TRUE', 'yes', 'false', ''])(
        'does not treat a stored %j as completion',
        (value) => {
            window.localStorage.setItem(STORAGE_KEY, value);

            expect(hasCompletedDesktopOnboarding()).toBe(false);
        },
    );

    it('reads completion written by an earlier session of the same profile', () => {
        window.localStorage.setItem(STORAGE_KEY, 'true');

        expect(hasCompletedDesktopOnboarding()).toBe(true);
    });

    it('ignores a stored marker when the page runs in a browser', () => {
        window.localStorage.setItem(STORAGE_KEY, 'true');
        mockedIsTauri.mockReturnValue(false);

        expect(hasCompletedDesktopOnboarding()).toBe(false);
    });

    it.each([
        '/admin',
        '/admin/',
        '/admin/users',
        '/admin/cabinets/4/edit',
        '/admin?tab=licences',
    ])('marks completion for the same-origin Filament path %s', (url) => {
        markDesktopOnboardingForPlatformLocation(locationEvent({ url }));

        expect(hasCompletedDesktopOnboarding()).toBe(true);
    });

    it.each([
        '/administrator',
        '/adminer',
        '/app/admin',
        '/dashboard',
        '/',
        'https://evil.example/admin',
        '//evil.example/admin',
    ])('does not mark completion for %s', (url) => {
        markDesktopOnboardingForPlatformLocation(locationEvent({ url }));

        expect(hasCompletedDesktopOnboarding()).toBe(false);
    });

    it('accepts a URL object for the destination', () => {
        markDesktopOnboardingForPlatformLocation(
            locationEvent({
                url: new URL('/admin/settings', window.location.origin),
            }),
        );

        expect(hasCompletedDesktopOnboarding()).toBe(true);
    });

    it.each([
        ['no detail', undefined],
        ['a null detail', null],
        ['an empty detail', {}],
        ['an empty url', { url: '' }],
    ])('ignores a location event with %s', (_label, detail) => {
        expect(() =>
            markDesktopOnboardingForPlatformLocation(locationEvent(detail)),
        ).not.toThrow();
        expect(hasCompletedDesktopOnboarding()).toBe(false);
    });

    it('ignores a plain Event without detail', () => {
        markDesktopOnboardingForPlatformLocation(new Event('inertia:location'));

        expect(hasCompletedDesktopOnboarding()).toBe(false);
    });

    it('ignores a malformed URL without throwing', () => {
        expect(() =>
            markDesktopOnboardingForPlatformLocation(
                locationEvent({ url: 'http://[broken' }),
            ),
        ).not.toThrow();
        expect(hasCompletedDesktopOnboarding()).toBe(false);
    });

    it('does nothing for an admin location in the hosted browser', () => {
        mockedIsTauri.mockReturnValue(false);

        markDesktopOnboardingForPlatformLocation(
            locationEvent({ url: '/admin' }),
        );

        expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('does not let a storage failure escape the location listener', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('quota');
        });

        expect(() =>
            markDesktopOnboardingForPlatformLocation(
                locationEvent({ url: '/admin' }),
            ),
        ).not.toThrow();
    });

    it('only reacts to inertia:location events', () => {
        const stop = listenForPlatformOnboardingLocation();

        document.dispatchEvent(
            new CustomEvent('inertia:navigate', { detail: { url: '/admin' } }),
        );
        window.dispatchEvent(locationEvent({ url: '/admin' }));

        expect(hasCompletedDesktopOnboarding()).toBe(false);

        stop();
    });

    it('can be stopped more than once without error', () => {
        const stop = listenForPlatformOnboardingLocation();

        stop();

        expect(() => stop()).not.toThrow();
    });

    it('registers and removes the same handler on the document', () => {
        const add = vi.spyOn(document, 'addEventListener');
        const remove = vi.spyOn(document, 'removeEventListener');

        const stop = listenForPlatformOnboardingLocation();
        stop();

        expect(add).toHaveBeenCalledWith(
            'inertia:location',
            markDesktopOnboardingForPlatformLocation,
        );
        expect(remove).toHaveBeenCalledWith(
            'inertia:location',
            markDesktopOnboardingForPlatformLocation,
        );
    });
});
