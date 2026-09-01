import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

// The product ships light-only. Large parts of it — the public landing page, and
// much of what the desktop shell renders — carry no `dark:` variants, so
// following the operating system theme repainted them against a design that was
// never drawn for it. The rule lives here, in the one place that owns the class,
// so no caller and no OS preference can put dark back.
export function updateTheme(_value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    document.documentElement.classList.remove('dark');
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

export function initializeTheme(): void {
    // No system-preference listener: the theme is fixed, so there is nothing to
    // react to. This only clears a `dark` class left over from an older build.
    updateTheme('light');
}

const appearance = ref<Appearance>('light');

export function useAppearance(): UseAppearanceReturn {
    // Always light, so callers that branch on the theme — the 2FA QR modal reads
    // this to pick its foreground — render against the palette actually on screen.
    const resolvedAppearance = computed<ResolvedAppearance>(() => 'light');

    // The chosen value is still persisted so an existing preference survives, but
    // it no longer selects a theme while the app is light-only.
    function updateAppearance(value: Appearance) {
        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
