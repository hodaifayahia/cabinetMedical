import { router } from '@inertiajs/vue3';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';
import { initializeFlashToast } from './flashToast';

vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn() },
}));

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
}));

const mockedOn = vi.mocked(router.on);

const registeredHandler = () => {
    initializeFlashToast();

    const call = mockedOn.mock.calls.at(-1);

    expect(call?.[0]).toBe('flash');

    return call![1] as (event: unknown) => void;
};

const flashEvent = (flash: unknown) =>
    new CustomEvent('flash', {
        detail: { flash },
    });

describe('initializeFlashToast', () => {
    beforeEach(() => {
        mockedOn.mockReset();
    });

    it('subscribes once to Inertia flash events', () => {
        initializeFlashToast();

        expect(mockedOn).toHaveBeenCalledTimes(1);
        expect(mockedOn).toHaveBeenCalledWith('flash', expect.any(Function));
    });

    it.each(['success', 'info', 'warning', 'error'] as const)(
        'shows a %s toast with the flashed message',
        (type) => {
            registeredHandler()(
                flashEvent({ toast: { type, message: `Message ${type}` } }),
            );

            expect(toast[type]).toHaveBeenCalledTimes(1);
            expect(toast[type]).toHaveBeenCalledWith(`Message ${type}`);
        },
    );

    it.each([
        ['no toast in the flash bag', flashEvent({ status: 'saved' })],
        ['an empty flash bag', flashEvent({})],
        ['a null flash bag', flashEvent(null)],
        ['an event without detail', new CustomEvent('flash')],
    ])('stays silent for %s', (_label, event) => {
        registeredHandler()(event);

        for (const method of ['success', 'info', 'warning', 'error'] as const) {
            expect(toast[method]).not.toHaveBeenCalled();
        }
    });
});
