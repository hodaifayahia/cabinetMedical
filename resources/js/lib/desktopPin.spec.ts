import { isTauri } from '@tauri-apps/api/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearDesktopPinEnrollment,
    generateDesktopDeviceToken,
    isDesktopPinEnrollmentForUser,
    isValidDesktopPin,
    normalizeDesktopPin,
    readDesktopPinEnrollment,
    saveDesktopPinEnrollment,
} from './desktopPin';

vi.mock('@tauri-apps/api/core', () => ({
    isTauri: vi.fn(),
}));

const mockedIsTauri = vi.mocked(isTauri);

describe('desktop PIN installation state', () => {
    beforeEach(() => {
        window.localStorage.clear();
        mockedIsTauri.mockReturnValue(false);
        vi.restoreAllMocks();
    });

    it('is unavailable and never persists a device in the hosted browser', () => {
        expect(() => generateDesktopDeviceToken()).toThrow();
        expect(
            saveDesktopPinEnrollment(
                'ab'.repeat(32),
                'Poste accueil',
                7,
                'Docteur Test',
            ),
        ).toBe(false);
        expect(readDesktopPinEnrollment()).toBeNull();
        expect(window.localStorage).toHaveLength(0);
    });

    it('generates independent 32-byte cryptographic device tokens', () => {
        mockedIsTauri.mockReturnValue(true);

        const first = generateDesktopDeviceToken();
        const second = generateDesktopDeviceToken();

        expect(first).toMatch(/^[a-f0-9]{64}$/u);
        expect(second).toMatch(/^[a-f0-9]{64}$/u);
        expect(first).not.toBe(second);
    });

    it('stores an account-bound enrollment without storing a PIN', () => {
        mockedIsTauri.mockReturnValue(true);
        const token = '01'.repeat(32);

        expect(
            saveDesktopPinEnrollment(
                token,
                '  Secrétariat principal  ',
                42,
                '  Amel Benali  ',
            ),
        ).toBe(true);
        const enrollment = readDesktopPinEnrollment();

        expect(enrollment).toEqual({
            version: 1,
            deviceToken: token,
            deviceName: 'Secrétariat principal',
            userId: 42,
            userName: 'Amel Benali',
        });
        expect(isDesktopPinEnrollmentForUser(enrollment, 42)).toBe(true);
        expect(isDesktopPinEnrollmentForUser(enrollment, 43)).toBe(false);
        expect(isDesktopPinEnrollmentForUser(enrollment, null)).toBe(false);

        const serialized = window.localStorage.key(0)
            ? window.localStorage.getItem(window.localStorage.key(0)!)
            : null;

        expect(serialized).not.toContain('4821');
        expect(serialized).not.toContain('pin');
    });

    it('fails closed for malformed or legacy unbound enrollment records', () => {
        mockedIsTauri.mockReturnValue(true);
        window.localStorage.setItem(
            'drclickdz.desktop-pin.enrollment.v1',
            JSON.stringify({
                version: 1,
                deviceToken: '01'.repeat(32),
                deviceName: 'Poste ancien',
            }),
        );

        expect(readDesktopPinEnrollment()).toBeNull();
        expect(window.localStorage).toHaveLength(0);
        expect(
            saveDesktopPinEnrollment(
                'not-a-token',
                'Poste invalide',
                1,
                'Test',
            ),
        ).toBe(false);
    });

    it('clears only the local installation enrollment', () => {
        mockedIsTauri.mockReturnValue(true);
        saveDesktopPinEnrollment(
            '01'.repeat(32),
            'Poste accueil',
            8,
            'Karim Test',
        );

        clearDesktopPinEnrollment();

        expect(readDesktopPinEnrollment()).toBeNull();
        expect(window.localStorage).toHaveLength(0);
    });

    it('handles unavailable WebView storage without throwing', () => {
        mockedIsTauri.mockReturnValue(true);
        const setItem = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw new DOMException('storage unavailable');
            });

        expect(
            saveDesktopPinEnrollment(
                '01'.repeat(32),
                'Poste accueil',
                8,
                'Karim Test',
            ),
        ).toBe(false);
        expect(() => clearDesktopPinEnrollment()).not.toThrow();

        setItem.mockRestore();
        const getItem = vi
            .spyOn(Storage.prototype, 'getItem')
            .mockImplementation(() => {
                throw new DOMException('storage unavailable');
            });

        expect(readDesktopPinEnrollment()).toBeNull();
        getItem.mockRestore();
    });

    it('normalizes input to exactly four numeric characters', () => {
        expect(normalizeDesktopPin(' 1a2-345 ')).toBe('1234');
        expect(isValidDesktopPin('1234')).toBe(true);
        expect(isValidDesktopPin('123')).toBe(false);
        expect(isValidDesktopPin('12345')).toBe(false);
        expect(isValidDesktopPin('12a4')).toBe(false);
    });
});
