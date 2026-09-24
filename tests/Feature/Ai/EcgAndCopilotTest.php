<?php

namespace Tests\Feature\Ai;

use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\AiConversation;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Exam;
use App\Models\Patient;
use App\Models\User;
use App\Services\Ai\EcgSafetyRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The ECG tab (measure, AI reading checked against the measurements, chat,
 * doctor validation) and the consultation copilot.
 */
class EcgAndCopilotTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'https://ai.test/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake();
        config([
            'ai.base_url' => 'https://ai.test/v1',
            'ai.api_key' => 'test-key',
            'ai.ecg_model' => 'ecg-model',
        ]);
    }

    /**
     * @return array{0: Cabinet, 1: User, 2: Consultation}
     */
    private function consultation(): array
    {
        $cabinet = Cabinet::query()->create(['name' => 'Cabinet ECG', 'status' => CabinetStatus::ACTIVE, 'activated_at' => now()]);
        $doctor = User::factory()->create(['cabinet_id' => $cabinet->getKey(), 'approved_at' => now()]);
        $doctor->assignRole(RoleName::DOCTOR->value);
        $this->actingAs($doctor);

        $patient = Patient::factory()->create(['gender' => 'male', 'allergies' => 'Aspirine']);
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'motif' => 'Palpitations',
        ]);

        return [$cabinet, $doctor, $consultation];
    }

    private function uploadEcg(Consultation $consultation): int
    {
        return (int) $this->post(route('app.ecgs.store', $consultation), [
            'file' => UploadedFile::fake()->image('ecg.png', 1200, 400),
            'title' => 'ECG repos',
            'recorded_at' => '2026-09-24',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ecg.status', 'draft')
            ->json('ecg.id');
    }

    /**
     * @param  array<string, mixed>|string  $content
     */
    private function fakeProvider(array|string $content): void
    {
        Http::fake([
            self::PROVIDER => Http::response([
                'model' => 'ecg-model',
                'choices' => [['message' => ['content' => is_string($content) ? $content : json_encode($content)]]],
                'usage' => ['prompt_tokens' => 1500, 'completion_tokens' => 200],
            ]),
        ]);
    }

    /**
     * An atrial-fibrillation-like strip: fast and irregularly irregular.
     *
     * @return list<int>
     */
    private function irregularRr(): array
    {
        return [410, 480, 660, 590, 410, 740, 390, 520, 450, 630, 400, 560];
    }

    public function test_measurements_are_recomputed_on_the_server_and_flag_an_irregular_rhythm(): void
    {
        [, , $consultation] = $this->consultation();
        $id = $this->uploadEcg($consultation);

        $response = $this->putJson(route('app.ecgs.measurements', $id), [
            'source' => 'auto',
            'px_per_mm' => 7.8,
            'duration_s' => 6.8,
            'rr_ms' => $this->irregularRr(),
            // A client that lies about derived values is simply ignored.
            'heart_rate_bpm' => 60,
            'calipers' => [['label' => 'qt', 'ms' => 360], ['label' => 'QRS', 'ms' => 90]],
        ])->assertOk();

        $measurements = $response->json('ecg.measurements');
        $this->assertSame(13, $measurements['beat_count']);
        $this->assertSame(115, $measurements['heart_rate_bpm']);
        $this->assertGreaterThan(EcgSafetyRules::IRREGULAR_CV_PERCENT, $measurements['rr_cv_percent']);
        $this->assertSame(499, $measurements['qtc_ms']);

        $codes = array_column($response->json('ecg.flags'), 'code');
        $this->assertContains('rr_irregular', $codes);
        $this->assertContains('rate_high', $codes);
        $this->assertContains('qtc_long', $codes);
    }

    public function test_an_ai_reading_that_contradicts_the_measurements_is_flagged_critical(): void
    {
        [$cabinet, , $consultation] = $this->consultation();
        $id = $this->uploadEcg($consultation);
        $this->putJson(route('app.ecgs.measurements', $id), ['rr_ms' => $this->irregularRr()])->assertOk();

        // What general vision models actually answered on our AF test strip.
        $this->fakeProvider([
            'quality' => ['rating' => 'bonne', 'issues' => []],
            'rate_bpm' => 75,
            'regularity' => 'régulier',
            'rhythm' => 'Rythme sinusal',
            'p_waves' => 'Présentes',
            'primary_statement' => 'ECG normal',
            'urgency' => 'normal',
            'confidence' => 'élevée',
        ]);

        $response = $this->postJson(route('app.ai.ecgs.analysis', $id))
            ->assertOk()
            ->assertJsonPath('ecg.analysis.primary_statement', 'ECG normal')
            ->assertJsonPath('ecg.urgency', 'critique')
            ->assertJsonPath('balance', 496);

        $codes = array_column($response->json('ecg.flags'), 'code');
        $this->assertContains('conflict_rhythm', $codes);
        $this->assertContains('conflict_rate', $codes);
        $this->assertSame(496, $cabinet->fresh()->ai_credits);

        Http::assertSent(function (HttpRequest $request): bool {
            $prompt = json_encode($request['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $request['model'] === 'ecg-model'
                && str_contains($prompt, 'data:image/png;base64,')
                && str_contains($prompt, 'MESURES AUTOMATIQUES')
                && str_contains($prompt, 'irrégulier');
        });
    }

    public function test_the_doctor_can_chat_about_the_tracing_and_the_conversation_is_kept(): void
    {
        [, , $consultation] = $this->consultation();
        $id = $this->uploadEcg($consultation);
        $this->fakeProvider('Les intervalles RR varient de 390 à 740 ms, sans onde P nette : une FA est probable.');

        $this->postJson(route('app.ai.ecgs.chat', $id), ['message' => 'Est-ce une FA ?'])
            ->assertOk()
            ->assertJsonPath('ecg.conversation.0.content', 'Est-ce une FA ?')
            ->assertJsonPath('ecg.conversation.1.role', 'assistant')
            ->assertJsonPath('balance', 499);

        // Chat replies are prose, not JSON mode.
        Http::assertSent(fn (HttpRequest $request): bool => ! isset($request['response_format']));
    }

    public function test_validation_requires_a_conclusion_and_is_reopened_by_an_edit(): void
    {
        [, $doctor, $consultation] = $this->consultation();
        $id = $this->uploadEcg($consultation);

        $this->putJson(route('app.ecgs.conclusion', $id), ['validate' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_conclusion');

        $this->putJson(route('app.ecgs.conclusion', $id), [
            'doctor_conclusion' => 'Fibrillation atriale rapide.',
            'validate' => true,
        ])
            ->assertOk()
            ->assertJsonPath('ecg.status', 'validated')
            ->assertJsonPath('ecg.validated_by', $doctor->name);

        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => 'FA rapide, à anticoaguler.'])
            ->assertOk()
            ->assertJsonPath('ecg.status', 'draft');
    }

    public function test_ecg_files_are_images_only_and_stay_inside_the_cabinet(): void
    {
        [, , $consultation] = $this->consultation();

        $this->post(route('app.ecgs.store', $consultation), [
            'file' => UploadedFile::fake()->create('ecg.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $id = $this->uploadEcg($consultation);
        $this->get(route('app.ecgs.file', $id))->assertOk();

        $other = Cabinet::query()->create(['name' => 'Autre', 'status' => CabinetStatus::ACTIVE, 'activated_at' => now()]);
        $stranger = User::factory()->create(['cabinet_id' => $other->id, 'approved_at' => now()]);
        $stranger->assignRole(RoleName::DOCTOR->value);

        $this->actingAs($stranger)->get(route('app.ecgs.file', $id))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('app.ai.ecgs.analysis', $id))->assertNotFound();
    }

    public function test_the_validated_conclusion_reaches_later_ai_context(): void
    {
        [, , $consultation] = $this->consultation();
        $id = $this->uploadEcg($consultation);
        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => 'Fibrillation atriale.', 'validate' => true]);
        $this->fakeProvider(['reply' => 'OK', 'sources' => [], 'actions' => []]);

        $this->postJson(route('app.ai.consultations.copilot.store', $consultation), ['message' => 'Résume'])->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => str_contains(
            json_encode($request['messages'], JSON_UNESCAPED_UNICODE),
            'conclusion du médecin : Fibrillation atriale.',
        ));
    }

    public function test_the_copilot_proposes_catalogue_matched_actions_and_keeps_the_conversation(): void
    {
        [$cabinet, , $consultation] = $this->consultation();
        $tsh = Exam::query()->create(['name' => 'TSH', 'category' => 'labo', 'is_active' => true]);
        $this->fakeProvider([
            'reply' => 'Palpitations : cherchez une hyperthyroïdie. Attention, allergie à l’aspirine.',
            'sources' => ['Antécédents', 'Consultation actuelle'],
            'actions' => [
                ['type' => 'set_field', 'field' => 'diagnostic', 'text' => 'Palpitations à explorer', 'mode' => 'replace'],
                ['type' => 'add_exam', 'name' => 'tsh', 'reason' => 'Hyperthyroïdie ?'],
                ['type' => 'add_medication', 'medication' => 'Bisoprolol 2,5 mg', 'dosage' => '1 cp/j', 'duration' => '1 boîte', 'instructions' => 'Le matin'],
                ['type' => 'add_medication', 'medication' => 'Aspégic 100 mg', 'dosage' => '1 sachet/j', 'duration' => '1 boîte', 'instructions' => ''],
                ['type' => 'set_field', 'field' => 'password', 'text' => 'ignored'],
                ['type' => 'run_sql', 'text' => 'ignored'],
            ],
        ]);

        $response = $this->postJson(route('app.ai.consultations.copilot.store', $consultation), [
            'message' => 'Que faire pour ces palpitations ?',
            'draft' => ['motif' => 'Palpitations depuis 2 semaines'],
            'workspace' => ['exams' => ['NFS'], 'medications' => []],
        ])
            ->assertOk()
            ->assertJsonPath('message.sources.0', 'Antécédents')
            ->assertJsonPath('balance', 499);

        $actions = $response->json('message.actions');
        $this->assertCount(4, $actions);
        $this->assertSame(['type' => 'add_exam', 'name' => 'TSH', 'exam_id' => $tsh->id, 'reason' => 'Hyperthyroïdie ?'], $actions[1]);
        $this->assertFalse($actions[2]['in_catalogue']);
        // The cabinet's deterministic allergy check runs on every proposal.
        $this->assertSame([], $actions[2]['allergy_conflicts']);
        $this->assertNotEmpty($actions[3]['allergy_conflicts']);

        $this->getJson(route('app.ai.consultations.copilot.show', $consultation))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.content', 'Que faire pour ces palpitations ?');

        $this->assertSame(1, AiConversation::query()->count());
        $this->assertSame(499, $cabinet->fresh()->ai_credits);

        Http::assertSent(fn (HttpRequest $request): bool => str_contains(
            json_encode($request['messages'], JSON_UNESCAPED_UNICODE),
            'Bilan en préparation : NFS',
        ));
    }

    public function test_dictation_is_sent_to_the_visit_assistant(): void
    {
        [, , $consultation] = $this->consultation();
        $this->fakeProvider(['motif' => 'Palpitations', 'examens' => '', 'diagnostic' => '', 'traitement' => '', 'alerts' => []]);

        $this->postJson(route('app.ai.consultations.text', $consultation), [
            'transcript' => 'patient qui se plaint de palpitations depuis deux semaines',
        ])->assertOk()->assertJsonPath('fields.motif', 'Palpitations');

        Http::assertSent(fn (HttpRequest $request): bool => str_contains(
            json_encode($request['messages'], JSON_UNESCAPED_UNICODE),
            'DICTÉE DU MÉDECIN',
        ));
    }
}
