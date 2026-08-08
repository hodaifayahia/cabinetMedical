import { describe, expect, it } from 'vitest';
import { desktopChromeVisibility } from './desktopExperience';

const download = {
    available: true,
    url: '/downloads/Drclick.exe',
    label: 'Télécharger Drclick',
    reason: null,
};

describe('desktopChromeVisibility', () => {
    it('hides installer and platform administration links in Tauri', () => {
        expect(desktopChromeVisibility(true, true, download)).toEqual({
            administration: false,
            installer: false,
        });
    });

    it('keeps browser links governed by their existing capabilities', () => {
        expect(desktopChromeVisibility(false, true, download)).toEqual({
            administration: true,
            installer: true,
        });

        expect(desktopChromeVisibility(false, false, null)).toEqual({
            administration: false,
            installer: false,
        });
    });
});
