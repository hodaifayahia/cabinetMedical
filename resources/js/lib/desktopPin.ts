import { isTauri } from '@tauri-apps/api/core';

const DESKTOP_PIN_ENROLLMENT_KEY = 'drclickdz.desktop-pin.enrollment.v1';
const DESKTOP_PIN_ENROLLMENTS_KEY = 'drclickdz.desktop-pin.enrollments.v2';
const MAX_ENROLLMENTS = 12;
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
        return 'Poste Drclick';
    }

    const platform = navigator.platform.trim();

    return platform ? `Poste Drclick · ${platform}` : 'Poste Drclick';
}

function readStoredList(): DesktopPinEnrollment[] {
    const enrollments: DesktopPinEnrollment[] = [];

    try {
        const serialized = window.localStorage.getItem(
            DESKTOP_PIN_ENROLLMENTS_KEY,
        );

        if (serialized) {
            const parsed: unknown = JSON.parse(serialized);

            if (Array.isArray(parsed)) {
                for (const candidate of parsed) {
                    if (
                        isEnrollment(candidate) &&
                        !enrollments.some(
                            (known) => known.userId === candidate.userId,
                        )
                    ) {
                        enrollments.push(candidate);
                    }
                }
            }
        }

        // Installations enrolled before several accounts could share a poste
        // kept a single record: fold it into the list once.
        const legacy = window.localStorage.getItem(DESKTOP_PIN_ENROLLMENT_KEY);

        if (legacy !== null) {
            window.localStorage.removeItem(DESKTOP_PIN_ENROLLMENT_KEY);

            const enrollment: unknown = JSON.parse(legacy);

            if (
                isEnrollment(enrollment) &&
                !enrollments.some((known) => known.userId === enrollment.userId)
            ) {
                enrollments.push(enrollment);
                writeStoredList(enrollments);
            }
        }
    } catch {
        return enrollments;
    }

    return enrollments;
}

function writeStoredList(enrollments: DesktopPinEnrollment[]): boolean {
    try {
        if (enrollments.length === 0) {
            window.localStorage.removeItem(DESKTOP_PIN_ENROLLMENTS_KEY);
        } else {
            window.localStorage.setItem(
                DESKTOP_PIN_ENROLLMENTS_KEY,
                JSON.stringify(enrollments.slice(0, MAX_ENROLLMENTS)),
            );
        }

        return true;
    } catch {
        return false;
    }
}

/**
 * Every account enrolled on this installation, most recently used first. A
 * poste shared by a doctor and an assistant keeps one PIN per person.
 */
export function readDesktopPinEnrollments(): DesktopPinEnrollment[] {
    if (!canUseDesktopStorage()) {
        return [];
    }

    return readStoredList();
}

/**
 * The enrollment of the given account, or the most recently used one.
 */
export function readDesktopPinEnrollment(
    userId?: number | null,
): DesktopPinEnrollment | null {
    const enrollments = readDesktopPinEnrollments();

    if (userId === undefined) {
        return enrollments[0] ?? null;
    }

    return (
        enrollments.find((enrollment) => enrollment.userId === userId) ?? null
    );
}

export function isDesktopPinEnrollmentForUser(
    enrollment: DesktopPinEnrollment | null,
    userId: number | null | undefined,
): boolean {
    return Boolean(enrollment && userId && enrollment.userId === userId);
}

/**
 * Whether this installation already holds a PIN for the account, whichever
 * other accounts were enrolled on it since.
 */
export function hasDesktopPinEnrollmentForUser(
    userId: number | null | undefined,
): boolean {
    return Boolean(userId && readDesktopPinEnrollment(userId));
}

/**
 * Persists only the opaque device identifier and its display label. The PIN
 * itself is never written to WebView storage. Enrolling one account never
 * removes the PIN another account set on the same poste.
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

    return writeStoredList([
        enrollment,
        ...readStoredList().filter((known) => known.userId !== userId),
    ]);
}

/**
 * Moves an account to the top of the list after it signed in with its PIN.
 */
export function touchDesktopPinEnrollment(userId: number): void {
    if (!canUseDesktopStorage()) {
        return;
    }

    const enrollments = readStoredList();
    const current = enrollments.find((known) => known.userId === userId);

    if (current) {
        writeStoredList([
            current,
            ...enrollments.filter((known) => known.userId !== userId),
        ]);
    }
}

/**
 * Forgets one account's PIN on this poste, or every PIN when no account is
 * given.
 */
export function clearDesktopPinEnrollment(userId?: number): void {
    if (!canUseDesktopStorage()) {
        return;
    }

    try {
        if (userId === undefined) {
            window.localStorage.removeItem(DESKTOP_PIN_ENROLLMENTS_KEY);
            window.localStorage.removeItem(DESKTOP_PIN_ENROLLMENT_KEY);

            return;
        }

        writeStoredList(
            readStoredList().filter((known) => known.userId !== userId),
        );
    } catch {
        // A user must always be able to fall back to account credentials.
    }
}
