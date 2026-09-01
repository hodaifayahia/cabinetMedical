import { invoke, isTauri } from '@tauri-apps/api/core';
import { onBeforeUnmount, onMounted, ref } from 'vue';

import { normalizeNativeUpdateCheck } from '@/pages/configuration/updateContract';

/**
 * Watches for a newly published desktop version while the app is in use.
 *
 * Why polling rather than a push channel: the server has no broadcasting
 * service, and adding one would be another process to run and secure for a
 * message that is never urgent to the second. Checking on launch and then every
 * few minutes means a version published in the back office reaches everyone who
 * is working, which is what "send it to everyone online" actually needs.
 *
 * The check is cheap. It is one small GET that answers 204 when nothing is new.
 */

/** How long to wait between checks while the app stays open. */
export const UPDATE_POLL_INTERVAL_MS = 15 * 60 * 1000;

/** How long to wait after launch before the first check. */
export const UPDATE_FIRST_CHECK_DELAY_MS = 20 * 1000;

export type AvailableUpdate = {
    version: string;
    currentVersion: string | null;
};

/**
 * Read a native check result and decide whether to tell the user.
 *
 * Kept separate from the timers so it can be tested without a running app.
 */
export const availableUpdateFrom = (raw: unknown): AvailableUpdate | null => {
    const result = normalizeNativeUpdateCheck(raw);

    if (!result || !result.update) {
        return null;
    }

    const version = result.update.version;

    if (typeof version !== 'string' || version.trim() === '') {
        return null;
    }

    const currentVersion = result.update.current_version;

    return {
        version: version.trim(),
        currentVersion:
            typeof currentVersion === 'string' && currentVersion.trim() !== ''
                ? currentVersion.trim()
                : null,
    };
};

/**
 * Poll for a new version for as long as the calling component is mounted.
 *
 * Outside the desktop shell this does nothing at all, so it is safe to mount in
 * a layout the browser also renders.
 */
export const useDesktopUpdateWatcher = () => {
    const available = ref<AvailableUpdate | null>(null);
    const dismissed = ref(false);

    let timer: ReturnType<typeof setInterval> | null = null;
    let firstCheck: ReturnType<typeof setTimeout> | null = null;

    const check = async (): Promise<void> => {
        if (!isTauri()) {
            return;
        }

        try {
            available.value = availableUpdateFrom(
                await invoke<unknown>('check_for_signed_update'),
            );
        } catch {
            // A failed check is not worth interrupting anyone over. The next
            // tick tries again, and the settings page still reports errors
            // properly when someone checks by hand.
        }
    };

    const dismiss = (): void => {
        dismissed.value = true;
    };

    const stop = (): void => {
        if (firstCheck !== null) {
            clearTimeout(firstCheck);
            firstCheck = null;
        }

        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    };

    onMounted(() => {
        if (!isTauri()) {
            return;
        }

        // Not immediately on launch: opening the app is the worst moment to
        // spend bandwidth, and a clinic starting its day should not wait.
        firstCheck = setTimeout(() => void check(), UPDATE_FIRST_CHECK_DELAY_MS);
        timer = setInterval(() => void check(), UPDATE_POLL_INTERVAL_MS);
    });

    onBeforeUnmount(stop);

    return { available, dismissed, check, dismiss, stop };
};
