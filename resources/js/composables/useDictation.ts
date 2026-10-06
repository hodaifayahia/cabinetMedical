// Voice dictation for the consultation. The doctor speaks the visit; the
// transcript is then structured into the visit fields by the AI.
//
// Two engines:
// - `speech`: the browser's own speech recognition (Chrome, Edge, Safari).
//   Used only in a real browser: in the desktop app (Tauri / WebView2) the
//   constructor exists but Microsoft's online speech service is not wired
//   in, so it fails with not-allowed / network / service-not-allowed.
// - `recorder`: the microphone is opened with getUserMedia, recorded with
//   MediaRecorder in short segments (cut on a pause, or every ~25 s), and
//   each segment is sent to the server for transcription.
// When the browser recognition fails, the recorder takes over by itself.

import { isTauri } from '@tauri-apps/api/core';
import { computed, onBeforeUnmount, ref } from 'vue';

type RecognitionResult = { isFinal: boolean; 0: { transcript: string } };
type RecognitionEvent = {
    resultIndex: number;
    results: ArrayLike<RecognitionResult>;
};
type Recognition = {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    start: () => void;
    stop: () => void;
    abort?: () => void;
    onresult: ((event: RecognitionEvent) => void) | null;
    onerror: ((event: { error: string }) => void) | null;
    onend: (() => void) | null;
};

export type DictationEngine = 'speech' | 'recorder';

export type DictationOptions = {
    /** Turns one recorded audio segment into text (server side). */
    transcribe?: (audio: Blob) => Promise<string>;
    /** Longest segment before it is cut and sent, in ms. */
    maxSegmentMs?: number;
    /** A pause only cuts a segment at least this long, in ms. */
    minSegmentMs?: number;
    /** How long a pause must last to cut a segment, in ms. */
    silenceMs?: number;
    /** Length of the "Tester le micro" check, in ms. */
    testMs?: number;
};

export const MIC_DENIED_MESSAGE =
    'Accès au micro refusé. Autorisez le micro (Paramètres Windows › Confidentialité › Microphone › autoriser les applications de bureau) puis réessayez.';
export const NO_MIC_MESSAGE =
    'Aucun micro détecté. Branchez un micro puis réessayez.';
export const MIC_BUSY_MESSAGE =
    'Le micro est utilisé par une autre application.';
export const INSECURE_CONTEXT_MESSAGE =
    'Le micro n’est accessible que sur une adresse sécurisée (https) ou sur ce poste (localhost). Ouvrez le logiciel en https, ou utilisez la dictée de Windows (Windows + H).';
export const NO_RECORDER_MESSAGE =
    'Ce navigateur ne sait pas enregistrer le son. Utilisez Chrome ou Edge à jour, ou la dictée de Windows (Windows + H).';
export const SPEECH_OFFLINE_MESSAGE =
    'La dictée du navigateur a besoin d’Internet. Sans connexion : cliquez dans le champ texte puis appuyez sur Windows + H.';
export const INTERRUPTED_MESSAGE = 'La dictée s’est interrompue.';
export const TRANSCRIBING_LABEL = 'Transcription…';

// Recorder formats in order of preference; WebView2 and Chrome record WebM
// Opus, Firefox Ogg Opus, Safari MP4.
const RECORDER_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
];

// Browser recognition errors after which the recorder is tried instead.
const FALLBACK_ERRORS = [
    'not-allowed',
    'service-not-allowed',
    'network',
    'audio-capture',
    'language-not-supported',
];

// Input level (0-1) above which the doctor is speaking, and below which a
// whole segment is considered silent and not sent.
const VOICE_LEVEL = 0.08;
const SILENT_SEGMENT_LEVEL = 0.03;
const TICK_MS = 100;

const recognitionClass = (): (new () => Recognition) | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const w = window as unknown as {
        SpeechRecognition?: new () => Recognition;
        webkitSpeechRecognition?: new () => Recognition;
    };

    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
};

const inDesktopApp = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        if (isTauri()) {
            return true;
        }
    } catch {
        // Not in Tauri.
    }

    return '__TAURI_INTERNALS__' in window;
};

/**
 * Whether the browser's recognition can be trusted: a real Chrome, Edge or
 * Safari. Embedded web views and Chromium forks (Brave, Opera, Electron)
 * expose the constructor without the online service behind it.
 */
