<?php

namespace Tests\Feature\Ai;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\EcgRecord;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The ECG tab end to end: upload, measure, AI reading, chat, sign, delete.
 */
class EcgFeatureTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private Cabinet $cabinet;

    private User $doctor;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake();
        $this->configureDirectAi();
        [$this->cabinet, $this->doctor, $this->consultation] = $this->aiConsultation(['gender' => 'male']);
    }

    private function balance(): int
    {
        return (int) Cabinet::query()->whereKey($this->cabinet->getKey())->value('ai_credits');
    }

    private function upload(array $data = []): int
    {
        return (int) $this->post(route('app.ecgs.store', $this->consultation), [
            'file' => UploadedFile::fake()->image('ecg.png', 1200, 400),
            ...$data,
        ], ['Accept' => 'application/json'])->assertCreated()->json('ecg.id');
    }

    // ----- upload -------------------------------------------------------

    public function test_an_upload_without_title_or_date_gets_defaults(): void
    {
        $id = $this->upload();
        $ecg = EcgRecord::query()->findOrFail($id);

        $this->assertSame('ECG', $ecg->title);
        $this->assertSame(now()->toDateString(), $ecg->recorded_at->toDateString());
        $this->assertSame(EcgRecord::STATUS_DRAFT, $ecg->status);
        $this->assertSame($this->doctor->getKey(), $ecg->created_by);
        Storage::assertExists($ecg->file_path);
        $this->assertStringStartsWith('patient-ecgs/'.$this->consultation->patient_id.'/', $ecg->file_path);
    }

    public function test_the_upload_payload_has_everything_the_tab_renders(): void
    {
        $this->post(route('app.ecgs.store', $this->consultation), [
            'file' => UploadedFile::fake()->image('tracé.jpg'),
            'title' => '  ECG d’effort  ',
            'recorded_at' => '2026-09-01',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ecg.title', 'ECG d’effort')
            ->assertJsonPath('ecg.recorded_at', '2026-09-01')
            ->assertJsonPath('ecg.flags', [])
            ->assertJsonPath('ecg.urgency', null)
            ->assertJsonPath('ecg.conversation', [])
            ->assertJsonPath('ecg.original_filename', 'tracé.jpg')
            ->assertJsonStructure(['ecg' => ['id', 'file_url', 'measurements', 'analysis', 'status', 'validated_at', 'validated_by', 'created_at']]);
    }

    /**
     * @return array<string, array{0: \Closure(): UploadedFile}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'pdf' => [fn () => UploadedFile::fake()->create('ecg.pdf', 100, 'application/pdf')],
            'dicom' => [fn () => UploadedFile::fake()->create('ecg.dcm', 100, 'application/dicom')],
            'svg' => [fn () => UploadedFile::fake()->create('ecg.svg', 10, 'image/svg+xml')],
            'over 10 MB' => [fn () => UploadedFile::fake()->image('ecg.png')->size(10241)],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_only_reasonable_images_are_accepted(\Closure $file): void
    {
        $this->post(route('app.ecgs.store', $this->consultation), ['file' => $file()], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, EcgRecord::query()->count());
    }

    public function test_an_upload_needs_a_file_and_a_valid_date(): void
    {
        $this->postJson(route('app.ecgs.store', $this->consultation), [])->assertJsonValidationErrors('file');
        $this->post(route('app.ecgs.store', $this->consultation), [
            'file' => UploadedFile::fake()->image('ecg.png'),
            'recorded_at' => 'hier',
            'title' => str_repeat('t', 201),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors(['recorded_at', 'title']);
    }

    // ----- listing and file ---------------------------------------------

    public function test_the_tab_lists_the_patients_ecgs_newest_first(): void
    {
        $older = $this->upload(['title' => 'Ancien', 'recorded_at' => '2025-01-01']);
        $newer = $this->upload(['title' => 'Récent', 'recorded_at' => '2026-09-01']);

        $other = Patient::factory()->create();
        EcgRecord::query()->create(['patient_id' => $other->getKey(), 'title' => 'Autre patient', 'file_path' => 'x.png', 'status' => 'draft', 'recorded_at' => now()]);

        $this->getJson(route('app.ecgs.index', $this->consultation))
            ->assertOk()
            ->assertJsonCount(2, 'ecgs')
            ->assertJsonPath('ecgs.0.id', $newer)
            ->assertJsonPath('ecgs.1.id', $older);
    }

    public function test_the_file_is_served_privately_and_404s_when_missing(): void
    {
        $id = $this->upload();

        $this->get(route('app.ecgs.file', $id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        Storage::delete(EcgRecord::query()->findOrFail($id)->file_path);

        $this->get(route('app.ecgs.file', $id))->assertNotFound();
    }

    // ----- measurements -------------------------------------------------

    public function test_measurements_are_validated(): void
    {
        $id = $this->upload();
        $route = route('app.ecgs.measurements', $id);

        $this->putJson($route, ['paper_speed_mm_s' => 30])->assertJsonValidationErrors('paper_speed_mm_s');
        $this->putJson($route, ['source' => 'ai'])->assertJsonValidationErrors('source');
        $this->putJson($route, ['px_per_mm' => 0.1])->assertJsonValidationErrors('px_per_mm');
        $this->putJson($route, ['duration_s' => 500])->assertJsonValidationErrors('duration_s');
        $this->putJson($route, ['rr_ms' => array_fill(0, 301, 800)])->assertJsonValidationErrors('rr_ms');
        $this->putJson($route, ['rr_ms' => ['vite']])->assertJsonValidationErrors('rr_ms.0');
        $this->putJson($route, ['calipers' => [['label' => 'QT']]])->assertJsonValidationErrors('calipers.0.ms');
        $this->putJson($route, ['calipers' => array_fill(0, 21, ['ms' => 100])])->assertJsonValidationErrors('calipers');

        $this->assertNull(EcgRecord::query()->findOrFail($id)->measurements);
    }

    public function test_a_slow_steady_rhythm_with_long_qt_is_flagged(): void
    {
        $id = $this->upload();

        $response = $this->putJson(route('app.ecgs.measurements', $id), [
            'rr_ms' => array_fill(0, 8, 1500),
            'calipers' => [['label' => 'QT', 'ms' => 640], ['label' => 'PR', 'ms' => 240]],
        ])->assertOk();

        $this->assertSame(40, $response->json('ecg.measurements.heart_rate_bpm'));
        $codes = array_column($response->json('ecg.flags'), 'code');
        $this->assertContains('rate_low', $codes);
        $this->assertContains('pr_long', $codes);
        $this->assertContains('qtc_very_long', $codes);
        $this->assertSame('critique', $response->json('ecg.urgency'));
    }

    public function test_measuring_again_replaces_the_previous_measurements(): void
    {
        $id = $this->upload();
        $this->putJson(route('app.ecgs.measurements', $id), ['rr_ms' => array_fill(0, 8, 400)])->assertOk();

        $this->putJson(route('app.ecgs.measurements', $id), ['rr_ms' => array_fill(0, 8, 1000)])
            ->assertOk()
            ->assertJsonPath('ecg.measurements.heart_rate_bpm', 60)
            ->assertJsonPath('ecg.flags', []);
    }

    // ----- AI reading ---------------------------------------------------

    public function test_a_reading_is_normalised_whatever_the_model_answers(): void
    {
        $id = $this->upload();
        $this->fakeAiReply([
            'quality' => ['rating' => 'excellente', 'issues' => 'aucune'],
            'rate_bpm' => '72.6',
            'regularity' => 'sinusal',
            'pr_ms' => 160,
            'qrs_ms' => 0,
            'qt_ms' => 5000,
            'urgency' => 'urgent',
            'confidence' => 'totale',
            'other_findings' => [null, 'Onde Q en DIII', ['x']],
            'primary_statement' => ['liste'],
        ], 'ecg-served');

        $analysis = $this->postJson(route('app.ai.ecgs.analysis', $id))->assertOk()->json('ecg.analysis');

        $this->assertSame('moyenne', $analysis['quality']['rating']);
        $this->assertSame([], $analysis['quality']['issues']);
        $this->assertSame(73, $analysis['rate_bpm']);
        $this->assertSame('indéterminé', $analysis['regularity']);
        $this->assertSame(160, $analysis['pr_ms']);
        $this->assertNull($analysis['qrs_ms']);
        $this->assertNull($analysis['qt_ms']);
        $this->assertSame('anormal', $analysis['urgency']);
        $this->assertSame('faible', $analysis['confidence']);
        $this->assertSame(['Onde Q en DIII'], $analysis['other_findings']);
        $this->assertSame('', $analysis['primary_statement']);
        $this->assertSame('ecg-served', $analysis['model']);
        $this->assertNotNull($analysis['analyzed_at']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadableReadings(): array
    {
        return [
            'prose' => ['Je vois un rythme sinusal.'],
            'empty object' => ['{}'],
            'quality as text' => ['{"quality": "bonne", "rate_bpm": "rapide"}'],
            'list' => ['[1, 2]'],
        ];
    }

    #[DataProvider('unreadableReadings')]
    public function test_an_unreadable_reading_still_gives_a_complete_safe_analysis(string $reply): void
    {
        $id = $this->upload();
        $this->fakeAiReply($reply);

        $response = $this->postJson(route('app.ai.ecgs.analysis', $id))->assertOk();
        $analysis = $response->json('ecg.analysis');

        $this->assertSame('moyenne', $analysis['quality']['rating']);
        $this->assertNull($analysis['rate_bpm']);
        $this->assertSame('indéterminé', $analysis['regularity']);
        $this->assertSame('anormal', $analysis['urgency']);
        $this->assertSame('faible', $analysis['confidence']);
        $this->assertSame('anormal', $response->json('ecg.urgency'));
    }

    public function test_reading_an_ecg_whose_file_vanished_is_free(): void
    {
        $id = $this->upload();
        Storage::delete(EcgRecord::query()->findOrFail($id)->file_path);
        Http::fake();

        $this->postJson(route('app.ai.ecgs.analysis', $id))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unsupported');

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    public function test_reading_an_oversized_image_is_free(): void
    {
        config(['ai.max_image_bytes' => 100]);
        $id = $this->upload();
        Http::fake();

        $this->postJson(route('app.ai.ecgs.analysis', $id))->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    public function test_a_failed_reading_is_refunded_and_keeps_no_analysis(): void
    {
        $id = $this->upload();
        $this->fakeAiFailure(503);

        $this->postJson(route('app.ai.ecgs.analysis', $id))->assertStatus(502);

        $this->assertSame(500, $this->balance());
        $this->assertNull(EcgRecord::query()->findOrFail($id)->analysis);
    }

    public function test_the_reading_prompt_announces_missing_measurements(): void
    {
        $id = $this->upload();
        $this->fakeAiReply(['urgency' => 'normal']);

        $this->postJson(route('app.ai.ecgs.analysis', $id))->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => str_contains(
            json_encode($request['messages'], JSON_UNESCAPED_UNICODE),
            'MESURES AUTOMATIQUES : aucune',
        ));
    }

    // ----- chat ---------------------------------------------------------

    public function test_a_chat_reply_is_trimmed_and_capped_history_is_replayed(): void
    {
        $id = $this->upload();
        $ecg = EcgRecord::query()->findOrFail($id);
        $conversation = [];

        foreach (range(1, 35) as $i) {
            $conversation[] = ['role' => 'user', 'content' => 'Q'.$i];
            $conversation[] = ['role' => 'assistant', 'content' => 'R'.$i];
        }

        $ecg->update(['conversation' => $conversation]);
        $this->fakeAiReply("  Probable bloc de branche droit.  \n");

        $response = $this->postJson(route('app.ai.ecgs.chat', $id), ['message' => '  Et le QRS ?  '])->assertOk();

        $kept = $response->json('ecg.conversation');
        $this->assertCount(60, $kept);
        $this->assertSame('Et le QRS ?', $kept[58]['content']);
        $this->assertSame($this->doctor->name, $kept[58]['by']);
        $this->assertSame('Probable bloc de branche droit.', $kept[59]['content']);

        // System + tracing + acknowledgement + 12 replayed turns + question.
        Http::assertSent(fn (HttpRequest $request): bool => count($request['messages']) === 16
            && $request['model'] === 'ecg-model'
            && $request['messages'][15] === ['role' => 'user', 'content' => 'Et le QRS ?']);
    }

    public function test_chat_input_is_validated(): void
    {
        $id = $this->upload();
        Http::fake();

        $this->postJson(route('app.ai.ecgs.chat', $id), [])->assertJsonValidationErrors('message');
        $this->postJson(route('app.ai.ecgs.chat', $id), ['message' => str_repeat('a', 1001)])->assertJsonValidationErrors('message');

        Http::assertNothingSent();
    }

    public function test_a_failed_chat_keeps_the_conversation_unchanged(): void
    {
        $id = $this->upload();
        $this->fakeAiFailure();

        $this->postJson(route('app.ai.ecgs.chat', $id), ['message' => 'Question'])->assertStatus(502);

        $this->assertNull(EcgRecord::query()->findOrFail($id)->conversation);
        $this->assertSame(500, $this->balance());
    }

    // ----- conclusion ---------------------------------------------------

    public function test_validating_records_the_signer_and_an_audit_line(): void
    {
        $id = $this->upload();
        EcgRecord::query()->whereKey($id)->update(['analysis' => json_encode(['primary_statement' => 'Rythme sinusal'])]);

        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => '  Rythme sinusal normal.  ', 'validate' => true, 'title' => ' ECG repos '])
            ->assertOk()
            ->assertJsonPath('ecg.status', 'validated')
            ->assertJsonPath('ecg.doctor_conclusion', 'Rythme sinusal normal.')
            ->assertJsonPath('ecg.title', 'ECG repos')
            ->assertJsonPath('ecg.validated_by', $this->doctor->name);

        $log = AuditLog::query()->where('action', 'ecg.validated')->sole();
        $this->assertSame('Rythme sinusal', $log->metadata['ai_primary_statement'] ?? null);
        $this->assertTrue($log->metadata['ai_used'] ?? null);
    }

    public function test_saving_a_draft_conclusion_does_not_validate(): void
    {
        $id = $this->upload();

        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => 'Brouillon'])
            ->assertOk()
            ->assertJsonPath('ecg.status', 'draft')
            ->assertJsonPath('ecg.validated_at', null);

        $this->assertSame(0, AuditLog::query()->where('action', 'ecg.validated')->count());
    }

    public function test_a_blank_title_keeps_the_existing_one_and_a_blank_conclusion_clears_it(): void
    {
        $id = $this->upload(['title' => 'Holter']);
        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => 'Texte'])->assertOk();

        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => '   ', 'title' => '  '])
            ->assertOk()
            ->assertJsonPath('ecg.title', 'Holter')
            ->assertJsonPath('ecg.doctor_conclusion', null);
    }

    public function test_renaming_a_validated_ecg_keeps_it_validated(): void
    {
        $id = $this->upload();
        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => 'Normal', 'validate' => true])->assertOk();

        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => 'Normal', 'title' => 'Nouveau titre'])
            ->assertOk()
            ->assertJsonPath('ecg.status', 'validated');
    }

    // ----- delete -------------------------------------------------------

    public function test_deleting_an_ecg_removes_its_file(): void
    {
        $id = $this->upload();
        $path = EcgRecord::query()->findOrFail($id)->file_path;

        $this->deleteJson(route('app.ecgs.destroy', $id))->assertOk()->assertJsonPath('deleted', true);

        Storage::assertMissing($path);
        $this->assertNull(EcgRecord::query()->find($id));
    }

    // ----- access -------------------------------------------------------

    public function test_another_cabinet_cannot_touch_this_ecg(): void
    {
        $id = $this->upload();
        $this->actingAs($this->makeDoctor($this->makeCabinet('Autre')));
        Http::fake();

        $this->putJson(route('app.ecgs.measurements', $id), ['rr_ms' => [800]])->assertNotFound();
        $this->putJson(route('app.ecgs.conclusion', $id), ['doctor_conclusion' => 'x'])->assertNotFound();
        $this->postJson(route('app.ai.ecgs.chat', $id), ['message' => 'x'])->assertNotFound();
        $this->deleteJson(route('app.ecgs.destroy', $id))->assertNotFound();
        $this->getJson(route('app.ecgs.index', $this->consultation))->assertNotFound();

        Http::assertNothingSent();
        $this->assertNotNull(EcgRecord::query()->withoutGlobalScopes()->find($id));
    }

    public function test_an_assistant_cannot_upload_or_read_ecgs_with_the_ai(): void
    {
        $id = $this->upload();
        $this->actingAs($this->makeDoctor($this->cabinet, RoleName::ASSISTANT));
        Http::fake();

        $this->post(route('app.ecgs.store', $this->consultation), ['file' => UploadedFile::fake()->image('ecg.png')], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson(route('app.ai.ecgs.analysis', $id))->assertForbidden();
        $this->postJson(route('app.ai.ecgs.chat', $id), ['message' => 'x'])->assertForbidden();
        $this->deleteJson(route('app.ecgs.destroy', $id))->assertForbidden();

        Http::assertNothingSent();
    }
}
