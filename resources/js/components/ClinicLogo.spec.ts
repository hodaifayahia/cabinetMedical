import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ClinicLogo from './ClinicLogo.vue';

describe('ClinicLogo', () => {
    it('falls back to the medical mark when the configured image fails', async () => {
        const wrapper = mount(ClinicLogo, {
            props: { src: '/missing-clinic-logo.png' },
            slots: { default: '<span data-test="fallback-mark">D</span>' },
        });

        expect(wrapper.find('[data-test="clinic-logo-image"]').exists()).toBe(
            true,
        );

        await wrapper.get('[data-test="clinic-logo-image"]').trigger('error');

        expect(wrapper.find('[data-test="clinic-logo-image"]').exists()).toBe(
            false,
        );
        expect(wrapper.get('[data-test="fallback-mark"]').text()).toBe('D');
    });

    it('retries when the configured logo URL changes', async () => {
        const wrapper = mount(ClinicLogo, {
            props: { src: '/old-logo.png' },
            slots: { default: '<span data-test="fallback-mark">D</span>' },
        });

        await wrapper.get('[data-test="clinic-logo-image"]').trigger('error');
        await wrapper.setProps({ src: '/new-logo.png' });

        expect(
            wrapper.get('[data-test="clinic-logo-image"]').attributes('src'),
        ).toBe('/new-logo.png');
    });
});
