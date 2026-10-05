import { beforeEach, describe, expect, it, vi } from 'vitest';

import { initializeTheme, updateTheme, useAppearance } from './useAppearance';

describe('useAppearance', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.documentElement.classList.remove('dark');
        document.cookie =
            'appearance=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    });

    it('removes a dark class left over from an older build', () => {
        document.documentElement.classList.add('dark');

        initializeTheme();

        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it.each(['dark', 'system', 'light'] as const)(
        'never applies a dark theme for %s',
        (value) => {
            document.documentElement.classList.add('dark');

            updateTheme(value);

            expect(document.documentElement.classList.contains('dark')).toBe(
                false,
            );
        },
    );

    it('always resolves to light', () => {
        expect(useAppearance().resolvedAppearance.value).toBe('light');
        expect(useAppearance().appearance.value).toBe('light');
    });

    it('persists the chosen preference without switching theme', () => {
        const { updateAppearance, resolvedAppearance } = useAppearance();

        updateAppearance('dark');

        expect(window.localStorage.getItem('appearance')).toBe('dark');
        expect(document.cookie).toContain('appearance=dark');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(resolvedAppearance.value).toBe('light');
    });

    it('does not watch the operating-system colour scheme', () => {
        const matchMedia = vi.fn();
        vi.stubGlobal('matchMedia', matchMedia);

        initializeTheme();
        useAppearance().updateAppearance('system');

        expect(matchMedia).not.toHaveBeenCalled();
    });
});
