import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AiFeature, AiStatus } from './ai';
import {
    aiCost,
    aiState,
    consultationAiUrl,
    creditsLabel,
    formatAiText,
    loadAiStatus,
    runAi,
    transcribeDictation,
} from './ai';
import { aiWorkspace, bindAiWorkspace, takeQueued } from './aiWorkspace';
import { HttpError, getJson, postFormData, postJson } from './http';
import type * as Http from './http';

vi.mock('./http', async (importActual) => ({
    ...(await importActual<typeof Http>()),
    getJson: vi.fn(),
    postJson: vi.fn(),
    postFormData: vi.fn(),
}));

const getJsonMock = vi.mocked(getJson);
const postJsonMock = vi.mocked(postJson);

const status = (overrides: Partial<AiStatus> = {}): AiStatus => ({
    available: true,
    enabled: true,
    balance: 100,
    costs: {
        consultation_text: 1,
        exam_suggestions: 2,
        prescription_suggestions: 2,
        document_analysis: 3,
        patient_analysis: 5,
        ecg_analysis: 4,
        ecg_chat: 1,
        copilot_chat: 1,
        dictation_transcription: 0,
    },
    support: { phone: null, email: null },
    message: null,
    ...overrides,
});

beforeEach(() => {
    aiState.status = null;
    aiState.loading = false;
    getJsonMock.mockReset();
    postJsonMock.mockReset();
});

describe('loadAiStatus', () => {
    it('loads the status once and shares it', async () => {
        getJsonMock.mockResolvedValue(status({ balance: 42 }));

        await loadAiStatus();
        await loadAiStatus();

        expect(getJsonMock).toHaveBeenCalledTimes(1);
        expect(getJsonMock).toHaveBeenCalledWith('/app/ai/status');
        expect(aiState.status?.balance).toBe(42);
        expect(aiState.loading).toBe(false);
    });

    it('shares one request between panels asking at the same time', async () => {
        let resolve!: (value: AiStatus) => void;
        getJsonMock.mockReturnValue(
            new Promise<AiStatus>((r) => {
                resolve = r;
            }),
        );

        const first = loadAiStatus();
        const second = loadAiStatus();
        expect(aiState.loading).toBe(true);

        resolve(status());
        await Promise.all([first, second]);

        expect(getJsonMock).toHaveBeenCalledTimes(1);
        expect(aiState.loading).toBe(false);
    });

    it('reloads when forced', async () => {
        getJsonMock
            .mockResolvedValueOnce(status({ balance: 10 }))
            .mockResolvedValueOnce(status({ balance: 9 }));

        await loadAiStatus();
        await loadAiStatus(true);

        expect(getJsonMock).toHaveBeenCalledTimes(2);
        expect(aiState.status?.balance).toBe(9);
    });

    it('never rejects: a failed status leaves the buttons in a neutral state', async () => {
        getJsonMock.mockRejectedValue(new HttpError(500, 'boom'));

        await expect(loadAiStatus()).resolves.toBeUndefined();

        expect(aiState.status).toBeNull();
        expect(aiState.loading).toBe(false);
    });

    it('can retry after a failure', async () => {
        getJsonMock
            .mockRejectedValueOnce(new TypeError('offline'))
            .mockResolvedValueOnce(status());

        await loadAiStatus();
        await loadAiStatus();

        expect(aiState.status).not.toBeNull();
    });
});

describe('aiCost and creditsLabel', () => {
    it('uses the default price list before the status is known', () => {
        const expected: Record<AiFeature, number> = {
            consultation_text: 1,
            exam_suggestions: 2,
            prescription_suggestions: 2,
            document_analysis: 3,
            patient_analysis: 5,
            ecg_analysis: 4,
            ecg_chat: 1,
            copilot_chat: 1,
            dictation_transcription: 0,
        };

        for (const [feature, cost] of Object.entries(expected)) {
            expect(aiCost(feature as AiFeature)).toBe(cost);
        }
    });

    it('uses the server price once known', () => {
        aiState.status = status();
        aiState.status.costs.patient_analysis = 8;

        expect(aiCost('patient_analysis')).toBe(8);
    });

    it('falls back to the default for a price the server did not send', () => {
        aiState.status = status({ costs: {} as AiStatus['costs'] });

        expect(aiCost('ecg_analysis')).toBe(4);
    });

    it('labels credits in French with the right plural', () => {
        expect(creditsLabel(0)).toBe('0 crédit');
        expect(creditsLabel(1)).toBe('1 crédit');
        expect(creditsLabel(2)).toBe('2 crédits');
        expect(creditsLabel(500)).toBe('500 crédits');
    });
});

