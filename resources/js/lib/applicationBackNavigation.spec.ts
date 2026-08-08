import { describe, expect, it } from 'vitest';
import { applicationBackNavigation } from './applicationBackNavigation';

describe('applicationBackNavigation', () => {
    it('uses the nearest parent breadcrumb on nested application pages', () => {
        expect(
            applicationBackNavigation(
                '/app/patients/42/edit',
                [
                    { title: 'Patients', href: '/app/patients' },
                    {
                        title: 'Modifier le patient',
                        href: '/app/patients/42/edit',
                    },
                ],
                '/dashboard',
            ),
        ).toEqual({
            href: '/app/patients',
            label: 'Retour vers Patients',
            visible: true,
        });
    });

    it('falls back to the dashboard on a top-level application page', () => {
        expect(
            applicationBackNavigation(
                '/app/patients',
                [{ title: 'Patients', href: '/app/patients' }],
                '/dashboard',
            ),
        ).toEqual({
            href: '/dashboard',
            label: 'Retour au tableau de bord',
            visible: true,
        });
    });

    it('does not add a redundant back arrow on the dashboard or public pages', () => {
        expect(
            applicationBackNavigation('/dashboard', [], '/dashboard').visible,
        ).toBe(false);
        expect(
            applicationBackNavigation('/login', [], '/dashboard').visible,
        ).toBe(false);
    });
});
