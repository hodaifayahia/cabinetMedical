<?php

namespace Tests\Unit\Ai;

use App\Enums\AiFeature;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The billable AI actions: prices, labels, image rights and model routing.
 */
class AiFeatureTest extends TestCase
{
    public function test_the_feature_values_are_the_stable_wire_names(): void
    {
        // A local desktop names these when it relays; renaming one breaks
        // every desktop already installed.
        $this->assertSame([
            'consultation_text',
            'exam_suggestions',
            'prescription_suggestions',
            'document_analysis',
            'patient_analysis',
            'ecg_analysis',
            'ecg_chat',
            'copilot_chat',
            'dictation_transcription',
        ], array_map(static fn (AiFeature $feature): string => $feature->value, AiFeature::cases()));
    }

    public function test_costs_come_from_the_configured_price_list(): void
    {
        $this->assertSame([
            'consultation_text' => 1,
            'exam_suggestions' => 2,
            'prescription_suggestions' => 2,
            'document_analysis' => 3,
            'patient_analysis' => 5,
            'ecg_analysis' => 4,
            'ecg_chat' => 1,
            'copilot_chat' => 1,
            'dictation_transcription' => 0,
        ], AiFeature::costs());
    }

    public function test_a_feature_missing_from_the_price_list_costs_one_credit(): void
    {
        config(['ai.costs' => []]);

        foreach (AiFeature::cases() as $feature) {
            $this->assertSame(1, $feature->cost(), $feature->value);
        }
    }

    public function test_a_negative_price_is_clamped_to_free_instead_of_crediting_the_wallet(): void
    {
        config(['ai.costs.copilot_chat' => -5]);

        $this->assertSame(0, AiFeature::COPILOT_CHAT->cost());
    }

    public function test_a_price_can_be_set_to_zero(): void
    {
        config(['ai.costs.ecg_chat' => 0]);

        $this->assertSame(0, AiFeature::ECG_CHAT->cost());
    }

    public function test_a_price_given_as_a_string_is_read_as_an_integer(): void
    {
        config(['ai.costs.patient_analysis' => '7']);

        $this->assertSame(7, AiFeature::PATIENT_ANALYSIS->cost());
    }

    public function test_every_feature_has_a_non_empty_label_and_short_label(): void
    {
        foreach (AiFeature::cases() as $feature) {
            $this->assertNotSame('', trim($feature->label()), $feature->value);
            $this->assertNotSame('', trim($feature->shortLabel()), $feature->value);
            $this->assertLessThanOrEqual(mb_strlen($feature->label()), mb_strlen($feature->shortLabel()), $feature->value);
        }
    }

    public function test_labels_are_unique(): void
    {
        $labels = array_map(static fn (AiFeature $feature): string => $feature->label(), AiFeature::cases());

        $this->assertSame($labels, array_values(array_unique($labels)));
    }

    /**
     * @return array<string, array{0: AiFeature, 1: bool}>
     */
    public static function imageRights(): array
    {
        return [
            'consultation text' => [AiFeature::CONSULTATION_TEXT, false],
            'exam suggestions' => [AiFeature::EXAM_SUGGESTIONS, false],
            'prescription' => [AiFeature::PRESCRIPTION_SUGGESTIONS, false],
            'document analysis' => [AiFeature::DOCUMENT_ANALYSIS, true],
            'patient analysis' => [AiFeature::PATIENT_ANALYSIS, false],
            'ecg analysis' => [AiFeature::ECG_ANALYSIS, true],
            'ecg chat' => [AiFeature::ECG_CHAT, true],
            'copilot' => [AiFeature::COPILOT_CHAT, false],
            'dictation' => [AiFeature::DICTATION_TRANSCRIPTION, false],
        ];
    }

    #[DataProvider('imageRights')]
    public function test_only_the_image_reading_features_may_send_an_image(AiFeature $feature, bool $readsImages): void
    {
        $this->assertSame($readsImages, $feature->readsImages());
    }

    public function test_ecg_features_always_use_the_ecg_model(): void
    {
        config(['ai.model' => 'text', 'ai.vision_model' => 'vision', 'ai.ecg_model' => 'ecg']);

        $this->assertSame('ecg', AiFeature::ECG_ANALYSIS->model(true));
        $this->assertSame('ecg', AiFeature::ECG_ANALYSIS->model(false));
        $this->assertSame('ecg', AiFeature::ECG_CHAT->model(true));
        $this->assertSame('ecg', AiFeature::ECG_CHAT->model(false));
    }

    public function test_other_features_use_the_vision_model_only_for_an_image(): void
    {
        config(['ai.model' => 'text', 'ai.vision_model' => 'vision', 'ai.ecg_model' => 'ecg']);

        $this->assertSame('vision', AiFeature::DOCUMENT_ANALYSIS->model(true));
        $this->assertSame('text', AiFeature::DOCUMENT_ANALYSIS->model(false));
        $this->assertSame('text', AiFeature::COPILOT_CHAT->model(false));
        $this->assertSame('text', AiFeature::PATIENT_ANALYSIS->model(false));
    }

    public function test_only_dictation_transcribes_audio_with_the_transcription_model(): void
    {
        config(['ai.model' => 'text', 'ai.vision_model' => 'vision', 'ai.transcription_model' => 'asr']);

        foreach (AiFeature::cases() as $feature) {
            $this->assertSame($feature === AiFeature::DICTATION_TRANSCRIPTION, $feature->transcribesAudio(), $feature->value);
        }

        $this->assertSame('asr', AiFeature::DICTATION_TRANSCRIPTION->model(false));
    }

    public function test_ledger_labels_cover_every_feature_and_admin_adjustments(): void
    {
        $labels = AiFeature::ledgerLabels();

        foreach (AiFeature::cases() as $feature) {
            $this->assertSame($feature->label(), $labels[$feature->value]);
        }

        $this->assertSame('Recharge / ajustement', $labels['admin_adjustment']);
        $this->assertCount(count(AiFeature::cases()) + 1, $labels);
    }
}
