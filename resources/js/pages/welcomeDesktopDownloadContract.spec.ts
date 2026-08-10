import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readFrontend = (path: string): string =>
    readFileSync(resolve(process.cwd(), `resources/js/${path}`), 'utf8');

describe('public showcase and gated desktop download contract', () => {
    it('keeps the showcase out of Tauri and preserves first-run onboarding', () => {
        const source = readFrontend('pages/Welcome.vue');

        expect(source).toContain('v-else-if="showDesktopOnboarding"');
        expect(source).toContain('redirectRememberedDesktopToLogin');
        expect(source).toContain('v-if="!desktopRuntime"');
        expect(source).toContain('if (desktopRuntime.value)');
        expect(source).not.toContain(':href="desktopDownload.url"');
    });

    it('presents a complete responsive product showcase', () => {
        const source = readFrontend('pages/Welcome.vue');

        for (const section of [
            'accueil',
            'solution',
            'fonctionnement',
            'telecharger',
        ]) {
            expect(source).toContain(`id="${section}"`);
        }

        for (const capability of [
            'Dossiers patients',
            'Agenda du cabinet',
            'Consultation structurée',
            'Ordonnances et documents',
            'Paiements lisibles',
            'Équipe et accès contrôlés',
        ]) {
            expect(source).toContain(capability);
        }

        expect(source).toContain('data-testid="open-desktop-download-form"');
        expect(source).toContain("get('download') === '1'");
        expect(source).toContain('downloadDialogOpen.value = true');
    });

    it('collects every lead field before posting to the public gateway', () => {
        const source = readFrontend('components/DesktopDownloadLeadDialog.vue');

        for (const field of [
            'name',
            'email',
            'phone',
            'cabinet_name',
            'specialization',
        ]) {
            expect(source).toContain(`v-model="form.${field}"`);
        }

        expect(source).toContain('v-model="form.website"');
        expect(source).toContain('form.post(props.action');
        expect(source).toContain('data-testid="desktop-download-submit"');
        expect(source).toContain(
            'temporaire et valable uniquement dans cette session',
        );
        expect(source).not.toContain('/desktop/download/file');
    });

    it('keeps the download dialog inside narrow and RTL viewports', () => {
        const dialog = readFrontend('components/DesktopDownloadLeadDialog.vue');
        const scrollContent = readFrontend(
            'components/ui/dialog/DialogScrollContent.vue',
        );

        expect(dialog).toContain('dir="ltr"');
        expect(dialog).toContain('lang="fr"');
        expect(dialog).toContain('overflow-x-hidden');
        expect(dialog).toContain('desktop-download-scroll-region');
        expect(dialog).toContain('class="sr-only"');
        expect(dialog).not.toContain('-left-[10000px]');

        expect(scrollContent).toContain('overflow-x-hidden');
        expect(scrollContent).toContain('p-2');
        expect(scrollContent).toContain('size-10');
    });
});
