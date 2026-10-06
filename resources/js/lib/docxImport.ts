/**
 * Import a Word (.docx) file into the document editor, entirely in the
 * browser and offline: mammoth (bundled, no CDN) turns the file into HTML,
 * images are re-encoded as small inline data URIs the server sanitizer
 * accepts, and Word alignment is carried over as text-align.
 */

export const DOCX_MAX_BYTES = 10 * 1024 * 1024;

/** Largest inline image the server sanitizer keeps (256 KB decoded). */
export const IMAGE_MAX_BYTES = 240 * 1024;

export const IMAGE_MAX_DIMENSION = 1600;

const DOCX_MIME =
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

export type DocxImportResult = {
    html: string;
    warnings: string[];
};

/** Returns a French error message, or null when the file can be imported. */
export const validateDocxFile = (file: {
    name: string;
    size: number;
    type?: string;
}): string | null => {
    const name = file.name.toLowerCase();

    if (name.endsWith('.doc') || file.type === 'application/msword') {
        return 'Les anciens fichiers .doc ne sont pas pris en charge. Ouvrez-le dans Word puis « Enregistrer sous » au format .docx.';
    }

    if (!name.endsWith('.docx') && file.type !== DOCX_MIME) {
        return 'Seuls les fichiers Word au format .docx sont acceptés.';
    }

    if (file.size === 0) {
        return 'Le fichier est vide.';
    }

    if (file.size > DOCX_MAX_BYTES) {
        return 'Le fichier dépasse 10 Mo. Réduisez la taille des images du document puis réessayez.';
    }

    return null;
};

const ALIGNMENTS: Record<string, string> = {
    center: 'center',
    right: 'right',
    both: 'justify',
    justify: 'justify',
};

/**
 * Mammoth maps Word styles to HTML but drops paragraph alignment. Paragraphs
 * are tagged with a synthetic style that the style map turns into a class,
 * which postProcessImportedHtml converts back to text-align.
 */
export const ALIGNMENT_STYLE_MAP: string[] = (() => {
    const map = [
        "p[style-name='Title'] => h1:fresh",
        "p[style-name='Subtitle'] => h2:fresh",
        'u => u',
        'strike => s',
        'highlight => mark',
    ];

    for (const align of Object.keys(ALIGNMENTS)) {
        if (align === 'justify') {
            continue;
        }

        map.push(
            `p[style-name='drc-p-${align}'] => p.drc-align-${align}:fresh`,
        );

        for (let level = 1; level <= 6; level++) {
            map.push(
                `p[style-name='drc-h${level}-${align}'] => h${level}.drc-align-${align}:fresh`,
            );
        }
    }

    return map;
})();

type MammothParagraph = {
    alignment?: string | null;
    numbering?: unknown;
    styleId?: string | null;
    styleName?: string | null;
    [key: string]: unknown;
};

export const headingLevelOf = (paragraph: MammothParagraph): number | null => {
    const name = String(paragraph.styleName ?? '').trim();
    const id = String(paragraph.styleId ?? '').trim();

    if (/^title$/i.test(name) || /^title$/i.test(id)) {
        return 1;
    }

    const match =
        /^heading\s*([1-6])$/i.exec(name) ??
        /^titre\s*([1-6])$/i.exec(name) ??
        /^heading([1-6])$/i.exec(id) ??
        /^titre([1-6])$/i.exec(id);

    return match ? Number(match[1]) : null;
};

export const tagParagraphAlignment = (
    paragraph: MammothParagraph,
): MammothParagraph => {
    const alignment = String(paragraph.alignment ?? '').toLowerCase();

    if (
        !(alignment in ALIGNMENTS) ||
        alignment === 'justify' ||
        paragraph.numbering
    ) {
        return paragraph;
    }

    const level = headingLevelOf(paragraph);
    const styleName =
        level === null ? `drc-p-${alignment}` : `drc-h${level}-${alignment}`;

    return { ...paragraph, styleId: styleName, styleName };
};

/**
 * Clean mammoth's output: alignment classes become text-align, bookmarks and
 * in-document links are unwrapped, skipped images are removed.
 */
export const postProcessImportedHtml = (html: string): string => {
    const doc = new DOMParser().parseFromString(
        '<!doctype html><html><body>' + html + '</body></html>',
        'text/html',
    );

    doc.body.querySelectorAll<HTMLElement>('[class]').forEach((element) => {
        const align = [...element.classList]
            .find((name) => name.startsWith('drc-align-'))
            ?.slice('drc-align-'.length);

        if (align && ALIGNMENTS[align]) {
            element.style.textAlign = ALIGNMENTS[align];
        }

        element.removeAttribute('class');
    });

    doc.body.querySelectorAll('a').forEach((anchor) => {
        const href = anchor.getAttribute('href') ?? '';

        if (href === '' || href.startsWith('#')) {
            anchor.replaceWith(...Array.from(anchor.childNodes));
        }
    });

    doc.body.querySelectorAll('img').forEach((image) => {
        if (!(image.getAttribute('src') ?? '').startsWith('data:image/')) {
            image.remove();
        }
    });

    return doc.body.innerHTML;
};

const dataUrlBytes = (dataUrl: string): number => {
    const base64 = dataUrl.slice(dataUrl.indexOf(',') + 1);

    return Math.floor((base64.length * 3) / 4);
};

const loadImage = (
    blob: Blob,
): Promise<CanvasImageSource & { width: number; height: number }> => {
    if (typeof createImageBitmap === 'function') {
        return createImageBitmap(blob);
    }

    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(blob);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('image-unreadable'));
        };
        image.src = url;
    });
};

