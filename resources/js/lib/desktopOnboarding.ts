import { isTauri } from '@tauri-apps/api/core';

const DESKTOP_ONBOARDING_COMPLETE_KEY =
    'drclickdz.desktop-onboarding.complete.v1';

/**
 * The first-run choice belongs to the installed WebView profile, not to a
 * cabinet or browser account. Tauri's persistent localStorage gives each
 * Windows profile its own marker while leaving the hosted web app untouched.
 */
export function hasCompletedDesktopOnboarding(): boolean {
    if (!isTauri() || typeof window === 'undefined') {
        return false;
    }

    try {
        return (
            window.localStorage.getItem(DESKTOP_ONBOARDING_COMPLETE_KEY) ===
            'true'
        );
    } catch {
        // Hardened WebViews can disable storage. In that case it is safer to
        // offer setup again than to strand a new installation at login.
        return false;
    }
}

export function markDesktopOnboardingComplete(): void {
    if (!isTauri() || typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(DESKTOP_ONBOARDING_COMPLETE_KEY, 'true');
    } catch {
        // Authentication must never fail because storage is unavailable.
    }
}

/**
 * Inertia emits this event immediately before a verified external location
 * response is followed. It lets a successful platform-admin login persist
 * desktop setup before the browser leaves Vue for Filament.
 */
export function markDesktopOnboardingForPlatformLocation(event: Event): void {
    if (!isTauri() || typeof window === 'undefined') {
        return;
    }

    const detail = (event as CustomEvent<{ url?: string | URL }>).detail;

    if (!detail?.url) {
        return;
    }

    try {
        const destination = new URL(String(detail.url), window.location.origin);

        if (
            destination.origin === window.location.origin &&
            (destination.pathname === '/admin' ||
                destination.pathname.startsWith('/admin/'))
        ) {
            markDesktopOnboardingComplete();
        }
    } catch {
        // Ignore malformed third-party events without affecting login.
    }
}

export function listenForPlatformOnboardingLocation(): () => void {
    if (typeof document === 'undefined') {
        return () => undefined;
    }

    document.addEventListener(
        'inertia:location',
        markDesktopOnboardingForPlatformLocation,
    );

    return () => {
        document.removeEventListener(
            'inertia:location',
            markDesktopOnboardingForPlatformLocation,
        );
    };
}
