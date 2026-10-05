<?php

namespace Tests\Feature\Ai;

use App\Enums\RoleName;
use App\Models\AiInsight;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Document;
use App\Models\Exam;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * Every "✨ IA" endpoint of the consultation workspace and the patient list.
 *
 * Whatever the model answers (prose, wrong types, empty object, huge lists)
 * and whatever happens to the provider, each endpoint must answer with the
 * exact shape the screen renders, or a readable error with the credits
 * given back.
 */
class ClinicalAiEndpointsTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private Cabinet $cabinet;

    private User $doctor;

    private Consultation $consultation;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake();
        $this->configureDirectAi();
        [$this->cabinet, $this->doctor, $this->consultation, $this->patient] = $this->aiConsultation(['allergies' => 'Pénicilline']);
    }

    private function balance(): int
    {
        return (int) Cabinet::query()->whereKey($this->cabinet->getKey())->value('ai_credits');
    }

    private function generatedDocument(string $content = '<p>Hémoglobine 9 g/dL</p>'): Document
    {
        return Document::query()->create([
            'patient_id' => $this->patient->getKey(),
            'consultation_id' => $this->consultation->getKey(),
            'category' => 'report',
            'title' => 'Compte rendu',
            'content' => $content,
        ]);
    }

    private function uploadedDocument(string $filename, string $mime, ?string $bytes): Document
    {
        $path = null;

        if ($bytes !== null) {
            $path = 'patient-documents/'.$this->patient->getKey().'/'.$filename;
            Storage::put($path, $bytes);
        }

        return Document::query()->create([
            'patient_id' => $this->patient->getKey(),
            'category' => 'uploaded',
            'title' => 'Import '.$filename,
            'file_path' => $path ?? 'patient-documents/missing/'.$filename,
            'original_filename' => $filename,
            'mime_type' => $mime,
        ]);
    }

    /**
     * Each billable endpoint, called the way the screen calls it.
     *
     * @return array<string, array{0: string}>
     */
    public static function billableEndpoints(): array
    {
        return [
            'consultation text' => ['consultation'],
            'exam suggestions' => ['exams'],
            'prescription' => ['prescription'],
            'document analysis' => ['document'],
            'patient analysis' => ['patient'],
            'copilot' => ['copilot'],
        ];
    }

    private function hit(string $endpoint): TestResponse
    {
        return match ($endpoint) {
            'consultation' => $this->postJson(route('app.ai.consultations.text', $this->consultation), ['draft' => ['motif' => 'Toux']]),
            'exams' => $this->postJson(route('app.ai.consultations.exams', $this->consultation)),
            'prescription' => $this->postJson(route('app.ai.consultations.prescription', $this->consultation), ['current_items' => ['Doliprane']]),
            'document' => $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $this->generatedDocument()])),
            'patient' => $this->postJson(route('app.ai.patients.analysis.store', $this->patient)),
            'copilot' => $this->postJson(route('app.ai.consultations.copilot.store', $this->consultation), ['message' => 'Que proposes-tu ?']),
        };
    }

    private function assertShape(string $endpoint, TestResponse $response): void
    {
        $response->assertOk();
        $json = $response->json();
        $this->assertIsInt($json['balance']);

        $strings = function (mixed $list, string $what): void {
            $this->assertIsArray($list, $what);
            $this->assertTrue(array_is_list($list), $what);

            foreach ($list as $item) {
                $this->assertIsString($item, $what);
                $this->assertNotSame('', $item, $what);
            }
        };

        switch ($endpoint) {
            case 'consultation':
                $this->assertSame(['motif', 'examens', 'diagnostic', 'traitement'], array_keys($json['fields']));
                array_map(fn ($value) => $this->assertIsString($value), $json['fields']);
                $strings($json['alerts'], 'alerts');
                break;

            case 'exams':
                $this->assertIsString($json['note']);
                $this->assertTrue(array_is_list($json['exams']));

                foreach ($json['exams'] as $exam) {
                    $this->assertIsString($exam['name']);
                    $this->assertNotSame('', $exam['name']);
                    $this->assertIsString($exam['reason']);
                    $this->assertContains($exam['priority'], ['urgent', 'recommandé', 'optionnel']);
                    $this->assertTrue($exam['exam_id'] === null || is_int($exam['exam_id']));
                }
                break;

            case 'prescription':
                $this->assertIsString($json['advice']);
                $strings($json['warnings'], 'warnings');

                foreach ($json['items'] as $item) {
                    foreach (['medication', 'dosage', 'duration', 'instructions', 'reason'] as $key) {
                        $this->assertIsString($item[$key], $key);
                    }

                    $this->assertIsBool($item['in_catalogue']);
                    $strings($item['allergy_conflicts'], 'allergy_conflicts');
                }
                break;

            case 'document':
                $content = $json['analysis']['content'];
                $this->assertIsString($content['document_type']);
                $this->assertIsString($content['summary']);
                $strings($content['recommendations'], 'recommendations');

                foreach ($content['findings'] as $finding) {
                    $this->assertIsString($finding['label']);
                    $this->assertIsString($finding['value']);
                    $this->assertContains($finding['status'], ['normal', 'anormal', 'à surveiller']);
                }
                break;

            case 'patient':
                $content = $json['analysis']['content'];
                $this->assertIsString($content['summary']);

                foreach (['problems', 'follow_up', 'suggested_exams', 'treatment_notes', 'alerts'] as $key) {
                    $strings($content[$key], $key);
                }

                foreach ($content['risks'] as $risk) {
                    $this->assertIsString($risk['label']);
                    $this->assertContains($risk['level'], ['élevé', 'modéré', 'faible']);
                    $this->assertIsString($risk['reason']);
                }
                break;

            case 'copilot':
                $message = $json['message'];
                $this->assertSame('assistant', $message['role']);
                $this->assertIsString($message['reply']);
                $this->assertNotSame('', $message['reply']);
                $strings($message['sources'], 'sources');
                $this->assertIsArray($message['actions']);
                $this->assertIsInt($json['conversation_id']);
                break;
        }
    }

    /**
     * Replies a model has actually been seen to produce, and worse.
     *
     * @return array<string, array{0: string}>
     */
    public static function garbageReplies(): array
    {
        return [
            'prose instead of json' => ['Je ne peux pas analyser ce dossier.'],
            'empty object' => ['{}'],
            'empty list' => ['[]'],
            'fenced json' => ["```json\n{\"summary\": \"ok\"}\n```"],
            'truncated json' => ['{"summary": "Le patient prés'],
            'json null' => ['null'],
            'every field null' => [json_encode([
                'motif' => null, 'examens' => null, 'diagnostic' => null, 'traitement' => null, 'alerts' => null,
                'exams' => null, 'note' => null, 'items' => null, 'warnings' => null, 'advice' => null,
                'document_type' => null, 'summary' => null, 'findings' => null, 'recommendations' => null,
                'problems' => null, 'risks' => null, 'follow_up' => null, 'reply' => null, 'sources' => null, 'actions' => null,
            ])],
            'wrong types everywhere' => [json_encode([
                'motif' => ['Toux', ['nested' => true], 3, null],
                'examens' => 12,
                'diagnostic' => true,
                'traitement' => ['a' => 'b'],
                'alerts' => 'Une alerte en texte',
                'exams' => 'NFS',
                'note' => ['x'],
                'items' => ['medication' => 'Doliprane'],
                'warnings' => [null, '', '  ', ['x'], 'Vraie alerte'],
                'advice' => 3.5,
                'document_type' => ['bilan'],
                'summary' => 42,
                'findings' => [null, 1, 'texte', ['label' => 5], ['value' => 'sans label'], ['label' => 'Hb', 'status' => 'grave']],
                'recommendations' => 'Une seule recommandation',
                'problems' => [1, 2, 3],
                'risks' => [['label' => 'HTA', 'level' => 'extrême'], 'risque en texte', ['level' => 'élevé']],
                'reply' => ['Bonjour'],
                'sources' => 'Antécédents',
                'actions' => 'ajoute une NFS',
            ])],
            'lists of junk items' => [json_encode([
                'exams' => [null, 1, 'NFS', ['name' => ''], ['name' => '   '], ['name' => 7], ['reason' => 'sans nom'], ['name' => 'Ionogramme', 'priority' => 'immédiat']],
                'items' => [null, 'Doliprane', ['medication' => ''], ['medication' => 5], ['dosage' => '1 cp'], ['medication' => 'Augmentin 1 g', 'dosage' => ['1 cp', 'x 2']]],
                'actions' => [null, 'x', ['type' => 'delete_patient'], ['type' => 'set_field', 'field' => 'patient_name', 'text' => 'X'], ['type' => 'set_field', 'field' => 'motif', 'text' => ''], ['type' => 'add_exam'], ['type' => 'add_medication', 'medication' => ['x']]],
            ])],
            'unicode and html' => [json_encode([
                'motif' => '<script>alert(1)</script> douleur — « aiguë » 🤒',
                'summary' => "Résumé\u{0000}avec octet nul",
                'reply' => 'مرحبا، خذ الدواء بعد الأكل',
            ], JSON_UNESCAPED_UNICODE)],
        ];
    }

    #[DataProvider('garbageReplies')]
    public function test_consultation_text_survives_any_model_reply(string $reply): void
    {
        $this->fakeAiReply($reply);

        $this->assertShape('consultation', $this->hit('consultation'));
    }

    #[DataProvider('garbageReplies')]
    public function test_exam_suggestions_survive_any_model_reply(string $reply): void
    {
        $this->fakeAiReply($reply);

        $this->assertShape('exams', $this->hit('exams'));
    }

    #[DataProvider('garbageReplies')]
    public function test_prescription_suggestions_survive_any_model_reply(string $reply): void
    {
        $this->fakeAiReply($reply);

        $this->assertShape('prescription', $this->hit('prescription'));
    }

    #[DataProvider('garbageReplies')]
    public function test_document_analysis_survives_any_model_reply(string $reply): void
    {
        $this->fakeAiReply($reply);

        $this->assertShape('document', $this->hit('document'));
    }

    #[DataProvider('garbageReplies')]
    public function test_patient_analysis_survives_any_model_reply(string $reply): void
    {
        $this->fakeAiReply($reply);

        $this->assertShape('patient', $this->hit('patient'));
    }

    #[DataProvider('garbageReplies')]
    public function test_the_copilot_survives_any_model_reply(string $reply): void
    {
        $this->fakeAiReply($reply);

        $this->assertShape('copilot', $this->hit('copilot'));
    }

    #[DataProvider('billableEndpoints')]
    public function test_a_provider_outage_answers_502_and_refunds(string $endpoint): void
    {
        $this->fakeAiFailure(500);

        $this->hit($endpoint)
            ->assertStatus(502)
            ->assertJsonPath('reason', 'provider_error')
            ->assertJsonStructure(['message', 'reason', 'balance']);

        $this->assertSame(500, $this->balance());
        $this->assertSame(0, AiUsage::query()->count());
    }

    #[DataProvider('billableEndpoints')]
    public function test_an_unreachable_provider_answers_503_and_refunds(string $endpoint): void
    {
        Http::fake([self::AI_PROVIDER => fn () => throw new ConnectionException('timeout')]);

        $this->hit($endpoint)->assertStatus(503)->assertJsonPath('reason', 'unavailable');

        $this->assertSame(500, $this->balance());
    }

    #[DataProvider('billableEndpoints')]
    public function test_an_empty_wallet_answers_402_without_calling_the_provider(string $endpoint): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_credits' => 0]);
        Http::fake();

        $this->hit($endpoint)
            ->assertStatus(402)
            ->assertJsonPath('reason', 'insufficient_credits')
            ->assertJsonPath('balance', 0);

        Http::assertNothingSent();
    }

    #[DataProvider('billableEndpoints')]
    public function test_a_disabled_cabinet_answers_403_without_calling_the_provider(string $endpoint): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_enabled' => false]);
        Http::fake();

        $this->hit($endpoint)->assertStatus(403)->assertJsonPath('reason', 'disabled');

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    #[DataProvider('billableEndpoints')]
    public function test_a_desktop_without_any_ai_route_answers_503(string $endpoint): void
    {
        config(['ai.api_key' => '']);
        Http::fake();

        $this->hit($endpoint)->assertStatus(503)->assertJsonPath('reason', 'unavailable');

        Http::assertNothingSent();
    }

    #[DataProvider('billableEndpoints')]
    public function test_an_assistant_without_clinical_rights_is_refused(string $endpoint): void
    {
        $assistant = $this->makeDoctor($this->cabinet, RoleName::ASSISTANT);
        $this->actingAs($assistant);
        Http::fake();

        $this->hit($endpoint)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    #[DataProvider('billableEndpoints')]
    public function test_a_guest_is_refused(string $endpoint): void
    {
        $this->app['auth']->forgetGuards();
        Http::fake();

        $this->assertContains($this->hit($endpoint)->status(), [401, 302, 403]);

        Http::assertNothingSent();
    }

    public function test_each_endpoint_charges_its_own_price(): void
    {
        $expected = ['consultation' => 1, 'exams' => 2, 'prescription' => 2, 'document' => 3, 'patient' => 5, 'copilot' => 1];
        $this->fakeAiReply(['ok' => true]);

        foreach ($expected as $endpoint => $price) {
            $before = $this->balance();

            $this->hit($endpoint)->assertOk()->assertJsonPath('balance', $before - $price);

            $this->assertSame($before - $price, $this->balance(), $endpoint);
        }
    }

    // ----- consultation text --------------------------------------------

    public function test_consultation_text_fields_are_trimmed_and_alerts_cleaned(): void
    {
        $this->fakeAiReply([
            'motif' => '  Toux  ',
            'examens' => ['Auscultation normale', 'Gorge rouge'],
            'diagnostic' => 'Rhinopharyngite',
            'traitement' => '',
            'alerts' => ['  Allergie pénicilline ', '', null, 'Fièvre > 39 °C'],
        ]);

        $this->hit('consultation')
            ->assertOk()
            ->assertJsonPath('fields.motif', 'Toux')
            ->assertJsonPath('fields.examens', "Auscultation normale\nGorge rouge")
            ->assertJsonPath('fields.traitement', '')
            ->assertJsonPath('alerts', ['Allergie pénicilline', 'Fièvre > 39 °C']);
    }

    public function test_at_most_twelve_alerts_are_returned(): void
    {
        $this->fakeAiReply(['alerts' => array_map(static fn (int $i): string => 'Alerte '.$i, range(1, 30))]);

        $this->assertCount(12, $this->hit('consultation')->json('alerts'));
    }

    public function test_an_empty_transcript_is_not_sent_as_dictation(): void
    {
        $this->fakeAiReply(['motif' => 'x']);

        $this->postJson(route('app.ai.consultations.text', $this->consultation), ['transcript' => '   '])->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => ! str_contains(json_encode($request['messages'], JSON_UNESCAPED_UNICODE), 'DICTÉE'));
    }

    public function test_consultation_text_input_is_validated(): void
    {
        Http::fake();
        $route = route('app.ai.consultations.text', $this->consultation);

        $this->postJson($route, ['transcript' => str_repeat('a', 20001)])->assertJsonValidationErrors('transcript');
        $this->postJson($route, ['draft' => ['motif' => str_repeat('a', 5001)]])->assertJsonValidationErrors('draft.motif');
        $this->postJson($route, ['draft' => ['weight_kg' => 'lourd']])->assertJsonValidationErrors('draft.weight_kg');
        $this->postJson($route, ['draft' => ['blood_pressure' => str_repeat('1', 21)]])->assertJsonValidationErrors('draft.blood_pressure');
        $this->postJson($route, ['draft' => 'pas un tableau'])->assertJsonValidationErrors('draft');

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    public function test_unknown_draft_keys_never_reach_the_prompt(): void
    {
        $this->fakeAiReply(['motif' => 'x']);

        $this->postJson(route('app.ai.consultations.text', $this->consultation), [
            'draft' => ['motif' => 'Toux sèche', 'patient_name' => 'Benali Yasmine'],
        ])->assertOk();

        Http::assertSent(function (HttpRequest $request): bool {
            $prompt = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);

            return str_contains($prompt, 'Toux sèche') && ! str_contains($prompt, 'Benali');
        });
    }

    // ----- exams --------------------------------------------------------

    public function test_exam_suggestions_are_matched_deduplicated_and_capped(): void
    {
        $nfs = Exam::query()->create(['name' => 'NFS (numération formule sanguine)', 'category' => 'biologie', 'is_active' => true]);
        Exam::query()->create(['name' => 'Échographie abdominale', 'category' => 'imagerie', 'is_active' => false]);

        $exams = [
            ['name' => 'nfs (Numération Formule Sanguine)', 'reason' => 'Fièvre', 'priority' => 'urgent'],
            ['name' => 'NFS (numération formule sanguine)', 'reason' => 'doublon', 'priority' => 'urgent'],
            ['name' => 'Echographie abdominale', 'priority' => 'immédiat'],
        ];

        foreach (range(1, 20) as $i) {
            $exams[] = ['name' => 'Examen '.$i, 'priority' => 'optionnel'];
        }

        $this->fakeAiReply(['exams' => $exams, 'note' => ' À jeun ']);

        $response = $this->hit('exams')->assertOk()->assertJsonPath('note', 'À jeun');
        $list = $response->json('exams');

        $this->assertSame('NFS (numération formule sanguine)', $list[0]['name']);
        $this->assertSame($nfs->getKey(), $list[0]['exam_id']);
        $this->assertSame('urgent', $list[0]['priority']);
        // The duplicate collapsed into the first suggestion.
        $this->assertSame('Echographie abdominale', $list[1]['name']);
        // An inactive catalogue entry is never matched.
        $this->assertNull($list[1]['exam_id']);
        $this->assertSame('recommandé', $list[1]['priority']);
        // At most 12 suggestions are read before de-duplication.
        $this->assertCount(11, $list);
    }

    public function test_the_exam_catalogue_is_sent_to_the_model(): void
    {
        Exam::query()->create(['name' => 'Glycémie à jeun', 'category' => 'biologie', 'is_active' => true]);
        Exam::query()->create(['name' => 'Examen retiré', 'category' => 'biologie', 'is_active' => false]);
        $this->fakeAiReply(['exams' => []]);

        $this->hit('exams')->assertOk()->assertJsonPath('exams', []);

        Http::assertSent(function (HttpRequest $request): bool {
            $prompt = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);

            return str_contains($prompt, 'Glycémie à jeun') && ! str_contains($prompt, 'Examen retiré');
        });
    }

    public function test_an_empty_exam_catalogue_is_announced(): void
    {
        $this->fakeAiReply(['exams' => []]);

        $this->hit('exams')->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => str_contains(json_encode($request['messages'], JSON_UNESCAPED_UNICODE), 'CATALOGUE D’EXAMENS DU CABINET : (vide)'));
    }

    // ----- prescription -------------------------------------------------

    public function test_prescription_lines_are_matched_capped_and_checked_for_allergies(): void
    {
        Medication::query()->create(['name' => 'Augmentin 1 g', 'dci' => 'Amoxicilline + acide clavulanique', 'is_active' => true]);
        $items = [
            ['medication' => 'AUGMENTIN 1 g', 'dosage' => '1 sachet x 2/j', 'duration' => '7 jours', 'reason' => 'Angine du 01/09'],
            ['medication' => 'Produit inventé '.str_repeat('x', 300), 'dosage' => str_repeat('d', 300), 'duration' => str_repeat('u', 200), 'instructions' => str_repeat('i', 600)],
        ];

        foreach (range(1, 15) as $i) {
            $items[] = ['medication' => 'Autre '.$i];
        }

        $this->fakeAiReply(['items' => $items, 'warnings' => ['Interaction possible'], 'advice' => 'Boire beaucoup']);

        $response = $this->hit('prescription')
            ->assertOk()
            ->assertJsonPath('warnings', ['Interaction possible'])
            ->assertJsonPath('advice', 'Boire beaucoup');
        $lines = $response->json('items');

        $this->assertCount(10, $lines);
        $this->assertSame('Augmentin 1 g', $lines[0]['medication']);
        $this->assertTrue($lines[0]['in_catalogue']);
        $this->assertNotEmpty($lines[0]['allergy_conflicts']);

        $this->assertFalse($lines[1]['in_catalogue']);
        $this->assertLessThanOrEqual(190, mb_strlen($lines[1]['medication']));
        $this->assertLessThanOrEqual(190, mb_strlen($lines[1]['dosage']));
        $this->assertLessThanOrEqual(95, mb_strlen($lines[1]['duration']));
        $this->assertLessThanOrEqual(480, mb_strlen($lines[1]['instructions']));
        $this->assertSame([], $lines[1]['allergy_conflicts']);
    }

    public function test_the_current_ordonnance_is_sent_so_it_is_not_repeated(): void
    {
        $this->fakeAiReply(['items' => []]);

        $this->postJson(route('app.ai.consultations.prescription', $this->consultation), ['current_items' => ['Doliprane 1 g', 'Smecta']])->assertOk();
        $this->postJson(route('app.ai.consultations.prescription', $this->consultation))->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => str_contains(json_encode($request['messages'], JSON_UNESCAPED_UNICODE), 'DÉJÀ SUR L’ORDONNANCE : Doliprane 1 g, Smecta'));
        Http::assertSent(fn (HttpRequest $request): bool => str_contains(json_encode($request['messages'], JSON_UNESCAPED_UNICODE), 'DÉJÀ SUR L’ORDONNANCE : rien'));
    }

    public function test_prescription_input_is_validated(): void
    {
        Http::fake();
        $route = route('app.ai.consultations.prescription', $this->consultation);

        $this->postJson($route, ['current_items' => array_fill(0, 31, 'x')])->assertJsonValidationErrors('current_items');
        $this->postJson($route, ['current_items' => [str_repeat('x', 201)]])->assertJsonValidationErrors('current_items.0');
        $this->postJson($route, ['current_items' => [['nested']]])->assertJsonValidationErrors('current_items.0');

        Http::assertNothingSent();
    }

    // ----- document analysis --------------------------------------------

    public function test_a_written_document_is_analysed_from_its_text_and_stored(): void
    {
        $document = $this->generatedDocument('<p>Hémoglobine 9 g/dL</p>');
        $this->fakeAiReply([
            'document_type' => 'Bilan',
            'summary' => 'Anémie',
            'findings' => [['label' => 'Hb', 'value' => '9 g/dL', 'status' => 'anormal'], ['label' => 'VGM', 'status' => '???']],
            'recommendations' => ['Bilan martial'],
        ]);

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))
            ->assertOk()
            ->assertJsonPath('analysis.document_id', $document->getKey())
            ->assertJsonPath('analysis.content.findings.0.status', 'anormal')
            ->assertJsonPath('analysis.content.findings.1.status', 'normal')
            ->assertJsonPath('analysis.content.findings.1.value', '');

        $this->assertSame(1, AiInsight::query()->where('kind', AiInsight::KIND_DOCUMENT_ANALYSIS)->count());
        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'text-model'
            && str_contains(json_encode($request['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Hémoglobine 9 g/dL'));
    }

    public function test_at_most_forty_findings_are_kept(): void
    {
        $this->fakeAiReply(['findings' => array_map(static fn (int $i): array => ['label' => 'F'.$i], range(1, 60))]);

        $this->assertCount(40, $this->hit('document')->json('analysis.content.findings'));
    }

    public function test_an_empty_written_document_is_refused_for_free(): void
    {
        $document = $this->generatedDocument('<p>  </p>');
        Http::fake();

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unsupported');

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    public function test_a_photo_is_sent_to_the_vision_model(): void
    {
        $document = $this->uploadedDocument('bilan.png', 'image/png', 'PNGBYTES');
        $this->fakeAiReply(['summary' => 'Bilan normal']);

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))
            ->assertOk()
            ->assertJsonPath('analysis.content.summary', 'Bilan normal');

        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'vision-model'
            && $request['messages'][1]['content'][0]['image_url']['url'] === 'data:image/png;base64,'.base64_encode('PNGBYTES'));
    }

    public function test_an_oversized_photo_is_refused_for_free(): void
    {
        config(['ai.max_image_bytes' => 10]);
        $document = $this->uploadedDocument('lourd.jpg', 'image/jpeg', str_repeat('x', 11));
        Http::fake();

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unsupported');

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    public function test_a_photo_missing_from_disk_is_refused_for_free(): void
    {
        $document = $this->uploadedDocument('perdu.png', 'image/png', null);
        Http::fake();

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le fichier est introuvable sur ce poste.');

        Http::assertNothingSent();
    }

    public function test_a_scanned_pdf_asks_for_a_photo_instead(): void
    {
        $document = $this->uploadedDocument('scan.pdf', 'application/pdf', "%PDF-1.4\nstream\nq /Im0 Do Q\nendstream\n");
        Http::fake();

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unsupported')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'photo'));

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    public function test_an_uploaded_text_file_is_analysed_from_its_text(): void
    {
        $document = $this->uploadedDocument('cr.txt', 'text/plain', 'Échographie : foie normal');
        $this->fakeAiReply(['summary' => 'RAS']);

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'text-model'
            && str_contains(json_encode($request['messages'], JSON_UNESCAPED_UNICODE), 'Échographie : foie normal'));
    }

    public function test_a_document_of_another_patient_cannot_be_analysed_from_this_visit(): void
    {
        $stranger = Patient::factory()->create();
        $document = Document::query()->create([
            'patient_id' => $stranger->getKey(),
            'category' => 'report',
            'title' => 'Autre',
            'content' => 'Secret',
        ]);
        Http::fake();

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_document_analyses_list_only_the_latest_per_document(): void
    {
        $document = $this->generatedDocument();

        foreach (['Première', 'Seconde'] as $summary) {
            AiInsight::query()->create([
                'patient_id' => $this->patient->getKey(),
                'document_id' => $document->getKey(),
                'kind' => AiInsight::KIND_DOCUMENT_ANALYSIS,
                'content' => ['summary' => $summary],
            ]);
            $this->travel(1)->minutes();
        }

        AiInsight::query()->create([
            'patient_id' => $this->patient->getKey(),
            'kind' => AiInsight::KIND_PATIENT_ANALYSIS,
            'content' => ['summary' => 'Synthèse'],
        ]);

        $this->getJson(route('app.ai.consultations.document-analyses', $this->consultation))
            ->assertOk()
            ->assertJsonCount(1, 'analyses')
            ->assertJsonPath('analyses.0.content.summary', 'Seconde');
    }

    // ----- patient analysis ---------------------------------------------

    public function test_patient_analysis_normalises_risks(): void
    {
        $risks = [['label' => 'HTA', 'level' => 'élevé', 'reason' => 'TA 16/10'], ['label' => 'Diabète', 'level' => 'critique']];

        foreach (range(1, 10) as $i) {
            $risks[] = ['label' => 'Risque '.$i];
        }

        $this->fakeAiReply(['summary' => 'Patiente suivie', 'risks' => $risks]);

        $content = $this->hit('patient')->assertOk()->json('analysis.content');

        $this->assertCount(8, $content['risks']);
        $this->assertSame('élevé', $content['risks'][0]['level']);
        $this->assertSame('modéré', $content['risks'][1]['level']);
        $this->assertSame('', $content['risks'][2]['reason']);
    }

    public function test_the_last_patient_analysis_reopens_for_free(): void
    {
        $this->getJson(route('app.ai.patients.analysis.show', $this->patient))
            ->assertOk()
            ->assertJsonPath('analysis', null);

        Http::fake([self::AI_PROVIDER => Http::sequence()
            ->push(['model' => 'm', 'choices' => [['message' => ['content' => '{"summary":"Première"}']]]])
            ->push(['model' => 'm', 'choices' => [['message' => ['content' => '{"summary":"Seconde"}']]]])]);
        $this->hit('patient')->assertOk()->assertJsonPath('analysis.content.summary', 'Première');
        $this->travel(1)->minutes();
        $this->hit('patient')->assertOk();
        $balance = $this->balance();

        $this->getJson(route('app.ai.patients.analysis.show', $this->patient))
            ->assertOk()
            ->assertJsonPath('analysis.content.summary', 'Seconde');

        $this->assertSame($balance, $this->balance());
    }

    public function test_patient_analysis_reads_the_full_dossier(): void
    {
        foreach (range(1, 10) as $i) {
            Consultation::query()->create([
                'patient_id' => $this->patient->getKey(),
                'consulted_at' => now()->subDays($i),
                'status' => 'completed',
                'motif' => 'Visite numéro '.$i,
            ]);
        }

        $this->fakeAiReply(['summary' => 'ok']);
        $this->hit('patient')->assertOk();

        // The full dossier carries more than the six visits of a short context.
        Http::assertSent(fn (HttpRequest $request): bool => substr_count(json_encode($request['messages'], JSON_UNESCAPED_UNICODE), 'Visite numéro') === 10);
    }

    // ----- status -------------------------------------------------------

    public function test_status_is_free_and_available_to_every_staff_member(): void
    {
        $assistant = $this->makeDoctor($this->cabinet, RoleName::ASSISTANT);
        Http::fake();

        $this->actingAs($assistant)
            ->getJson(route('app.ai.status'))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('balance', 500)
            ->assertJsonStructure(['available', 'enabled', 'balance', 'costs', 'support' => ['phone', 'email'], 'message']);

        Http::assertNothingSent();
    }

    public function test_status_shows_a_disabled_wallet(): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_enabled' => false, 'ai_credits' => 3]);

        $this->getJson(route('app.ai.status'))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('balance', 3);
    }

    // ----- tenancy ------------------------------------------------------

    public function test_another_cabinet_cannot_reach_this_patient_or_visit(): void
    {
        $stranger = $this->makeDoctor($this->makeCabinet('Autre'));
        $this->actingAs($stranger);
        Http::fake();

        $this->postJson(route('app.ai.patients.analysis.store', $this->patient))->assertNotFound();
        $this->getJson(route('app.ai.patients.analysis.show', $this->patient))->assertNotFound();
        $this->postJson(route('app.ai.consultations.exams', $this->consultation))->assertNotFound();
        $this->postJson(route('app.ai.consultations.prescription', $this->consultation))->assertNotFound();
        $this->postJson(route('app.ai.consultations.copilot.store', $this->consultation), ['message' => 'x'])->assertNotFound();
        $this->getJson(route('app.ai.consultations.document-analyses', $this->consultation))->assertNotFound();

        Http::assertNothingSent();
    }

    // ----- uploads reach the analysis -----------------------------------

    public function test_a_document_uploaded_through_storage_fake_is_readable(): void
    {
        $file = UploadedFile::fake()->createWithContent('cr.txt', 'Scanner cérébral normal');
        $path = $file->store('patient-documents/'.$this->patient->getKey());
        $document = Document::query()->create([
            'patient_id' => $this->patient->getKey(),
            'category' => 'uploaded',
            'title' => 'Scanner',
            'file_path' => $path,
            'original_filename' => 'cr.txt',
            'mime_type' => 'text/plain',
        ]);
        $this->fakeAiReply(['summary' => 'Normal']);

        $this->postJson(route('app.ai.consultations.documents.analysis', [$this->consultation, $document]))
            ->assertOk()
            ->assertJsonPath('analysis.content.summary', 'Normal');
    }
}
