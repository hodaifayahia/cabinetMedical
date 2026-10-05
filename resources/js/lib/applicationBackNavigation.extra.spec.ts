import { describe, expect, it } from 'vitest';
import { applicationBackNavigation } from './applicationBackNavigation';

describe('applicationBackNavigation (extended)', () => {
    it('skips every breadcrumb that points at the current page', () => {
        expect(
            applicationBackNavigation(
                '/app/patients/42/consultations/7',
                [
                    { title: 'Patients', href: '/app/patients' },
                    { title: 'Amina', href: '/app/patients/42' },
                    {
                        title: 'Consultation',
                        href: '/app/patients/42/consultations/7',
                    },
                ],
                '/dashboard',
            ),
        ).toEqual({
            href: '/app/patients/42',
            label: 'Retour vers Amina',
            visible: true,
        });
    });

    it('compares paths, ignoring the query string and fragment', () => {
        expect(
            applicationBackNavigation(
                '/app/patients?page=3#list',
                [{ title: 'Patients', href: '/app/patients' }],
                '/dashboard',
            ).href,
        ).toBe('/dashboard');
    });

    it('compares absolute breadcrumb URLs by path', () => {
        expect(
            applicationBackNavigation(
                'https://cabinet.example/app/payments/9',
                [
                    {
                        title: 'Paiements',
                        href: 'https://cabinet.example/app/payments',
                    },
                    {
                        title: 'Paiement',
                        href: 'https://cabinet.example/app/payments/9',
                    },
                ],
                '/dashboard',
            ).label,
        ).toBe('Retour vers Paiements');
    });

    it('accepts route objects for breadcrumbs and the dashboard', () => {
        const dashboard = { url: '/dashboard', method: 'get' as const };
        const result = applicationBackNavigation(
            '/app/staff/3',
            [{ title: 'Équipe', href: { url: '/app/staff', method: 'get' } }],
            dashboard,
        );

        expect(result.href).toEqual({ url: '/app/staff', method: 'get' });
        expect(result.visible).toBe(true);

        const fallback = applicationBackNavigation('/app/staff', [], dashboard);

        expect(fallback.href).toBe(dashboard);
        expect(fallback.label).toBe('Retour au tableau de bord');
    });

    it('is visible on account settings pages', () => {
        expect(
            applicationBackNavigation('/settings/profile', [], '/dashboard')
                .visible,
        ).toBe(true);
    });

    it.each(['/app', '/settings', '/', '/register', '/desktop/cabinet-login'])(
        'is hidden on %s',
        (url) => {
            expect(
                applicationBackNavigation(url, [], '/dashboard').visible,
            ).toBe(false);
        },
    );

    it('is hidden when the dashboard itself lives under /app', () => {
        expect(
            applicationBackNavigation('/app/dashboard', [], '/app/dashboard')
                .visible,
        ).toBe(false);
    });

    it('treats an unparseable URL as the root page', () => {
        const result = applicationBackNavigation(
            'http://[broken',
            [],
            '/dashboard',
        );

        expect(result.visible).toBe(false);
        expect(result.href).toBe('/dashboard');
    });
});
