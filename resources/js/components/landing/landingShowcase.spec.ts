import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    AI_SHOTS,
    LANDING_LOCALES,
    MOBILE_SCREENS,
    SHOWCASE_SHOTS,
    translations,
} from './translations';

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

describe('landing clinical AI section', () => {
    it('shows the same AI screens, in the same order, in every language', () => {
        for (const locale of LANDING_LOCALES) {
            const shots = translations[locale].ai.items.map(
                (item) => item.shot,
            );

            expect(shots, `AI order for "${locale}"`).toEqual([...AI_SHOTS]);
        }
    });

    it('has a real capture behind every AI screen, at both widths', () => {
        for (const shot of AI_SHOTS) {
            expect(
                existsSync(publicPath(`images/landing/ai/${shot}.webp`)),
                `ai/${shot}.webp is missing`,
            ).toBe(true);
            expect(
                existsSync(publicPath(`images/landing/ai/${shot}-800.webp`)),
                `ai/${shot}-800.webp is missing`,
            ).toBe(true);
        }
    });

    it('lists every AI action and the four safeguards in every language', () => {
        // The icons are matched to these lists by position.
        for (const locale of LANDING_LOCALES) {
            const { ai, hero, nav } = translations[locale];

            expect(ai.capabilities, locale).toHaveLength(8);
            expect(ai.principles, locale).toHaveLength(4);
            expect(ai.disclaimer.trim().length).toBeGreaterThan(20);
            expect(hero.aiBadge.trim()).not.toBe('');
            expect(nav.ai.trim()).not.toBe('');

            for (const item of ai.items) {
                expect(
                    item.alt.trim().length,
                    `alt for ${item.shot}`,
                ).toBeGreaterThan(10);
            }
        }
    });
});

describe('landing patient app screens', () => {
    it('uses real captures of the patient app in every language', () => {
        for (const locale of LANDING_LOCALES) {
            const screens = translations[locale].mobileApp.screens;

            expect(screens.map((item) => item.screen)).toEqual([
                ...MOBILE_SCREENS,
            ]);

            for (const item of screens) {
                expect(item.alt.trim().length).toBeGreaterThan(10);
                expect(item.label.trim()).not.toBe('');
            }
        }

        for (const screen of MOBILE_SCREENS) {
            expect(
                existsSync(publicPath(`images/landing/mobile/${screen}.webp`)),
                `mobile/${screen}.webp is missing`,
            ).toBe(true);
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
