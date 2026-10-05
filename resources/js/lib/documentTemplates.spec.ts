import { describe, expect, it } from 'vitest';
import { documentTemplates } from './documentTemplates';

const KNOWN_PLACEHOLDERS = new Set([
    'age',
    'annee_scolaire',
    'conclusion',
    'date',
    'date_longue',
    'diagnostic',
    'doctor_name',
    'dob',
    'duree',
    'examens',
    'interesse',
    'le_patient',
    'motif',
    'patient_name',
    'specialty',
    'traitement',
]);

const placeholdersOf = (body: string) =>
    [...body.matchAll(/\{\{([^}]*)\}\}/g)].map((match) => match[1]);

describe('documentTemplates', () => {
    it('has unique keys', () => {
        const keys = documentTemplates.map((template) => template.key);

        expect(new Set(keys).size).toBe(keys.length);
    });

    it('uses slug-style keys', () => {
        for (const template of documentTemplates) {
            expect(template.key).toMatch(/^[a-z]+(?:-[a-z]+)*$/);
        }
    });

    it('only uses the three supported categories', () => {
        for (const template of documentTemplates) {
            expect(['courrier', 'bilan', 'ordonnance']).toContain(
                template.category,
            );
        }
    });

    it('gives every template a title, group, and body', () => {
        for (const template of documentTemplates) {
            expect(template.title.trim()).not.toBe('');
            expect(template.group.trim()).not.toBe('');
            expect(template.body.trim()).not.toBe('');
        }
    });

    it('only references placeholders the editor knows how to fill', () => {
        for (const template of documentTemplates) {
            for (const name of placeholdersOf(template.body)) {
                expect(
                    KNOWN_PLACEHOLDERS.has(name),
                    `${template.key}: ${name}`,
                ).toBe(true);
            }
        }
    });

    it('never leaves a dangling placeholder brace', () => {
        for (const template of documentTemplates) {
            const opened = template.body.split('{{').length - 1;
            const closed = template.body.split('}}').length - 1;

            expect(opened, template.key).toBe(closed);
        }
    });

    it('contains no executable markup', () => {
        for (const template of documentTemplates) {
            expect(template.body).not.toMatch(/<script|javascript:|on\w+=/i);
        }
    });

    it('parses into well-formed HTML paragraphs', () => {
        for (const template of documentTemplates) {
            const container = document.createElement('div');
            container.innerHTML = template.body;

            expect(container.querySelectorAll('p').length).toBeGreaterThan(0);
            expect(container.innerHTML.length).toBeGreaterThan(0);
        }
    });

    it('offers exactly one ordonnance and one bilan template', () => {
        const ordonnances = documentTemplates.filter(
            (template) => template.category === 'ordonnance',
        );
        const bilans = documentTemplates.filter(
            (template) => template.category === 'bilan',
        );

        expect(ordonnances.map((template) => template.key)).toEqual([
            'ordonnance',
        ]);
        expect(ordonnances[0]?.body).toContain('{{traitement}}');
        expect(bilans.map((template) => template.key)).toEqual(['bilan']);
        expect(bilans[0]?.body).toContain('{{examens}}');
    });

    it('names the patient in every certificate', () => {
        for (const template of documentTemplates.filter(
            (candidate) => candidate.group === 'Certificats',
        )) {
            expect(template.body, template.key).toContain('{{patient_name}}');
            expect(template.body, template.key).toContain('{{doctor_name}}');
        }
    });

    it('signs the referral letters with the doctor name', () => {
        for (const key of ['lettre-confrere', 'lettre-orientation']) {
            const template = documentTemplates.find(
                (candidate) => candidate.key === key,
            );

            expect(template?.body).toMatch(/<p>Dr \{\{doctor_name\}\}<\/p>$/);
            expect(template?.body).toContain('{{date_longue}}');
        }
    });

    it('frames the work-stoppage certificate with its duration and start date', () => {
        const template = documentTemplates.find(
            (candidate) => candidate.key === 'arret-travail',
        );

        expect(template?.body).toContain('{{duree}}');
        expect(template?.body).toContain('{{date}}');
        expect(template?.body).toContain('ARRET DE TRAVAIL');
    });
});
