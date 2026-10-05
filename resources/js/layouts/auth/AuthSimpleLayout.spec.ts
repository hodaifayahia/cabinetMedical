import { isTauri } from '@tauri-apps/api/core';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import AuthSimpleLayout from './AuthSimpleLayout.vue';

vi.mock('@tauri-apps/api/core', () => ({
    isTauri: vi.fn(),
}));

vi.mock('@/routes', () => ({
    home: () => ({ url: '/', method: 'get' }),
    login: () => ({ url: '/login', method: 'get' }),
}));

const page = reactive({ component: 'auth/Login' });

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        usePage: () => page,
        Link: defineComponent({
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

const mockedIsTauri = vi.mocked(isTauri);

const render = async () => {
    const wrapper = mount(AuthSimpleLayout, {
        props: { title: 'Connexion', description: 'Accédez au cabinet' },
        slots: { default: '<form data-test="slot-form"></form>' },
        global: {
            stubs: { AppLogoIcon: true },
        },
    });
    await flushPromises();

    return wrapper;
};

describe('AuthSimpleLayout', () => {
    beforeEach(() => {
        mockedIsTauri.mockReturnValue(false);
        page.component = 'auth/Login';
    });

    it('links the logo to the public home page in the browser', async () => {
        const wrapper = await render();

        expect(
            wrapper
                .get('[aria-label="Retour à l’accueil Drclick"]')
                .attributes('href'),
        ).toBe('/');
        expect(
            wrapper
                .find('[aria-label="Espace de travail Drclick sécurisé"]')
                .exists(),
        ).toBe(false);
    });

    it('keeps desktop users inside authentication', async () => {
        mockedIsTauri.mockReturnValue(true);
        const wrapper = await render();

        expect(
            wrapper
                .get('[aria-label="Retour à l’accueil Drclick"]')
                .attributes('href'),
        ).toBe('/login');
        expect(
            wrapper
                .find('[aria-label="Espace de travail Drclick sécurisé"]')
                .exists(),
        ).toBe(true);
    });

    it('renders the title, description, and form slot', async () => {
        const wrapper = await render();

        expect(wrapper.text()).toContain('Connexion');
        expect(wrapper.text()).toContain('Accédez au cabinet');
        expect(wrapper.find('[data-test="slot-form"]').exists()).toBe(true);
    });

    it.each(['auth/Register', 'auth/JoinCabinet', 'auth/DesktopCabinetLogin'])(
        'widens the layout for %s',
        async (component) => {
            page.component = component;
            const wrapper = await render();

            expect(wrapper.find('.max-w-3xl').exists()).toBe(true);
            expect(wrapper.find('.max-w-md').exists()).toBe(false);
        },
    );

    it('keeps the narrow layout for the login form', async () => {
        const wrapper = await render();

        expect(wrapper.find('.max-w-md').exists()).toBe(true);
    });
});
