/**
 * Download the template being edited as a .docx built by the server
 * (DocxDocumentBuilder::buildTemplate). Works offline: the desktop app talks
 * to its local Laravel runtime.
 */

const readCookie = (name: string): string | null => {
    const match = document.cookie.match(
        new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'),
    );

    return match ? decodeURIComponent(match[1]) : null;
};

export const filenameFromDisposition = (
    header: string | null,
    fallback: string,
): string => {
    if (!header) {
        return fallback;
    }

    const encoded = /filename\*=UTF-8''([^;]+)/i.exec(header);

    if (encoded) {
        try {
            return decodeURIComponent(encoded[1].trim());
        } catch {
            // fall through
        }
    }

    const plain = /filename="?([^";]+)"?/i.exec(header);

    return plain ? plain[1].trim() : fallback;
};

export const downloadTemplateDocx = async (
    url: string,
    payload: {
        title: string;
        body: string;
        body_format: 'text' | 'html';
        paper_size: string;
    },
): Promise<void> => {
    const xsrf = readCookie('XSRF-TOKEN');
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document, application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
        },
        body: JSON.stringify(payload),
    });

    if (response.status === 422) {
        const data = (await response.json().catch(() => null)) as {
            errors?: Record<string, string[]>;
        } | null;
        const first = Object.values(data?.errors ?? {})[0]?.[0];

        throw new Error(first ?? 'Le modèle ne peut pas être exporté.');
    }

    if (!response.ok) {
        throw new Error('Le fichier Word n’a pas pu être généré.');
    }

    const blob = await response.blob();
    const link = document.createElement('a');
    const objectUrl = URL.createObjectURL(blob);
    link.href = objectUrl;
    link.download = filenameFromDisposition(
        response.headers.get('Content-Disposition'),
        'modele.docx',
    );
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
};
