import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { printClinicalDocument } from './printClinicalDocument';

const printStyles = () =>
    document.head.querySelectorAll<HTMLStyleElement>(
        'style[data-clinical-print="true"]',
    );

describe('printClinicalDocument', () => {
    let print = vi.fn();

    beforeEach(() => {
        print = vi.fn();
        vi.spyOn(window, 'print').mockImplementation(print);
    });

    afterEach(() => {
        printStyles().forEach((style) => style.remove());
    });

    it.each(['A4', 'A5'] as const)(
        'sets the %s page size before opening the print dialog',
        (size) => {
            print.mockImplementation(() => {
                const styles = printStyles();

                expect(styles).toHaveLength(1);
                expect(styles[0]?.textContent).toContain(
                    `@page { size: ${size}; margin: 0; }`,
                );
            });

            printClinicalDocument(size);

            expect(print).toHaveBeenCalledTimes(1);
        },
    );

    it('only reveals the clinical page while printing', () => {
        printClinicalDocument('A4');

        const css = printStyles()[0]?.textContent ?? '';

        expect(css).toContain('@media print');
        expect(css).toContain('body * { visibility: hidden !important; }');
        expect(css).toContain(
            '[data-clinical-print-page], [data-clinical-print-page] * { visibility: visible !important; }',
        );
    });

    it('removes its stylesheet after printing', () => {
        printClinicalDocument('A5');
        expect(printStyles()).toHaveLength(1);

        window.dispatchEvent(new Event('afterprint'));

        expect(printStyles()).toHaveLength(0);
    });

    it('cleans up each print independently', () => {
        printClinicalDocument('A4');
        printClinicalDocument('A5');

        expect(printStyles()).toHaveLength(2);

        window.dispatchEvent(new Event('afterprint'));

        expect(printStyles()).toHaveLength(0);
    });

    it('registers a one-shot afterprint listener', () => {
        const add = vi.spyOn(window, 'addEventListener');

        printClinicalDocument('A4');

        expect(add).toHaveBeenCalledWith('afterprint', expect.any(Function), {
            once: true,
        });
    });

    it('leaves unrelated styles in place', () => {
        const unrelated = document.createElement('style');
        unrelated.textContent = 'body { color: red; }';
        document.head.appendChild(unrelated);

        printClinicalDocument('A4');
        window.dispatchEvent(new Event('afterprint'));

        expect(unrelated.isConnected).toBe(true);
        unrelated.remove();
    });

    it('carries the page CSP nonce so the print rules are not blocked', () => {
        const meta = document.createElement('meta');
        meta.setAttribute('property', 'csp-nonce');
        meta.nonce = 'test-nonce';
        document.head.appendChild(meta);

        try {
            printClinicalDocument('A5');

            expect(printStyles()[0]?.nonce).toBe('test-nonce');
        } finally {
            meta.remove();
        }
    });
});
