import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import AlertError from '@/components/AlertError.vue';

describe('AlertError', () => {
    it('renders the default accessible alert title', () => {
        const wrapper = mount(AlertError, {
            props: { errors: [] },
        });

        expect(wrapper.get('[role="alert"]').attributes('role')).toBe('alert');
        expect(wrapper.get('[data-slot="alert-title"]').text()).toBe(
            'Something went wrong.',
        );
        expect(wrapper.findAll('li')).toHaveLength(0);
    });

    it('deduplicates localized messages while preserving their order', () => {
        const wrapper = mount(AlertError, {
            props: {
                errors: [
                    'Le nom est obligatoire.',
                    'رقم الهاتف مطلوب.',
                    'Le nom est obligatoire.',
                ],
                title: 'Veuillez corriger les erreurs',
            },
        });

        expect(wrapper.get('[data-slot="alert-title"]').text()).toBe(
            'Veuillez corriger les erreurs',
        );
        expect(wrapper.findAll('li').map((item) => item.text())).toEqual([
            'Le nom est obligatoire.',
            'رقم الهاتف مطلوب.',
        ]);
    });

    it('reacts when the caller replaces the validation messages', async () => {
        const wrapper = mount(AlertError, {
            props: { errors: ['Ancienne erreur'] },
        });

        await wrapper.setProps({ errors: ['Nouvelle erreur'] });

        expect(wrapper.findAll('li').map((item) => item.text())).toEqual([
            'Nouvelle erreur',
        ]);
        expect(wrapper.text()).not.toContain('Ancienne erreur');
    });
});
