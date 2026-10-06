import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = (path: string): string =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

describe('Configuration › Modèles de documents', () => {
    const page = source(
        'resources/js/pages/configuration/DocumentTemplates.vue',
    );

    it('edits templates in the Word-like editor instead of a textarea', () => {
        expect(page).toContain('<RichDocumentEditor');
        expect(page).not.toContain('<textarea');
        expect(page).toContain("body_format: 'html'");
        // Legacy "## " bodies are converted when opened.
        expect(page).toContain('templateBodyToHtml(row.body');
    });

    it('imports and exports Word files', () => {
        expect(page).toContain('Importer un fichier Word (.docx)');
        expect(page).toContain('importDocxFile');
        expect(page).toContain('Télécharger en Word (.docx)');
        expect(page).toContain('/export-docx');
    });

    it('uses a near full-screen dialog that Escape cannot close while in full screen', () => {
        expect(page).toContain('h-[calc(100dvh-1.5rem)]');
        expect(page).toContain('@escape-key-down="onDialogEscape"');
        expect(page).toContain('@fullscreen-change="onFullscreenChange"');
    });

    it('bundles the editor and the Word importer (offline, no CDN)', () => {
        const importer = source('resources/js/lib/docxImport.ts');
        const extensions = source(
            'resources/js/components/documents/richDocumentExtensions.ts',
        );
        const composable = source(
            'resources/js/components/documents/useRichDocumentEditor.ts',
        );

        expect(importer).toContain("import('mammoth/mammoth.browser.js')");
        expect(importer).not.toMatch(/https?:\/\//);
        expect(extensions).not.toMatch(/https?:\/\/(?!www\.)/);
        // The CSP forbids runtime <style> elements.
        expect(composable).toContain('injectCSS: false');
    });
});
