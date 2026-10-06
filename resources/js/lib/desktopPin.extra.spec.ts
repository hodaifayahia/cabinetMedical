import { isTauri } from '@tauri-apps/api/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearDesktopPinEnrollment,
    defaultDesktopDeviceName,
    generateDesktopDeviceToken,
    isDesktopPinEnrollmentForUser,
    isValidDesktopPin,
    normalizeDesktopPin,
    hasDesktopPinEnrollmentForUser,
    readDesktopPinEnrollment,
    readDesktopPinEnrollments,
    saveDesktopPinEnrollment,
    touchDesktopPinEnrollment,
} from './desktopPin';
import type { DesktopPinEnrollment } from './desktopPin';

vi.mock('@tauri-apps/api/core', () => ({
    isTauri: vi.fn(),
}));

const mockedIsTauri = vi.mocked(isTauri);
const STORAGE_KEY = 'drclickdz.desktop-pin.enrollment.v1';
const LIST_KEY = 'drclickdz.desktop-pin.enrollments.v2';
const VALID_TOKEN = '0123456789abcdef'.repeat(4);

const validRecord = (
    overrides: Partial<Record<keyof DesktopPinEnrollment, unknown>> = {},
) => ({
    version: 1,
    deviceToken: VALID_TOKEN,
    deviceName: 'Poste accueil',
    userId: 12,
    userName: 'Dr Amel Benali',
    ...overrides,
});

const storeRaw = (value: unknown) =>
    window.localStorage.setItem(
        STORAGE_KEY,
        typeof value === 'string' ? value : JSON.stringify(value),
    );

