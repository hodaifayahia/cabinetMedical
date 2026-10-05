import { describe, expect, it } from 'vitest';
import type { Pixels } from './ecgDigitizer';
import {
    clampRegion,
    detectGrid,
    isGrid,
    isInk,
    measureStrip,
    pxToMs,
    rrStats,
} from './ecgDigitizer';

const blank = (
    width: number,
    height: number,
    rgb: [number, number, number] = [255, 255, 255],
): Pixels => {
    const data = new Uint8ClampedArray(width * height * 4);

    for (let i = 0; i < data.length; i += 4) {
        data[i] = rgb[0];
        data[i + 1] = rgb[1];
        data[i + 2] = rgb[2];
        data[i + 3] = 255;
    }

    return { width, height, data } as Pixels;
};

describe('isInk', () => {
    it.each([
        [[0, 0, 0], true],
        [[40, 40, 50], true],
        [[90, 90, 90], true],
        [[255, 255, 255], false],
        [[150, 150, 150], false],
        // Dark but strongly coloured: a dark red grid line, not the trace.
        [[160, 0, 0], false],
    ] as [[number, number, number], boolean][])(
        'rgb%j is ink: %s',
        ([r, g, b], expected) => {
            expect(isInk(r, g, b)).toBe(expected);
        },
    );
});

describe('isGrid', () => {
    it.each([
        [[240, 150, 150], true],
        [[255, 180, 190], true],
        [[200, 200, 200], true],
        [[255, 255, 255], false],
        [[0, 0, 0], false],
        [[60, 60, 60], false],
        [[100, 200, 100], false],
    ] as [[number, number, number], boolean][])(
        'rgb%j is grid: %s',
        ([r, g, b], expected) => {
            expect(isGrid(r, g, b)).toBe(expected);
        },
    );

    it('never classifies the same pixel as both ink and grid', () => {
        for (let v = 0; v <= 255; v += 15) {
            for (const rgb of [
                [v, v, v],
                [v, v / 2, v / 2],
                [255, v, v],
            ]) {
                const [r, g, b] = rgb;
                expect(isInk(r, g, b) && isGrid(r, g, b)).toBe(false);
            }
        }
    });
});

describe('clampRegion', () => {
    const pixels = blank(100, 50);

    it('defaults to the whole image', () => {
        expect(clampRegion(pixels, null)).toEqual({
            x: 0,
            y: 0,
            w: 100,
            h: 50,
        });
    });

    it('keeps a region that fits', () => {
        expect(clampRegion(pixels, { x: 10, y: 5, w: 20, h: 10 })).toEqual({
            x: 10,
            y: 5,
            w: 20,
            h: 10,
        });
    });

    it('floors fractional coordinates from a canvas selection', () => {
        expect(
            clampRegion(pixels, { x: 10.7, y: 5.2, w: 20.9, h: 10.9 }),
        ).toEqual({
            x: 10,
            y: 5,
            w: 20,
            h: 10,
        });
    });

    it('clamps a region dragged past the edges', () => {
        expect(clampRegion(pixels, { x: -20, y: -5, w: 500, h: 500 })).toEqual({
            x: 0,
            y: 0,
            w: 100,
            h: 50,
        });
        expect(clampRegion(pixels, { x: 90, y: 40, w: 50, h: 50 })).toEqual({
            x: 90,
            y: 40,
            w: 10,
            h: 10,
        });
    });

    it('never returns an empty or out-of-image region', () => {
        expect(clampRegion(pixels, { x: 500, y: 500, w: 0, h: -3 })).toEqual({
            x: 99,
            y: 49,
            w: 1,
            h: 1,
        });
    });
});

describe('pxToMs', () => {
    it('converts at 25 and 50 mm/s', () => {
        expect(pxToMs(50, 10)).toBe(200);
        expect(pxToMs(50, 10, 50)).toBe(100);
    });

    it('measures a distance whatever the drag direction', () => {
        expect(pxToMs(-50, 10)).toBe(200);
    });

    it('rounds to the millisecond', () => {
        expect(pxToMs(1, 3)).toBe(13);
        expect(pxToMs(0, 10)).toBe(0);
    });
});

describe('rrStats', () => {
    it('gives the rate and no variability for one interval', () => {
        expect(rrStats([1000])).toEqual({ heartRate: 60, cv: null });
    });

    it('matches the server computation (population SD over mean)', () => {
        expect(rrStats([800, 1200])).toEqual({ heartRate: 60, cv: 20 });
    });

    it('rounds variability to one decimal', () => {
        const { cv } = rrStats([700, 720, 760, 800]);

        expect(cv).not.toBeNull();
        expect(Math.round((cv as number) * 10)).toBe((cv as number) * 10);
    });
});

describe('measureStrip robustness', () => {
    it.each([
        ['an empty white image', blank(400, 120)],
        ['a black image', blank(400, 120, [0, 0, 0])],
        ['a grey image', blank(400, 120, [180, 180, 180])],
        ['a single pixel', blank(1, 1)],
        ['a very thin strip', blank(600, 2)],
    ])('does not throw on %s and reports no rate', (_, pixels) => {
        const result = measureStrip(pixels, null);

        expect(result).toBeTruthy();
        expect(result.heartRate ?? null).toBeNull();
    });

    it('does not throw on a region entirely outside the image', () => {
        expect(() =>
            measureStrip(blank(200, 100), { x: 5000, y: 5000, w: 10, h: 10 }),
        ).not.toThrow();
    });

    it('does not throw with a nonsensical manual calibration', () => {
        for (const pxPerMm of [0, -5, Number.NaN, 1e9]) {
            expect(() =>
                measureStrip(blank(300, 100), null, { pxPerMm }),
            ).not.toThrow();
        }
    });

    it('finds no grid on blank paper', () => {
        expect(detectGrid(blank(300, 100))).toBeNull();
    });
});
