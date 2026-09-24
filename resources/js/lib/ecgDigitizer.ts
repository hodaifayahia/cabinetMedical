// Reads an ECG rhythm strip from an image: finds the scale from the paper
// grid, follows the trace, detects the R waves and measures the RR intervals.
//
// It exists because general vision models misread rhythm on ECG images; the
// software measures, the AI comments, the doctor decides. Everything here is
// pure (no DOM) so it can be tested with synthetic pixels.

export type Pixels = {
    data: Uint8ClampedArray;
    width: number;
    height: number;
};

export type Region = { x: number; y: number; w: number; h: number };

export type GridScale = {
    /** Spacing of the finest grid lines found, in pixels. */
    period: number;
    /** Every fifth line is heavier: the period is certainly the 1 mm square. */
    boldEveryFifth: boolean;
};

export type StripMeasurement = {
    pxPerMm: number | null;
    calibration: 'grid' | 'manual' | 'none';
    durationS: number | null;
    peaksX: number[];
    rrMs: number[];
    heartRate: number | null;
    rrCvPercent: number | null;
    polarity: 'up' | 'down';
    /** Why the measurement is not usable, if it is not. */
    problem: string | null;
};

const PLAUSIBLE_RATE = { min: 25, max: 250 };

const luminance = (r: number, g: number, b: number): number =>
    0.299 * r + 0.587 * g + 0.114 * b;

/** Dark and not coloured: the trace, not the (red, pink or grey) grid. */
export const isInk = (r: number, g: number, b: number): boolean =>
    luminance(r, g, b) < 100 && Math.max(r, g, b) - Math.min(r, g, b) < 70;

/** Grid lines: reddish/pinkish, or light grey lines on white paper. */
export const isGrid = (r: number, g: number, b: number): boolean => {
    const reddish = r > 140 && r - g > 25 && r - b > 15;
    const lum = luminance(r, g, b);
    const greyLine =
        lum > 110 && lum < 225 && Math.max(r, g, b) - Math.min(r, g, b) < 20;

    return reddish || greyLine;
};

const gridWeight = (r: number, g: number, b: number): number =>
    // Darker grid lines weigh much more, so bold 5 mm lines stand out.
    isGrid(r, g, b) ? ((255 - luminance(r, g, b)) / 64) ** 2 : 0;

export const clampRegion = (pixels: Pixels, region: Region | null): Region => {
    const r = region ?? { x: 0, y: 0, w: pixels.width, h: pixels.height };
    const x = Math.max(0, Math.min(pixels.width - 1, Math.floor(r.x)));
    const y = Math.max(0, Math.min(pixels.height - 1, Math.floor(r.y)));

    return {
        x,
        y,
        w: Math.max(1, Math.min(pixels.width - x, Math.floor(r.w))),
        h: Math.max(1, Math.min(pixels.height - y, Math.floor(r.h))),
    };
};

const percentile = (values: number[], p: number): number => {
    if (values.length === 0) {
        return 0;
    }

    const sorted = [...values].sort((a, b) => a - b);

    return sorted[
        Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))
    ];
};

/**
 * The periodicity of the grid along x. Null when no grid is visible, in which
 * case the doctor calibrates by hand.
 */
export const detectGrid = (
    pixels: Pixels,
    region: Region | null = null,
): GridScale | null => {
    const r = clampRegion(pixels, region);
    const profile: number[] = new Array(r.w).fill(0);

    for (let x = 0; x < r.w; x++) {
        let weight = 0;

        for (let y = r.y; y < r.y + r.h; y++) {
            const i = (y * pixels.width + r.x + x) * 4;
            weight += gridWeight(
                pixels.data[i],
                pixels.data[i + 1],
                pixels.data[i + 2],
            );
        }

        profile[x] = weight;
    }

    const mean = profile.reduce((sum, v) => sum + v, 0) / profile.length;

    if (mean < 0.5) {
        return null;
    }

    const centred = profile.map((v) => v - mean);
    const energy = centred.reduce((sum, v) => sum + v * v, 0);

    if (energy === 0) {
        return null;
    }

    const maxLag = Math.min(400, Math.floor(r.w / 3));
    const corr: number[] = [];

    for (let lag = 0; lag <= maxLag; lag++) {
        let sum = 0;

        for (let x = 0; x + lag < centred.length; x++) {
            sum += centred[x] * centred[x + lag];
        }

        corr[lag] = sum / energy;
    }

    const isPeak = (lag: number): boolean =>
        lag > 2 &&
        lag < maxLag &&
        corr[lag] > 0.1 &&
        corr[lag] >= corr[lag - 1] &&
        corr[lag] >= corr[lag + 1];
    const first = corr.findIndex((_, lag) => isPeak(lag));

    if (first < 0) {
        return null;
    }

    // Refine the period over several repetitions for sub-pixel precision.
    let period = first;
    const repeats = Math.floor(maxLag / first);

    if (repeats >= 3) {
        const n = Math.min(repeats, 10);
        let best = n * first;

        for (let lag = n * first - 2; lag <= n * first + 2; lag++) {
            if (lag < maxLag && corr[lag] > corr[best]) {
                best = lag;
            }
        }

        period = best / n;
    }

    // Bold every fifth line: the correlation at 5 periods clearly beats 1.
    const five = Math.round(period * 5);
    const boldEveryFifth =
        five < maxLag &&
        Math.max(corr[five - 1] ?? 0, corr[five] ?? 0, corr[five + 1] ?? 0) >
            corr[first] * 1.15;

    return { period, boldEveryFifth };
};

