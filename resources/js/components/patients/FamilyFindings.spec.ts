import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import FamilyFindings from '@/components/patients/FamilyFindings.vue';
import type { FamilyMedicalRelative } from '@/lib/patientHistory';

const brother: FamilyMedicalRelative = {
    patient_id: 7,
    full_name: 'Ahmed Benali',
    short_name: 'Ahmed B.',
    patient_number: 'P-0007',
    gender: 'male',
    age: 38,
    relation: 'brother',
    relation_label: 'Frère',
    first_degree: true,
    source: 'link',
    link_id: 'abc',
    items: [
        {
            category: 'condition',
            label: 'Maladie chronique',
            text: 'Diabète type 2',
            display: 'Diabète type 2',
        },
        {
            category: 'allergy',
            label: 'Allergie',
            text: 'Pénicilline',
            display: 'Allergie : Pénicilline',
        },
    ],
    summary: 'Diabète type 2 ; Allergie : Pénicilline',
    has_alert: true,
};

const mountFindings = (
    relatives: FamilyMedicalRelative[],
    linkDossiers = false,
) =>
    mount(FamilyFindings, {
        props: { relatives, linkDossiers },
        global: {
            stubs: {
                Link: {
                    props: ['href'],
                    template: '<a :href="href"><slot /></a>',
                },
            },
        },
    });

describe('FamilyFindings', () => {
    it('lists what each relative’s dossier reports', () => {
        const wrapper = mountFindings([brother]);

        expect(wrapper.text()).toContain('Signalé chez les proches');
        expect(wrapper.text()).toContain('Frère — Ahmed B.');
        expect(wrapper.text()).toContain('Diabète type 2');
        expect(wrapper.text()).toContain('Allergie : Pénicilline');
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('links to the relative’s dossier when asked', () => {
        const wrapper = mountFindings([brother], true);

        expect(wrapper.get('a').attributes('href')).toBe('/app/patients/7');
    });

    it('renders nothing when no relative has a finding', () => {
        const wrapper = mountFindings([
            { ...brother, items: [], summary: null, has_alert: false },
        ]);

        expect(wrapper.find('[data-testid="family-findings"]').exists()).toBe(
            false,
        );
    });
});
