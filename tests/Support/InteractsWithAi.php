<?php

namespace Tests\Support;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * A cabinet, a doctor and a consultation, plus a fake OpenAI-compatible
 * provider, for the AI tests.
 */
trait InteractsWithAi
{
    protected const AI_PROVIDER = 'https://ai.test/v1/chat/completions';

    protected function configureDirectAi(): void
    {
        config([
            'ai.base_url' => 'https://ai.test/v1',
            'ai.api_key' => 'test-key',
            'ai.model' => 'text-model',
            'ai.vision_model' => 'vision-model',
            'ai.ecg_model' => 'ecg-model',
        ]);
    }

    protected function makeCabinet(string $name = 'Cabinet IA', array $attributes = []): Cabinet
    {
        return Cabinet::query()->create([
            'name' => $name,
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
            ...$attributes,
        ]);
    }

    protected function makeDoctor(Cabinet $cabinet, RoleName $role = RoleName::DOCTOR): User
    {
        $user = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $patient
     * @return array{0: Cabinet, 1: User, 2: Consultation, 3: Patient}
     */
    protected function aiConsultation(array $patient = []): array
    {
        $cabinet = $this->makeCabinet();
        $doctor = $this->makeDoctor($cabinet);
        $this->actingAs($doctor);

        $record = Patient::factory()->create([
            'first_name' => 'Yasmine',
            'last_name' => 'Benali',
            'phone' => '0555123456',
            'email' => 'yasmine@example.test',
            'address' => '12 rue des Oliviers',
            ...$patient,
        ]);
        $consultation = Consultation::query()->create([
            'patient_id' => $record->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'motif' => 'Fièvre et toux depuis 3 jours',
        ]);

        return [$cabinet, $doctor, $consultation, $record];
    }

    /**
     * @param  array<mixed>|string  $content  A JSON payload, or the raw text the model replies.
     */
    protected function fakeAiReply(array|string $content, string $model = 'qwen-test'): void
    {
        Http::fake([
            self::AI_PROVIDER => Http::response([
                'model' => $model,
                'choices' => [['message' => ['content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE)]]],
                'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 120],
            ]),
        ]);
    }

    protected function fakeAiFailure(int $status = 500): void
    {
        Http::fake([self::AI_PROVIDER => Http::response(['error' => 'boom'], $status)]);
    }
}
