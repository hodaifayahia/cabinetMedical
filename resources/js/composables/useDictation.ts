// Voice dictation with the browser's speech recognition (Chrome, Edge).
// Explicitly request microphone permission first: WebView2 and some browser
// profiles otherwise leave SpeechRecognition silent until permission has been
// granted. The transcript is then structured into the visit fields by the AI.

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

const microphoneErrorMessage = (error: unknown): string => {
    const name =
        typeof error === 'object' && error !== null && 'name' in error
            ? String(error.name)
            : '';

    if (name === 'NotAllowedError' || name === 'SecurityError') {
        return 'Micro refusé : autorisez le micro pour Drclick dans Windows et dans les paramètres du site, puis réessayez.';
    }

    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return 'Aucun microphone détecté. Branchez un micro et vérifiez les paramètres audio de Windows.';
    }

    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return 'Le microphone est occupé par une autre application. Fermez-la puis réessayez.';
    }

    return 'Le microphone n’a pas pu démarrer. Vérifiez ses autorisations et réessayez.';
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
    const starting = ref(false);
    const transcript = ref('');
    const interim = ref('');
    const error = ref<string | null>(null);
    let recognition: Recognition | null = null;
    let wanted = false;

    const start = async () => {
        const Klass = recognitionClass();

        if (!Klass || listening.value || starting.value) {
            return;
        }

        error.value = null;
        wanted = true;
        starting.value = true;
        let permissionStream: MediaStream | null = null;

        try {
            const mediaDevices =
                typeof navigator === 'undefined'
                    ? undefined
                    : navigator.mediaDevices;

            if (mediaDevices?.getUserMedia) {
                permissionStream = await mediaDevices.getUserMedia({
                    audio: true,
                });
                permissionStream.getTracks().forEach((track) => track.stop());
                permissionStream = null;
            }

            if (!wanted) {
                return;
            }

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
                if (event.error === 'no-speech') {
                    error.value = null;

                    return;
                }

                error.value =
                    event.error === 'not-allowed' ||
                    event.error === 'service-not-allowed'
                        ? 'Micro refusé : autorisez le micro pour Drclick dans Windows et dans les paramètres du site.'
                        : event.error === 'network'
                          ? 'La dictée a besoin d’Internet.'
                          : event.error === 'audio-capture'
                            ? 'Aucun microphone disponible. Vérifiez le micro sélectionné dans Windows.'
                            : 'La dictée s’est interrompue. Vérifiez le microphone et réessayez.';
                wanted = false;
                listening.value = false;
            };
            recognition.onend = () => {
                interim.value = '';

                // Browsers stop after a silence; keep going until the doctor stops.
                if (wanted && !error.value) {
                    try {
                        recognition?.start();

                        return;
                    } catch {
                        error.value = microphoneErrorMessage(null);
                        wanted = false;
                    }
                }

                listening.value = false;
            };

            listening.value = true;
            recognition.start();
        } catch (caught) {
            wanted = false;
            listening.value = false;
            recognition = null;
            error.value = microphoneErrorMessage(caught);
        } finally {
            permissionStream?.getTracks().forEach((track) => track.stop());
            starting.value = false;
        }
    };

    const stop = () => {
        wanted = false;

        try {
            recognition?.stop();
        } catch {
            // The browser may already have ended the recognition session.
        }

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
        starting,
        transcript,
        interim,
        error,
        start,
        stop,
        clear,
    };
};