const detectPeaks = (
    signal: number[],
    refractoryPx: number,
    minAmplitudePx: number,
): number[] => {
    // Anchored on the tallest deflections: QRS complexes can occupy well under
    // 1 % of the columns on a high-resolution scan.
    const threshold = Math.max(0.5 * percentile(signal, 99.9), minAmplitudePx);
    const peaks: number[] = [];

    for (let x = 1; x < signal.length - 1; x++) {
        if (
            signal[x] < threshold ||
            signal[x] < signal[x - 1] ||
            signal[x] < signal[x + 1]
        ) {
            continue;
        }

        const last = peaks[peaks.length - 1];

        if (last !== undefined && x - last < refractoryPx) {
            if (signal[x] > signal[last]) {
                peaks[peaks.length - 1] = x;
            }

            continue;
        }

        peaks.push(x);
    }

    return peaks;
};

export const rrStats = (
    rrMs: number[],
): { heartRate: number | null; cv: number | null } => {
    if (rrMs.length === 0) {
        return { heartRate: null, cv: null };
    }

    const mean = rrMs.reduce((sum, v) => sum + v, 0) / rrMs.length;
    const variance =
        rrMs.reduce((sum, v) => sum + (v - mean) ** 2, 0) / rrMs.length;

    return {
        heartRate: Math.round(60000 / mean),
        cv:
            rrMs.length >= 2
                ? Math.round((Math.sqrt(variance) / mean) * 1000) / 10
                : null,
    };
};

/**
 * Measure the rhythm on one strip. `pxPerMm` comes from the doctor's manual
 * calibration when given, otherwise from the grid.
 */
