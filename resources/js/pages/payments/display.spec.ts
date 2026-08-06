import { describe, expect, it } from 'vitest';

import {
    createFrDzMoneyFormatter,
    paymentDateLabel,
    paymentMethodLabel,
    paymentPaginationLabel,
    paymentStatusLabel,
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
