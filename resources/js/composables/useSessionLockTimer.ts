import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    deadlineFromServerState,
    isTrustedUserActivity,
    normalizedIdleTimeoutSeconds,
    remainingSecondsAt,
} from '@/lib/sessionLockTimer';
import type { SessionLockState } from '@/lib/sessionLockTimer';

const ACTIVITY_HEARTBEAT_INTERVAL_MS = 30_000;
const ACTIVITY_EVENTS = [
    'pointerdown',
    'pointermove',
    'keydown',
    'scroll',
    'wheel',
    'touchstart',
] as const;

const currentRelativeUrl = (): string =>
    `${window.location.pathname}${window.location.search}`;

const xsrfCookie = (): string | null => {
    const prefix = 'XSRF-TOKEN=';
    const encoded = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(prefix))
        ?.slice(prefix.length);

    return encoded ? decodeURIComponent(encoded) : null;
};

export const useSessionLockTimer = (
    state: Readonly<Ref<SessionLockState | null>>,
) => {
    const remainingSeconds = ref(0);
    const timeoutSeconds = ref(0);
    const isPrivacyShieldActive = ref(false);
    let deadlineMilliseconds = 0;
    let ticker: ReturnType<typeof setInterval> | null = null;
    let heartbeatInFlight = false;
    let lastHeartbeatAt = 0;
    let lockRequestInFlight = false;

    const isExpiringSoon = computed(
        () => remainingSeconds.value > 0 && remainingSeconds.value <= 60,
    );

    const applyServerState = (nextState: SessionLockState | null) => {
        if (nextState === null) {
            timeoutSeconds.value = 0;
            remainingSeconds.value = 0;
            deadlineMilliseconds = 0;

            return;
        }

        timeoutSeconds.value = normalizedIdleTimeoutSeconds(
            nextState.idleTimeoutSeconds,
        );
        deadlineMilliseconds = deadlineFromServerState(Date.now(), nextState);
        remainingSeconds.value = remainingSecondsAt(
            deadlineMilliseconds,
            Date.now(),
        );
    };

    const requestIdleLock = () => {
        if (lockRequestInFlight || state.value === null) {
            return;
        }

        lockRequestInFlight = true;
        isPrivacyShieldActive.value = true;
        remainingSeconds.value = 0;
        router.flushAll();
        router.post(
            '/session/lock/idle',
            {
                intended: currentRelativeUrl(),
                session_instance_id: state.value.instanceId,
            },
            {
                preserveScroll: true,
                replace: true,
                onFinish: () => {
                    lockRequestInFlight = false;
                },
            },
        );
    };

    const tick = () => {
        if (state.value === null || lockRequestInFlight) {
            return;
        }

        remainingSeconds.value = remainingSecondsAt(
            deadlineMilliseconds,
            Date.now(),
        );

        if (remainingSeconds.value === 0) {
            requestIdleLock();
        }
    };

    const sendActivityHeartbeat = async () => {
        const now = Date.now();
        const instanceId = state.value?.instanceId;

        if (
            !instanceId ||
            heartbeatInFlight ||
            now - lastHeartbeatAt < ACTIVITY_HEARTBEAT_INTERVAL_MS
        ) {
            return;
        }

        heartbeatInFlight = true;
        lastHeartbeatAt = now;

        const token = xsrfCookie();
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-MediSmart-Session-Instance': instanceId,
        };

        if (token !== null) {
            headers['X-XSRF-TOKEN'] = token;
        }

        try {
            const response = await fetch('/session/activity', {
                method: 'POST',
                credentials: 'same-origin',
                headers,
            });

            if (
                response.status === 409 ||
                response.status === 419 ||
                response.status === 423
            ) {
                window.location.assign('/session/locked');

                return;
            }

            if (
                response.status === 204 &&
                !lockRequestInFlight &&
                state.value !== null &&
                state.value.instanceId === instanceId &&
                timeoutSeconds.value > 0
            ) {
                // Anchor the client deadline at request start. The server can
                // only have recorded activity at or after this instant, so
                // the visible countdown never outlives the server deadline.
                deadlineMilliseconds = now + timeoutSeconds.value * 1000;
                remainingSeconds.value = remainingSecondsAt(
                    deadlineMilliseconds,
                    Date.now(),
                );
            }
        } catch {
            // The desktop app can be offline from the internet while its local
            // Laravel runtime remains usable. A failed heartbeat never extends
            // the server-side deadline.
        } finally {
            heartbeatInFlight = false;
        }
    };

    const registerActivity = (event: Event) => {
        if (
            !isTrustedUserActivity(event) ||
            lockRequestInFlight ||
            state.value === null
        ) {
            return;
        }

        const target = event.target;

        if (
            target instanceof Element &&
            target.closest('[data-session-lock-no-activity]') !== null
        ) {
            return;
        }

        if (remainingSecondsAt(deadlineMilliseconds, Date.now()) === 0) {
            requestIdleLock();

            return;
        }

        void sendActivityHeartbeat();
    };

    const handleVisibilityChange = () => {
        if (document.visibilityState === 'visible') {
            tick();
        }
    };

    watch(state, applyServerState, { immediate: true, deep: true });

    onMounted(() => {
        ACTIVITY_EVENTS.forEach((eventName) => {
            window.addEventListener(eventName, registerActivity, {
                passive: true,
            });
        });
        document.addEventListener('visibilitychange', handleVisibilityChange);
        ticker = setInterval(tick, 1000);
        tick();
    });

    onBeforeUnmount(() => {
        ACTIVITY_EVENTS.forEach((eventName) => {
            window.removeEventListener(eventName, registerActivity);
        });
        document.removeEventListener(
            'visibilitychange',
            handleVisibilityChange,
        );

        if (ticker !== null) {
            clearInterval(ticker);
            ticker = null;
        }
    });

    return {
        isExpiringSoon,
        isPrivacyShieldActive,
        remainingSeconds,
    };
};
