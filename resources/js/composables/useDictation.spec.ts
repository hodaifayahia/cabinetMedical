import { isTauri } from '@tauri-apps/api/core';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Mock, MockInstance } from 'vitest';
import { defineComponent, h } from 'vue';

import type { DictationOptions } from './useDictation';
import {
    INSECURE_CONTEXT_MESSAGE,
    INTERRUPTED_MESSAGE,
    MIC_BUSY_MESSAGE,
    MIC_DENIED_MESSAGE,
    NO_MIC_MESSAGE,
    NO_RECORDER_MESSAGE,
    SPEECH_OFFLINE_MESSAGE,
    TRANSCRIBING_LABEL,
    useDictation,
} from './useDictation';

vi.mock('@tauri-apps/api/core', () => ({ isTauri: vi.fn(() => false) }));

const CHROME_UA =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const EDGE_UA = `${CHROME_UA} Edg/140.0.0.0`;

// ----- fake browser recognition -------------------------------------------

type ResultItem = { isFinal: boolean; 0: { transcript: string } };

class FakeRecognition {
    static instances: FakeRecognition[] = [];
    static throwOnStart = false;

    lang = '';
    continuous = false;
    interimResults = false;
    start = vi.fn(() => {
        if (FakeRecognition.throwOnStart) {
            throw new Error('not supported');
        }
    });
    stop = vi.fn();
    abort = vi.fn();
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

const recognition = () => {
    const instance = FakeRecognition.instances.at(-1);

    if (!instance) {
        throw new Error('No recognition was created.');
    }

    return instance;
};

// ----- fake microphone, recorder and analyser ------------------------------

class FakeTrack {
    stop = vi.fn();
}

class FakeStream {
    track = new FakeTrack();
    getTracks() {
        return [this.track];
    }
}

class FakeMediaRecorder {
    static instances: FakeMediaRecorder[] = [];
    static supported = ['audio/webm;codecs=opus', 'audio/webm'];
    static isTypeSupported = (type: string) =>
        FakeMediaRecorder.supported.includes(type);

    state: 'inactive' | 'recording' = 'inactive';
    mimeType: string;
    ondataavailable: ((event: { data: Blob }) => void) | null = null;
    onstop: (() => void) | null = null;
    onerror: ((event: Event) => void) | null = null;

    constructor(
        public stream: FakeStream,
        options?: { mimeType?: string },
    ) {
        this.mimeType = options?.mimeType ?? '';
        FakeMediaRecorder.instances.push(this);
    }

    start() {
        this.state = 'recording';
    }

    stop() {
        if (this.state === 'inactive') {
            return;
        }

        this.state = 'inactive';
        const index = FakeMediaRecorder.instances.indexOf(this);
        this.ondataavailable?.({
            data: new Blob([`segment-${index}`], { type: this.mimeType }),
        });
        this.onstop?.();
    }
}

// The analyser reports a constant waveform: 128 is silence.
let sample = 128;

class FakeAudioContext {
    static instances: FakeAudioContext[] = [];
    state = 'running';
    close = vi.fn(() => Promise.resolve());
    resume = vi.fn(() => Promise.resolve());

    constructor() {
        FakeAudioContext.instances.push(this);
    }

    createAnalyser() {
        return {
            fftSize: 2048,
            getByteTimeDomainData: (array: Uint8Array) => array.fill(sample),
        };
    }

    createMediaStreamSource() {
        return { connect: () => undefined };
    }
}

const streams: FakeStream[] = [];
const getUserMedia = vi.fn(() => {
    const stream = new FakeStream();
    streams.push(stream);

    return Promise.resolve(stream);
});

const domError = (name: string) => {
    const error = new Error(name);
    error.name = name;

    return error;
};

const settle = async () => {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
};

const deferred = <T>() => {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
};

// ----- mounting ------------------------------------------------------------

type Dictation = ReturnType<typeof useDictation>;

const mountDictation = (lang?: string, options?: DictationOptions) => {
    let dictation: Dictation | undefined;
    const wrapper = mount(
        defineComponent({
            setup() {
                dictation = useDictation(lang, options);

                return () => h('div');
            },
        }),
    );

    if (!dictation) {
        throw new Error('The composable did not run.');
    }

    return { wrapper, dictation };
};

const useUserAgent = (ua: string) =>
    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(ua);

const installRecognition = () =>
    Object.assign(window, { SpeechRecognition: FakeRecognition });

const installRecorder = () => {
    Object.assign(window, { MediaRecorder: FakeMediaRecorder });
    Object.defineProperty(window.navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia },
    });
};