export const measureStrip = (
    pixels: Pixels,
    region: Region | null,
    options: { pxPerMm?: number | null; paperSpeed?: number } = {},
): StripMeasurement => {
    const r = clampRegion(pixels, region);
    const paperSpeed = options.paperSpeed ?? 25;
    const manual = options.pxPerMm != null && options.pxPerMm > 0;
    const grid = manual ? null : detectGrid(pixels, r);

    const result = (
        pxPerMm: number | null,
        problem: string | null,
        extra: Partial<StripMeasurement> = {},
    ): StripMeasurement => ({
        pxPerMm,
        calibration: manual ? 'manual' : pxPerMm ? 'grid' : 'none',
        durationS: pxPerMm
            ? Math.round((r.w / pxPerMm / paperSpeed) * 100) / 100
            : null,
        peaksX: [],
        rrMs: [],
        heartRate: null,
        rrCvPercent: null,
        polarity: 'up',
        problem,
        ...extra,
    });

    if (!manual && !grid) {
        return result(
            null,
            'Quadrillage non détecté : calibrez l’échelle avec l’outil « Calibrer ».',
        );
    }

    const ink = (x: number, y: number): boolean => {
        const i = (y * pixels.width + r.x + x) * 4;

        return isInk(pixels.data[i], pixels.data[i + 1], pixels.data[i + 2]);
    };

    // Printed frames and rulers are perfectly straight across the whole zone;
    // a trace never is. Those rows and columns are ignored.
    const frameRows = new Set<number>();
    const frameColumns = new Set<number>();

    for (let y = r.y; y < r.y + r.h; y++) {
        let count = 0;

        for (let x = 0; x < r.w; x++) {
            count += ink(x, y) ? 1 : 0;
        }

        if (count > r.w * 0.9) {
            frameRows.add(y);
        }
    }

    for (let x = 0; x < r.w; x++) {
        let count = 0;

        for (let y = r.y; y < r.y + r.h; y++) {
            count += ink(x, y) ? 1 : 0;
        }

        if (count > r.h * 0.8) {
            frameColumns.add(x);
        }
    }

    // Ink runs of each column, then follow the trace as one connected line:
    // text, labels and neighbouring leads are separate blobs and get skipped.
    const runs: [number, number][][] = [];
    const rowInk = new Map<number, number>();

    for (let x = 0; x < r.w; x++) {
        const columnRuns: [number, number][] = [];

        if (!frameColumns.has(x)) {
            let start: number | null = null;

            for (let y = r.y; y <= r.y + r.h; y++) {
                const on = y < r.y + r.h && !frameRows.has(y) && ink(x, y);

                if (on) {
                    start ??= y;
                    rowInk.set(y, (rowInk.get(y) ?? 0) + 1);
                } else if (start !== null) {
                    columnRuns.push([start, y - 1]);
                    start = null;
                }
            }
        }

        runs.push(columnRuns);
    }

    // Start from the row that holds the most ink: the trace's baseline.
    let previous: [number, number] | null = null;
    let busiest = -1;

    rowInk.forEach((count, y) => {
        if (count > busiest) {
            busiest = count;
            previous = [y, y];
        }
    });

    const maxJump = Math.max(4, r.h * 0.2);
    const top: (number | null)[] = [];
    const bottom: (number | null)[] = [];

    for (const columnRuns of runs) {
        let chosen: [number, number] | null = null;
        let bestDistance = Infinity;

        for (const run of columnRuns) {
            const [pTop, pBottom] = previous ?? run;
            const distance =
                run[1] < pTop - 1
                    ? pTop - run[1]
                    : run[0] > pBottom + 1
                      ? run[0] - pBottom
                      : 0;

            if (distance < bestDistance) {
                bestDistance = distance;
                chosen = run;
            }
        }

        if (chosen && bestDistance <= maxJump) {
            previous = chosen;
            top.push(chosen[0]);
            bottom.push(chosen[1]);
        } else {
            top.push(null);
            bottom.push(null);
        }
    }

    const candidates = manual
        ? [options.pxPerMm as number]
        : grid!.boldEveryFifth
          ? [grid!.period]
          : // Without bold lines the finest lines may be the 5 mm squares.
            [grid!.period, grid!.period / 5];

    if (top.filter((v) => v !== null).length < r.w * 0.5) {
        return result(
            candidates[0],
            'Tracé peu visible dans la zone choisie : sélectionnez une seule dérivation bien contrastée.',
        );
    }

    const fill = (values: (number | null)[]): number[] => {
        let previous = values.find((v) => v !== null) ?? 0;

        return values.map((v) => {
            previous = v ?? previous;

            return previous;
        });
    };
    const tops = fill(top);
    const bottoms = fill(bottom);
    const up = tops.map((v) => percentile(tops, 50) - v);
    const down = bottoms.map((v) => v - percentile(bottoms, 50));
    const polarity: 'up' | 'down' =
        percentile(down, 99) > percentile(up, 99) * 1.3 ? 'down' : 'up';
    const signal = polarity === 'up' ? up : down;

    // One detection for every hypothesis, with the shortest refractory
    // window: a window sized for the wrong scale would merge real beats and
    // could make that wrong scale look plausible.
    const smallest = Math.min(...candidates);
    // A QRS is at least ~2 mm tall; anything smaller is baseline noise.
    const peaks = detectPeaks(
        signal,
        Math.max(2, 0.22 * paperSpeed * smallest),
        2 * smallest,
    );

    const measureWith = (pxPerMm: number) => {
        const msPerPx = 1000 / (pxPerMm * paperSpeed);
        const rrMs = peaks
            .slice(1)
            .map((x, index) => Math.round((x - peaks[index]) * msPerPx));

        return { pxPerMm, peaks, rrMs, stats: rrStats(rrMs) };
    };

    const readings = candidates.map(measureWith);
    const plausible = readings.find(
        (reading) =>
            reading.stats.heartRate !== null &&
            reading.stats.heartRate >= PLAUSIBLE_RATE.min &&
            reading.stats.heartRate <= PLAUSIBLE_RATE.max,
    );
    const chosen = plausible ?? readings[0];

    let problem: string | null = null;

    if (chosen.peaks.length < 3) {
        problem =
            'Moins de 3 QRS détectés : choisissez une dérivation plus longue (DII long).';
    } else if (!plausible) {
        problem =
            'Fréquence non physiologique avec l’échelle détectée : vérifiez la calibration.';
    }

    return result(chosen.pxPerMm, problem, {
        peaksX: chosen.peaks.map((x) => x + r.x),
        rrMs: chosen.rrMs,
        heartRate: chosen.stats.heartRate,
        rrCvPercent: chosen.stats.cv,
        polarity,
    });
};

/** Milliseconds between two x positions at the given scale. */
export const pxToMs = (dx: number, pxPerMm: number, paperSpeed = 25): number =>
    Math.round((Math.abs(dx) / (pxPerMm * paperSpeed)) * 1000);
