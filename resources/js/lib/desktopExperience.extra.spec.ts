import { describe, expect, it } from 'vitest';
import { desktopChromeVisibility } from './desktopExperience';

const unavailableDownload = {
    available: false,
    url: null,
    label: 'Télécharger Drclick',
    reason: 'Bientôt disponible',
};

describe('desktopChromeVisibility (extended)', () => {
    it('hides the administration link in the browser without the capability', () => {
        expect(
            desktopChromeVisibility(false, false, unavailableDownload),
        ).toEqual({ administration: false, installer: true });
    });

    it('hides the installer when no download descriptor is shared', () => {
        expect(desktopChromeVisibility(false, true, undefined)).toEqual({
            administration: true,
            installer: false,
        });
    });

    it('keeps the installer entry for a temporarily unavailable download so the dialog can explain why', () => {
        expect(
            desktopChromeVisibility(false, false, unavailableDownload)
                .installer,
        ).toBe(true);
    });

    it.each([
        [true, false, null],
        [true, true, undefined],
        [true, false, unavailableDownload],
    ] as const)(
        'never shows either link inside the desktop shell (%s, %s, %s)',
        (runtime, admin, download) => {
            expect(desktopChromeVisibility(runtime, admin, download)).toEqual({
                administration: false,
                installer: false,
            });
        },
    );
});
