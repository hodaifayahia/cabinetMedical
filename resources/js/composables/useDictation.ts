// Voice dictation with the browser's speech recognition (Chrome, Edge).
// The doctor speaks the visit; the transcript is then structured into the
// visit fields by the AI. Where the browser has no recognition (some desktop
// web views), `supported` is false and the screen offers typing instead.

import { onBeforeUnmount, ref } from 'vue';

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
    onresult: ((event: RecognitionEvent) => void) | null;
    onerror: ((event: { error: string }) => void) | null;
    onend: (() => void) | null;
};

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

export const useDictation = (lang = 'fr-FR') => {
    const supported = recognitionClass() !== null;
    const listening = ref(false);
    const transcript = ref('');
    const interim = ref('');
    const error = ref<string | null>(null);
    let recognition: Recognition | null = null;
    let wanted = false;

    const start = () => {
        const Klass = recognitionClass();

        if (!Klass || listening.value) {
            return;
        }

        error.value = null;
        recognition = new Klass();
        recognition.lang = lang;
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.onresult = (event) => {
            let pending = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const result = event.results[i];

                if (result.isFinal) {
                    transcript.value =
                        `${transcript.value} ${result[0].transcript}`.trim();
                } else {
                    pending += result[0].transcript;
                }
            }

            interim.value = pending;
        };
        recognition.onerror = (event) => {
            error.value =
                event.error === 'not-allowed'
                    ? 'Micro refusé : autorisez le micro pour ce site.'
                    : event.error === 'network'
                      ? 'La dictée a besoin d’Internet.'
                      : event.error === 'no-speech'
                        ? null
                        : 'La dictée s’est interrompue.';
        };
        recognition.onend = () => {
            interim.value = '';

            // Browsers stop after a silence; keep going until the doctor stops.
            if (wanted && !error.value) {
                recognition?.start();

                return;
            }

            listening.value = false;
        };

        wanted = true;
        listening.value = true;
        recognition.start();
    };

    const stop = () => {
        wanted = false;
        recognition?.stop();
        listening.value = false;
    };

    const clear = () => {
        transcript.value = '';
        interim.value = '';
    };

    onBeforeUnmount(stop);

    return {
        supported,
        listening,
        transcript,
        interim,
        error,
        start,
        stop,
        clear,
    };
};
