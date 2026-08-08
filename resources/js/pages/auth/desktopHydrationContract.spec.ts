import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const read = (path: string): string =>
    readFileSync(resolve(process.cwd(), `resources/js/${path}`), 'utf8');

describe('desktop runtime hydration contract', () => {
    it.each([
        'pages/auth/Login.vue',
        'pages/Welcome.vue',
        'pages/auth/Register.vue',
        'components/AppHeader.vue',
        'components/DesktopPinEnrollment.vue',
    ])('detects Tauri after mount in %s', (path) => {
        const source = read(path);
        const mounted = source.indexOf('onMounted(');
        const detection = source.indexOf('isTauri()', mounted);

        expect(source).not.toMatch(/const \w+ = isTauri\(\);/u);
        expect(mounted).toBeGreaterThan(0);
        expect(detection).toBeGreaterThan(mounted);
    });

    it('does not read PIN or onboarding storage during login setup', () => {
        const source = read('pages/auth/Login.vue');
        const mounted = source.indexOf('onMounted(');

        expect(source.indexOf('readDesktopPinEnrollment()')).toBeGreaterThan(
            mounted,
        );
        expect(
            source.indexOf('hasCompletedDesktopOnboarding()'),
        ).toBeGreaterThan(mounted);
        expect(source).toContain('v-if="!runtimeResolved"');
        expect(source).toContain('runtimeResolved && pinEnrollment');
        expect(source).toContain('v-else-if="runtimeResolved"');
    });

    it('keeps the welcome and header chrome neutral until detection completes', () => {
        const welcome = read('pages/Welcome.vue');
        const header = read('components/AppHeader.vue');

        expect(welcome).toContain('v-if="!runtimeResolved"');
        expect(welcome).toContain('v-else-if="showDesktopOnboarding"');
        expect(header).toContain('if (!runtimeResolved.value)');
        expect(header).toContain(
            'return { administration: false, installer: false };',
        );
    });

    it('does not read enrollment storage during PIN overlay setup', () => {
        const source = read('components/DesktopPinEnrollment.vue');
        const mounted = source.indexOf('onMounted(');
        const mountedRead = source.indexOf(
            'localEnrollment.value = readDesktopPinEnrollment()',
            mounted,
        );

        expect(mountedRead).toBeGreaterThan(mounted);
        expect(source).toContain(
            'const localEnrollment = ref<DesktopPinEnrollment | null>(null)',
        );
    });
});