const installAnalyser = () =>
    Object.assign(window, { AudioContext: FakeAudioContext });

describe('useDictation', () => {
    let transcribe: Mock<(audio: Blob) => Promise<string>>;
    let warn: MockInstance<typeof console.warn>;

    beforeEach(() => {
        FakeRecognition.instances = [];
        FakeRecognition.throwOnStart = false;
        FakeMediaRecorder.instances = [];
        FakeMediaRecorder.supported = ['audio/webm;codecs=opus', 'audio/webm'];
        FakeAudioContext.instances = [];
        streams.length = 0;
        sample = 128;
        vi.mocked(isTauri).mockReturnValue(false);
        getUserMedia.mockImplementation(() => {
            const stream = new FakeStream();
            streams.push(stream);

            return Promise.resolve(stream);
        });
        transcribe = vi.fn((audio: Blob) =>
            Promise.resolve(`texte ${audio.size}`),
        );
        warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    });

    afterEach(() => {
        vi.useRealTimers();
        Reflect.deleteProperty(window, 'SpeechRecognition');
        Reflect.deleteProperty(window, 'webkitSpeechRecognition');
        Reflect.deleteProperty(window, 'MediaRecorder');
        Reflect.deleteProperty(window, 'AudioContext');
        Reflect.deleteProperty(window, '__TAURI_INTERNALS__');
        Reflect.deleteProperty(window.navigator, 'mediaDevices');
        Reflect.deleteProperty(window, 'isSecureContext');
    });

    describe('choosing the engine', () => {
        it('is unsupported without recognition nor recorder and ignores start', () => {
            const { dictation } = mountDictation();

            dictation.start();

            expect(dictation.supported).toBe(false);
            expect(dictation.engine.value).toBeNull();
            expect(dictation.listening.value).toBe(false);
        });

        it('can still be stopped and cleared safely', async () => {
            const { dictation } = mountDictation();

            await expect(dictation.stop()).resolves.toBeUndefined();
            dictation.clear();
            expect(dictation.transcript.value).toBe('');
        });

        it('uses the browser recognition in Chrome and Edge', () => {
            installRecognition();
            installRecorder();

            for (const ua of [CHROME_UA, EDGE_UA]) {
                useUserAgent(ua);
                const { dictation } = mountDictation('fr-FR', { transcribe });

                expect(dictation.engine.value).toBe('speech');
            }
        });

        it('records the microphone in the desktop app even though recognition exists', async () => {
            installRecognition();
            installRecorder();
            useUserAgent(EDGE_UA);
            vi.mocked(isTauri).mockReturnValue(true);
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await settle();

            expect(dictation.supported).toBe(true);
            expect(dictation.engine.value).toBe('recorder');
            expect(FakeRecognition.instances).toHaveLength(0);
            expect(getUserMedia).toHaveBeenCalledWith({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
            expect(FakeMediaRecorder.instances).toHaveLength(1);
        });

        it('detects the desktop app from the Tauri internals too', () => {
            installRecognition();
            installRecorder();
            useUserAgent(EDGE_UA);
            Object.assign(window, { __TAURI_INTERNALS__: {} });

            const { dictation } = mountDictation('fr-FR', { transcribe });

            expect(dictation.engine.value).toBe('recorder');
        });

        it('offers no dictation in the desktop app without a transcriber', () => {
            installRecognition();
            installRecorder();
            vi.mocked(isTauri).mockReturnValue(true);

            const { dictation } = mountDictation();

            expect(dictation.supported).toBe(false);
            expect(dictation.engine.value).toBeNull();
        });

        it.each([
            ['an unknown browser', 'Mozilla/5.0 jsdom'],
            ['Opera', `${CHROME_UA} OPR/120.0.0.0`],
            ['Electron', `${CHROME_UA} Electron/30.0.0`],
        ])('prefers the recorder in %s', (_label, ua) => {
            installRecognition();
            installRecorder();
            useUserAgent(ua);

            const { dictation } = mountDictation('fr-FR', { transcribe });

            expect(dictation.engine.value).toBe('recorder');
        });

        it('prefers the recorder in Brave', () => {
            installRecognition();
            installRecorder();
            useUserAgent(CHROME_UA);
            Object.defineProperty(window.navigator, 'brave', {
                configurable: true,
                value: {},
            });

            const { dictation } = mountDictation('fr-FR', { transcribe });

            expect(dictation.engine.value).toBe('recorder');
            Reflect.deleteProperty(window.navigator, 'brave');
        });

        it('keeps the browser recognition as a last resort without a recorder', () => {
            installRecognition();

            const { dictation } = mountDictation();

            expect(dictation.engine.value).toBe('speech');
        });
    });

    describe('with the browser recognition', () => {
        beforeEach(() => {
            installRecognition();
            useUserAgent(CHROME_UA);
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
            expect(getUserMedia).not.toHaveBeenCalled();
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
            ['not-allowed', MIC_DENIED_MESSAGE],
            ['service-not-allowed', MIC_DENIED_MESSAGE],
            ['network', SPEECH_OFFLINE_MESSAGE],
            ['audio-capture', NO_MIC_MESSAGE],
            ['aborted', INTERRUPTED_MESSAGE],
        ])(
            'explains the %s error when nothing can take over',
            (code, message) => {
                const { dictation } = mountDictation();
                dictation.start();

                recognition().onerror?.({ error: code });

                expect(dictation.error.value).toBe(message);
                expect(warn).toHaveBeenCalled();
            },
        );

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

        it('gives up when the recognition keeps ending at once without hearing anything', () => {
            const { dictation } = mountDictation();
            dictation.start();

            recognition().onend?.();
            recognition().onend?.();
            recognition().onend?.();

            expect(recognition().start).toHaveBeenCalledTimes(3);
            expect(dictation.error.value).toBe(INTERRUPTED_MESSAGE);
            expect(dictation.listening.value).toBe(false);
        });

        it('stops for good after a real error', () => {
            const { dictation } = mountDictation();
            dictation.start();

            recognition().onerror?.({ error: 'network' });
            recognition().onend?.();

            expect(recognition().start).toHaveBeenCalledTimes(1);
            expect(dictation.listening.value).toBe(false);
        });

        it('stop ends recognition and does not restart it', async () => {
            const { dictation } = mountDictation();
            dictation.start();

            await dictation.stop();
            recognition().onerror?.({ error: 'aborted' });
            recognition().onend?.();

            expect(recognition().stop).toHaveBeenCalledTimes(1);
            expect(recognition().start).toHaveBeenCalledTimes(1);
            expect(dictation.listening.value).toBe(false);
            expect(dictation.error.value).toBeNull();
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
            void dictation.stop();
            recognition().onend?.();

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

    describe('falling back from the browser recognition to the recorder', () => {
        beforeEach(() => {
            installRecognition();
            installRecorder();
            useUserAgent(EDGE_UA);
        });

        it.each([
            'not-allowed',
            'service-not-allowed',
            'network',
            'audio-capture',
        ])('switches to the recorder after %s', async (code) => {
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();
            const broken = recognition();

            broken.onerror?.({ error: code });
            await settle();

            expect(dictation.engine.value).toBe('recorder');
            expect(dictation.error.value).toBeNull();
            expect(dictation.listening.value).toBe(true);
            expect(broken.abort).toHaveBeenCalled();
            expect(broken.onend).toBeNull();
            expect(getUserMedia).toHaveBeenCalledTimes(1);
            expect(FakeMediaRecorder.instances).toHaveLength(1);
            expect(warn).toHaveBeenCalledWith(
                '[Dictée] reconnaissance du navigateur :',
                code,
            );
        });

        it('switches to the recorder when the recognition keeps ending at once', async () => {
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();

            recognition().onend?.();
            recognition().onend?.();
            recognition().onend?.();
            await settle();

            expect(dictation.engine.value).toBe('recorder');
            expect(FakeMediaRecorder.instances).toHaveLength(1);
        });

        it('switches to the recorder when the recognition cannot start', async () => {
            FakeRecognition.throwOnStart = true;
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await settle();

            expect(dictation.engine.value).toBe('recorder');
            expect(dictation.listening.value).toBe(true);
        });

        it('keeps the recorder for the next dictation', async () => {
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();
            recognition().onerror?.({ error: 'network' });
            await settle();
            await dictation.stop();

            dictation.start();
            await settle();

            expect(FakeRecognition.instances).toHaveLength(1);
            expect(getUserMedia).toHaveBeenCalledTimes(2);
        });

        it('does not fall back on mere silence', () => {
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();

            recognition().onerror?.({ error: 'no-speech' });

            expect(dictation.engine.value).toBe('speech');
            expect(getUserMedia).not.toHaveBeenCalled();
        });
    });

    describe('with the recorder', () => {
        beforeEach(() => {
            installRecorder();
            vi.mocked(isTauri).mockReturnValue(true);
        });

        it('records WebM Opus and transcribes the last segment on stop', async () => {
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();

            expect(dictation.listening.value).toBe(true);
            await settle();
            expect(FakeMediaRecorder.instances[0].mimeType).toBe(
                'audio/webm;codecs=opus',
            );
            expect(FakeMediaRecorder.instances[0].state).toBe('recording');

            await dictation.stop();

            expect(transcribe).toHaveBeenCalledTimes(1);
            const audio = transcribe.mock.calls[0][0];
            expect(audio.type).toBe('audio/webm;codecs=opus');
            expect(dictation.transcript.value).toBe(`texte ${audio.size}`);
            expect(dictation.listening.value).toBe(false);
            expect(dictation.interim.value).toBe('');
            expect(streams[0].track.stop).toHaveBeenCalled();
            expect(FakeMediaRecorder.instances).toHaveLength(1);
        });

        it('falls back to the formats this recorder supports', async () => {
            FakeMediaRecorder.supported = ['audio/mp4'];
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await settle();

            expect(FakeMediaRecorder.instances[0].mimeType).toBe('audio/mp4');
        });

        it('shows the transcription in progress, then appends in order', async () => {
            const first = deferred<string>();
            const second = deferred<string>();
            transcribe
                .mockImplementationOnce(() => first.promise)
                .mockImplementationOnce(() => second.promise);
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', {
                transcribe,
                maxSegmentMs: 1000,
            });
            dictation.transcript.value = 'Déjà tapé';
            dictation.start();
            await settle();

            vi.advanceTimersByTime(1000);

            expect(FakeMediaRecorder.instances).toHaveLength(2);
            expect(dictation.interim.value).toBe(TRANSCRIBING_LABEL);
            expect(dictation.transcribing.value).toBe(true);

            const stopping = dictation.stop();
            await settle();
            // The second segment waits for the first.
            expect(transcribe).toHaveBeenCalledTimes(1);

            second.resolve('deuxième');
            first.resolve('premier');
            await stopping;

            expect(dictation.transcript.value).toBe(
                'Déjà tapé premier deuxième',
            );
            expect(dictation.transcribing.value).toBe(false);
            expect(dictation.interim.value).toBe('');
        });

        it('cuts a long segment and keeps recording', async () => {
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();
            await settle();

            vi.advanceTimersByTime(24_900);
            expect(transcribe).not.toHaveBeenCalled();

            vi.advanceTimersByTime(200);
            await settle();

            expect(transcribe).toHaveBeenCalledTimes(1);
            expect(FakeMediaRecorder.instances).toHaveLength(2);
            expect(FakeMediaRecorder.instances[1].state).toBe('recording');
            expect(dictation.listening.value).toBe(true);
        });

        it('cuts a segment at a pause and shows the input level', async () => {
            installAnalyser();
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();
            await settle();

            sample = 160;
            vi.advanceTimersByTime(5000);
            expect(dictation.level.value).toBeGreaterThan(0.5);
            expect(transcribe).not.toHaveBeenCalled();

            sample = 128;
            vi.advanceTimersByTime(1000);
            await settle();

            expect(dictation.level.value).toBe(0);
            expect(transcribe).toHaveBeenCalledTimes(1);
            expect(FakeMediaRecorder.instances).toHaveLength(2);
        });

        it('does not send a segment without any sound', async () => {
            installAnalyser();
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', { transcribe });
            dictation.start();
            await settle();

            vi.advanceTimersByTime(25_100);
            await dictation.stop();

            expect(transcribe).not.toHaveBeenCalled();
            expect(FakeAudioContext.instances[0].close).toHaveBeenCalled();
        });

        it('stops recording and explains a failed transcription', async () => {
            transcribe.mockRejectedValueOnce({
                reason: 'unavailable',
                message: 'La transcription de la dictée a besoin d’Internet.',
            });
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', {
                transcribe,
                maxSegmentMs: 1000,
            });
            dictation.start();
            await settle();

            vi.advanceTimersByTime(1000);
            await settle();

            expect(dictation.error.value).toBe(
                'La transcription de la dictée a besoin d’Internet.',
            );
            expect(dictation.listening.value).toBe(false);
            expect(streams[0].track.stop).toHaveBeenCalled();
        });

        it.each([
            ['NotAllowedError', MIC_DENIED_MESSAGE],
            ['SecurityError', MIC_DENIED_MESSAGE],
            ['NotFoundError', NO_MIC_MESSAGE],
            ['NotReadableError', MIC_BUSY_MESSAGE],
        ])('explains a %s from the microphone', async (name, message) => {
            getUserMedia.mockRejectedValueOnce(domError(name));
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await settle();

            expect(dictation.error.value).toBe(message);
            expect(dictation.listening.value).toBe(false);
            expect(warn).toHaveBeenCalledWith(
                '[Dictée] micro indisponible :',
                name,
                expect.anything(),
            );
        });

        it('names an unexpected microphone error', async () => {
            getUserMedia.mockRejectedValueOnce(domError('WeirdError'));
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await settle();

            expect(dictation.error.value).toContain('WeirdError');
        });

        it('explains that an insecure address has no microphone', async () => {
            Reflect.deleteProperty(window.navigator, 'mediaDevices');
            Object.defineProperty(window, 'isSecureContext', {
                configurable: true,
                value: false,
            });
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await settle();

            expect(dictation.error.value).toBe(INSECURE_CONTEXT_MESSAGE);
        });

        it('explains a web view that cannot reach the microphone', async () => {
            Reflect.deleteProperty(window.navigator, 'mediaDevices');
            Object.defineProperty(window, 'isSecureContext', {
                configurable: true,
                value: true,
            });
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await settle();

            expect(dictation.error.value).toBe(NO_RECORDER_MESSAGE);
        });

        it('releases a microphone granted after the doctor already stopped', async () => {
            const granted = deferred<FakeStream>();
            getUserMedia.mockImplementationOnce(() => granted.promise);
            const { dictation } = mountDictation('fr-FR', { transcribe });

            dictation.start();
            await dictation.stop();
            const stream = new FakeStream();
            granted.resolve(stream);
            await settle();

            expect(stream.track.stop).toHaveBeenCalled();
            expect(FakeMediaRecorder.instances).toHaveLength(0);
        });

        it('releases the microphone when the component unmounts', async () => {
            const { wrapper, dictation } = mountDictation('fr-FR', {
                transcribe,
            });
            dictation.start();
            await settle();

            wrapper.unmount();
            await settle();

            expect(streams[0].track.stop).toHaveBeenCalled();
            expect(dictation.listening.value).toBe(false);
        });

        it('keeps the in-progress label when the transcript is cleared', async () => {
            transcribe.mockImplementationOnce(
                () => new Promise(() => undefined),
            );
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', {
                transcribe,
                maxSegmentMs: 1000,
            });
            dictation.start();
            await settle();
            vi.advanceTimersByTime(1000);

            dictation.clear();

            expect(dictation.interim.value).toBe(TRANSCRIBING_LABEL);
        });
    });

    describe('testing the microphone', () => {
        beforeEach(() => {
            installRecorder();
            installAnalyser();
            vi.mocked(isTauri).mockReturnValue(true);
        });

        it('reports a working microphone and releases it', async () => {
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', {
                transcribe,
                testMs: 2000,
            });

            const testing = dictation.testMicrophone();
            expect(dictation.testing.value).toBe(true);
            await testing;
            sample = 170;
            vi.advanceTimersByTime(500);
            expect(dictation.level.value).toBeGreaterThan(0.5);

            vi.advanceTimersByTime(1600);

            expect(dictation.testResult.value).toBe('ok');
            expect(dictation.testing.value).toBe(false);
            expect(dictation.level.value).toBe(0);
            expect(streams[0].track.stop).toHaveBeenCalled();
        });

        it('reports a microphone that hears nothing', async () => {
            vi.useFakeTimers();
            const { dictation } = mountDictation('fr-FR', {
                transcribe,
                testMs: 2000,
            });

            await dictation.testMicrophone();
            vi.advanceTimersByTime(2100);

            expect(dictation.testResult.value).toBe('silent');
        });

        it('explains a refused microphone', async () => {
            getUserMedia.mockRejectedValueOnce(domError('NotAllowedError'));
            const { dictation } = mountDictation('fr-FR', { transcribe });

            await dictation.testMicrophone();

            expect(dictation.error.value).toBe(MIC_DENIED_MESSAGE);
            expect(dictation.testing.value).toBe(false);
            expect(dictation.testResult.value).toBeNull();
        });

        it('ends the check when the dictation starts', async () => {
            const { dictation } = mountDictation('fr-FR', { transcribe });
            await dictation.testMicrophone();

            dictation.start();
            await settle();

            expect(dictation.testing.value).toBe(false);
            expect(streams[0].track.stop).toHaveBeenCalled();
            expect(dictation.listening.value).toBe(true);
        });
    });
});
