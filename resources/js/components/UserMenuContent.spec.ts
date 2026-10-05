import { router } from '@inertiajs/vue3';
import { isTauri } from '@tauri-apps/api/core';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, reactive } from 'vue';
import UserMenuContent from './UserMenuContent.vue';

vi.mock('@tauri-apps/api/core', () => ({
    isTauri: vi.fn(),
}));

vi.mock('@/routes', () => ({
    logout: (options?: { query?: Record<string, unknown> }) => ({
        url: options?.query ? '/logout?desktop=1' : '/logout',
        method: 'post',
    }),
}));

vi.mock('@/routes/profile', () => ({
    edit: () => ({ url: '/settings/profile', method: 'get' }),
}));

const page = reactive({
    props: {
        sessionLock: { instanceId: 'instance-7' } as null | {
            instanceId: string;
        },
    },
});

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent: define, h } = await import('vue');

    return {
        usePage: () => page,
        router: { flushAll: vi.fn(), post: vi.fn() },
        Link: define({
            inheritAttrs: false,
            props: { href: { type: [String, Object], required: true } },
            setup(props, { attrs, slots }) {
                return () =>
                    h(
                        'a',
                        {
                            ...attrs,
                            href:
                                typeof props.href === 'string'
                                    ? props.href
                                    : (props.href as { url: string }).url,
                        },
                        slots.default?.(),
                    );
            },
        }),
    };
});

const PassThrough = defineComponent({
    inheritAttrs: false,
    template: '<div v-bind="$attrs"><slot /></div>',
});

const mockedIsTauri = vi.mocked(isTauri);
const mockedPost = vi.mocked(router.post);
const mockedFlushAll = vi.mocked(router.flushAll);

const render = async () => {
    const wrapper = mount(UserMenuContent, {
        props: {
            user: {
                id: 1,
                name: 'Dr Amel',
                email: 'amel@cabinet.dz',
            } as never,
        },
        global: {
            stubs: {
                DropdownMenuGroup: PassThrough,
                DropdownMenuItem: PassThrough,
                DropdownMenuLabel: PassThrough,
                DropdownMenuSeparator: true,
                UserInfo: true,
            },
        },
    });
    await flushPromises();

    return wrapper;
};

describe('UserMenuContent', () => {
    beforeEach(() => {
        mockedIsTauri.mockReturnValue(false);
        page.props.sessionLock = { instanceId: 'instance-7' };
        window.history.replaceState({}, '', '/app/patients?search=amel');
    });

    it('logs out to the plain endpoint in the browser', async () => {
        const wrapper = await render();

        expect(
            wrapper.get('[data-test="logout-button"]').attributes('href'),
        ).toBe('/logout');
    });

    it('marks desktop logouts so the server keeps the PIN flow', async () => {
        mockedIsTauri.mockReturnValue(true);
        const wrapper = await render();

        expect(
            wrapper.get('[data-test="logout-button"]').attributes('href'),
        ).toBe('/logout?desktop=1');
    });

    it('flushes cached pages before logging out', async () => {
        const wrapper = await render();

        await wrapper.get('[data-test="logout-button"]').trigger('click');

        expect(mockedFlushAll).toHaveBeenCalledTimes(1);
    });

    it('locks the session and returns to the current page afterwards', async () => {
        const wrapper = await render();

        await wrapper.get('[data-test="lock-session-button"]').trigger('click');

        expect(mockedFlushAll).toHaveBeenCalledTimes(1);
        expect(mockedPost).toHaveBeenCalledWith(
            '/session/lock',
            {
                intended: '/app/patients?search=amel',
                session_instance_id: 'instance-7',
            },
            { replace: true },
        );
    });

    it('sends an empty instance id when no lock state is shared', async () => {
        page.props.sessionLock = null;
        const wrapper = await render();

        await wrapper.get('[data-test="lock-session-button"]').trigger('click');

        expect(mockedPost.mock.calls[0]?.[1]).toMatchObject({
            session_instance_id: '',
        });
    });

    it('keeps menu actions from counting as session activity', async () => {
        const wrapper = await render();

        for (const selector of [
            '[data-test="lock-session-button"]',
            '[data-test="logout-button"]',
        ]) {
            expect(wrapper.get(selector).attributes()).toHaveProperty(
                'data-session-lock-no-activity',
            );
        }
    });

    it('links to the profile settings', async () => {
        const wrapper = await render();

        expect(
            wrapper
                .findAll('a')
                .some(
                    (link) => link.attributes('href') === '/settings/profile',
                ),
        ).toBe(true);
    });
});
