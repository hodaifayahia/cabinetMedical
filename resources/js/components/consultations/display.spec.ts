import { describe, expect, it } from 'vitest';

import { appointmentStatusLabel } from '@/components/consultations/display';

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
});
