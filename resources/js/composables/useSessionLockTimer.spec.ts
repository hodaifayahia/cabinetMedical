import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import type { Ref } from 'vue';

import type { SessionLockState } from '@/lib/sessionLockTimer';
import { useSessionLockTimer } from './useSessionLockTimer';

vi.mock('@inertiajs/vue3', () => ({
    router: { flushAll: vi.fn(), post: vi.fn() },
}));

const mockedPost = vi.mocked(router.post);
const mockedFlushAll = vi.mocked(router.flushAll);

type Timer = ReturnType<typeof useSessionLockTimer>;
type ActivityHandler = (event: Partial<Event> & { isTrusted: boolean }) => void;

const lockState = (
    remainingSeconds = 900,
    instanceId = 'instance-a',
    idleTimeoutSeconds = 900,
): SessionLockState => ({ idleTimeoutSeconds, remainingSeconds, instanceId });

const mountTimer = (state: Ref<SessionLockState | null>) => {
    let timer: Timer | undefined;
    const addListener = vi.spyOn(window, 'addEventListener');
    const wrapper = mount(
        defineComponent({
            setup() {
                timer = useSessionLockTimer(state);

                return () => h('div');
            },
        }),
    );
    const handler = addListener.mock.calls.find(
        ([name]) => name === 'keydown',
    )?.[1] as unknown as ActivityHandler;

    addListener.mockRestore();

    return { wrapper, timer: timer!, activity: handler };
};

const trusted = (target: EventTarget | null = document.body) => ({
    isTrusted: true,
    target,
});

const settle = async () => {
    for (let index = 0; index < 5; index += 1) {
        await Promise.resolve();
    }
};

