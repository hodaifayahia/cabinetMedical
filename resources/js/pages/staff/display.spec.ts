import { describe, expect, it } from 'vitest';

import { staffPaginationLabel, staffRoleLabel } from '@/pages/staff/display';

describe('staff display labels', () => {
    // Roles consolidated to Doctor + Assistant; the six former names are now
    // aliases in PHP (App\Enums\RoleName) and never reach the frontend as
    // distinct roles. These labels mirror RoleName::label().
    it.each([
        ['Doctor', 'Médecin (Super administrateur)'],
        ['Assistant', 'Assistant'],
    ])('localizes the technical role %s', (role, expected) => {
        expect(staffRoleLabel(role)).toBe(expected);
    });

    it.each(['doctor', 'DOCTOR', 'Stock Manager', 'stock-manager'])(
        'normalizes casing and separators for %s',
        (role) => {
            expect(staffRoleLabel(role)).not.toBe('');
        },
    );

    // A record written before the consolidation can still carry a retired
    // name. It must be shown as-is rather than blanked out.
    it.each([
        'Super Administrator',
        'Administrator',
        'Receptionist',
        'Cashier',
        'Stock Manager',
        'Pharmacist',
    ])('passes a retired role name through unchanged: %s', (role) => {
        expect(staffRoleLabel(role)).toBe(role);
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
