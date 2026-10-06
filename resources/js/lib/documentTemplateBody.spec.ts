import { describe, expect, it } from 'vitest';
import {
    SAMPLE_TEMPLATE_VALUES,
    ageFromBirthDate,
    buildTemplateValues,
    formatDisplayDate,
    htmlIsEffectivelyEmpty,
    isHtmlBody,
    legacyTextToHtml,
    renderTemplateHtml,
    substituteTemplateTokens,
    templateBodyToHtml,
} from './documentTemplateBody';

describe('legacyTextToHtml', () => {
    it('turns ## lines into headings and groups lines into paragraphs', () => {
        expect(
            legacyTextToHtml(
                '## Antécédents\nLigne 1\nLigne <2>\n\n{{patient.allergies}}',
            ),
        ).toBe(
            '<h3>Antécédents</h3><p>Ligne 1<br>Ligne &lt;2&gt;</p><p>{{patient.allergies}}</p>',
        );
    });

    it('handles empty and windows line endings', () => {
        expect(legacyTextToHtml('')).toBe('');
        expect(legacyTextToHtml(null)).toBe('');
        expect(legacyTextToHtml('a\r\nb\r\n\r\nc')).toBe(
            '<p>a<br>b</p><p>c</p>',
        );
    });
});

describe('templateBodyToHtml', () => {
    it('keeps html bodies and converts text bodies', () => {
        expect(templateBodyToHtml('<p>Rich</p>', 'html')).toBe('<p>Rich</p>');
        expect(templateBodyToHtml('Plain', 'text')).toBe('<p>Plain</p>');
    });

    it('detects html when the format is unknown', () => {
        expect(isHtmlBody('  <h2>Titre</h2>')).toBe(true);
        expect(isHtmlBody('## Titre')).toBe(false);
        expect(templateBodyToHtml('<p>x</p>')).toBe('<p>x</p>');
        expect(templateBodyToHtml('## x')).toBe('<h3>x</h3>');
        // An explicit text format wins over detection.
        expect(templateBodyToHtml('<p>x</p>', 'text')).toBe(
            '<p>&lt;p&gt;x&lt;/p&gt;</p>',
        );
    });
});

describe('substituteTemplateTokens', () => {
    it('escapes values, keeps line breaks and tolerates spaces in tokens', () => {
        expect(
            substituteTemplateTokens(
                '<p>{{patient.allergies}} / {{ doctor.name }}</p>',
                {
                    'patient.allergies': 'Iode <b>\nLatex',
                    'doctor.name': 'Dr A & B',
                },
            ),
        ).toBe('<p>Iode &lt;b&gt;<br>Latex / Dr A &amp; B</p>');
    });

    it('blanks unknown tokens unless asked to keep them', () => {
        expect(substituteTemplateTokens('<p>{{x.y}}</p>', {})).toBe('<p></p>');
        expect(
            substituteTemplateTokens(
                '<p>{{x.y}}</p>',
                {},
                { keepUnknown: true },
            ),
        ).toBe('<p>{{x.y}}</p>');
    });
});

describe('buildTemplateValues', () => {
    const values = buildTemplateValues({
        patient: {
            full_name: 'Amine Bensalem',
            date_of_birth: '1980-04-12',
            allergies: 'Pénicilline',
            antecedents_medical: 'HTA',
            antecedents_surgical: 'Appendicectomie',
            antecedents_family: 'Père diabétique',
        },
        consultation: {
            motif: 'Douleur',
            examens: 'ECG',
            diagnostic: 'Angor',
            traitement: 'Aspirine',
            notes: null,
        },
        cabinet: {
            doctor_name: 'Dr Exemple',
            specialty: 'Cardiologie',
            clinic_name: 'Cabinet du Parc',
            address: '1 rue X',
            city: 'Alger',
        },
        documentDate: '2026-10-06',
    });

    it('fills every variable offered in the configuration page', () => {
        const offered = [
            'patient.full_name',
            'patient.date_of_birth',
            'patient.age',
            'patient.allergies',
            'patient.antecedents_medical',
            'patient.antecedents_surgical',
            'patient.antecedents_family',
            'consultation.motif',
            'consultation.examens',
            'consultation.diagnostic',
            'consultation.traitement',
            'doctor.name',
            'doctor.specialty',
            'document.date',
            'cabinet.name',
        ];

        for (const key of offered) {
            expect(values[key], key).not.toBe('');
        }

        expect(values['patient.date_of_birth']).toBe('12/04/1980');
        expect(values['patient.allergies']).toBe('Pénicilline');
        expect(values['patient.antecedents_family']).toBe('Père diabétique');
        expect(values['cabinet.name']).toBe('Cabinet du Parc');
        expect(values['cabinet.address']).toBe('1 rue X, Alger');
        expect(values['document.date']).toBe('06/10/2026');
        expect(values['document.date_long']).toContain('octobre 2026');
        // Legacy aliases.
        expect(values.doctor_name).toBe('Dr Exemple');
        expect(values.diagnostic).toBe('Angor');
    });

    it('renders a legacy template with the consultation values', () => {
        expect(
            renderTemplateHtml(
                '## Allergies\n{{patient.allergies}}\n\nFait le {{document.date}}',
                'text',
                values,
            ),
        ).toBe('<h3>Allergies</h3><p>Pénicilline</p><p>Fait le 06/10/2026</p>');
    });

    it('renders a rich template without touching its formatting', () => {
        expect(
            renderTemplateHtml(
                '<h2 style="text-align: center">{{cabinet.name}}</h2>\n<p><strong>{{patient.antecedents_surgical}}</strong></p>',
                'html',
                values,
            ),
        ).toBe(
            '<h2 style="text-align: center">Cabinet du Parc</h2>\n<p><strong>Appendicectomie</strong></p>',
        );
    });

    it('provides sample values for the template preview', () => {
        expect(SAMPLE_TEMPLATE_VALUES['patient.full_name']).toBe(
            'Amine Bensalem',
        );
        expect(SAMPLE_TEMPLATE_VALUES['patient.allergies']).not.toBe('');
    });
});

describe('small helpers', () => {
    it('computes the age at a given date', () => {
        expect(
            ageFromBirthDate('2000-10-07', new Date('2026-10-06T12:00:00')),
        ).toBe(25);
        expect(
            ageFromBirthDate('2000-10-06', new Date('2026-10-06T12:00:00')),
        ).toBe(26);
        expect(ageFromBirthDate(null)).toBeNull();
        expect(ageFromBirthDate('nope')).toBeNull();
    });

    it('formats ISO dates for display', () => {
        expect(formatDisplayDate('2026-01-02')).toBe('02/01/2026');
        expect(formatDisplayDate('2026-01-02T10:00:00Z')).toBe('02/01/2026');
        expect(formatDisplayDate('')).toBe('');
    });

    it('detects documents without visible content', () => {
        expect(htmlIsEffectivelyEmpty('<p></p>')).toBe(true);
        expect(htmlIsEffectivelyEmpty('<p>&nbsp; </p>')).toBe(true);
        expect(htmlIsEffectivelyEmpty('<p>x</p>')).toBe(false);
        expect(
            htmlIsEffectivelyEmpty(
                '<p><img src="data:image/png;base64,AA"></p>',
            ),
        ).toBe(false);
        expect(
            htmlIsEffectivelyEmpty('<table><tr><td></td></tr></table>'),
        ).toBe(false);
    });
});
