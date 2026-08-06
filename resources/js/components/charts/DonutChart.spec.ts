import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import DonutChart from '@/components/charts/DonutChart.vue';

const data = [
    { label: 'Payé', value: 70, color: '#16a34a' },
    { label: 'Impayé', value: 30, color: '#dc2626' },
    { label: 'Sans montant', value: 0, color: '#64748b' },
];

describe('DonutChart', () => {
    it('renders positive segments and an explicit localized center value', () => {
        const wrapper = mount(DonutChart, {
            props: {
                centerLabel: 'المجموع',
                centerValue: '100 دج',
                data,
            },
        });

        const segments = wrapper.findAll('circle.cursor-pointer');

        expect(segments).toHaveLength(2);
        expect(segments.map((segment) => segment.get('title').text())).toEqual([
            'Payé: 70',
            'Impayé: 30',
        ]);
        expect(wrapper.findAll('span').map((item) => item.text())).toEqual([
            '100 دج',
            'المجموع',
        ]);
    });

    it('shows the hovered segment and restores the center summary', async () => {
        const wrapper = mount(DonutChart, {
            props: {
                centerLabel: 'Total encaissé',
                centerValue: '100 DA',
                data,
            },
        });
        const segments = wrapper.findAll('circle.cursor-pointer');

        await segments[1].trigger('mouseenter');

        expect(wrapper.findAll('span').map((item) => item.text())).toEqual([
            '30',
            'Impayé',
        ]);
        expect(segments[0].attributes('style')).toContain('opacity: 0.35');
        expect(segments[1].attributes('stroke-width')).toBe('36');

        await segments[1].trigger('mouseleave');

        expect(wrapper.findAll('span').map((item) => item.text())).toEqual([
            '100 DA',
            'Total encaissé',
        ]);
    });

    it('uses the positive total when no center value is supplied', () => {
        const wrapper = mount(DonutChart, {
            props: { data },
        });

        expect(wrapper.findAll('span').map((item) => item.text())).toEqual([
            '100',
            'Total',
        ]);
    });
});
