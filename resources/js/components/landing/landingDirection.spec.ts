import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';
import { useLandingLocale } from './translations';

/**
 * The landing page is Arabic by default and writes `dir`/`lang` onto <html>,
 * which outlives it: Inertia swaps the page component without reloading the
 * document. Leaving `dir="rtl"` behind put every screen visited afterwards —
 * the authenticated app, and the desktop shell with it — into right-to-left.
 */
const Harness = defineComponent({
    setup() {
        return useLandingLocale();
    },
    template: '<div :dir="dir" :lang="locale"></div>',
});

describe('landing locale document direction', () => {
    beforeEach(() => {
        document.documentElement.removeAttribute('dir');
        document.documentElement.setAttribute('lang', 'fr');
        localStorage.clear();
    });

    it('flips the document to RTL while the landing page is mounted', () => {
        mount(Harness);

        expect(document.documentElement.getAttribute('dir')).toBe('rtl');
        expect(document.documentElement.getAttribute('lang')).toBe('ar');
    });

    it('restores the served direction when the landing page unmounts', () => {
        const wrapper = mount(Harness);
        expect(document.documentElement.getAttribute('dir')).toBe('rtl');

        wrapper.unmount();

        // Removed, not set to 'ltr' — the server renders no `dir` at all.
        expect(document.documentElement.hasAttribute('dir')).toBe(false);
        expect(document.documentElement.getAttribute('lang')).toBe('fr');
    });

    it('leaves no RTL behind when the visitor chose French', async () => {
        const wrapper = mount(Harness);

        wrapper.vm.setLocale('fr');
        await wrapper.vm.$nextTick();

        expect(document.documentElement.getAttribute('dir')).toBe('ltr');

        wrapper.unmount();

        expect(document.documentElement.hasAttribute('dir')).toBe(false);
        expect(document.documentElement.getAttribute('lang')).toBe('fr');
    });
});