/**
 * Re-encode an image so it fits the sanitizer limits (≤ 1600 px, ≤ 240 KB):
 * PNG is kept when small enough, otherwise JPEG on a white background with
 * decreasing quality and size. Returns null when the image cannot be read.
 */
export const compressImageBlob = async (
    blob: Blob,
    {
        maxBytes = IMAGE_MAX_BYTES,
        maxDimension = IMAGE_MAX_DIMENSION,
    }: { maxBytes?: number; maxDimension?: number } = {},
): Promise<string | null> => {
    let source: CanvasImageSource & { width: number; height: number };

    try {
        source = await loadImage(blob);
    } catch {
        return null;
    }

    if (!source.width || !source.height) {
        return null;
    }

    let scale = Math.min(
        1,
        maxDimension / Math.max(source.width, source.height),
    );

    for (let attempt = 0; attempt < 8; attempt++) {
        const width = Math.max(1, Math.round(source.width * scale));
        const height = Math.max(1, Math.round(source.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        if (!context) {
            return null;
        }

        if (blob.type === 'image/png' || blob.type === 'image/gif') {
            context.drawImage(source, 0, 0, width, height);
            const png = canvas.toDataURL('image/png');

            if (
                png.startsWith('data:image/png') &&
                dataUrlBytes(png) <= maxBytes
            ) {
                return png;
            }

            context.clearRect(0, 0, width, height);
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(source, 0, 0, width, height);

        for (const quality of [0.86, 0.72, 0.6]) {
            const jpeg = canvas.toDataURL('image/jpeg', quality);

            if (
                jpeg.startsWith('data:image/jpeg') &&
                dataUrlBytes(jpeg) <= maxBytes
            ) {
                return jpeg;
            }
        }

        scale *= 0.7;
    }

    return null;
};

const RASTER_TYPES = [
    'image/png',
    'image/jpeg',
    'image/jpg',
    'image/gif',
    'image/webp',
    'image/bmp',
];

type MammothModule = {
    convertToHtml: (
        input: { arrayBuffer: ArrayBuffer },
        options: Record<string, unknown>,
    ) => Promise<{
        value: string;
        messages: { type: string; message: string }[];
    }>;
    images: {
        imgElement: (
            converter: (image: {
                contentType: string;
                readAsArrayBuffer: () => Promise<ArrayBuffer>;
            }) => Promise<{ src: string }>,
        ) => unknown;
    };
    transforms: {
        paragraph: (
            transform: (paragraph: MammothParagraph) => MammothParagraph,
        ) => (element: unknown) => unknown;
    };
};

const loadMammoth = async (): Promise<MammothModule> => {
    // The prebuilt browser bundle has no Node built-ins; it is split into its
    // own chunk and only downloaded (from the app itself) on first import.
    const module = (await import('mammoth/mammoth.browser.js')) as unknown as {
        default?: MammothModule;
    } & MammothModule;

    return module.default ?? module;
};

export const readFileAsArrayBuffer = (file: Blob): Promise<ArrayBuffer> =>
    typeof file.arrayBuffer === 'function'
        ? file.arrayBuffer()
        : new Promise((resolve, reject) => {
              const reader = new FileReader();
              reader.onload = () => resolve(reader.result as ArrayBuffer);
              reader.onerror = () => reject(reader.error);
              reader.readAsArrayBuffer(file);
          });

export const importDocxFile = async (file: File): Promise<DocxImportResult> => {
    const invalid = validateDocxFile(file);

    if (invalid) {
        throw new Error(invalid);
    }

    const mammoth = await loadMammoth();
    let skippedImages = 0;
    let result: {
        value: string;
        messages: { type: string; message: string }[];
    };

    try {
        result = await mammoth.convertToHtml(
            { arrayBuffer: await readFileAsArrayBuffer(file) },
            {
                styleMap: ALIGNMENT_STYLE_MAP,
                includeDefaultStyleMap: true,
                ignoreEmptyParagraphs: false,
                externalFileAccess: false,
                transformDocument: mammoth.transforms.paragraph(
                    tagParagraphAlignment,
                ),
                convertImage: mammoth.images.imgElement(async (image) => {
                    const type = image.contentType.toLowerCase();

                    if (!RASTER_TYPES.includes(type)) {
                        skippedImages++;

                        return { src: '' };
                    }

                    const blob = new Blob([await image.readAsArrayBuffer()], {
                        type: type === 'image/jpg' ? 'image/jpeg' : type,
                    });
                    const src = await compressImageBlob(blob);

                    if (src === null) {
                        skippedImages++;

                        return { src: '' };
                    }

                    return { src };
                }),
            },
        );
    } catch {
        throw new Error(
            'Impossible de lire ce fichier Word. Vérifiez qu’il n’est pas protégé par un mot de passe ou endommagé.',
        );
    }

    const html = postProcessImportedHtml(result.value);
    const warnings: string[] = [];

    if (skippedImages > 0) {
        warnings.push(
            skippedImages === 1
                ? 'Une image n’a pas pu être importée (format non pris en charge ou trop volumineuse).'
                : `${skippedImages} images n’ont pas pu être importées (format non pris en charge ou trop volumineuses).`,
        );
    }

    if (html.replace(/<[^>]*>/g, '').trim() === '' && !/<img\b/i.test(html)) {
        throw new Error('Ce fichier Word ne contient aucun texte à importer.');
    }

    return { html, warnings };
};
