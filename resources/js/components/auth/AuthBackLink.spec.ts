import { router } from '@inertiajs/vue3';
import { isTauri } from '@tauri-apps/api/core';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import AuthBackLink from './AuthBackLink.vue';

vi.mock('@tauri-apps/api/core', () => ({
    isTauri: vi.fn(),
}));

vi.mock('@/routes', () => ({
    login: () => ({ url: '/login', method: 'get' }),
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent: define, h } = await import('vue');

    return {
        router: { post: vi.fn() },
        Link: define({
            name: 'Link',
            inheritAttrs: false,
            props: {
                href: { type: [String, Object], required: true },
                method: { type: String, default: 'get' },
                as: { type: String, default: 'a' },
            },
            setup(props, { attrs, slots }) {
                return () =>
                    h(
                        props.as,
                        {
                            ...attrs,
                            'data-href':
                                typeof props.href === 'string'
                                    ? props.href
                                    : (props.href as { url: string }).url,
                            'data-method': props.method,
                        },
                        slots.default?.(),
                    );
            },
        }),
    };
});

const mockedIsTauri = vi.mocked(isTauri);
const mockedPost = vi.mocked(router.post);

const render = (props: Record<string, unknown> = {}) =>
    mount(
        defineComponent({
            components: { AuthBackLink },
            setup: () => ({ props }),
            template: '<AuthBackLink v-bind="props" />',
        }),
    );

describe('AuthBackLink', () => {
    beforeEach(() => {
        mockedIsTauri.mockReturnValue(false);
        mockedPost.mockReset();
    });

    it('links back with a GET and the default label in the browser', async () => {
        const wrapper = render({ href: '/' });
        await flushPromises();
        const link = wrapper.get('[data-test="auth-back-link"]');

        expect(link.attributes('data-href')).toBe('/');
        expect(link.attributes('data-method')).toBe('get');
        expect(link.text()).toBe('Retour');
        expect(link.element.tagName).toBe('A');
    });

    it('uses a custom label and element', async () => {
        const wrapper = render({
            href: '/logout',
            label: 'Retour à la connexion',
            as: 'button',
        });
        await flushPromises();
        const link = wrapper.get('[data-test="auth-back-link"]');

        expect(link.text()).toBe('Retour à la connexion');
        expect(link.element.tagName).toBe('BUTTON');
    });

    it('keeps a POST method in the browser', async () => {
        const wrapper = render({ href: '/logout', method: 'post' });
        await flushPromises();

        expect(
            wrapper
                .get('[data-test="auth-back-link"]')
                .attributes('data-method'),
        ).toBe('post');
    });

    it('always points at the login page inside the desktop shell', async () => {
        mockedIsTauri.mockReturnValue(true);
        const wrapper = render({ href: '/', method: 'post' });
        await flushPromises();
        const link = wrapper.get('[data-test="auth-back-link"]');

        expect(link.attributes('data-href')).toBe('/login');
        expect(link.attributes('data-method')).toBe('get');
    });

    it('logs out explicitly when leaving through a POST link', async () => {
        const wrapper = render({ href: '/logout', method: 'post' });
        await flushPromises();

        const event = new MouseEvent('click', { cancelable: true });
        wrapper
            .get('[data-test="auth-back-link"]')
            .element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(mockedPost).toHaveBeenCalledTimes(1);
        expect(mockedPost).toHaveBeenCalledWith(
            '/logout',
            {},
            expect.objectContaining({
                replace: true,
                onFinish: expect.any(Function),
            }),
        );
    });

    it('still logs out from the desktop shell before returning to login', async () => {
        mockedIsTauri.mockReturnValue(true);
        const wrapper = render({ href: '/logout', method: 'post' });
        await flushPromises();

        await wrapper.get('[data-test="auth-back-link"]').trigger('click');

        expect(mockedPost).toHaveBeenCalledWith(
            '/logout',
            {},
            expect.objectContaining({ replace: true }),
        );
    });

    it('does not intercept an ordinary GET link', async () => {
        const wrapper = render({ href: '/register' });
        await flushPromises();

        const event = new MouseEvent('click', { cancelable: true });
        wrapper
            .get('[data-test="auth-back-link"]')
            .element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(mockedPost).not.toHaveBeenCalled();
    });
});
