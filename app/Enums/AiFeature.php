<?php

namespace App\Enums;

/**
 * Every billable AI action. The value is what the ledger records and what a
 * local desktop names when it relays a request to the hosted service.
 */
enum AiFeature: string
{
    case CONSULTATION_TEXT = 'consultation_text';
    case EXAM_SUGGESTIONS = 'exam_suggestions';
    case PRESCRIPTION_SUGGESTIONS = 'prescription_suggestions';
    case DOCUMENT_ANALYSIS = 'document_analysis';
    case PATIENT_ANALYSIS = 'patient_analysis';
    case ECG_ANALYSIS = 'ecg_analysis';
    case ECG_CHAT = 'ecg_chat';
    case COPILOT_CHAT = 'copilot_chat';

    public function cost(): int
    {
        return max(0, (int) config('ai.costs.'.$this->value, 1));
    }

    public function label(): string
    {
        return match ($this) {
            self::CONSULTATION_TEXT => 'Rédaction de la consultation',
            self::EXAM_SUGGESTIONS => 'Suggestion d’examens',
            self::PRESCRIPTION_SUGGESTIONS => 'Suggestion d’ordonnance',
            self::DOCUMENT_ANALYSIS => 'Analyse de document',
            self::PATIENT_ANALYSIS => 'Analyse du patient',
            self::ECG_ANALYSIS => 'Lecture d’ECG',
            self::ECG_CHAT => 'Question sur un ECG',
            self::COPILOT_CHAT => 'Copilote',
        };
    }

    /**
     * A chart axis label: short enough to sit beside a bar in a narrow card.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::CONSULTATION_TEXT => 'Consultation',
            self::EXAM_SUGGESTIONS => 'Examens',
            self::PRESCRIPTION_SUGGESTIONS => 'Ordonnance',
            self::DOCUMENT_ANALYSIS => 'Document',
            self::PATIENT_ANALYSIS => 'Analyse patient',
            self::ECG_ANALYSIS => 'Lecture ECG',
            self::ECG_CHAT => 'Question ECG',
            self::COPILOT_CHAT => 'Copilote',
        };
    }

    /**
     * Whether the action reads an image: an ECG tracing or a photographed
     * document. No other action may send one to the vision model.
     */
    public function readsImages(): bool
    {
        return in_array($this, [self::DOCUMENT_ANALYSIS, self::ECG_ANALYSIS, self::ECG_CHAT], true);
    }

    /**
     * The model is chosen where the key lives (here, or on the hosted relay),
     * so a desktop can never pick a model on its own.
     */
    public function model(bool $vision): string
    {
        return match (true) {
            $this === self::ECG_ANALYSIS, $this === self::ECG_CHAT => (string) config('ai.ecg_model'),
            $vision => (string) config('ai.vision_model'),
            default => (string) config('ai.model'),
        };
    }

    /**
     * @return array<string, int>
     */
    public static function costs(): array
    {
        $costs = [];

        foreach (self::cases() as $feature) {
            $costs[$feature->value] = $feature->cost();
        }

        return $costs;
    }

    /**
     * Labels for every ledger line, including the admin's manual adjustments.
     *
     * @return array<string, string>
     */
    public static function ledgerLabels(): array
    {
        $labels = [];

        foreach (self::cases() as $feature) {
            $labels[$feature->value] = $feature->label();
        }

        return $labels + ['admin_adjustment' => 'Recharge / ajustement'];
    }
}