describe('desktop PIN enrollment storage (extended)', () => {
    beforeEach(() => {
        window.localStorage.clear();
        mockedIsTauri.mockReturnValue(true);
    });

    afterEach(() => {
        window.localStorage.clear();
    });

    it('reads a well-formed record stored under the versioned key', () => {
        storeRaw(validRecord());

        expect(readDesktopPinEnrollment()).toEqual(validRecord());
    });

    it('writes the record under the exact versioned list key', () => {
        expect(saveDesktopPinEnrollment(VALID_TOKEN, 'Poste', 3, 'Nadia')).toBe(
            true,
        );

        expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
        expect(JSON.parse(window.localStorage.getItem(LIST_KEY)!)).toEqual([
            {
                version: 1,
                deviceToken: VALID_TOKEN,
                deviceName: 'Poste',
                userId: 3,
                userName: 'Nadia',
            },
        ]);
    });

    it.each([
        ['an unsupported version', { version: 2 }],
        ['a string version', { version: '1' }],
        [
            'an uppercase device token',
            { deviceToken: VALID_TOKEN.toUpperCase() },
        ],
        ['a 63-character token', { deviceToken: VALID_TOKEN.slice(1) }],
        ['a 65-character token', { deviceToken: `${VALID_TOKEN}a` }],
        ['a non-hex token', { deviceToken: 'g'.repeat(64) }],
        ['a numeric token', { deviceToken: 1234 }],
        ['a blank device name', { deviceName: '   ' }],
        ['a 101-character device name', { deviceName: 'x'.repeat(101) }],
        ['a missing device name', { deviceName: undefined }],
        ['a zero user id', { userId: 0 }],
        ['a negative user id', { userId: -4 }],
        ['a fractional user id', { userId: 1.5 }],
        ['a string user id', { userId: '12' }],
        ['an unsafe integer user id', { userId: Number.MAX_SAFE_INTEGER + 1 }],
        ['a blank user name', { userName: '  ' }],
        ['a 256-character user name', { userName: 'n'.repeat(256) }],
        ['a numeric user name', { userName: 42 }],
    ])('discards a stored record with %s', (_label, overrides) => {
        storeRaw(validRecord(overrides));

        expect(readDesktopPinEnrollment()).toBeNull();
        expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it.each([
        ['null', 'null'],
        ['an array', '[]'],
        ['a number', '42'],
        ['a string', '"enrolled"'],
    ])('discards a stored JSON %s', (_label, raw) => {
        storeRaw(raw);

        expect(readDesktopPinEnrollment()).toBeNull();
        expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('returns null without throwing for unparseable JSON', () => {
        storeRaw('{not json');

        expect(() => readDesktopPinEnrollment()).not.toThrow();
        expect(readDesktopPinEnrollment()).toBeNull();
    });

    it('returns null for an empty stored value', () => {
        storeRaw('');

        expect(readDesktopPinEnrollment()).toBeNull();
    });

    it('accepts the boundary lengths for device and user names', () => {
        storeRaw(
            validRecord({
                deviceName: 'd'.repeat(100),
                userName: 'u'.repeat(255),
                userId: Number.MAX_SAFE_INTEGER,
            }),
        );

        expect(readDesktopPinEnrollment()).not.toBeNull();
    });

    it('validates the trimmed values when saving', () => {
        expect(
            saveDesktopPinEnrollment(
                VALID_TOKEN,
                `   ${'d'.repeat(100)}   `,
                5,
                '  Karim  ',
            ),
        ).toBe(true);
        expect(readDesktopPinEnrollment()?.deviceName).toBe('d'.repeat(100));

        expect(saveDesktopPinEnrollment(VALID_TOKEN, '    ', 5, 'Karim')).toBe(
            false,
        );
        expect(saveDesktopPinEnrollment(VALID_TOKEN, 'Poste', 5, ' ')).toBe(
            false,
        );
    });

    it.each([0, -1, 2.5, Number.NaN])(
        'refuses to save an enrollment for user id %s',
        (userId) => {
            expect(
                saveDesktopPinEnrollment(VALID_TOKEN, 'Poste', userId, 'Karim'),
            ).toBe(false);
            expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
        },
    );

    it('does not overwrite a valid record when a new save is rejected', () => {
        saveDesktopPinEnrollment(VALID_TOKEN, 'Poste A', 5, 'Karim');

        expect(saveDesktopPinEnrollment('bad', 'Poste B', 5, 'Karim')).toBe(
            false,
        );
        expect(readDesktopPinEnrollment()?.deviceName).toBe('Poste A');
    });

    it('keeps the PIN of every account enrolled on this installation', () => {
        saveDesktopPinEnrollment(VALID_TOKEN, 'Poste A', 5, 'Karim');
        saveDesktopPinEnrollment('ff'.repeat(32), 'Poste A', 9, 'Nadia');

        expect(readDesktopPinEnrollment()?.userId).toBe(9);
        expect(readDesktopPinEnrollment(5)?.deviceToken).toBe(VALID_TOKEN);
        expect(readDesktopPinEnrollment(9)?.deviceToken).toBe('ff'.repeat(32));
        expect(hasDesktopPinEnrollmentForUser(5)).toBe(true);
        expect(hasDesktopPinEnrollmentForUser(9)).toBe(true);
        expect(readDesktopPinEnrollments().map((e) => e.userId)).toEqual([
            9, 5,
        ]);
    });

    it('re-enrolling an account replaces only that account', () => {
        saveDesktopPinEnrollment(VALID_TOKEN, 'Poste A', 5, 'Karim');
        saveDesktopPinEnrollment('ff'.repeat(32), 'Poste A', 9, 'Nadia');
        saveDesktopPinEnrollment('ee'.repeat(32), 'Poste A', 5, 'Karim');

        expect(readDesktopPinEnrollments()).toHaveLength(2);
        expect(readDesktopPinEnrollment(5)?.deviceToken).toBe('ee'.repeat(32));
        expect(readDesktopPinEnrollment(9)?.deviceToken).toBe('ff'.repeat(32));
    });

    it('forgets one account without touching the others', () => {
        saveDesktopPinEnrollment(VALID_TOKEN, 'Poste A', 5, 'Karim');
        saveDesktopPinEnrollment('ff'.repeat(32), 'Poste A', 9, 'Nadia');

        clearDesktopPinEnrollment(9);

        expect(readDesktopPinEnrollment(9)).toBeNull();
        expect(readDesktopPinEnrollment(5)?.userName).toBe('Karim');
    });

    it('moves an account to the top after it signs in', () => {
        saveDesktopPinEnrollment(VALID_TOKEN, 'Poste A', 5, 'Karim');
        saveDesktopPinEnrollment('ff'.repeat(32), 'Poste A', 9, 'Nadia');

        touchDesktopPinEnrollment(5);

        expect(readDesktopPinEnrollment()?.userId).toBe(5);
    });

    it('migrates a single-account record from an older version', () => {
        storeRaw(validRecord());

        expect(readDesktopPinEnrollments()).toEqual([validRecord()]);
        expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
        expect(readDesktopPinEnrollment(12)).toEqual(validRecord());
    });

    it('clear removes only its own key', () => {
        saveDesktopPinEnrollment(VALID_TOKEN, 'Poste', 5, 'Karim');
        window.localStorage.setItem('other.key', 'kept');

        clearDesktopPinEnrollment();

        expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
        expect(window.localStorage.getItem('other.key')).toBe('kept');
    });

    it('clear swallows a storage failure', () => {
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new DOMException('denied');
        });

        expect(() => clearDesktopPinEnrollment()).not.toThrow();
    });

    it('a corrupt record that cannot be removed still reads as null', () => {
        storeRaw(validRecord({ version: 7 }));
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new DOMException('denied');
        });

        expect(readDesktopPinEnrollment()).toBeNull();
    });

    it('outside Tauri, reading ignores even a valid stored record', () => {
        storeRaw(validRecord());
        mockedIsTauri.mockReturnValue(false);

        expect(readDesktopPinEnrollment()).toBeNull();
    });

    it('outside Tauri, clearing leaves storage untouched', () => {
        storeRaw(validRecord());
        mockedIsTauri.mockReturnValue(false);

        clearDesktopPinEnrollment();

        expect(window.localStorage.getItem(STORAGE_KEY)).not.toBeNull();
    });
});

