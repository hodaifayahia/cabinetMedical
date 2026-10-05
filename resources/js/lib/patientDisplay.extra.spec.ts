import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ageInYears,
    avatarTone,
    formatAge,
    formatDate,
    formatDateTime,
    formatGender,
    initials,
    relativeDay,
} from './patientDisplay';

describe('patientDisplay (extended)', () => {
    const now = new Date(2026, 8, 24, 10, 0);

    afterEach(() => {
        vi.useRealTimers();
    });

    it.each([
        ['female', 'Femme'],
        ['FEMALE', 'Femme'],
        ['femme', 'Femme'],
        ['male', 'Homme'],
        ['Homme', 'Homme'],
        ['autre', 'autre'],
        ['', '—'],
        [null, '—'],
        [undefined, '—'],
    ])('formats gender %j as %j', (value, expected) => {
        expect(formatGender(value)).toBe(expected);
    });

    it('formats a date-time value by its calendar day', () => {
        expect(formatDate('2026-09-24 23:30:00')).toBe(
            formatDate('2026-09-24'),
        );
        expect(formatDate('2026-09-24')).toMatch(/24/);
        expect(formatDate('2026-09-24')).toMatch(/2026/);
    });

    it('returns a dash for missing dates', () => {
        expect(formatDate(null)).toBe('—');
        expect(formatDate('')).toBe('—');
        expect(formatDateTime(undefined)).toBe('—');
    });

    it('accepts both date-only and SQL date-time values for date-time formatting', () => {
        expect(formatDateTime('2026-09-24 09:05:00')).toMatch(/09.05/);
        expect(formatDateTime('2026-09-24')).toMatch(/(?:00|12).00/);
    });

    it('returns null for a birth date in the future', () => {
        expect(ageInYears('2027-01-01', now)).toBeNull();
    });

    it('returns 0 for a baby born this year', () => {
        expect(ageInYears('2026-01-15', now)).toBe(0);
    });

    it('handles birthdays earlier and later in the month', () => {
        expect(ageInYears('2000-08-30', now)).toBe(26);
        expect(ageInYears('2000-10-01', now)).toBe(25);
    });

    it('ignores a time component on the birth date', () => {
        expect(ageInYears('1990-09-24 23:59:59', now)).toBe(36);
    });

    it('handles a 29 February birthday in a non-leap year', () => {
        expect(ageInYears('2000-02-29', new Date(2026, 1, 28))).toBe(25);
        expect(ageInYears('2000-02-29', new Date(2026, 2, 1))).toBe(26);
    });

    it('uses singular and plural forms for the age label', () => {
        vi.useFakeTimers();
        vi.setSystemTime(now);

        expect(formatAge('2026-01-01')).toBe('0 an');
        expect(formatAge('2025-09-24')).toBe('1 an');
        expect(formatAge('2024-09-24')).toBe('2 ans');
        expect(formatAge('2030-01-01')).toBe('—');
    });

    it.each([
        ['2026-10-07', 'dans 13 j'],
        ['2026-10-08', 'dans 2 sem.'],
        ['2026-09-10', 'il y a 2 sem.'],
        ['2026-11-23', 'dans 2 mois'],
        ['2026-06-24', 'il y a 3 mois'],
        ['2024-09-24', 'il y a 2 ans'],
        ['2029-09-24', 'dans 3 ans'],
    ])('describes %s relative to now as %j', (value, expected) => {
        expect(relativeDay(value, now)).toBe(expected);
    });

    it('treats a late-evening time as the same calendar day', () => {
        expect(relativeDay('2026-09-24 23:59:00', now)).toBe('aujourd’hui');
    });

    it('builds initials from the first two names only', () => {
        expect(initials('Amina Nour Benali')).toBe('AN');
        expect(initials('élodie')).toBe('É');
        expect(initials('  karim   saïdi ')).toBe('KS');
        expect(initials('')).toBe('?');
    });

    it('chooses one of the six avatar tones deterministically', () => {
        const tone = avatarTone('Amina Benali');

        expect(tone).toMatch(/^bg-\w+-100 text-\w+-800/);
        expect(avatarTone('Amina Benali')).toBe(tone);
        expect(avatarTone(42)).toBe(avatarTone('42'));
    });

    it('spreads patients across several tones', () => {
        const tones = new Set(
            Array.from({ length: 30 }, (_, index) => avatarTone(index)),
        );

        expect(tones.size).toBe(6);
    });

    it('handles an empty seed', () => {
        expect(avatarTone('')).toContain('bg-teal-100');
    });
});
