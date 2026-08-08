import { isTauri } from '@tauri-apps/api/core';

const DESKTOP_PIN_ENROLLMENT_KEY = 'drclickdz.desktop-pin.enrollment.v1';
const DEVICE_TOKEN_BYTES = 32;
const DEVICE_TOKEN_PATTERN = /^[a-f0-9]{64}$/u;
const PIN_PATTERN = /^\d{4}$/u;

export type DesktopPinEnrollment = {
    version: 1;
    deviceToken: string;
    deviceName: string;
    userId: number;
    userName: string;
};

function canUseDesktopStorage(): boolean {
    return isTauri() && typeof window !== 'undefined';
}

function isEnrollment(value: unknown): value is DesktopPinEnrollment {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const candidate = value as Partial<DesktopPinEnrollment>;

    return (
        candidate.version === 1 &&
        typeof candidate.deviceToken === 'string' &&
        DEVICE_TOKEN_PATTERN.test(candidate.deviceToken) &&
        typeof candidate.deviceName === 'string' &&
        candidate.deviceName.trim().length > 0 &&
        candidate.deviceName.length <= 100 &&
        typeof candidate.userId === 'number' &&
        Number.isSafeInteger(candidate.userId) &&
        candidate.userId > 0 &&
        typeof candidate.userName === 'string' &&
        candidate.userName.trim().length > 0 &&
        candidate.userName.length <= 255
    );
}

/**
 * Creates a per-installation identifier using the WebView cryptographic RNG.
 * The token remains in memory until the server confirms PIN enrollment.
 */
export function generateDesktopDeviceToken(): string {
    if (!canUseDesktopStorage() || !window.crypto?.getRandomValues) {
        throw new Error('Secure desktop storage is unavailable.');
    }

    const bytes = new Uint8Array(DEVICE_TOKEN_BYTES);
    window.crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join(
        '',
    );
}

export function normalizeDesktopPin(value: string): string {
    return value.replace(/\D/gu, '').slice(0, 4);
}

export function isValidDesktopPin(value: string): boolean {
    return PIN_PATTERN.test(value);
}

export function defaultDesktopDeviceName(): string {
    if (typeof navigator === 'undefined') {
        return 'Poste DrClickDz';
    }

    const platform = navigator.platform.trim();

    return platform ? `Poste DrClickDz · ${platform}` : 'Poste DrClickDz';
}

export function readDesktopPinEnrollment(): DesktopPinEnrollment | null {
    if (!canUseDesktopStorage()) {
        return null;
    }

    try {
        const serialized = window.localStorage.getItem(
            DESKTOP_PIN_ENROLLMENT_KEY,
        );

        if (!serialized) {
            return null;
        }

        const enrollment: unknown = JSON.parse(serialized);

        if (!isEnrollment(enrollment)) {
            window.localStorage.removeItem(DESKTOP_PIN_ENROLLMENT_KEY);

            return null;
        }

        return enrollment;
    } catch {
        return null;
    }
}

export function isDesktopPinEnrollmentForUser(
    enrollment: DesktopPinEnrollment | null,
    userId: number | null | undefined,
): boolean {
    return Boolean(enrollment && userId && enrollment.userId === userId);
}

/**
 * Persists only the opaque device identifier and its display label. The PIN
 * itself is never written to WebView storage.
 */
export function saveDesktopPinEnrollment(
    deviceToken: string,
    deviceName: string,
    userId: number,
    userName: string,
): boolean {
    if (!canUseDesktopStorage()) {
        return false;
    }

    const enrollment: DesktopPinEnrollment = {
        version: 1,
        deviceToken,
        deviceName: deviceName.trim(),
        userId,
        userName: userName.trim(),
    };

    if (!isEnrollment(enrollment)) {
        return false;
    }

    try {
        window.localStorage.setItem(
            DESKTOP_PIN_ENROLLMENT_KEY,
            JSON.stringify(enrollment),
        );

        return true;
    } catch {
        return false;
    }
}

export function clearDesktopPinEnrollment(): void {
    if (!canUseDesktopStorage()) {
        return;
    }

    try {
        window.localStorage.removeItem(DESKTOP_PIN_ENROLLMENT_KEY);
    } catch {
        // A user must always be able to fall back to account credentials.
    }
}
