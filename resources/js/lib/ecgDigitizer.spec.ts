import { describe, expect, it } from 'vitest';
import type { Pixels } from './ecgDigitizer';
import { detectGrid, measureStrip, pxToMs, rrStats } from './ecgDigitizer';

type StripOptions = {
    pxPerMm: number;
    beatsS: number[];
    seconds?: number;
    smallSquares?: boolean;
    boldEveryFifth?: boolean;
    grid?: boolean;
};

/**
 * Paint a 25 mm/s rhythm strip: pink grid, flat black baseline and a narrow
 * tall spike (the QRS) at each beat time.
 */
const strip = ({
    pxPerMm,
    beatsS,
    seconds = 10,
    smallSquares = true,
    boldEveryFifth = true,
    grid = true,
}: StripOptions): Pixels => {
    const width = Math.round(seconds * 25 * pxPerMm);
    const height = Math.round(30 * pxPerMm);
    const data = new Uint8ClampedArray(width * height * 4).fill(255);
    const paint = (x: number, y: number, rgb: [number, number, number]) => {
        if (x < 0 || y < 0 || x >= width || y >= height) {
            return;
        }

        const i = (y * width + x) * 4;
        data[i] = rgb[0];
        data[i + 1] = rgb[1];
        data[i + 2] = rgb[2];
    };

    if (grid) {
        const step = smallSquares ? pxPerMm : pxPerMm * 5;

        for (let n = 0; n * step < width; n++) {
            const x = Math.round(n * step);
            const bold = boldEveryFifth && smallSquares && n % 5 === 0;

            for (let y = 0; y < height; y++) {
                paint(x, y, bold ? [220, 90, 90] : [245, 170, 170]);
            }
        }

        for (let n = 0; n * step < height; n++) {
            const y = Math.round(n * step);

            for (let x = 0; x < width; x++) {
                paint(x, y, [245, 170, 170]);
            }
        }
    }

    const baseline = Math.round(height * 0.65);

    // A real trace is never a perfectly straight line: it wanders slightly.
    for (let x = 0; x < width; x++) {
        const y = baseline + Math.round(Math.sin(x / (3 * pxPerMm)) * 1.5);
        paint(x, y, [0, 0, 0]);
        paint(x, y + 1, [0, 0, 0]);
    }

    for (const t of beatsS) {
        const cx = Math.round(t * 25 * pxPerMm);
        const peak = baseline - Math.round(12 * pxPerMm);

        for (let dx = -1; dx <= 1; dx++) {
            for (let y = peak + Math.abs(dx) * 2; y <= baseline + 2; y++) {
                paint(cx + dx, y, [0, 0, 0]);
            }
        }
    }

    // A printed label above the trace and a black frame, as on real paper.
    for (let x = 4; x < 4 + 12 * pxPerMm; x++) {
        for (let y = 2; y < 2 + 3 * pxPerMm; y++) {
            if ((x + y) % 3 === 0) {
                paint(x, y, [0, 0, 0]);
            }
        }
    }

    for (let x = 0; x < width; x++) {
        paint(x, 0, [0, 0, 0]);
        paint(x, height - 1, [0, 0, 0]);
    }

    return { data, width, height };
};

const regularBeats = (rr: number, seconds = 10): number[] => {
    const beats: number[] = [];

    for (let t = 0.4; t < seconds - 0.1; t += rr) {
        beats.push(t);
    }

    return beats;
};

// Each test paints and scans a full-size synthetic ECG image: fast alone, but
// it can pass the default 5 s limit when the whole suite runs in parallel.
describe('ecgDigitizer', { timeout: 20_000 }, () => {
    it('finds the 1 mm square from a grid with bold 5 mm lines', () => {
        const grid = detectGrid(strip({ pxPerMm: 6, beatsS: [] }));

        expect(grid?.boldEveryFifth).toBe(true);
        expect(grid?.period).toBeCloseTo(6, 0);
    });

    it('measures a regular sinus-like strip at 75/min', () => {
        const result = measureStrip(
            strip({ pxPerMm: 6, beatsS: regularBeats(0.8) }),
            null,
        );

        expect(result.problem).toBeNull();
        expect(result.calibration).toBe('grid');
        expect(result.peaksX).toHaveLength(12);
        expect(result.heartRate).toBeGreaterThanOrEqual(73);
        expect(result.heartRate).toBeLessThanOrEqual(77);
        expect(result.rrCvPercent).toBeLessThan(3);
    });

    it('shows an irregularly irregular fast rhythm as such', () => {
        const beats = [0.3];
        const rr = [0.41, 0.48, 0.66, 0.59, 0.41, 0.74, 0.39, 0.52, 0.45];
        rr.forEach((interval) =>
            beats.push(beats[beats.length - 1] + interval),
        );

        const result = measureStrip(strip({ pxPerMm: 6, beatsS: beats }), null);

        expect(result.peaksX).toHaveLength(beats.length);
        expect(result.heartRate).toBeGreaterThan(100);
        expect(result.rrCvPercent).toBeGreaterThan(15);
    });

    it('does not mistake a high-resolution small square for a large one', () => {
        const result = measureStrip(
            strip({ pxPerMm: 24, beatsS: regularBeats(1.0, 5), seconds: 5 }),
            null,
        );

        expect(result.pxPerMm).toBeCloseTo(24, 0);
        expect(result.heartRate).toBeGreaterThanOrEqual(58);
        expect(result.heartRate).toBeLessThanOrEqual(62);
    });

    it('reads paper where only the 5 mm squares are printed', () => {
        const result = measureStrip(
            strip({
                pxPerMm: 4,
                beatsS: regularBeats(0.75),
                smallSquares: false,
            }),
            null,
        );

        expect(result.pxPerMm).toBeCloseTo(4, 0);
        expect(result.heartRate).toBeGreaterThanOrEqual(78);
        expect(result.heartRate).toBeLessThanOrEqual(82);
    });

    it('asks for a calibration when there is no grid, and uses it', () => {
        const pixels = strip({
            pxPerMm: 5,
            beatsS: regularBeats(1),
            grid: false,
        });

        expect(measureStrip(pixels, null).calibration).toBe('none');
        expect(measureStrip(pixels, null).problem).toContain('Calibrer');

        const calibrated = measureStrip(pixels, null, { pxPerMm: 5 });
        expect(calibrated.calibration).toBe('manual');
        expect(calibrated.heartRate).toBe(60);
    });

    it('converts caliper distances and summarises RR intervals', () => {
        expect(pxToMs(30, 6)).toBe(200);
        expect(pxToMs(30, 6, 50)).toBe(100);
        expect(rrStats([800, 800, 800])).toEqual({ heartRate: 75, cv: 0 });
        expect(rrStats([])).toEqual({ heartRate: null, cv: null });
    });
});
