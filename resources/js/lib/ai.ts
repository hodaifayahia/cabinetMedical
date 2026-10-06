// Clinical AI assistant: API calls and the cabinet's credit balance, shared by
// every "✨ IA" button so a spend in one panel updates the counter everywhere.

import { reactive } from 'vue';
import {
    getJson,
    isHttpError,
    isValidationError,
    postFormData,
    postJson,
} from '@/lib/http';

export type AiFeature =
    | 'consultation_text'
    | 'exam_suggestions'
    | 'prescription_suggestions'
    | 'document_analysis'
    | 'patient_analysis'
    | 'ecg_analysis'
    | 'ecg_chat'
    | 'copilot_chat'
    | 'dictation_transcription';

export type AiStatus = {
    available: boolean;
    enabled: boolean;
    balance: number | null;
    costs: Record<AiFeature, number>;
    support: { phone: string | null; email: string | null };
    message: string | null;
};

export type AiFailure = {
    message: string;
    reason:
        | 'insufficient_credits'
        | 'disabled'
        | 'unavailable'
        | 'provider_error'
        | 'unsupported'
        | 'unknown';
};

export type ConsultationDraft = Partial<
    Record<
        | 'motif'
        | 'examens'
        | 'diagnostic'
        | 'traitement'
        | 'notes'
        | 'weight_kg'
        | 'height_cm'
        | 'temperature_c'
        | 'blood_pressure',
        string | number | null
    >
>;

export type ConsultationSuggestion = {
    fields: Record<'motif' | 'examens' | 'diagnostic' | 'traitement', string>;
    alerts: string[];
};

export type ExamSuggestion = {
    name: string;
    exam_id: number | null;
    reason: string;
    priority: 'urgent' | 'recommandé' | 'optionnel';
};

export type PrescriptionSuggestion = {
    medication: string;
    in_catalogue: boolean;
    dosage: string;
    duration: string;
    instructions: string;
    reason: string;
    /** Found by the cabinet's deterministic allergy check. */
    allergy_conflicts?: string[];
};

export type DocumentAnalysis = {
    id: number;
    document_id: number | null;
    created_at: string | null;
    content: {
        document_type: string;
        summary: string;
        findings: {
            label: string;
            value: string;
            status: 'normal' | 'anormal' | 'à surveiller';
        }[];
        recommendations: string[];
    };
};

export type PatientAnalysis = {
    id: number;
    created_at: string | null;
    content: {
        summary: string;
        problems: string[];
        risks: {
            label: string;
            level: 'élevé' | 'modéré' | 'faible';
            reason: string;
        }[];
        follow_up: string[];
        suggested_exams: string[];
        treatment_notes: string[];
        alerts: string[];
    };
};

