import JSZip from 'jszip';
import { describe, expect, it } from 'vitest';
import {
    DOCX_MAX_BYTES,
    headingLevelOf,
    importDocxFile,
    postProcessImportedHtml,
    tagParagraphAlignment,
    validateDocxFile,
} from './docxImport';

describe('validateDocxFile', () => {
    it('accepts a .docx file within the size limit', () => {
        expect(
            validateDocxFile({ name: 'Modèle.DOCX', size: 2048 }),
        ).toBeNull();
    });

    it('refuses legacy .doc files with guidance', () => {
        expect(validateDocxFile({ name: 'ancien.doc', size: 10 })).toContain(
            'Enregistrer sous',
        );
    });

    it('refuses other formats, empty and oversized files', () => {
        expect(validateDocxFile({ name: 'scan.pdf', size: 10 })).toContain(
            '.docx',
        );
        expect(validateDocxFile({ name: 'vide.docx', size: 0 })).toBe(
            'Le fichier est vide.',
        );
        expect(
            validateDocxFile({ name: 'gros.docx', size: DOCX_MAX_BYTES + 1 }),
        ).toContain('10 Mo');
    });
});

describe('paragraph alignment tagging', () => {
    it('recognises Word heading styles', () => {
        expect(headingLevelOf({ styleName: 'Heading 2' })).toBe(2);
        expect(headingLevelOf({ styleName: 'Titre 3' })).toBe(3);
        expect(headingLevelOf({ styleId: 'Heading1' })).toBe(1);
        expect(headingLevelOf({ styleName: 'Title' })).toBe(1);
        expect(headingLevelOf({ styleName: 'Normal' })).toBeNull();
    });

    it('tags aligned paragraphs and headings but leaves lists alone', () => {
        expect(tagParagraphAlignment({ alignment: 'center' }).styleName).toBe(
            'drc-p-center',
        );
        expect(
            tagParagraphAlignment({
                alignment: 'right',
                styleName: 'Heading 2',
            }).styleName,
        ).toBe('drc-h2-right');
        expect(tagParagraphAlignment({ alignment: 'both' }).styleName).toBe(
            'drc-p-both',
        );
        expect(
            tagParagraphAlignment({ alignment: 'center', numbering: {} })
                .styleName,
        ).toBeUndefined();
        expect(
            tagParagraphAlignment({ alignment: 'left' }).styleName,
        ).toBeUndefined();
    });
});

describe('postProcessImportedHtml', () => {
    it('turns alignment classes into styles and drops internal links and broken images', () => {
        const html = postProcessImportedHtml(
            '<p class="drc-align-center">Centré</p><h2 class="drc-align-both">Justifié</h2>' +
                '<p><a id="_Toc1"></a><a href="#footnote-1">[1]</a> <a href="https://exemple.fr">lien</a></p>' +
                '<p><img src=""><img src="data:image/png;base64,AA"></p>',
        );

        expect(html).toContain('<p style="text-align: center;">Centré</p>');
        expect(html).toContain(
            '<h2 style="text-align: justify;">Justifié</h2>',
        );
        expect(html).not.toContain('drc-align');
        expect(html).not.toContain('#footnote');
        expect(html).toContain('[1]');
        expect(html).toContain('href="https://exemple.fr"');
        expect(html.match(/<img/g)?.length).toBe(1);
    });
});

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

const buildDocx = async (body: string): Promise<File> => {
    const zip = new JSZip();
    zip.file(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
            '<Default Extension="xml" ContentType="application/xml"/>' +
            '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' +
            '</Types>',
    );
    zip.file(
        '_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' +
            '</Relationships>',
    );
    zip.file(
        'word/document.xml',
        `<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="${W}"><w:body>${body}</w:body></w:document>`,
    );
    const bytes = await zip.generateAsync({ type: 'arraybuffer' });

    return new File([bytes], 'certificat.docx', {
        type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    });
};

describe('importDocxFile', () => {
    it('converts a Word document to editable HTML, keeping alignment and underline', async () => {
        const file = await buildDocx(
            '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t>CERTIFICAT MÉDICAL</w:t></w:r></w:p>' +
                '<w:p><w:r><w:t xml:space="preserve">Patient : </w:t></w:r><w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t>{{patient.full_name}}</w:t></w:r></w:p>' +
                '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>ECG</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Normal</w:t></w:r></w:p></w:tc></w:tr></w:tbl>',
        );

        const result = await importDocxFile(file);

        expect(result.html).toContain(
            '<p style="text-align: center;"><strong>CERTIFICAT MÉDICAL</strong></p>',
        );
        expect(result.html).toContain('<u>{{patient.full_name}}</u>');
        expect(result.html).toContain('<table>');
        expect(result.html).toContain('ECG');
        expect(result.warnings).toEqual([]);
    });

    it('rejects an invalid file before reading it', async () => {
        await expect(
            importDocxFile(
                new File(['x'], 'notes.txt', { type: 'text/plain' }),
            ),
        ).rejects.toThrow('.docx');
    });

    it('reports a corrupted Word file in French', async () => {
        await expect(
            importDocxFile(new File(['pas un zip'], 'casse.docx')),
        ).rejects.toThrow('Impossible de lire ce fichier Word');
    });
});
