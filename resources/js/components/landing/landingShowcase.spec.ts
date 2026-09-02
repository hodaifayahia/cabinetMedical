import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { LANDING_LOCALES, SHOWCASE_SHOTS, translations } from './translations';

const publicPath = (path: string): string =>
    resolve(process.cwd(), 'public', path);

describe('landing product tour', () => {
    it('ships the same six screens, in the same order, in every language', () => {
        for (const locale of LANDING_LOCALES) {
            const shots = translations[locale].showcase.items.map(
                (item) => item.shot,
            );

            expect(shots, `showcase order for "${locale}"`).toEqual([
                ...SHOWCASE_SHOTS,
            ]);
        }
    });

    it('has a real image behind every screen, at both widths', () => {
        // The template switches tabs by index against one shared image set,
        // so a missing file would render an empty frame rather than fail.
        for (const shot of SHOWCASE_SHOTS) {
            expect(
                existsSync(publicPath(`images/landing/app/${shot}.webp`)),
                `${shot}.webp is missing`,
            ).toBe(true);
            expect(
                existsSync(publicPath(`images/landing/app/${shot}-800.webp`)),
                `${shot}-800.webp is missing`,
            ).toBe(true);
        }
    });

    it('describes every screenshot for assistive technology', () => {
        for (const locale of LANDING_LOCALES) {
            for (const item of translations[locale].showcase.items) {
                expect(
                    item.alt.trim().length,
                    `alt for ${item.shot}`,
                ).toBeGreaterThan(10);
                expect(item.tab.trim()).not.toBe('');
            }
        }
    });
});

describe('landing claims match what actually ships', () => {
    it('no longer announces the patient mobile app as upcoming', () => {
        // The app is released; a "coming soon" badge would understate it and
        // contradicts the present-tense body copy around it.
        const stale = ['قريبًا', 'قريباً', 'Bientôt', 'Coming soon'];

        for (const locale of LANDING_LOCALES) {
            const serialised = JSON.stringify(translations[locale]);

            for (const phrase of stale) {
                expect(
                    serialised,
                    `"${phrase}" still present in "${locale}"`,
                ).not.toContain(phrase);
            }
        }
    });

    it('never promises a 24-hour activation delay', () => {
        // Activation is immediate. The old copy advertised 24 h in the hero
        // stats and in the second setup step.
        const stale = [
            '24 ساعة',
            '24 سا',
            'sous 24',
            'within 24 hours',
            '24 h',
        ];

        for (const locale of LANDING_LOCALES) {
            const serialised = JSON.stringify(translations[locale]);

            for (const phrase of stale) {
                expect(
                    serialised,
                    `"${phrase}" still present in "${locale}"`,
                ).not.toContain(phrase);
            }
        }
    });

    it('replaces the bare hero stats with explained reassurances', () => {
        for (const locale of LANDING_LOCALES) {
            const { assurances } = translations[locale].hero;

            expect(assurances).toHaveLength(3);

            for (const assurance of assurances) {
                expect(assurance.title.trim()).not.toBe('');
                // A body is what separates these from the numbers they
                // replaced, so an empty one would be a silent regression.
                expect(assurance.body.trim().length).toBeGreaterThan(20);
            }
        }
    });
});