describe('desktop device token generation', () => {
    beforeEach(() => {
        mockedIsTauri.mockReturnValue(true);
    });

    it('hex-encodes every random byte with zero padding', () => {
        vi.spyOn(window.crypto, 'getRandomValues').mockImplementation(
            <T extends ArrayBufferView | null>(array: T): T => {
                const bytes = array as unknown as Uint8Array;

                bytes.forEach((_, index) => {
                    bytes[index] = index;
                });

                return array;
            },
        );

        const token = generateDesktopDeviceToken();

        expect(token).toHaveLength(64);
        expect(token.startsWith('000102030405060708090a0b0c0d0e0f')).toBe(true);
        expect(token.endsWith('1f')).toBe(true);
    });

    it('requests exactly 32 bytes from the cryptographic RNG', () => {
        const spy = vi.spyOn(window.crypto, 'getRandomValues');

        generateDesktopDeviceToken();

        const argument = spy.mock.calls[0]?.[0] as Uint8Array;

        expect(argument).toBeInstanceOf(Uint8Array);
        expect(argument.byteLength).toBe(32);
    });

    it('refuses to generate a token when the RNG is missing', () => {
        vi.stubGlobal('crypto', {});

        expect(() => generateDesktopDeviceToken()).toThrow(
            'Secure desktop storage is unavailable.',
        );
    });
});

describe('desktop PIN input helpers', () => {
    it.each([
        ['', ''],
        ['12', '12'],
        ['123456', '1234'],
        ['a1b2c3d4e5', '1234'],
        ['12 34', '1234'],
        ['١٢٣٤', ''],
        ['abcd', ''],
    ])('normalizes %j to %j', (input, expected) => {
        expect(normalizeDesktopPin(input)).toBe(expected);
    });

    it.each(['0000', '9999', '0420'])('accepts %s as a PIN', (pin) => {
        expect(isValidDesktopPin(pin)).toBe(true);
    });

    it.each(['', ' 1234', '1234 ', '12.4', '١٢٣٤', '-123'])(
        'rejects %j as a PIN',
        (pin) => {
            expect(isValidDesktopPin(pin)).toBe(false);
        },
    );

    it('matches enrollments only to the same positive user id', () => {
        const enrollment = validRecord() as DesktopPinEnrollment;

        expect(isDesktopPinEnrollmentForUser(null, 12)).toBe(false);
        expect(isDesktopPinEnrollmentForUser(enrollment, undefined)).toBe(
            false,
        );
        expect(isDesktopPinEnrollmentForUser(enrollment, 0)).toBe(false);
        expect(isDesktopPinEnrollmentForUser(enrollment, 12)).toBe(true);
    });
});

describe('default desktop device name', () => {
    it('includes the WebView platform when it is known', () => {
        vi.spyOn(navigator, 'platform', 'get').mockReturnValue('  Win32  ');

        expect(defaultDesktopDeviceName()).toBe('Poste Drclick · Win32');
    });

    it('falls back to the bare product label for an empty platform', () => {
        vi.spyOn(navigator, 'platform', 'get').mockReturnValue('   ');

        expect(defaultDesktopDeviceName()).toBe('Poste Drclick');
    });

    it('stays within the 100-character device name limit', () => {
        vi.spyOn(navigator, 'platform', 'get').mockReturnValue('Win32');

        expect(defaultDesktopDeviceName().length).toBeLessThanOrEqual(100);
    });
});
