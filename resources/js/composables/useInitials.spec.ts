import { describe, expect, it } from 'vitest';

import { getInitials, useInitials } from '@/composables/useInitials';

describe('getInitials', () => {
    it('returns an empty value when no readable name is provided', () => {
        expect(getInitials()).toBe('');
        expect(getInitials('')).toBe('');
        expect(getInitials(' \t\n ')).toBe('');
    });

    it('uses the first and last words for multi-part Latin names', () => {
        expect(getInitials('  Élodie   Ben Ahmed  ')).toBe('ÉA');
        expect(getInitials('cabinet doctor')).toBe('CD');
    });

    it('preserves Unicode initials for Arabic and single-word names', () => {
        expect(getInitials('ليلى بن عمر')).toBe('لع');
        expect(getInitials('王小明')).toBe('王');
    });

    it('exposes the same behavior through the composable', () => {
        expect(useInitials().getInitials('Marie Curie')).toBe('MC');
    });
});
