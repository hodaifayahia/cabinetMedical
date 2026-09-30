<?php

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\AiInsight;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Exam;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use App\Services\Ai\AiCreditLedger;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * The clinical AI assistant and its per-cabinet credit wallet.
 */
class ClinicalAiTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'https://ai.test/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'ai.base_url' => 'https://ai.test/v1',
            'ai.api_key' => 'test-key',
        ]);
    }

    /**
     * @return array{0: Cabinet, 1: User, 2: Consultation}
     */
    private function consultationForDoctor(): array
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet IA',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        $doctor = User::factory()->create([
            'cabinet_id' => $cabinet->getKey(),
            'approved_at' => now(),
        ]);
        $doctor->assignRole(RoleName::DOCTOR->value);

        $this->actingAs($doctor);

        $patient = Patient::factory()->create([
            'first_name' => 'Yasmine',
            'last_name' => 'Benali',
            'phone' => '0555123456',
            'allergies' => 'Pénicilline',
        ]);
        $consultation = Consultation::query()->create([
            'patient_id' => $patient->getKey(),
            'consulted_at' => now(),
            'status' => 'in_progress',
            'motif' => 'Fièvre et toux depuis 3 jours',
        ]);

        return [$cabinet, $doctor, $consultation];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function fakeProvider(array $json): void
    {
        Http::fake([
            self::PROVIDER => Http::response([
                'model' => 'qwen-test',
                'choices' => [['message' => ['content' => json_encode($json)]]],
                'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 120],
            ]),
        ]);
    }

    public function test_consultation_suggestion_charges_one_credit_and_keeps_identity_private(): void
    {
        [$cabinet, $doctor, $consultation] = $this->consultationForDoctor();
        $this->fakeProvider([
            'motif' => 'Fièvre et toux évoluant depuis 3 jours.',
            'examens' => 'Auscultation : [à compléter]',
            'diagnostic' => 'Bronchite aiguë probable',
            'traitement' => 'Paracétamol 1 g x 3/j',
            'alerts' => ['Allergie à la pénicilline'],
        ]);

        $this->postJson(route('app.ai.consultations.text', $consultation), [
            'draft' => ['diagnostic' => 'bronchite ?'],
        ])
            ->assertOk()
            ->assertJsonPath('fields.diagnostic', 'Bronchite aiguë probable')
            ->assertJsonPath('alerts.0', 'Allergie à la pénicilline')
            ->assertJsonPath('balance', 499);

        $this->assertSame(499, $cabinet->fresh()->ai_credits);
        $usage = AiUsage::query()->sole();
        $this->assertSame(-1, $usage->credits);
        $this->assertSame($doctor->id, $usage->user_id);
        $this->assertSame(800, $usage->prompt_tokens);

        Http::assertSent(function (HttpRequest $request): bool {
            $prompt = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && str_contains($prompt, 'Pénicilline')
                // The unsaved draft wins over the stored value.
                && str_contains($prompt, 'bronchite ?')
                && ! str_contains($prompt, 'Benali')
                && ! str_contains($prompt, '0555123456');
        });
    }

    public function test_an_empty_wallet_is_refused_without_calling_the_provider(): void
    {
        [$cabinet, , $consultation] = $this->consultationForDoctor();
        Cabinet::query()->whereKey($cabinet->id)->update(['ai_credits' => 0]);
        Http::fake();

        $this->postJson(route('app.ai.consultations.text', $consultation))
            ->assertStatus(402)
            ->assertJsonPath('reason', 'insufficient_credits')
            ->assertJsonPath('balance', 0);

        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_a_failed_provider_call_gives_the_credits_back(): void
    {
        [$cabinet, , $consultation] = $this->consultationForDoctor();
        Http::fake([self::PROVIDER => Http::response(['error' => 'boom'], 500)]);

        $this->postJson(route('app.ai.consultations.exams', $consultation))
            ->assertStatus(502)
            ->assertJsonPath('reason', 'provider_error');

        $this->assertSame(500, $cabinet->fresh()->ai_credits);
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_a_disabled_cabinet_cannot_use_the_assistant(): void
    {
        [$cabinet, , $consultation] = $this->consultationForDoctor();
        Cabinet::query()->whereKey($cabinet->id)->update(['ai_enabled' => false]);
        Http::fake();

        $this->postJson(route('app.ai.consultations.text', $consultation))
            ->assertForbidden()
            ->assertJsonPath('reason', 'disabled');

        Http::assertNothingSent();
    }

    public function test_suggested_exams_are_matched_to_the_cabinet_catalogue(): void
    {
        [$cabinet, , $consultation] = $this->consultationForDoctor();
        $nfs = Exam::query()->create(['name' => 'FNS (NFS)', 'category' => 'labo', 'is_active' => true]);
        Exam::query()->create(['name' => 'Glycémie à jeun', 'category' => 'labo', 'is_active' => true]);
        $this->fakeProvider([
            'exams' => [
                ['name' => 'fns (nfs)', 'reason' => 'Syndrome infectieux', 'priority' => 'recommandé'],
                ['name' => 'Radiographie thoracique', 'reason' => 'Toux fébrile', 'priority' => 'urgent'],
            ],
            'note' => 'À adapter à l’examen clinique.',
        ]);

        $this->postJson(route('app.ai.consultations.exams', $consultation))
            ->assertOk()
            ->assertJsonPath('exams.0.exam_id', $nfs->id)
            ->assertJsonPath('exams.0.name', 'FNS (NFS)')
            ->assertJsonPath('exams.1.exam_id', null)
            ->assertJsonPath('exams.1.priority', 'urgent')
            ->assertJsonPath('balance', 498);

        $this->assertSame(498, $cabinet->fresh()->ai_credits);
    }

    public function test_suggested_medications_prefer_the_catalogue_name(): void
    {
        [, , $consultation] = $this->consultationForDoctor();
        Medication::query()->create(['name' => 'DOLIPRANE 1000 MG', 'dci' => 'Paracétamol', 'is_active' => true]);
        $this->fakeProvider([
            'items' => [
                ['medication' => 'Doliprane 1000 mg', 'dosage' => '1 cp x 3/j', 'duration' => '1 boîte', 'instructions' => 'Si fièvre', 'reason' => 'Antipyrétique'],
                ['medication' => 'Azithromycine 500 mg', 'dosage' => '1 cp/j', 'duration' => '3 jours', 'instructions' => '', 'reason' => 'Allergie pénicilline'],
            ],
            'warnings' => ['Éviter les bêta-lactamines'],
            'advice' => 'Bien s’hydrater.',
        ]);

        $this->postJson(route('app.ai.consultations.prescription', $consultation), [
            'current_items' => ['Vitamine C'],
        ])
            ->assertOk()
            ->assertJsonPath('items.0.medication', 'DOLIPRANE 1000 MG')
            ->assertJsonPath('items.0.in_catalogue', true)
            ->assertJsonPath('items.1.in_catalogue', false)
            ->assertJsonPath('warnings.0', 'Éviter les bêta-lactamines');
    }

    public function test_patient_analysis_is_stored_and_reopened_for_free(): void
    {
        [$cabinet, , $consultation] = $this->consultationForDoctor();
        $patient = Patient::query()->findOrFail($consultation->patient_id);
        $this->fakeProvider([
            'summary' => 'Patiente suivie pour infections respiratoires.',
            'problems' => ['Toux récidivante'],
            'risks' => [['label' => 'Allergie', 'level' => 'élevé', 'reason' => 'Pénicilline']],
            'follow_up' => ['Contrôle dans 7 jours'],
            'suggested_exams' => [],
            'treatment_notes' => [],
            'alerts' => [],
        ]);

        $this->postJson(route('app.ai.patients.analysis.store', $patient))
            ->assertOk()
            ->assertJsonPath('analysis.content.summary', 'Patiente suivie pour infections respiratoires.')
            ->assertJsonPath('analysis.content.risks.0.level', 'élevé')
            ->assertJsonPath('balance', 495);

        $this->getJson(route('app.ai.patients.analysis.show', $patient))
            ->assertOk()
            ->assertJsonPath('analysis.content.problems.0', 'Toux récidivante');

        $this->assertSame(1, AiInsight::query()->count());
        $this->assertSame(495, $cabinet->fresh()->ai_credits);
    }

    public function test_status_reports_balance_costs_and_support(): void
    {
        $this->consultationForDoctor();

        $this->getJson(route('app.ai.status'))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('balance', 500)
            ->assertJsonPath('costs.patient_analysis', 5);
    }

    public function test_a_local_desktop_without_key_relays_through_the_hosted_service(): void
    {
        [$cabinet, , $consultation] = $this->consultationForDoctor();
        config(['ai.api_key' => '']);
        app(MobileSyncSettings::class)->configure('https://hosted.test', 'sync-token');
        Http::fake([
            'https://hosted.test/api/v1/ai/complete' => Http::response([
                'content' => json_encode(['motif' => 'Fièvre', 'examens' => '', 'diagnostic' => '', 'traitement' => '', 'alerts' => []]),
                'model' => 'qwen-test',
                'balance' => 321,
            ]),
        ]);

        $this->postJson(route('app.ai.consultations.text', $consultation))
            ->assertOk()
            ->assertJsonPath('fields.motif', 'Fièvre')
            ->assertJsonPath('balance', 321);

        Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer sync-token')
            && $request['feature'] === 'consultation_text');
        // The hosted wallet is the one charged, never the local copy.
        $this->assertSame(500, $cabinet->fresh()->ai_credits);
    }

    public function test_the_hosted_relay_charges_the_token_owners_cabinet(): void
    {
        [$cabinet, $doctor] = $this->consultationForDoctor();
        $this->fakeProvider(['ok' => true]);
        Sanctum::actingAs($doctor);

        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'patient_analysis',
            'messages' => [['role' => 'user', 'content' => 'Synthèse']],
        ])
            ->assertOk()
            ->assertJsonPath('balance', 495);

        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'not_a_feature',
            'messages' => [['role' => 'user', 'content' => 'x']],
        ])->assertUnprocessable();

        $this->assertSame(495, $cabinet->fresh()->ai_credits);
    }

    public function test_the_relay_takes_an_image_only_for_an_action_that_reads_one(): void
    {
        [$cabinet, $doctor] = $this->consultationForDoctor();
        Http::fake();
        Sanctum::actingAs($doctor);
        $image = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.base64_encode('png')]];

        // A one-credit chat cannot carry an image, flagged as vision or not.
        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'copilot_chat',
            'vision' => true,
            'messages' => [['role' => 'user', 'content' => [$image]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('vision');

        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'copilot_chat',
            'messages' => [['role' => 'user', 'content' => [$image, ['type' => 'text', 'text' => 'Et ça ?']]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('messages');

        // An ECG question carries its one tracing, not a stack of them.
        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'ecg_chat',
            'vision' => true,
            'messages' => [['role' => 'user', 'content' => [$image, $image]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('messages');

        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'ecg_chat',
            'vision' => true,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.str_repeat('A', (int) config('ai.max_image_bytes') * 2)]],
            ]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('messages');

        $this->assertSame(500, $cabinet->fresh()->ai_credits);
        Http::assertNothingSent();
    }

    public function test_the_relay_accepts_an_ecg_question_with_its_tracing(): void
    {
        [$cabinet, $doctor] = $this->consultationForDoctor();
        $this->fakeProvider(['ok' => true]);
        Sanctum::actingAs($doctor);

        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'ecg_chat',
            'vision' => true,
            'json' => false,
            'messages' => [
                ['role' => 'system', 'content' => 'Cardiologue'],
                ['role' => 'user', 'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.base64_encode('png')]],
                    ['type' => 'text', 'text' => 'Voici le tracé.'],
                ]],
                ['role' => 'assistant', 'content' => 'Entendu.'],
                ['role' => 'user', 'content' => 'Un bloc ?'],
            ],
        ])->assertOk();

        $this->assertSame(500 - AiFeature::ECG_CHAT->cost(), $cabinet->fresh()->ai_credits);
    }

    public function test_a_relay_that_times_out_after_sending_warns_it_may_have_been_charged(): void
    {
        [, , $consultation] = $this->consultationForDoctor();
        config(['ai.api_key' => '']);
        app(MobileSyncSettings::class)->configure('https://hosted.test', 'sync-token');
        Http::fake([
            'https://hosted.test/api/v1/ai/complete' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 75001 milliseconds with 0 bytes received',
                0,
                new ConnectException(
                    'cURL error 28: Operation timed out after 75001 milliseconds with 0 bytes received',
                    new GuzzleRequest('POST', 'https://hosted.test/api/v1/ai/complete'),
                    null,
                    ['errno' => 28, 'pretransfer_time' => 0.4, 'size_upload' => 2048.0],
                ),
            ),
        ]);

        $this->postJson(route('app.ai.consultations.text', $consultation))
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'facturée'));
    }

    public function test_a_relay_that_never_connects_says_internet_is_needed(): void
    {
        [, , $consultation] = $this->consultationForDoctor();
        config(['ai.api_key' => '']);
        app(MobileSyncSettings::class)->configure('https://hosted.test', 'sync-token');
        Http::fake([
            'https://hosted.test/api/v1/ai/complete' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host'),
        ]);

        $this->postJson(route('app.ai.consultations.text', $consultation))
            ->assertStatus(503)
            ->assertJsonPath('message', 'L’assistant IA a besoin d’Internet. Vérifiez la connexion puis réessayez.');
    }

    public function test_a_desktop_linked_for_another_cabinet_does_not_relay_this_ones_requests(): void
    {
        [, , $consultation] = $this->consultationForDoctor();
        $other = Cabinet::query()->create(['name' => 'Autre', 'status' => CabinetStatus::ACTIVE, 'activated_at' => now()]);
        config(['ai.api_key' => '']);
        app(MobileSyncSettings::class)->configure('https://hosted.test', 'sync-token', cabinetId: (int) $other->getKey());
        Http::fake();

        $this->postJson(route('app.ai.consultations.text', $consultation))
            ->assertStatus(503)
            ->assertJsonPath('reason', 'unavailable');

        Http::assertNothingSent();
    }

    public function test_a_failed_call_does_not_undo_an_admin_cut_made_while_it_ran(): void
    {
        [$cabinet, $doctor] = $this->consultationForDoctor();
        $ledger = app(AiCreditLedger::class);

        $this->spendAndFail($ledger, $cabinet, $doctor, fn () => $ledger->adjust($cabinet, null, 'set', 0, 'Coupé'));

        // Cut off at 0 while the ECG was read: the refund does not reopen it.
        $this->assertSame(0, $cabinet->fresh()->ai_credits);

        // A recharge made while a call runs still gets that call's credits back.
        $ledger->adjust($cabinet, null, 'set', 10);
        $this->spendAndFail($ledger, $cabinet, $doctor, fn () => $ledger->adjust($cabinet, null, 'add', 100));

        $this->assertSame(110, $cabinet->fresh()->ai_credits);
    }

    private function spendAndFail(AiCreditLedger $ledger, Cabinet $cabinet, User $doctor, callable $meanwhile): void
    {
        try {
            $ledger->spend($cabinet, $doctor, AiFeature::ECG_ANALYSIS, function () use ($meanwhile): never {
                $meanwhile();

                throw new RuntimeException('provider timeout');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('provider timeout', $exception->getMessage());

            return;
        }

        $this->fail('The provider call should have failed.');
    }

    public function test_the_admin_can_recharge_remove_and_set_credits(): void
    {
        [$cabinet] = $this->consultationForDoctor();
        $ledger = app(AiCreditLedger::class);

        $this->assertSame(1000, $ledger->adjust($cabinet, null, 'add', 500, 'Recharge'));
        $this->assertSame(900, $ledger->adjust($cabinet, null, 'remove', 100));
        $this->assertSame(0, $ledger->adjust($cabinet, null, 'remove', 5000));
        $this->assertSame(250, $ledger->adjust($cabinet, null, 'set', 250));

        $this->assertSame([500, -100, -900, 250], AiUsage::query()->orderBy('id')->pluck('credits')->all());
    }

    public function test_a_doctor_cannot_reach_another_cabinets_consultation(): void
    {
        [, , $consultation] = $this->consultationForDoctor();

        $other = Cabinet::query()->create(['name' => 'Autre', 'status' => CabinetStatus::ACTIVE, 'activated_at' => now()]);
        $stranger = User::factory()->create(['cabinet_id' => $other->id, 'approved_at' => now()]);
        $stranger->assignRole(RoleName::DOCTOR->value);
        Http::fake();

        $this->actingAs($stranger)
            ->postJson(route('app.ai.consultations.text', $consultation))
            ->assertNotFound();

        Http::assertNothingSent();
    }
}