describe('useSessionLockTimer', () => {
    let fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-05T09:00:00Z'));
        fetchMock = vi
            .fn<typeof fetch>()
            .mockResolvedValue(new Response(null, { status: 204 }));
        vi.stubGlobal('fetch', fetchMock);
        document.cookie =
            'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('countdown', () => {
        it('stays idle without a server state', async () => {
            const { timer } = mountTimer(ref(null));

            await vi.advanceTimersByTimeAsync(10_000);

            expect(timer.remainingSeconds.value).toBe(0);
            expect(timer.isExpiringSoon.value).toBe(false);
            expect(timer.isPrivacyShieldActive.value).toBe(false);
            expect(mockedPost).not.toHaveBeenCalled();
        });

        it('starts from the server remaining time and counts down every second', async () => {
            const { timer } = mountTimer(ref(lockState(275)));

            expect(timer.remainingSeconds.value).toBe(275);

            await vi.advanceTimersByTimeAsync(10_000);

            expect(timer.remainingSeconds.value).toBe(265);
        });

        it('flags the final minute as expiring soon', async () => {
            const { timer } = mountTimer(ref(lockState(61)));

            expect(timer.isExpiringSoon.value).toBe(false);

            await vi.advanceTimersByTimeAsync(1_000);

            expect(timer.remainingSeconds.value).toBe(60);
            expect(timer.isExpiringSoon.value).toBe(true);
        });

        it('applies a new server state immediately', async () => {
            const state = ref<SessionLockState | null>(lockState(100));
            const { timer } = mountTimer(state);

            state.value = lockState(700);
            await nextTick();

            expect(timer.remainingSeconds.value).toBe(700);
        });

        it('reacts to deep changes of the server state', async () => {
            const state = ref<SessionLockState | null>(lockState(100));
            const { timer } = mountTimer(state);

            state.value!.remainingSeconds = 300;
            await nextTick();

            expect(timer.remainingSeconds.value).toBe(300);
        });

        it('resets to zero when the server state disappears', async () => {
            const state = ref<SessionLockState | null>(lockState(100));
            const { timer } = mountTimer(state);

            state.value = null;
            await nextTick();
            await vi.advanceTimersByTimeAsync(200_000);

            expect(timer.remainingSeconds.value).toBe(0);
            expect(mockedPost).not.toHaveBeenCalled();
        });
    });

    describe('idle lock', () => {
        it('locks the session once the countdown reaches zero', async () => {
            window.history.replaceState({}, '', '/app/patients?page=2');
            const { timer } = mountTimer(ref(lockState(3, 'instance-z')));

            await vi.advanceTimersByTimeAsync(3_000);

            expect(mockedFlushAll).toHaveBeenCalledTimes(1);
            expect(mockedPost).toHaveBeenCalledTimes(1);
            expect(mockedPost).toHaveBeenCalledWith(
                '/session/lock/idle',
                {
                    intended: '/app/patients?page=2',
                    session_instance_id: 'instance-z',
                },
                expect.objectContaining({
                    preserveScroll: true,
                    replace: true,
                }),
            );
            expect(timer.isPrivacyShieldActive.value).toBe(true);
            expect(timer.remainingSeconds.value).toBe(0);
        });

        it('locks immediately when the server says no time is left', () => {
            mountTimer(ref(lockState(0)));

            expect(mockedPost).toHaveBeenCalledTimes(1);
        });

        it('sends only one lock request while it is in flight', async () => {
            mountTimer(ref(lockState(1)));

            await vi.advanceTimersByTimeAsync(10_000);

            expect(mockedPost).toHaveBeenCalledTimes(1);
        });

        it('can lock again after the previous request finished', async () => {
            mountTimer(ref(lockState(1)));
            await vi.advanceTimersByTimeAsync(1_000);
            const options = mockedPost.mock.calls[0]?.[2] as {
                onFinish: () => void;
            };

            options.onFinish();
            await vi.advanceTimersByTimeAsync(1_000);

            expect(mockedPost).toHaveBeenCalledTimes(2);
        });

        it('re-checks the countdown when the window becomes visible again', async () => {
            const { timer } = mountTimer(ref(lockState(600)));
            vi.setSystemTime(Date.now() + 120_000);

            Object.defineProperty(document, 'visibilityState', {
                configurable: true,
                get: () => 'visible',
            });
            document.dispatchEvent(new Event('visibilitychange'));

            expect(timer.remainingSeconds.value).toBe(480);
            Reflect.deleteProperty(document, 'visibilityState');
        });

        it('ignores visibility changes to hidden', () => {
            const { timer } = mountTimer(ref(lockState(600)));
            vi.setSystemTime(Date.now() + 120_000);

            Object.defineProperty(document, 'visibilityState', {
                configurable: true,
                get: () => 'hidden',
            });
            document.dispatchEvent(new Event('visibilitychange'));

            expect(timer.remainingSeconds.value).toBe(600);
            Reflect.deleteProperty(document, 'visibilityState');
        });

        it('stops ticking after unmount', async () => {
            const { wrapper } = mountTimer(ref(lockState(2)));

            wrapper.unmount();
            await vi.advanceTimersByTimeAsync(10_000);

            expect(mockedPost).not.toHaveBeenCalled();
        });
    });

    describe('activity heartbeat', () => {
        it('listens to every kind of user activity', () => {
            const addListener = vi.spyOn(window, 'addEventListener');

            mount(
                defineComponent({
                    setup() {
                        useSessionLockTimer(ref(lockState()));

                        return () => h('div');
                    },
                }),
            );

            const names = addListener.mock.calls.map(([name]) => name);

            for (const name of [
                'pointerdown',
                'pointermove',
                'keydown',
                'scroll',
                'wheel',
                'touchstart',
            ]) {
                expect(names).toContain(name);
            }
        });

        it('removes its listeners on unmount', () => {
            const removeListener = vi.spyOn(window, 'removeEventListener');
            const { wrapper, activity } = mountTimer(ref(lockState()));

            wrapper.unmount();

            expect(removeListener).toHaveBeenCalledWith('keydown', activity);
            expect(removeListener).toHaveBeenCalledWith('scroll', activity);
        });

        it('reports trusted activity to the server with the session instance', async () => {
            document.cookie = 'XSRF-TOKEN=csrf%2Bvalue; path=/';
            const { activity } = mountTimer(ref(lockState(500, 'instance-q')));

            activity(trusted());
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(fetchMock).toHaveBeenCalledWith('/session/activity', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-MediSmart-Session-Instance': 'instance-q',
                    'X-XSRF-TOKEN': 'csrf+value',
                },
            });
        });

        it('omits the XSRF header when there is no token cookie', async () => {
            const { activity } = mountTimer(ref(lockState(500)));

            activity(trusted());
            await settle();

            const headers = fetchMock.mock.calls[0]?.[1]?.headers as Record<
                string,
                string
            >;

            expect(headers).not.toHaveProperty('X-XSRF-TOKEN');
        });

        it('extends the visible countdown after the server records activity', async () => {
            const { timer, activity } = mountTimer(ref(lockState(500)));
            await vi.advanceTimersByTimeAsync(5_000);
            expect(timer.remainingSeconds.value).toBe(495);

            activity(trusted());
            await settle();

            expect(timer.remainingSeconds.value).toBe(900);
        });

        it('ignores untrusted, synthetic events', async () => {
            const { activity } = mountTimer(ref(lockState(500)));

            activity({ isTrusted: false, target: document.body });
            window.dispatchEvent(new KeyboardEvent('keydown'));
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('ignores activity inside an opted-out region', async () => {
            const region = document.createElement('div');
            region.setAttribute('data-session-lock-no-activity', '');
            const inner = document.createElement('button');
            region.appendChild(inner);
            document.body.appendChild(region);
            const { activity } = mountTimer(ref(lockState(500)));

            activity(trusted(inner));
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('accepts activity whose target is not an element', async () => {
            const { activity } = mountTimer(ref(lockState(500)));

            activity(trusted(window));
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        it('throttles heartbeats to one every thirty seconds', async () => {
            const { activity } = mountTimer(ref(lockState(800)));

            activity(trusted());
            await settle();
            await vi.advanceTimersByTimeAsync(29_999);
            activity(trusted());
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(1);
            activity(trusted());
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        it('does not send overlapping heartbeats', async () => {
            fetchMock.mockImplementation(() => new Promise(() => undefined));
            const { activity } = mountTimer(ref(lockState(800)));

            activity(trusted());
            vi.setSystemTime(Date.now() + 60_000);
            activity(trusted());
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        it('locks instead of reporting activity once the deadline passed', async () => {
            const { activity } = mountTimer(ref(lockState(5)));
            vi.setSystemTime(Date.now() + 6_000);

            activity(trusted());
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
            expect(mockedPost).toHaveBeenCalledWith(
                '/session/lock/idle',
                expect.anything(),
                expect.anything(),
            );
        });

        it('ignores activity while the lock request is in flight', async () => {
            const { activity } = mountTimer(ref(lockState(1)));
            await vi.advanceTimersByTimeAsync(1_000);

            activity(trusted());
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('ignores activity without a server state', async () => {
            const { activity } = mountTimer(ref(null));

            activity(trusted());
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it.each([409, 419, 423])(
            'sends the user to the lock screen when the server answers %s',
            async (status) => {
                const assign = vi.fn();
                vi.stubGlobal('location', {
                    pathname: '/app',
                    search: '',
                    assign,
                });
                fetchMock.mockResolvedValue(new Response(null, { status }));
                const { timer, activity } = mountTimer(ref(lockState(400)));

                activity(trusted());
                await settle();

                expect(assign).toHaveBeenCalledWith('/session/locked');
                expect(timer.remainingSeconds.value).toBe(400);
            },
        );

        it('does not leave the page after a successful heartbeat', async () => {
            const assign = vi.fn();
            vi.stubGlobal('location', { pathname: '/app', search: '', assign });
            const { activity } = mountTimer(ref(lockState(400)));

            activity(trusted());
            await settle();

            expect(assign).not.toHaveBeenCalled();
        });

        it('does not extend the countdown for an unexpected success code', async () => {
            fetchMock.mockResolvedValue(new Response('{}', { status: 200 }));
            const { timer, activity } = mountTimer(ref(lockState(400)));

            activity(trusted());
            await settle();

            expect(timer.remainingSeconds.value).toBe(400);
        });

        it('keeps the countdown when the heartbeat fails offline', async () => {
            fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
            const { timer, activity } = mountTimer(ref(lockState(400)));

            activity(trusted());
            await settle();

            expect(timer.remainingSeconds.value).toBe(400);

            // The in-flight flag is released, so a later heartbeat is sent.
            fetchMock.mockResolvedValue(new Response(null, { status: 204 }));
            await vi.advanceTimersByTimeAsync(30_000);
            activity(trusted());
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(2);
            expect(timer.remainingSeconds.value).toBe(900);
        });

        it('does not extend the countdown for a session that changed meanwhile', async () => {
            let respond: (response: Response) => void = () => undefined;
            fetchMock.mockImplementation(
                () =>
                    new Promise<Response>((resolve) => {
                        respond = resolve;
                    }),
            );
            const state = ref<SessionLockState | null>(
                lockState(400, 'instance-a'),
            );
            const { timer, activity } = mountTimer(state);

            activity(trusted());
            state.value = lockState(200, 'instance-b');
            await nextTick();
            respond(new Response(null, { status: 204 }));
            await settle();

            expect(timer.remainingSeconds.value).toBe(200);
        });
    });
});
