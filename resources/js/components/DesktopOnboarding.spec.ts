import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';
import DesktopOnboarding from './DesktopOnboarding.vue';

const LinkStub = defineComponent({
    inheritAttrs: false,
    props: {
        href: {
            type: [String, Object],
            required: true,
        },
    },
    template:
        '<a :href="typeof href === \'string\' ? href : href.url" v-bind="$attrs"><slot /></a>',
});

const renderOnboarding = (canRegister = true) =>
    mount(DesktopOnboarding, {
        props: { canRegister },
        global: {
            stubs: { Link: LinkStub },
        },
    });

describe('DesktopOnboarding', () => {
    it('presents a blocking setup dialog with the authoritative next steps', () => {
        const wrapper = renderOnboarding();

        expect(wrapper.get('[role="dialog"]').attributes('aria-modal')).toBe(
            'true',
        );
        expect(
            wrapper
                .get('[data-test="desktop-create-cabinet"]')
                .attributes('href'),
        ).toBe('/register');
        expect(
            wrapper
                .get('[data-test="desktop-existing-cabinet"]')
                .attributes('href'),
        ).toBe('/desktop/cabinet-login');
        expect(
            wrapper.find('[data-test="desktop-join-cabinet"]').exists(),
        ).toBe(false);
        expect(
            wrapper
                .get('[data-test="desktop-existing-account"]')
                .attributes('href'),
        ).toBe('/login');

        for (const detail of [
            'Nom complet',
            'Téléphone',
            'Adresse e-mail',
            'Spécialité',
            'Cabinet',
        ]) {
            expect(wrapper.text()).toContain(detail);
        }
    });

    it('does not offer cabinet creation when registration is disabled', () => {
        const wrapper = renderOnboarding(false);

        expect(
            wrapper.find('[data-test="desktop-create-cabinet"]').exists(),
        ).toBe(false);
        expect(wrapper.text()).toContain(
            'Création temporairement indisponible',
        );
        expect(
            wrapper.find('[data-test="desktop-existing-cabinet"]').exists(),
        ).toBe(true);
    });
});
