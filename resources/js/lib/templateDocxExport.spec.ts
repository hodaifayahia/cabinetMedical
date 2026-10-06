import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    downloadTemplateDocx,
    filenameFromDisposition,
} from './templateDocxExport';

describe('filenameFromDisposition', () => {
    it('reads plain and RFC 5987 file names', () => {
        expect(
            filenameFromDisposition(
                'attachment; filename=certificat.docx',
                'x',
            ),
        ).toBe('certificat.docx');
        expect(
            filenameFromDisposition(
                'attachment; filename="a.docx"; filename*=UTF-8\'\'mod%C3%A8le.docx',
                'x',
            ),
        ).toBe('modèle.docx');
        expect(filenameFromDisposition(null, 'modele.docx')).toBe(
            'modele.docx',
        );
    });
});

describe('downloadTemplateDocx', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('surfaces the first validation message in French', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        errors: {
                            body: [
                                'Le contenu du modèle ne peut pas être vide.',
                            ],
                        },
                    }),
                    { status: 422 },
                ),
            ),
        );

        await expect(
            downloadTemplateDocx('/export', {
                title: 'T',
                body: '',
                body_format: 'html',
                paper_size: 'A4',
            }),
        ).rejects.toThrow('Le contenu du modèle ne peut pas être vide.');
    });

    it('posts the template with the XSRF token and downloads the file', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const fetchMock = vi.fn().mockResolvedValue(
            new Response('docx', {
                status: 200,
                headers: {
                    'Content-Disposition': 'attachment; filename=modele.docx',
                },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);
        const original = {
            create: URL.createObjectURL,
            revoke: URL.revokeObjectURL,
        };
        URL.createObjectURL = vi.fn(() => 'blob:docx');
        URL.revokeObjectURL = vi.fn();
        const click = vi
            .spyOn(HTMLAnchorElement.prototype, 'click')
            .mockImplementation(() => {});

        try {
            await downloadTemplateDocx('/export', {
                title: 'T',
                body: '<p>x</p>',
                body_format: 'html',
                paper_size: 'A5',
            });
        } finally {
            URL.createObjectURL = original.create;
            URL.revokeObjectURL = original.revoke;
        }

        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe(
            'abc=',
        );
        expect(JSON.parse(String(init.body))).toMatchObject({
            paper_size: 'A5',
        });
        expect(click).toHaveBeenCalledTimes(1);
    });
});
