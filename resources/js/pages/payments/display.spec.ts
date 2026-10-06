import { describe, expect, it } from 'vitest';

import {
    createFrDzMoneyFormatter,
    localIsoDate,
    maxCollectable,
    monthStartIsoDate,
    paymentDateLabel,
    paymentMethodLabel,
    paymentPaginationLabel,
    paymentStatusLabel,
    projectedOutstanding,
} from '@/pages/payments/display';

describe('payment display labels', () => {
    it.each([
        ['cash', 'Espèces'],
        ['credit-card', 'Carte bancaire'],
        ['bank_transfer', 'Virement bancaire'],
        ['cheque', 'Chèque'],
    ])('localizes the technical payment method %s', (method, expected) => {
        expect(paymentMethodLabel(method)).toBe(expected);
    });

    it('preserves configured labels and handles an empty method', () => {
        expect(paymentMethodLabel('Versement CCP')).toBe('Versement CCP');
        expect(paymentMethodLabel(null)).toBe('Non renseigné');
    });

    it('localizes filter statuses without changing their values', () => {
        expect(paymentStatusLabel('all')).toBe('Tous les paiements');
        expect(paymentStatusLabel('paid')).toBe('Payés');
        expect(paymentStatusLabel('unpaid')).toBe('Impayés');
    });

    it('formats timestamps in French for Algeria with a safe fallback', () => {
        expect(paymentDateLabel('2026-08-05T08:15:00Z')).toBe(
            '05/08/2026 09:15',
        );
        expect(paymentDateLabel('invalid', '05/08/2026 09:15')).toBe(
            '05/08/2026 09:15',
        );
    });

    it('formats ISO and configured currencies with French separators', () => {
        expect(createFrDzMoneyFormatter('DA')(1234.5)).toMatch(
            /^1[\s\u202f]234,5[\s\u00a0]DA$/u,
        );
        expect(createFrDzMoneyFormatter('points')(1234.5)).toMatch(
            /^1[\s\u202f]234,5 points$/u,
        );
    });

    it('localizes Laravel pagination text without changing its markup', () => {
        expect(paymentPaginationLabel('&laquo; Previous')).toBe(
            '&laquo; Précédent',
        );
        expect(paymentPaginationLabel('Next &raquo;')).toBe('Suivant &raquo;');
    });
});

describe('local calendar dates', () => {
    it('formats the local day, not the UTC one', () => {
        // Local midnight: in UTC+1 toISOString() would give the previous day.
        const midnight = new Date(2026, 9, 1, 0, 0, 0);

        expect(localIsoDate(midnight)).toBe('2026-10-01');
        expect(localIsoDate(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05');
    });

    it('returns the first day of the month in local time', () => {
        expect(monthStartIsoDate(new Date(2026, 9, 6, 0, 30))).toBe(
            '2026-10-01',
        );
        expect(monthStartIsoDate(new Date(2026, 0, 31, 23, 0))).toBe(
            '2026-01-01',
        );
    });
});

describe('collectable amounts', () => {
    it('allows collecting the difference when the total is raised', () => {
        // Already paid 1 000, total raised from 1 500 to 2 000 in the dialog.
        expect(maxCollectable('2000', 1000)).toBe(1000);
        expect(projectedOutstanding('2000', 1000, '600')).toBe(400);
    });

    it('works in whole cents to avoid floating point leftovers', () => {
        expect(maxCollectable('1500.3', 1000.1)).toBe(500.2);
        expect(projectedOutstanding('1500.3', 1000.1, '500.2')).toBe(0);
    });

    it('never goes negative and treats blank or invalid input as zero', () => {
        expect(maxCollectable('500', 800)).toBe(0);
        expect(maxCollectable('', 0)).toBe(0);
        expect(maxCollectable('abc', 0)).toBe(0);
        expect(projectedOutstanding('1000', 0, '')).toBe(1000);
        expect(projectedOutstanding('1000', 200, '900')).toBe(0);
    });
});
