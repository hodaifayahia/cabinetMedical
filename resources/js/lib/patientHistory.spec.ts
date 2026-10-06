import { describe, expect, it } from 'vitest';

import {
    filledPatientHistory,
    firstDegreeAlerts,
    formatRelativeFinding,
    PATIENT_HISTORY_FIELDS,
    patientHistoryLabel,
    relativesWithFindings,
} from '@/lib/patientHistory';
import type { FamilyMedicalRelative } from '@/lib/patientHistory';

const relative = (
    overrides: Partial<FamilyMedicalRelative> = {},
): FamilyMedicalRelative => ({
    patient_id: 1,
    full_name: 'Ahmed Benali',
    short_name: 'Ahmed B.',
    patient_number: 'P-0001',
    gender: 'male',
    age: 40,
    relation: 'brother',
    relation_label: 'Frère',
    first_degree: true,
    source: 'link',
    link_id: 'link-1',
    items: [
        {
            category: 'condition',
            label: 'Maladie chronique',
            text: 'Diabète type 2',
            display: 'Diabète type 2',
        },
    ],
    summary: 'Diabète type 2',
    has_alert: true,
    ...overrides,
});

describe('patient history labels', () => {
    it('uses one clear label per field, in the usual order', () => {
        expect(PATIENT_HISTORY_FIELDS.map((field) => field.label)).toEqual([
            'Allergies et réactions connues',
            'Maladies chroniques / antécédents médicaux',
            'Antécédents chirurgicaux',
            'Antécédents familiaux',
            'Antécédents gynéco-obstétricaux',
            'Autres antécédents',
        ]);
        expect(patientHistoryLabel('antecedents_medical')).toBe(
            'Maladies chroniques / antécédents médicaux',
        );
    });

    it('keeps only the filled fields', () => {
        expect(
            filledPatientHistory({
                allergies: ' Pénicilline ',
                antecedents_medical: '',
                antecedents_family: null,
                antecedents_gyneco: 'G2P2',
            }),
        ).toEqual([
            {
                key: 'allergies',
                label: 'Allergies et réactions connues',
                value: 'Pénicilline',
            },
            {
                key: 'antecedents_gyneco',
                label: 'Antécédents gynéco-obstétricaux',
                value: 'G2P2',
            },
        ]);
    });
});

describe('relatives findings', () => {
    it('formats a relative finding for the consultation notice', () => {
        expect(formatRelativeFinding(relative())).toBe(
            'Frère — Ahmed B. : Diabète type 2',
        );
        expect(
            formatRelativeFinding(relative({ summary: null, items: [] })),
        ).toBe('Frère — Ahmed B.');
    });

    it('warns only about first-degree relatives with a disease or an allergy', () => {
        const brother = relative();
        const cousin = relative({
            patient_id: 2,
            relation: 'cousin',
            relation_label: 'Cousin(e)',
            first_degree: false,
        });
        const operatedSister = relative({
            patient_id: 3,
            relation: 'sister',
            relation_label: 'Sœur',
            has_alert: false,
            items: [
                {
                    category: 'surgical',
                    label: 'Antécédents chirurgicaux',
                    text: 'Appendicectomie',
                    display: 'Chirurgie : Appendicectomie',
                },
            ],
        });
        const healthyFather = relative({
            patient_id: 4,
            relation: 'father',
            relation_label: 'Père',
            items: [],
            summary: null,
            has_alert: false,
        });

        expect(
            firstDegreeAlerts([brother, cousin, operatedSister, healthyFather]),
        ).toEqual([brother]);
        expect(
            relativesWithFindings([
                brother,
                cousin,
                operatedSister,
                healthyFather,
            ]).map((item) => item.patient_id),
        ).toEqual([1, 2, 3]);
    });
});
