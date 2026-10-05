import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import DesktopOnboarding from './DesktopOnboarding.vue';

vi.mock('@/routes', () => ({
    login: () => ({ url: '/login', method: 'get' }),
    register: () => ({ url: '/register', method: 'get' }),
}));

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

const render = (props: { canRegister: boolean; canRestoreBackup?: boolean }) =>
    mount(DesktopOnboarding, {
        props,
        global: { stubs: { Link: LinkStub } },
    });

describe('DesktopOnboarding (extended)', () => {
    it('hides backup restoration by default', () => {
        const wrapper = render({ canRegister: true });

        expect(
            wrapper.find('[data-test="desktop-restore-backup"]').exists(),
        ).toBe(false);
    });

    it('offers backup restoration only when the server allows it', () => {
        const wrapper = render({ canRegister: true, canRestoreBackup: true });
        const restore = wrapper.get('[data-test="desktop-restore-backup"]');

        expect(restore.attributes('href')).toBe('/desktop/restore-backup');
        expect(restore.text()).toContain('Restaurer une sauvegarde');
    });

    it('still offers restoration when registration is closed', () => {
        const wrapper = render({ canRegister: false, canRestoreBackup: true });

        expect(
            wrapper.find('[data-test="desktop-restore-backup"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('[data-test="desktop-create-cabinet"]').exists(),
        ).toBe(false);
    });

    it('wires the dialog labels to rendered elements', () => {
        const wrapper = render({ canRegister: true });
        const dialog = wrapper.get('[role="dialog"]');

        expect(
            wrapper.get(`#${dialog.attributes('aria-labelledby')}`).text(),
        ).toBe('Configurons votre accès au cabinet');
        expect(
            wrapper.get(`#${dialog.attributes('aria-describedby')}`).text(),
        ).toContain('créez votre propre');
    });

    it('always lets an existing account sign in', () => {
        for (const canRegister of [true, false]) {
            const wrapper = render({ canRegister });

            expect(
                wrapper
                    .get('[data-test="desktop-existing-account"]')
                    .attributes('href'),
            ).toBe('/login');
        }
    });

    it('lists the profile details in the documented order', () => {
        const wrapper = render({ canRegister: true });
        const chips = wrapper
            .findAll('span.rounded-full')
            .map((chip) => chip.text())
            .filter(Boolean);

        expect(chips.slice(-5)).toEqual([
            'Nom complet',
            'Téléphone',
            'Adresse e-mail',
            'Spécialité',
            'Cabinet',
        ]);
    });
});
