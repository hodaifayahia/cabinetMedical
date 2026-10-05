import { invoke, isTauri } from '@tauri-apps/api/core';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

import {
    availableUpdateFrom,
    UPDATE_FIRST_CHECK_DELAY_MS,
    UPDATE_POLL_INTERVAL_MS,
    useDesktopUpdateWatcher,
} from '@/lib/desktopUpdateWatcher';

vi.mock('@tauri-apps/api/core', () => ({
    invoke: vi.fn(),
    isTauri: vi.fn(),
}));

const mockedInvoke = vi.mocked(invoke);
const mockedIsTauri = vi.mocked(isTauri);

const updateReply = (version = '1.3.0', currentVersion = '1.2.0') => ({
    update: {
        version,
        current_version: currentVersion,
        published_at: null,
    },
    checked_at: 1_760_000_000,
});

const currentReply = { update: null, checked_at: 1_760_000_000 };

type Watcher = ReturnType<typeof useDesktopUpdateWatcher>;

const mountWatcher = () => {
    let watcher: Watcher | undefined;
    const wrapper = mount(
        defineComponent({
            setup() {
                watcher = useDesktopUpdateWatcher();

                return () => h('div');
            },
        }),
    );

    return { wrapper, watcher: watcher! };
};

describe('availableUpdateFrom (extended)', () => {
    it('accepts a pre-release version', () => {
        expect(availableUpdateFrom(updateReply('2.0.0-beta.1'))).toEqual({
            version: '2.0.0-beta.1',
            currentVersion: '1.2.0',
        });
    });

    it('refuses a reply with an extra key', () => {
        expect(
            availableUpdateFrom({ ...updateReply(), debug: true }),
        ).toBeNull();
    });

    it('refuses metadata with an extra key', () => {
        const reply = updateReply();

        expect(
            availableUpdateFrom({
                ...reply,
                update: { ...reply.update, url: 'https://x' },
            }),
        ).toBeNull();
    });

    it.each(['1.3', 'v1.3.0', ' 1.3.0', '01.3.0', ''])(
        'refuses the non-canonical version %j',
        (version) => {
            expect(availableUpdateFrom(updateReply(version))).toBeNull();
        },
    );

    it.each([0, -1, 1.5, '1760000000'])(
        'refuses checked_at %j',
        (checkedAt) => {
            expect(
                availableUpdateFrom({
                    ...updateReply(),
                    checked_at: checkedAt,
                }),
            ).toBeNull();
        },
    );

    it('refuses a published_at carrying control characters', () => {
        const reply = updateReply();

        expect(
            availableUpdateFrom({
                ...reply,
                update: { ...reply.update, published_at: '2026\n10' },
            }),
        ).toBeNull();
    });

    it('accepts a bounded published_at string', () => {
        const reply = updateReply();

        expect(
            availableUpdateFrom({
                ...reply,
                update: {
                    ...reply.update,
                    published_at: '2026-10-01T08:00:00Z',
                },
            }),
        ).toEqual({ version: '1.3.0', currentVersion: '1.2.0' });
    });

    it('refuses an array reply', () => {
        expect(availableUpdateFrom([updateReply()])).toBeNull();
    });
});

describe('useDesktopUpdateWatcher', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        mockedIsTauri.mockReturnValue(true);
        mockedInvoke.mockResolvedValue(updateReply());
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('starts empty and not dismissed', () => {
        const { watcher } = mountWatcher();

        expect(watcher.available.value).toBeNull();
        expect(watcher.dismissed.value).toBe(false);
    });

    it('waits for the first-check delay before asking the shell', async () => {
        const { watcher } = mountWatcher();

        await vi.advanceTimersByTimeAsync(UPDATE_FIRST_CHECK_DELAY_MS - 1);
        expect(mockedInvoke).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(mockedInvoke).toHaveBeenCalledTimes(1);
        expect(mockedInvoke).toHaveBeenCalledWith('check_for_signed_update');
        expect(watcher.available.value).toEqual({
            version: '1.3.0',
            currentVersion: '1.2.0',
        });
    });

    it('keeps polling on the configured interval', async () => {
        mountWatcher();

        await vi.advanceTimersByTimeAsync(UPDATE_POLL_INTERVAL_MS);
        expect(mockedInvoke).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(UPDATE_POLL_INTERVAL_MS * 2);
        expect(mockedInvoke).toHaveBeenCalledTimes(4);
    });

    it('clears the offer once the shell reports the app is current', async () => {
        const { watcher } = mountWatcher();

        await vi.advanceTimersByTimeAsync(UPDATE_FIRST_CHECK_DELAY_MS);
        expect(watcher.available.value).not.toBeNull();

        mockedInvoke.mockResolvedValue(currentReply);
        await vi.advanceTimersByTimeAsync(UPDATE_POLL_INTERVAL_MS);

        expect(watcher.available.value).toBeNull();
    });

    it('keeps the last known offer when a check fails', async () => {
        const { watcher } = mountWatcher();

        await vi.advanceTimersByTimeAsync(UPDATE_FIRST_CHECK_DELAY_MS);
        mockedInvoke.mockRejectedValue(new Error('offline'));

        await expect(watcher.check()).resolves.toBeUndefined();
        expect(watcher.available.value).toEqual({
            version: '1.3.0',
            currentVersion: '1.2.0',
        });
    });

    it('treats a malformed shell reply as no update', async () => {
        mockedInvoke.mockResolvedValue({ update: { version: '9.9.9' } });
        const { watcher } = mountWatcher();

        await watcher.check();

        expect(watcher.available.value).toBeNull();
    });

    it('updates the offered version when a newer one appears', async () => {
        const { watcher } = mountWatcher();

        await watcher.check();
        mockedInvoke.mockResolvedValue(updateReply('1.4.0'));
        await watcher.check();

        expect(watcher.available.value?.version).toBe('1.4.0');
    });

    it('remembers a dismissal without discarding the offer', async () => {
        const { watcher } = mountWatcher();

        await watcher.check();
        watcher.dismiss();

        expect(watcher.dismissed.value).toBe(true);
        expect(watcher.available.value).not.toBeNull();
    });

    it('stops polling when the component unmounts', async () => {
        const { wrapper } = mountWatcher();

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(UPDATE_POLL_INTERVAL_MS * 3);

        expect(mockedInvoke).not.toHaveBeenCalled();
    });

    it('can be stopped by hand, idempotently', async () => {
        const { watcher } = mountWatcher();

        watcher.stop();
        watcher.stop();
        await vi.advanceTimersByTimeAsync(UPDATE_POLL_INTERVAL_MS * 2);

        expect(mockedInvoke).not.toHaveBeenCalled();
    });

    it('does nothing at all outside the desktop shell', async () => {
        mockedIsTauri.mockReturnValue(false);
        const { watcher } = mountWatcher();

        await vi.advanceTimersByTimeAsync(UPDATE_POLL_INTERVAL_MS * 2);
        await watcher.check();

        expect(mockedInvoke).not.toHaveBeenCalled();
        expect(watcher.available.value).toBeNull();
        expect(vi.getTimerCount()).toBe(0);
    });
});
