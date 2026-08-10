import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const workspace = readFileSync(
    resolve(process.cwd(), 'resources/js/pages/consultations/Workspace.vue'),
    'utf8',
);

describe('consultation workspace responsive layout', () => {
    it('keeps the important note usable without consuming the full column', () => {
        expect(workspace).toContain(
            'data-testid="consultation-important-note"',
        );
        expect(workspace).toContain(
            'class="h-36 max-h-56 min-h-28 w-full resize-y overflow-y-auto"',
        );
        expect(workspace).not.toContain('class="min-h-24 flex-1 resize-none"');
    });

    it('keeps all document shortcut labels readable in the narrow desktop rail', () => {
        expect(workspace).toContain(
            'data-testid="consultation-document-shortcuts"',
        );
        expect(workspace).toContain(
            'grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-2',
        );
        expect(workspace).toContain(
            'w-full text-center text-[11px] leading-tight',
        );
    });

    it('uses a three-column workspace only when enough width is available', () => {
        expect(workspace).toContain(
            'xl:grid-cols-[16rem_minmax(0,1fr)_minmax(0,1.05fr)]',
        );
        expect(workspace).toContain('overflow-x-hidden overflow-y-auto');
        expect(workspace).not.toContain(
            'lg:grid-cols-[13.5rem_minmax(0,1fr)_minmax(0,1.05fr)]',
        );
    });
});