const defaultCosts: Record<AiFeature, number> = {
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

export const aiState = reactive<{
    status: AiStatus | null;
    loading: boolean;
}>({
    status: null,
    loading: false,
});

let statusRequest: Promise<void> | null = null;

export const loadAiStatus = (force = false): Promise<void> => {
    if (!force && (aiState.status !== null || statusRequest !== null)) {
        return statusRequest ?? Promise.resolve();
    }

    aiState.loading = true;
    statusRequest = getJson<AiStatus>('/app/ai/status')
        .then((status) => {
            aiState.status = status;
        })
        .catch(() => {
            aiState.status = null;
        })
        .finally(() => {
            aiState.loading = false;
            statusRequest = null;
        });

    return statusRequest;
};

export const aiCost = (feature: AiFeature): number =>
    aiState.status?.costs?.[feature] ?? defaultCosts[feature];

export const creditsLabel = (count: number): string =>
    `${count} crédit${count > 1 ? 's' : ''}`;

const rememberBalance = (balance: unknown) => {
    if (typeof balance === 'number' && aiState.status) {
        aiState.status.balance = balance;
    }
};

/**
 * POST an AI action. Resolves with the payload, or rejects with an AiFailure
 * whose message is written for the doctor.
 */
export const runAi = async <T>(url: string, body: unknown = {}): Promise<T> => {
    try {
        const result = await postJson<T & { balance?: number | null }>(
            url,
            body,
        );
        rememberBalance(result?.balance);

        return result;
    } catch (error) {
        throw toFailure(error);
    }
};

const toFailure = (error: unknown): AiFailure => {
    if (isValidationError(error)) {
        return {
            reason: 'unsupported',
            message:
                error.message ??
                'Cette demande ne peut pas être traitée par l’assistant IA.',
        };
    }

    if (isHttpError(error)) {
        const reason: AiFailure['reason'] =
            error.status === 402
                ? 'insufficient_credits'
                : error.status === 403
                  ? 'disabled'
                  : error.status === 503
                    ? 'unavailable'
                    : error.status === 429
                      ? 'unavailable'
                      : 'provider_error';

        if (reason === 'insufficient_credits' || reason === 'disabled') {
            void loadAiStatus(true);
        }

        return {
            reason,
            message:
                error.status === 429
                    ? 'Trop de demandes en peu de temps. Patientez une minute.'
                    : error.message,
        };
    }

    return {
        reason: 'unknown',
        message:
            'L’assistant IA n’a pas pu répondre. Vérifiez la connexion puis réessayez.',
    };
};

/**
 * Turn one recorded dictation segment into text (desktop app, whose web view
 * has no working speech recognition). Rejects with an AiFailure.
 */
export const transcribeDictation = async (
    consultationId: number,
    audio: Blob,
): Promise<string> => {
    const type = audio.type || 'audio/webm';
    const extension = type.includes('ogg')
        ? 'ogg'
        : type.includes('mp4')
          ? 'm4a'
          : type.includes('mpeg')
            ? 'mp3'
            : type.includes('wav')
              ? 'wav'
              : 'webm';
    const body = new FormData();
    body.append('audio', audio, `dictee.${extension}`);

    try {
        const result = await postFormData<{
            text?: unknown;
            balance?: number | null;
        }>(
            `/app/ai/consultations/${consultationId}/dictation/transcribe`,
            body,
        );
        rememberBalance(result?.balance);

        return typeof result?.text === 'string' ? result.text : '';
    } catch (error) {
        throw toFailure(error);
    }
};

export const consultationAiUrl = (
    consultationId: number,
    action: 'consultation-text' | 'exams' | 'prescription',
): string => `/app/ai/consultations/${consultationId}/${action}`;

export type EcgFlag = {
    level: 'info' | 'warning' | 'critical';
    code: string;
    message: string;
};

export type EcgMeasurements = {
    source: 'auto' | 'manual';
    paper_speed_mm_s: number;
    px_per_mm: number | null;
    duration_s: number | null;
    beat_count: number;
    rr_ms: number[];
    heart_rate_bpm: number | null;
    rr_mean_ms: number | null;
    rr_min_ms: number | null;
    rr_max_ms: number | null;
    rr_cv_percent: number | null;
    calipers: { label: string; ms: number }[];
    qtc_ms: number | null;
};

export type EcgAnalysis = {
    quality: { rating: 'bonne' | 'moyenne' | 'mauvaise'; issues: string[] };
    leads_visible: string;
    rate_bpm: number | null;
    regularity: 'régulier' | 'irrégulier' | 'indéterminé';
    rhythm: string;
    p_waves: string;
    pr_ms: number | null;
    qrs_ms: number | null;
    qt_ms: number | null;
    axis: string;
    st_t: string;
    other_findings: string[];
    primary_statement: string;
    secondary_statements: string[];
    urgency: 'normal' | 'anormal' | 'critique';
    confidence: 'faible' | 'moyenne' | 'élevée';
    recommendations: string[];
    analyzed_at: string;
};

export type EcgRecord = {
    id: number;
    title: string;
    recorded_at: string | null;
    consultation_id: number | null;
    file_url: string;
    original_filename: string | null;
    measurements: EcgMeasurements | null;
    analysis: EcgAnalysis | null;
    flags: EcgFlag[];
    urgency: 'normal' | 'anormal' | 'critique' | null;
    conversation: {
        role: 'user' | 'assistant';
        content: string;
        at?: string;
        by?: string;
    }[];
    doctor_conclusion: string | null;
    status: 'draft' | 'validated';
    validated_at: string | null;
    validated_by: string | null;
    created_at: string | null;
};

export type CopilotAction =
    | {
          type: 'set_field';
          field: 'motif' | 'examens' | 'diagnostic' | 'traitement' | 'notes';
          text: string;
          mode: 'replace' | 'append';
      }
    | { type: 'add_exam'; name: string; exam_id: number | null; reason: string }
    | {
          type: 'add_medication';
          medication: string;
          in_catalogue: boolean;
          dosage: string;
          duration: string;
          instructions: string;
          reason: string;
          allergy_conflicts?: string[];
      }
    | { type: 'patient_advice'; text: string };

export type CopilotMessage =
    | { role: 'user'; content: string; at?: string; by?: string }
    | {
          role: 'assistant';
          reply: string;
          sources: string[];
          actions: CopilotAction[];
          at?: string;
      };

/**
 * AI replies use light markdown (**bold**). Escape everything first, then
 * allow only bold, so a reply can never inject markup into the page.
 */
export const formatAiText = (text: string): string =>
    text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
