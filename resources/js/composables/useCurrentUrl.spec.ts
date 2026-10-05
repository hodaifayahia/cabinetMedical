import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const page = reactive({ url: '/app/patients/42?tab=history' });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
}));

const { useCurrentUrl } = await import('./useCurrentUrl');

describe('useCurrentUrl', () => {
    beforeEach(() => {
        page.url = '/app/patients/42?tab=history';
    });

    it('exposes the current path without its query string', () => {
        expect(useCurrentUrl().currentUrl.value).toBe('/app/patients/42');
    });

    it('follows Inertia navigations', () => {
        const { currentUrl } = useCurrentUrl();

        page.url = '/app/appointments';

        expect(currentUrl.value).toBe('/app/appointments');
    });

    it('matches an exact relative path', () => {
        const { isCurrentUrl } = useCurrentUrl();

        expect(isCurrentUrl('/app/patients/42')).toBe(true);
        expect(isCurrentUrl('/app/patients')).toBe(false);
    });

    it('matches route objects by their url', () => {
        expect(
            useCurrentUrl().isCurrentUrl({
                url: '/app/patients/42',
                method: 'get',
            }),
        ).toBe(true);
    });

    it('compares absolute URLs by path only', () => {
        const { isCurrentUrl } = useCurrentUrl();

        expect(isCurrentUrl('https://cabinet.example/app/patients/42')).toBe(
            true,
        );
        expect(isCurrentUrl('http://other.example/app/patients/41')).toBe(
            false,
        );
    });

    it('returns false for an unparseable absolute URL', () => {
        expect(useCurrentUrl().isCurrentUrl('http://[broken')).toBe(false);
    });

    it('accepts an explicit current URL to compare against', () => {
        expect(
            useCurrentUrl().isCurrentUrl(
                '/settings/profile',
                '/settings/profile',
            ),
        ).toBe(true);
    });

    it('recognises parent sections', () => {
        const { isCurrentOrParentUrl } = useCurrentUrl();

        expect(isCurrentOrParentUrl('/app/patients')).toBe(true);
        expect(isCurrentOrParentUrl('/app/payments')).toBe(false);
        expect(isCurrentOrParentUrl('https://cabinet.example/app')).toBe(true);
    });

    it('picks a value depending on the current page', () => {
        const { whenCurrentUrl } = useCurrentUrl();

        expect(whenCurrentUrl('/app/patients/42', 'active', 'idle')).toBe(
            'active',
        );
        expect(whenCurrentUrl('/dashboard', 'active', 'idle')).toBe('idle');
        expect(whenCurrentUrl('/dashboard', 'active')).toBeNull();
    });
});