const speechLooksReliable = (): boolean => {
    if (recognitionClass() === null || inDesktopApp()) {
        return false;
    }

    const nav = navigator as Navigator & { brave?: unknown };
    const ua = nav.userAgent ?? '';

    if (nav.brave !== undefined || /OPR\/|Electron\/|; wv\)/.test(ua)) {
        return false;
    }

    return (
        /(Chrome|Chromium|Edg|CriOS)\//.test(ua) ||
        (/Safari\//.test(ua) && /Version\//.test(ua))
    );
};

const recorderClass = (): typeof MediaRecorder | null =>
    typeof window !== 'undefined' &&
    typeof (window as { MediaRecorder?: unknown }).MediaRecorder === 'function'
        ? window.MediaRecorder
        : null;

const pickMimeType = (): string => {
    const Recorder = recorderClass();

    if (!Recorder || typeof Recorder.isTypeSupported !== 'function') {
        return '';
    }

    return (
        RECORDER_TYPES.find((type) => {
            try {
                return Recorder.isTypeSupported(type);
            } catch {
                return false;
            }
        }) ?? ''
    );
};

const audioContextClass = (): typeof AudioContext | null => {
    const w = window as unknown as {
        AudioContext?: typeof AudioContext;
        webkitAudioContext?: typeof AudioContext;
    };

    return w.AudioContext ?? w.webkitAudioContext ?? null;
};

export const microphoneErrorMessage = (error: unknown): string => {
    const name =
        typeof error === 'object' && error !== null && 'name' in error
            ? String((error as { name: unknown }).name)
            : '';

    switch (name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
        case 'SecurityError':
            return MIC_DENIED_MESSAGE;
        case 'NotFoundError':
        case 'DevicesNotFoundError':
        case 'OverconstrainedError':
            return NO_MIC_MESSAGE;
        case 'NotReadableError':
        case 'TrackStartError':
        case 'AbortError':
            return MIC_BUSY_MESSAGE;
        case 'InsecureContextError':
            return INSECURE_CONTEXT_MESSAGE;
        case 'NotSupportedError':
            return NO_RECORDER_MESSAGE;
        default:
            return `Impossible d’ouvrir le micro${name ? ` (${name})` : ''}. Vérifiez le micro puis réessayez.`;
    }
};

const speechErrorMessage = (code: string): string | null => {
    switch (code) {
        case 'no-speech':
            return null;
        case 'not-allowed':
        case 'service-not-allowed':
            return MIC_DENIED_MESSAGE;
        case 'network':
            return SPEECH_OFFLINE_MESSAGE;
        case 'audio-capture':
            return NO_MIC_MESSAGE;
        default:
            return INTERRUPTED_MESSAGE;
    }
};

const failureMessage = (error: unknown): string => {
    if (typeof error === 'string' && error.trim() !== '') {
        return error;
    }

    if (
        typeof error === 'object' &&
        error !== null &&
        'message' in error &&
        typeof (error as { message: unknown }).message === 'string' &&
        (error as { message: string }).message.trim() !== ''
    ) {
        return (error as { message: string }).message;
    }

    return 'La transcription de la dictée a échoué. Réessayez ou tapez vos notes.';
};

const namedError = (name: string): Error => {
    const error = new Error(name);
    error.name = name;

    return error;
};

const wait = (ms: number) =>
    new Promise<void>((resolve) => setTimeout(resolve, ms));

/** A live input level read from the microphone stream. */
type Meter = { read: () => number; close: () => void; live: boolean };

const openMeter = (stream: MediaStream): Meter => {
    const Context = audioContextClass();

    if (!Context) {
        return { read: () => 0, close: () => undefined, live: false };
    }

    try {
        const context = new Context();
        const analyser = context.createAnalyser();
        analyser.fftSize = 1024;
        context.createMediaStreamSource(stream).connect(analyser);
        const samples = new Uint8Array(analyser.fftSize);
        void context.resume?.().catch(() => undefined);

        return {
            get live() {
                return context.state === 'running';
            },
            read: () => {
                analyser.getByteTimeDomainData(samples);
                let sum = 0;

                for (const sample of samples) {
                    const centred = (sample - 128) / 128;
                    sum += centred * centred;
                }

                // Speech sits around 0.05-0.2 RMS; spread it over 0-1.
                return Math.min(1, Math.sqrt(sum / samples.length) * 5);
            },
            close: () => {
                void context.close?.().catch(() => undefined);
            },
        };
    } catch (error) {
        console.warn('[Dictée] mesure du niveau indisponible', error);

        return { read: () => 0, close: () => undefined, live: false };
    }
};