describe('runAi', () => {
    it('posts the body and returns the payload', async () => {
        postJsonMock.mockResolvedValue({ fields: { motif: 'Toux' } });

        const result = await runAi<{ fields: { motif: string } }>('/x', {
            draft: { motif: 'a' },
        });

        expect(postJsonMock).toHaveBeenCalledWith('/x', {
            draft: { motif: 'a' },
        });
        expect(result.fields.motif).toBe('Toux');
    });

    it('posts an empty object by default', async () => {
        postJsonMock.mockResolvedValue({});

        await runAi('/x');

        expect(postJsonMock).toHaveBeenCalledWith('/x', {});
    });

    it('updates the shared balance after a spend', async () => {
        aiState.status = status({ balance: 100 });
        postJsonMock.mockResolvedValue({ balance: 95 });

        await runAi('/x');

        expect(aiState.status.balance).toBe(95);
    });

    it('ignores a missing or non-numeric balance', async () => {
        aiState.status = status({ balance: 100 });

        postJsonMock.mockResolvedValueOnce({ balance: null });
        await runAi('/x');
        postJsonMock.mockResolvedValueOnce(null);
        await runAi('/x');
        postJsonMock.mockResolvedValueOnce({ balance: '12' });
        await runAi('/x');

        expect(aiState.status.balance).toBe(100);
    });

    it('does not invent a status from a balance', async () => {
        postJsonMock.mockResolvedValue({ balance: 5 });

        await runAi('/x');

        expect(aiState.status).toBeNull();
    });

    it.each([
        [402, 'insufficient_credits'],
        [403, 'disabled'],
        [503, 'unavailable'],
        [500, 'provider_error'],
        [502, 'provider_error'],
        [404, 'provider_error'],
    ])('maps HTTP %i to %s with the server message', async (code, reason) => {
        getJsonMock.mockResolvedValue(status());
        postJsonMock.mockRejectedValue(new HttpError(code, 'Message serveur'));

        await expect(runAi('/x')).rejects.toEqual({
            reason,
            message: 'Message serveur',
        });
    });

    it('refreshes the status after a wallet refusal', async () => {
        getJsonMock.mockResolvedValue(status({ balance: 0 }));
        postJsonMock.mockRejectedValue(new HttpError(402, 'Plus de crédits'));

        await expect(runAi('/x')).rejects.toMatchObject({
            reason: 'insufficient_credits',
        });
        await vi.waitFor(() => expect(getJsonMock).toHaveBeenCalledTimes(1));
    });

    it('does not refresh the status after a provider error', async () => {
        postJsonMock.mockRejectedValue(new HttpError(502, 'x'));

        await expect(runAi('/x')).rejects.toBeTruthy();

        expect(getJsonMock).not.toHaveBeenCalled();
    });

    it('explains a rate limit in plain words', async () => {
        postJsonMock.mockRejectedValue(
            new HttpError(429, 'Too Many Attempts.'),
        );

        await expect(runAi('/x')).rejects.toEqual({
            reason: 'unavailable',
            message: 'Trop de demandes en peu de temps. Patientez une minute.',
        });
    });

    it('turns a validation error into an unsupported failure', async () => {
        postJsonMock.mockRejectedValue({
            validation: true,
            errors: { message: ['Trop long'] },
            message: 'Le message est trop long.',
        });

        await expect(runAi('/x')).rejects.toEqual({
            reason: 'unsupported',
            message: 'Le message est trop long.',
        });
    });

    it('gives a default message for a validation error without one', async () => {
        postJsonMock.mockRejectedValue({ validation: true, errors: {} });

        await expect(runAi('/x')).rejects.toEqual({
            reason: 'unsupported',
            message:
                'Cette demande ne peut pas être traitée par l’assistant IA.',
        });
    });

    it.each([
        ['a network failure', new TypeError('Failed to fetch')],
        ['a JSON parse error', new SyntaxError('Unexpected token')],
        ['a thrown string', 'oops'],
        ['undefined', undefined],
    ])('turns %s into a readable unknown failure', async (_, error) => {
        postJsonMock.mockRejectedValue(error);

        await expect(runAi('/x')).rejects.toEqual({
            reason: 'unknown',
            message:
                'L’assistant IA n’a pas pu répondre. Vérifiez la connexion puis réessayez.',
        });
    });
});

describe('transcribeDictation', () => {
    const postFormDataMock = vi.mocked(postFormData);

    it('uploads the segment with a matching file name and returns the text', async () => {
        postFormDataMock.mockResolvedValueOnce({ text: 'Toux sèche' });

        const text = await transcribeDictation(
            9,
            new Blob(['x'], { type: 'audio/ogg;codecs=opus' }),
        );

        expect(text).toBe('Toux sèche');
        const [url, body] = postFormDataMock.mock.calls.at(-1)!;
        expect(url).toBe('/app/ai/consultations/9/dictation/transcribe');
        expect((body.get('audio') as File).name).toBe('dictee.ogg');
    });

    it('defaults to a WebM file name and an empty text', async () => {
        postFormDataMock.mockResolvedValueOnce({});

        const text = await transcribeDictation(9, new Blob(['x']));

        expect(text).toBe('');
        const [, body] = postFormDataMock.mock.calls.at(-1)!;
        expect((body.get('audio') as File).name).toBe('dictee.webm');
    });

    it('rejects with a readable failure', async () => {
        postFormDataMock.mockRejectedValueOnce(
            new HttpError(503, 'Pas d’Internet'),
        );

        await expect(
            transcribeDictation(9, new Blob(['x'], { type: 'audio/webm' })),
        ).rejects.toEqual({ reason: 'unavailable', message: 'Pas d’Internet' });
    });
});

