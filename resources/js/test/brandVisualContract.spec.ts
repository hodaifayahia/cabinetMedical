import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = (path: string): string =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

describe('Drclick visual identity', () => {
    it('uses the stable product mark in the shared product-logo component', () => {
        const logo = source('resources/js/components/AppLogoIcon.vue');

        expect(logo).toContain('src="/brand/drclick-mark.png"');
        expect(source('resources/views/app.blade.php')).toContain(
            'href="/brand/drclick-mark.png?v=',
        );
    });

    it('keeps the active visual surfaces flat', () => {
        const files = [
            'resources/css/app.css',
            'resources/js/pages/Welcome.vue',
            'resources/js/pages/Dashboard.vue',
            'resources/js/pages/auth/Login.vue',
            'resources/js/layouts/auth/AuthSimpleLayout.vue',
            'resources/js/components/DesktopOnboarding.vue',
            'resources/js/components/DesktopPinEnrollment.vue',
            'resources/js/components/DesktopDownloadLeadDialog.vue',
            'resources/js/components/charts/AreaChart.vue',
            'src-tauri/frontend/index.html',
        ];

        for (const file of files) {
            expect(source(file), file).not.toMatch(/gradient\s*\(/i);
            expect(source(file), file).not.toMatch(/bg-gradient-/);
        }
    });

    it('renders the configured or fallback logo behind every document editor', () => {
        const editors = [
            'resources/js/components/consultations/PrescriptionDocumentEditor.vue',
            'resources/js/components/consultations/BilanDocumentEditor.vue',
            'resources/js/components/consultations/CourrierDocumentEditor.vue',
        ];

        for (const editor of editors) {
            const editorSource = source(editor);

            expect(editorSource, editor).toContain(':src="logoUrl"');
            expect(editorSource, editor).toContain('watermark');
            expect(editorSource, editor).toContain('opacity: 0.055');
        }
    });
});
