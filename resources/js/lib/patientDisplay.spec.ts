import { describe, expect, it } from 'vitest';
import {
    ageInYears,
    avatarTone,
    formatAge,
    initials,
    relativeDay,
} from './patientDisplay';

describe('patientDisplay', () => {
    const now = new Date(2026, 8, 24, 10, 0);

    it('counts age in whole years around the birthday', () => {
        expect(ageInYears('1990-09-24', now)).toBe(36);
        expect(ageInYears('1990-09-25', now)).toBe(35);
        expect(ageInYears(null, now)).toBeNull();
        expect(formatAge(null)).toBe('—');
    });

    it('describes days close to now in plain French', () => {
        expect(relativeDay('2026-09-24 08:00:00', now)).toBe('aujourd’hui');
        expect(relativeDay('2026-09-23', now)).toBe('hier');
        expect(relativeDay('2026-09-25T09:00:00Z', now)).toBe('demain');
        expect(relativeDay('2026-09-21', now)).toBe('il y a 3 j');
        expect(relativeDay('2026-10-15', now)).toBe('dans 3 sem.');
        expect(relativeDay(null, now)).toBe('—');
    });

    it('builds initials and a stable colour', () => {
        expect(initials('amina benali')).toBe('AB');
        expect(initials('  ')).toBe('?');
        expect(avatarTone(42)).toBe(avatarTone(42));
    });
});