describe('consultationAiUrl', () => {
    it('builds the consultation action URLs', () => {
        expect(consultationAiUrl(12, 'consultation-text')).toBe(
            '/app/ai/consultations/12/consultation-text',
        );
        expect(consultationAiUrl(12, 'exams')).toBe(
            '/app/ai/consultations/12/exams',
        );
        expect(consultationAiUrl(7, 'prescription')).toBe(
            '/app/ai/consultations/7/prescription',
        );
    });
});

describe('formatAiText', () => {
    it('escapes every HTML special character', () => {
        expect(formatAiText(`<a href="x" onclick='y'>&</a>`)).toBe(
            '&lt;a href=&quot;x&quot; onclick=&#39;y&#39;&gt;&amp;&lt;/a&gt;',
        );
    });

    it('renders several bold runs on a line', () => {
        expect(formatAiText('**FC** 72/min, **QTc** 450 ms')).toBe(
            '<strong>FC</strong> 72/min, <strong>QTc</strong> 450 ms',
        );
    });

    it('cannot smuggle markup through bold', () => {
        expect(formatAiText('**<script>alert(1)</script>**')).toBe(
            '<strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong>',
        );
    });

    it('leaves unmatched or empty markers alone', () => {
        expect(formatAiText('**seul')).toBe('**seul');
        expect(formatAiText('****')).toBe('****');
    });

    it('keeps plain text, accents, Arabic and new lines', () => {
        const text = 'Fièvre à 39 °C\nخذ الدواء بعد الأكل';

        expect(formatAiText(text)).toBe(text);
        expect(formatAiText('')).toBe('');
    });

    it('does not double-escape entities written by the model', () => {
        expect(formatAiText('&amp;')).toBe('&amp;amp;');
    });
});

describe('aiWorkspace', () => {
    beforeEach(() => {
        aiWorkspace.consultationId = null;
        bindAiWorkspace(1);
    });

    it('starts empty for a consultation', () => {
        expect(aiWorkspace.consultationId).toBe(1);
        expect(aiWorkspace.bilanExams).toEqual([]);
        expect(aiWorkspace.ordonnanceItems).toEqual([]);
        expect(aiWorkspace.queuedExams).toEqual([]);
        expect(aiWorkspace.queuedMedications).toEqual([]);
        expect(aiWorkspace.queuedAdvice).toEqual([]);
    });

    it('keeps its state when the same consultation binds again', () => {
        aiWorkspace.bilanExams.push('NFS');
        aiWorkspace.queuedAdvice.push('Boire');

        bindAiWorkspace(1);

        expect(aiWorkspace.bilanExams).toEqual(['NFS']);
        expect(aiWorkspace.queuedAdvice).toEqual(['Boire']);
    });

    it('forgets everything when another consultation opens', () => {
        aiWorkspace.bilanExams.push('NFS');
        aiWorkspace.ordonnanceItems.push('Doliprane');
        aiWorkspace.queuedExams.push({ exam_id: 3, name: 'CRP' });
        aiWorkspace.queuedMedications.push({
            medication: 'Doliprane',
            dosage: '1 g',
            duration: '3 j',
            instructions: '',
        });
        aiWorkspace.queuedAdvice.push('Repos');

        bindAiWorkspace(2);

        expect(aiWorkspace.consultationId).toBe(2);
        expect(aiWorkspace.bilanExams).toEqual([]);
        expect(aiWorkspace.ordonnanceItems).toEqual([]);
        expect(aiWorkspace.queuedExams).toEqual([]);
        expect(aiWorkspace.queuedMedications).toEqual([]);
        expect(aiWorkspace.queuedAdvice).toEqual([]);
    });

    it('hands queued items over once and clears the queue', () => {
        aiWorkspace.queuedExams.push(
            { exam_id: 3, name: 'CRP' },
            { exam_id: null, name: 'TSH' },
        );

        const taken = takeQueued('queuedExams');

        expect(taken).toEqual([
            { exam_id: 3, name: 'CRP' },
            { exam_id: null, name: 'TSH' },
        ]);
        expect(aiWorkspace.queuedExams).toEqual([]);
        expect(takeQueued('queuedExams')).toEqual([]);
    });

    it('returns a copy that later queueing does not change', () => {
        aiWorkspace.queuedAdvice.push('Boire');
        const taken = takeQueued('queuedAdvice');

        aiWorkspace.queuedAdvice.push('Dormir');

        expect(taken).toEqual(['Boire']);
    });

    it('takes only the requested queue', () => {
        aiWorkspace.queuedAdvice.push('Boire');
        aiWorkspace.queuedExams.push({ exam_id: 1, name: 'NFS' });

        takeQueued('queuedAdvice');

        expect(aiWorkspace.queuedExams).toHaveLength(1);
    });
});
