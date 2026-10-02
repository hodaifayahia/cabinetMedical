import { describe, expect, it } from 'vitest';

import {
    appointmentStatusLabel,
    familyRelationLabel,
} from '@/components/consultations/display';

describe('consultation display labels', () => {
    it.each([
        ['scheduled', 'Planifié'],
        ['confirmed', 'Confirmé'],
        ['checked_in', 'Arrivé'],
        ['in_progress', 'En cours'],
        ['completed', 'Terminé'],
        ['cancelled', 'Annulé'],
        ['no_show', 'Absent'],
    ])('localizes the appointment status %s', (status, expected) => {
        expect(appointmentStatusLabel(status)).toBe(expected);
    });

    it('normalizes compatible technical separators', () => {
        expect(appointmentStatusLabel('IN-PROGRESS')).toBe('En cours');
    });

    it('preserves an unknown server-defined status', () => {
        expect(appointmentStatusLabel('custom_status')).toBe('custom_status');
    });

    it.each([
        ['father', 'père'],
        ['mother', 'mère'],
        ['husband', 'époux'],
        ['wife', 'épouse'],
        ['son', 'fils'],
        ['daughter', 'fille'],
        ['other', 'proche'],
    ])('localizes the family relation %s', (relation, expected) => {
        expect(familyRelationLabel(relation)).toBe(expected);
    });

    it('uses a clear label when the relation is missing and preserves unknown values', () => {
        expect(familyRelationLabel(null)).toBe('proche');
        expect(familyRelationLabel('guardian')).toBe('guardian');
    });
});
