import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

import { useDictation } from './useDictation';

type ResultItem = { isFinal: boolean; 0: { transcript: string } };

class FakeRecognition {
    static instances: FakeRecognition[] = [];

    lang = '';
    continuous = false;
    interimResults = false;
    start = vi.fn();
    stop = vi.fn();
    onresult:
        | ((event: { resultIndex: number; results: ResultItem[] }) => void)
        | null = null;
    onerror: ((event: { error: string }) => void) | null = null;
    onend: (() => void) | null = null;

    constructor() {
        FakeRecognition.instances.push(this);
    }

    emitResults(resultIndex: number, results: [string, boolean][]) {
        this.onresult?.({
            resultIndex,
            results: results.map(([transcript, isFinal]) => ({
                isFinal,
                0: { transcript },
            })),
        });
    }
}

type Dictation = ReturnType<typeof useDictation>;

const mountDictation = (lang?: string) => {
    let dictation: Dictation | undefined;
    const wrapper = mount(
        defineComponent({
            setup() {
                dictation = lang ? useDictation(lang) : useDictation();

                return () => h('div');
            },
        }),
    );

    return { wrapper, dictation: dictation! };
};

const recognition = () => {
    const instance = FakeRecognition.instances.at(-1);

    if (!instance) {
        throw new Error('No recognition was created.');
    }

    return instance;
};

describe('useDictation', () => {
    beforeEach(() => {
        FakeRecognition.instances = [];
    });

    afterEach(() => {
        Reflect.deleteProperty(window, 'SpeechRecognition');
        Reflect.deleteProperty(window, 'webkitSpeechRecognition');
    });

    describe('without browser support', () => {
        it('reports itself unsupported and ignores start', () => {
            const { dictation } = mountDictation();

            dictation.start();

            expect(dictation.supported).toBe(false);
            expect(dictation.listening.value).toBe(false);
        });

        it('can still be stopped and cleared safely', () => {
            const { dictation } = mountDictation();

            expect(() => dictation.stop()).not.toThrow();
            dictation.clear();
            expect(dictation.transcript.value).toBe('');
        });
    });

    describe('with browser support', () => {
        beforeEach(() => {
            Object.assign(window, { SpeechRecognition: FakeRecognition });
        });

        it('falls back to the prefixed WebKit implementation', () => {
            Reflect.deleteProperty(window, 'SpeechRecognition');
            Object.assign(window, { webkitSpeechRecognition: FakeRecognition });
            const { dictation } = mountDictation();

            dictation.start();

            expect(dictation.supported).toBe(true);
            expect(recognition().start).toHaveBeenCalledTimes(1);
        });

        it('starts continuous French recognition with interim results', () => {
            const { dictation } = mountDictation();

            dictation.start();

            expect(recognition().lang).toBe('fr-FR');
            expect(recognition().continuous).toBe(true);
            expect(recognition().interimResults).toBe(true);
            expect(recognition().start).toHaveBeenCalledTimes(1);
            expect(dictation.listening.value).toBe(true);
        });

        it('honours a requested language', () => {
            const { dictation } = mountDictation('ar-DZ');

            dictation.start();

            expect(recognition().lang).toBe('ar-DZ');
        });

        it('ignores a second start while already listening', () => {
            const { dictation } = mountDictation();

            dictation.start();
            dictation.start();

            expect(FakeRecognition.instances).toHaveLength(1);
        });

        it('appends final results and shows pending words separately', () => {
            const { dictation } = mountDictation();
            dictation.start();

            recognition().emitResults(0, [
                ['Patient de 54 ans', true],
                [' douleur', false],
            ]);

            expect(dictation.transcript.value).toBe('Patient de 54 ans');
            expect(dictation.interim.value).toBe(' douleur');

            recognition().emitResults(1, [
                ['ignored', true],
                ['douleur thoracique', true],
            ]);

            expect(dictation.transcript.value).toBe(
                'Patient de 54 ans douleur thoracique',
            );
            expect(dictation.interim.value).toBe('');
        });

        it('concatenates several interim fragments', () => {
            const { dictation } = mountDictation();
            dictation.start();

            recognition().emitResults(0, [
                ['tension ', false],
                ['douze', false],
            ]);

            expect(dictation.interim.value).toBe('tension douze');
            expect(dictation.transcript.value).toBe('');
        });

        it.each([
            ['not-allowed', 'Micro refusé : autorisez le micro pour ce site.'],
            ['network', 'La dictée a besoin d’Internet.'],
            ['audio-capture', 'La dictée s’est interrompue.'],
            ['aborted', 'La dictée s’est interrompue.'],
        ])('explains the %s error', (code, message) => {
            const { dictation } = mountDictation();
            dictation.start();

            recognition().onerror?.({ error: code });

            expect(dictation.error.value).toBe(message);
        });

        it('treats silence as a non-error and keeps listening', () => {
            const { dictation } = mountDictation();
            dictation.start();

            recognition().onerror?.({ error: 'no-speech' });
            recognition().onend?.();

            expect(dictation.error.value).toBeNull();
            expect(recognition().start).toHaveBeenCalledTimes(2);
            expect(dictation.listening.value).toBe(true);
        });

        it('restarts after the browser stops on its own', () => {
            const { dictation } = mountDictation();
            dictation.start();
            recognition().emitResults(0, [['en cours', false]]);

            recognition().onend?.();

            expect(recognition().start).toHaveBeenCalledTimes(2);
            expect(dictation.interim.value).toBe('');
            expect(dictation.listening.value).toBe(true);
        });

        it('stops for good after a real error', () => {
            const { dictation } = mountDictation();
            dictation.start();

            recognition().onerror?.({ error: 'network' });
            recognition().onend?.();

            expect(recognition().start).toHaveBeenCalledTimes(1);
            expect(dictation.listening.value).toBe(false);
        });

        it('stop ends recognition and does not restart it', () => {
            const { dictation } = mountDictation();
            dictation.start();

            dictation.stop();
            recognition().onend?.();

            expect(recognition().stop).toHaveBeenCalledTimes(1);
            expect(recognition().start).toHaveBeenCalledTimes(1);
            expect(dictation.listening.value).toBe(false);
        });

        it('clears a previous error on a new start', () => {
            const { dictation } = mountDictation();
            dictation.start();
            recognition().onerror?.({ error: 'network' });
            recognition().onend?.();

            dictation.start();

            expect(dictation.error.value).toBeNull();
            expect(FakeRecognition.instances).toHaveLength(2);
        });

        it('keeps the transcript across restarts until cleared', () => {
            const { dictation } = mountDictation();
            dictation.start();
            recognition().emitResults(0, [['première phrase', true]]);
            dictation.stop();

            dictation.start();
            recognition().emitResults(0, [['seconde phrase', true]]);

            expect(dictation.transcript.value).toBe(
                'première phrase seconde phrase',
            );

            dictation.clear();

            expect(dictation.transcript.value).toBe('');
            expect(dictation.interim.value).toBe('');
        });

        it('stops listening when the component unmounts', () => {
            const { wrapper, dictation } = mountDictation();
            dictation.start();

            wrapper.unmount();

            expect(recognition().stop).toHaveBeenCalledTimes(1);
            expect(dictation.listening.value).toBe(false);
        });
    });
});
