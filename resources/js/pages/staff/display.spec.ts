import { describe, expect, it } from 'vitest';

import { staffPaginationLabel, staffRoleLabel } from '@/pages/staff/display';

describe('staff display labels', () => {
    it.each([
        ['Super Administrator', 'Super-administrateur'],
        ['Administrator', 'Administrateur'],
        ['Doctor', 'Médecin'],
        ['Receptionist', 'Réceptionniste'],
        ['Cashier', 'Caissier'],
        ['Stock Manager', 'Gestionnaire de stock'],
        ['Pharmacist', 'Pharmacien'],
    ])('localizes the technical role %s', (role, expected) => {
        expect(staffRoleLabel(role)).toBe(expected);
    });

    it('preserves an unknown custom role', () => {
        expect(staffRoleLabel('Coordinateur clinique')).toBe(
            'Coordinateur clinique',
        );
    });

    it('localizes Laravel pagination text without changing its markup', () => {
        expect(staffPaginationLabel('&laquo; Previous')).toBe(
            '&laquo; Précédent',
        );
        expect(staffPaginationLabel('Next &raquo;')).toBe('Suivant &raquo;');
    });
});