export const useDictation = (
    lang = 'fr-FR',
    options: DictationOptions = {},
) => {
    const maxSegmentMs = options.maxSegmentMs ?? 25_000;
    const minSegmentMs = options.minSegmentMs ?? 4_000;
    const silenceMs = options.silenceMs ?? 900;
    const testMs = options.testMs ?? 5_000;

    const recorderSupported =
        options.transcribe !== undefined && recorderClass() !== null;
    const engine = ref<DictationEngine | null>(
        speechLooksReliable()
            ? 'speech'
            : recorderSupported
              ? 'recorder'
              : recognitionClass() !== null && !inDesktopApp()
                ? 'speech'
                : null,
    );
    const supported = engine.value !== null;

    const listening = ref(false);
    const transcript = ref('');
    const interim = ref('');
    const error = ref<string | null>(null);
    const level = ref(0);
    const testing = ref(false);
    const testResult = ref<'ok' | 'silent' | null>(null);
    const pending = ref(0);
    const transcribing = computed(() => pending.value > 0);

    let wanted = false;

    const append = (text: string) => {
        const clean = text.trim();

        if (clean) {
            transcript.value = `${transcript.value} ${clean}`.trim();
        }
    };

    // ----- microphone -------------------------------------------------

    const openMicrophone = async (): Promise<MediaStream> => {
        const devices =
            typeof navigator !== 'undefined' ? navigator.mediaDevices : null;

        if (!devices || typeof devices.getUserMedia !== 'function') {
            throw namedError(
                typeof window !== 'undefined' &&
                    window.isSecureContext === false
                    ? 'InsecureContextError'
                    : 'NotSupportedError',
            );
        }

        return devices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
    };

    const releaseStream = (stream: MediaStream | null) => {
        stream?.getTracks().forEach((track) => track.stop());
    };

    const reportMicrophoneError = (cause: unknown) => {
        const name =
            typeof cause === 'object' && cause !== null && 'name' in cause
                ? String((cause as { name: unknown }).name)
                : String(cause);
        console.warn('[Dictée] micro indisponible :', name, cause);
        error.value = microphoneErrorMessage(cause);
    };

    // ----- recorder engine --------------------------------------------

    let stream: MediaStream | null = null;
    let meter: Meter | null = null;
    let recorder: MediaRecorder | null = null;
    let ticker: ReturnType<typeof setInterval> | null = null;
    let segmentStartedAt = 0;
    let lastVoiceAt = 0;
    let segmentPeak = 0;
    let queue: Promise<void> = Promise.resolve();
    let recorderStopped: Promise<void> = Promise.resolve();
    const mimeType = pickMimeType();

    const enqueue = (audio: Blob) => {
        const transcribe = options.transcribe;

        if (!transcribe) {
            return;
        }

        pending.value++;
        interim.value = TRANSCRIBING_LABEL;
        queue = queue.then(async () => {
            try {
                append(await transcribe(audio));
            } catch (cause) {
                console.warn('[Dictée] transcription impossible', cause);
                error.value = failureMessage(cause);
                // Recording on would only lose more of the dictation.
                haltRecorder();
            } finally {
                pending.value--;
                interim.value = pending.value > 0 ? TRANSCRIBING_LABEL : '';
            }
        });
    };

    const beginSegment = () => {
        const Recorder = recorderClass();

        if (!Recorder || !stream) {
            return;
        }

        const current = mimeType
            ? new Recorder(stream, { mimeType })
            : new Recorder(stream);
        const chunks: Blob[] = [];
        let resolveStopped: () => void = () => undefined;
        recorderStopped = new Promise<void>((resolve) => {
            resolveStopped = resolve;
        });

        current.ondataavailable = (event: BlobEvent) => {
            if (event.data && event.data.size > 0) {
                chunks.push(event.data);
            }
        };
        current.onstop = () => {
            const peak = segmentPeak;
            const audio = new Blob(chunks, {
                type: current.mimeType || mimeType || 'audio/webm',
            });
            // A segment with no sound at all is not worth a request, but
            // only when the level could really be measured.
            const silent = meter?.live === true && peak < SILENT_SEGMENT_LEVEL;

            if (audio.size > 0 && !silent) {
                enqueue(audio);
            }

            if (recorder === current) {
                recorder = null;
            }

            resolveStopped();

            if (wanted && stream) {
                beginSegment();
            }
        };
        current.onerror = (event: Event) => {
            console.warn('[Dictée] enregistreur en erreur', event);
        };

        recorder = current;
        segmentStartedAt = Date.now();
        lastVoiceAt = segmentStartedAt;
        segmentPeak = 0;
        current.start();
    };

    const cutSegment = () => {
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    };

    const tick = () => {
        if (!meter) {
            return;
        }

        const now = Date.now();
        const current = meter.read();
        level.value = current;
        segmentPeak = Math.max(segmentPeak, current);

        if (current >= VOICE_LEVEL) {
            lastVoiceAt = now;
        }

        if (!recorder || !wanted) {
            return;
        }

        const elapsed = now - segmentStartedAt;
        const pausing =
            meter.live &&
            segmentPeak >= VOICE_LEVEL &&
            now - lastVoiceAt >= silenceMs;

        if (elapsed >= maxSegmentMs || (elapsed >= minSegmentMs && pausing)) {
            cutSegment();
        }
    };

    const releaseRecorder = () => {
        if (ticker !== null) {
            clearInterval(ticker);
            ticker = null;
        }

        meter?.close();
        meter = null;
        releaseStream(stream);
        stream = null;
        level.value = 0;
    };

    /** Stop recording; the last segment is still sent. */
    const haltRecorder = () => {
        wanted = false;
        listening.value = false;
        cutSegment();
        releaseRecorder();
    };

    const startRecorder = async () => {
        if (!recorderClass()) {
            error.value = NO_RECORDER_MESSAGE;
            listening.value = false;
            wanted = false;

            return;
        }

        listening.value = true;
        let opened: MediaStream;

        try {
            opened = await openMicrophone();
        } catch (cause) {
            reportMicrophoneError(cause);
            listening.value = false;
            wanted = false;

            return;
        }

        if (!wanted) {
            // Stopped while Windows was asking for the microphone.
            releaseStream(opened);

            return;
        }

        stopTest();
        stream = opened;
        meter = openMeter(opened);

        try {
            beginSegment();
        } catch (cause) {
            console.warn('[Dictée] enregistrement impossible', cause);
            error.value = NO_RECORDER_MESSAGE;
            haltRecorder();

            return;
        }

        ticker = setInterval(tick, TICK_MS);
    };

    // ----- speech engine ----------------------------------------------

    let recognition: Recognition | null = null;
    let speechStartedAt = 0;
    let speechHeard = false;
    let quickEnds = 0;

    const switchToRecorder = () => {
        const broken = recognition;
        recognition = null;

        if (broken) {
            broken.onresult = null;
            broken.onerror = null;
            broken.onend = null;

            try {
                (broken.abort ?? broken.stop).call(broken);
            } catch {
                // Already stopped.
            }
        }

        engine.value = 'recorder';
        interim.value = '';

        if (wanted) {
            void startRecorder();
        }
    };

    const startSpeech = () => {
        const Klass = recognitionClass();

        if (!Klass) {
            return;
        }

        const current = new Klass();
        recognition = current;
        current.lang = lang;
        current.continuous = true;
        current.interimResults = true;
        current.onresult = (event) => {
            speechHeard = true;
            let pendingText = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const result = event.results[i];

                if (result.isFinal) {
                    append(result[0].transcript);
                } else {
                    pendingText += result[0].transcript;
                }
            }

            interim.value = pendingText;
        };
        current.onerror = (event) => {
            if (event.error !== 'no-speech') {
                console.warn(
                    '[Dictée] reconnaissance du navigateur :',
                    event.error,
                );
            }

            if (FALLBACK_ERRORS.includes(event.error) && recorderSupported) {
                switchToRecorder();

                return;
            }

            if (event.error === 'aborted' && !wanted) {
                return;
            }

            error.value = speechErrorMessage(event.error);
        };
        current.onend = () => {
            interim.value = '';

            // Browsers stop after a silence; keep going until the doctor stops.
            if (wanted && !error.value) {
                // A recognition that ends at once, again and again, without
                // hearing anything has no service behind it.
                quickEnds =
                    !speechHeard && Date.now() - speechStartedAt < 1500
                        ? quickEnds + 1
                        : 0;

                if (quickEnds >= 3) {
                    if (recorderSupported) {
                        switchToRecorder();

                        return;
                    }

                    error.value = INTERRUPTED_MESSAGE;
                } else {
                    restartSpeech(current);

                    return;
                }
            }

            listening.value = false;
        };

        wanted = true;
        listening.value = true;
        restartSpeech(current);
    };

    const restartSpeech = (current: Recognition) => {
        speechStartedAt = Date.now();
        speechHeard = false;

        try {
            current.start();
        } catch (cause) {
            console.warn('[Dictée] reconnaissance du navigateur :', cause);

            if (recorderSupported) {
                switchToRecorder();

                return;
            }

            error.value = INTERRUPTED_MESSAGE;
            listening.value = false;
            wanted = false;
        }
    };

    // ----- public API -------------------------------------------------

    const start = () => {
        if (!supported || listening.value) {
            return;
        }

        error.value = null;
        testResult.value = null;
        wanted = true;
        quickEnds = 0;

        if (engine.value === 'speech') {
            startSpeech();
        } else {
            void startRecorder();
        }
    };

    /**
     * Stop listening. Resolves once the last recorded segment is
     * transcribed, so the transcript is complete.
     */
    const stop = async (): Promise<void> => {
        wanted = false;

        if (recognition) {
            recognition.stop();
        }

        const lastSegment = recorder ? recorderStopped : Promise.resolve();
        cutSegment();
        listening.value = false;

        if (stream) {
            // onstop normally fires at once; never wait on it forever.
            await Promise.race([lastSegment, wait(3000)]);
            releaseRecorder();
        }

        await queue;
    };

    const clear = () => {
        transcript.value = '';
        interim.value = pending.value > 0 ? TRANSCRIBING_LABEL : '';
    };

    // ----- microphone check -------------------------------------------

    let testStream: MediaStream | null = null;
    let testMeter: Meter | null = null;
    let testTicker: ReturnType<typeof setInterval> | null = null;
    let testTimer: ReturnType<typeof setTimeout> | null = null;
    let testPeak = 0;

    function stopTest() {
        if (testTicker !== null) {
            clearInterval(testTicker);
            testTicker = null;
        }

        if (testTimer !== null) {
            clearTimeout(testTimer);
            testTimer = null;
        }

        testMeter?.close();
        testMeter = null;
        releaseStream(testStream);
        testStream = null;

        if (testing.value) {
            testing.value = false;

            if (!listening.value) {
                level.value = 0;
            }
        }
    }

    /**
     * Ask for the microphone and show its level for a few seconds, so the
     * doctor sees whether the sound is picked up before dictating.
     */
    const testMicrophone = async (): Promise<void> => {
        if (testing.value || listening.value) {
            return;
        }

        error.value = null;
        testResult.value = null;
        testing.value = true;
        testPeak = 0;
        let opened: MediaStream;

        try {
            opened = await openMicrophone();
        } catch (cause) {
            reportMicrophoneError(cause);
            testing.value = false;

            return;
        }

        if (!testing.value) {
            releaseStream(opened);

            return;
        }

        testStream = opened;
        testMeter = openMeter(opened);
        const measured = testMeter;
        testTicker = setInterval(() => {
            level.value = measured.read();
            testPeak = Math.max(testPeak, level.value);
        }, TICK_MS);
        testTimer = setTimeout(() => {
            const live = measured.live;
            stopTest();
            testResult.value =
                !live || testPeak >= VOICE_LEVEL ? 'ok' : 'silent';
        }, testMs);
    };

    onBeforeUnmount(() => {
        void stop();
        stopTest();
    });

    return {
        supported,
        engine,
        listening,
        transcript,
        interim,
        error,
        level,
        testing,
        testResult,
        transcribing,
        start,
        stop,
        clear,
        testMicrophone,
        stopTest,
    };
};
