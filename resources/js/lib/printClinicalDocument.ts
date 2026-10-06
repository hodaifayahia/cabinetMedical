export type ClinicalPaperSize = 'A4' | 'A5';

export const printClinicalDocument = (paperSize: ClinicalPaperSize): void => {
    const style = window.document.createElement('style');
    style.dataset.clinicalPrint = 'true';

    // The page CSP only accepts <style> elements carrying the request nonce
    // (exposed by app.blade.php); without it the print rules are ignored.
    const nonce = window.document.querySelector<HTMLMetaElement>(
        'meta[property="csp-nonce"]',
    )?.nonce;

    if (nonce) {
        style.nonce = nonce;
    }

    style.textContent =
        '@page { size: ' +
        paperSize +
        '; margin: 0; }' +
        '@media print {' +
        'body * { visibility: hidden !important; }' +
        '[data-clinical-print-page], [data-clinical-print-page] * { visibility: visible !important; }' +
        '[data-clinical-print-page] {' +
        'position: absolute !important; inset: 0 !important; width: 100% !important; max-width: none !important; min-height: 0 !important; box-shadow: none !important;' +
        '}' +
        '}';
    window.document.head.appendChild(style);

    const cleanup = () => {
        style.remove();
    };

    window.addEventListener('afterprint', cleanup, { once: true });
    window.print();
};
