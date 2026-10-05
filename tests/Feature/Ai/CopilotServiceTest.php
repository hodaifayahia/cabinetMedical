<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\Exam;
use App\Models\Medication;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The consultation copilot: it only proposes actions, keeps an audit trail,
 * and drops anything malformed the model invents.
 */
class CopilotServiceTest extends TestCase
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
        $this->configureDirectAi();
        [$this->cabinet, $this->doctor, $this->consultation] = $this->aiConsultation(['allergies' => 'Pénicilline']);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function actionsFor(array $actions): array
    {
        $this->fakeAiReply(['reply' => 'Voici.', 'sources' => [], 'actions' => $actions]);

        return $this->ask('Propose')->assertOk()->json('message.actions');
    }

    private function ask(string $message, array $extra = []): TestResponse
    {
        return $this->postJson(route('app.ai.consultations.copilot.store', $this->consultation), ['message' => $message, ...$extra]);
    }

    public function test_set_field_accepts_only_the_visit_fields(): void
    {
        $actions = $this->actionsFor([
            ['type' => 'set_field', 'field' => 'motif', 'text' => 'Toux'],
            ['type' => 'set_field', 'field' => 'examens', 'text' => 'Auscultation', 'mode' => 'append'],
            ['type' => 'set_field', 'field' => 'diagnostic', 'text' => 'Bronchite', 'mode' => 'weird'],
            ['type' => 'set_field', 'field' => 'traitement', 'text' => 'Repos'],
            ['type' => 'set_field', 'field' => 'notes', 'text' => 'Revoir'],
            ['type' => 'set_field', 'field' => 'patient_id', 'text' => '99'],
            ['type' => 'set_field', 'field' => 'motif', 'text' => '   '],
            ['type' => 'set_field', 'text' => 'Sans champ'],
        ]);

        $this->assertSame(['motif', 'examens', 'diagnostic', 'traitement', 'notes'], array_column($actions, 'field'));
        $this->assertSame(['replace', 'append', 'replace', 'replace', 'replace'], array_column($actions, 'mode'));
    }

    public function test_long_texts_are_capped(): void
    {
        $actions = $this->actionsFor([
            ['type' => 'set_field', 'field' => 'notes', 'text' => str_repeat('a', 6000)],
            ['type' => 'patient_advice', 'text' => str_repeat('b', 3000)],
        ]);

        $this->assertSame(5000, mb_strlen($actions[0]['text']));
        $this->assertSame(2000, mb_strlen($actions[1]['text']));
    }

    public function test_exams_are_matched_to_the_active_catalogue(): void
    {
        $crp = Exam::query()->create(['name' => 'CRP', 'category' => 'biologie', 'is_active' => true]);
        Exam::query()->create(['name' => 'Ferritine', 'category' => 'biologie', 'is_active' => false]);

        $actions = $this->actionsFor([
            ['type' => 'add_exam', 'name' => 'crp', 'reason' => 'Inflammation'],
            ['type' => 'add_exam', 'name' => 'Ferritine'],
            ['type' => 'add_exam', 'name' => ''],
        ]);

        $this->assertSame([
            ['type' => 'add_exam', 'name' => 'CRP', 'exam_id' => $crp->getKey(), 'reason' => 'Inflammation'],
            ['type' => 'add_exam', 'name' => 'Ferritine', 'exam_id' => null, 'reason' => ''],
        ], $actions);
    }

    public function test_medications_are_matched_and_checked_against_allergies(): void
    {
        Medication::query()->create(['name' => 'Amoxicilline 1 g', 'is_active' => true]);

        $actions = $this->actionsFor([
            ['type' => 'add_medication', 'medication' => 'amoxicilline 1 g', 'dosage' => '1 cp x 2', 'duration' => '7 jours', 'instructions' => 'Pendant le repas', 'reason' => 'Angine'],
            ['type' => 'add_medication', 'medication' => 'Paracétamol 1 g', 'dosage' => str_repeat('d', 400)],
        ]);

        $this->assertSame('Amoxicilline 1 g', $actions[0]['medication']);
        $this->assertTrue($actions[0]['in_catalogue']);
        $this->assertNotEmpty($actions[0]['allergy_conflicts']);

        $this->assertSame('Paracétamol 1 g', $actions[1]['medication']);
        $this->assertFalse($actions[1]['in_catalogue']);
        $this->assertSame([], $actions[1]['allergy_conflicts']);
        $this->assertSame(190, mb_strlen($actions[1]['dosage']));
        $this->assertSame('', $actions[1]['duration']);
    }

    public function test_unknown_or_malformed_actions_are_dropped(): void
    {
        $actions = $this->actionsFor([
            null,
            'texte',
            ['type' => 'delete_patient'],
            ['type' => 'patient_advice', 'text' => ''],
            ['type' => 'add_medication', 'medication' => ['x']],
            ['no_type' => true],
            ['type' => 'patient_advice', 'text' => 'Boire de l’eau'],
        ]);

        $this->assertSame([['type' => 'patient_advice', 'text' => 'Boire de l’eau']], $actions);
    }

    public function test_only_the_first_fifteen_actions_are_read(): void
    {
        $actions = $this->actionsFor(array_fill(0, 30, ['type' => 'patient_advice', 'text' => 'Conseil']));

        $this->assertCount(15, $actions);
    }

    public function test_an_empty_reply_is_replaced_by_a_readable_message(): void
    {
        $this->fakeAiReply(['reply' => '   ', 'sources' => 'x', 'actions' => 'y']);

        $this->ask('?')
            ->assertOk()
            ->assertJsonPath('message.reply', 'Je n’ai pas pu formuler de réponse. Reformulez votre demande.')
            ->assertJsonPath('message.sources', [])
            ->assertJsonPath('message.actions', []);
    }

    public function test_at_most_ten_sources_are_kept(): void
    {
        $this->fakeAiReply(['reply' => 'ok', 'sources' => array_map(static fn (int $i): string => 'S'.$i, range(1, 20))]);

        $this->assertCount(10, $this->ask('?')->json('message.sources'));
    }

    public function test_the_conversation_is_replayed_with_its_proposed_actions(): void
    {
        AiConversation::query()->create([
            'patient_id' => $this->consultation->patient_id,
            'consultation_id' => $this->consultation->getKey(),
            'created_by' => $this->doctor->getKey(),
            'messages' => [
                ['role' => 'user', 'content' => 'Première question'],
                ['role' => 'assistant', 'reply' => 'Première réponse', 'actions' => [
                    ['type' => 'set_field', 'field' => 'diagnostic', 'text' => 'x'],
                    ['type' => 'add_exam', 'name' => 'NFS'],
                    ['type' => 'add_medication', 'medication' => 'Doliprane'],
                    ['type' => 'patient_advice', 'text' => 'y'],
                ]],
            ],
        ]);
        $this->fakeAiReply(['reply' => 'Seconde réponse']);

        $this->ask('Seconde question')->assertOk();

        Http::assertSent(function (HttpRequest $request): bool {
            $messages = $request['messages'];
            $count = count($messages);

            return $messages[$count - 1] === ['role' => 'user', 'content' => 'Seconde question']
                && $messages[$count - 3] === ['role' => 'user', 'content' => 'Première question']
                && $messages[$count - 2]['role'] === 'assistant'
                && $messages[$count - 2]['content'] === "Première réponse\n[Actions proposées : remplir diagnostic, examen NFS, médicament Doliprane, conseils patient]";
        });

        $this->getJson(route('app.ai.consultations.copilot.show', $this->consultation))
            ->assertOk()
            ->assertJsonCount(4, 'messages')
            ->assertJsonPath('messages.2.by', $this->doctor->name);
    }

    public function test_only_the_last_twelve_turns_are_replayed(): void
    {
        $turns = [];

        foreach (range(1, 20) as $i) {
            $turns[] = ['role' => 'user', 'content' => 'Q'.$i];
            $turns[] = ['role' => 'assistant', 'reply' => 'R'.$i, 'actions' => []];
        }

        AiConversation::query()->create([
            'patient_id' => $this->consultation->patient_id,
            'consultation_id' => $this->consultation->getKey(),
            'messages' => $turns,
        ]);
        $this->fakeAiReply(['reply' => 'ok']);

        $this->ask('Nouvelle')->assertOk();

        // System + dossier + greeting + 12 replayed turns + the new question.
        Http::assertSent(fn (HttpRequest $request): bool => count($request['messages']) === 16
            && $request['messages'][3] === ['role' => 'user', 'content' => 'Q15']);
    }

    public function test_the_stored_conversation_is_capped_at_eighty_messages(): void
    {
        AiConversation::query()->create([
            'patient_id' => $this->consultation->patient_id,
            'consultation_id' => $this->consultation->getKey(),
            'messages' => array_map(static fn (int $i): array => ['role' => 'user', 'content' => 'M'.$i], range(1, 100)),
        ]);
        $this->fakeAiReply(['reply' => 'ok']);

        $this->ask('Dernière')->assertOk();

        $messages = AiConversation::query()->sole()->messages;
        $this->assertCount(80, $messages);
        $this->assertSame('Dernière', $messages[78]['content']);
        $this->assertSame('assistant', $messages[79]['role']);
    }

    public function test_reset_starts_a_new_conversation_and_keeps_the_old_one(): void
    {
        $this->fakeAiReply(['reply' => 'ok']);
        $this->ask('Bonjour')->assertOk();

        $this->deleteJson(route('app.ai.consultations.copilot.reset', $this->consultation))
            ->assertOk()
            ->assertJsonPath('messages', []);

        $this->getJson(route('app.ai.consultations.copilot.show', $this->consultation))
            ->assertOk()
            ->assertJsonPath('messages', []);
        $this->assertSame(2, AiConversation::query()->count());

        // The next question goes into the new conversation, without history.
        $this->ask('Encore')->assertOk();
        Http::assertSent(fn (HttpRequest $request): bool => count($request['messages']) === 4
            && $request['messages'][3] === ['role' => 'user', 'content' => 'Encore']);
        $this->assertSame(2, AiConversation::query()->count());
        $this->assertSame(['Bonjour', 'Encore'], AiConversation::query()->orderBy('id')->get()
            ->map(fn (AiConversation $conversation): string => $conversation->messages[0]['content'])->all());
    }

    public function test_reset_is_free(): void
    {
        Http::fake();

        $this->deleteJson(route('app.ai.consultations.copilot.reset', $this->consultation))->assertOk();

        Http::assertNothingSent();
        $this->assertSame(500, $this->cabinet->fresh()->ai_credits);
    }

    public function test_the_workspace_in_progress_is_described(): void
    {
        $this->fakeAiReply(['reply' => 'ok']);

        $this->ask('?', ['workspace' => ['exams' => ['NFS', 'CRP'], 'medications' => []]])->assertOk();

        Http::assertSent(function (HttpRequest $request): bool {
            $prompt = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);

            return str_contains($prompt, 'Bilan en préparation : NFS, CRP')
                && str_contains($prompt, 'Ordonnance en préparation : rien');
        });
    }

    public function test_the_message_is_trimmed_before_it_is_kept(): void
    {
        $this->fakeAiReply(['reply' => 'ok']);

        $this->ask('   Question ?   ')->assertOk();

        $this->assertSame('Question ?', AiConversation::query()->sole()->messages[0]['content']);
    }

    public function test_copilot_input_is_validated(): void
    {
        Http::fake();

        $this->ask('')->assertJsonValidationErrors('message');
        $this->ask(str_repeat('a', 2001))->assertJsonValidationErrors('message');
        $this->ask('ok', ['workspace' => ['exams' => array_fill(0, 61, 'x')]])->assertJsonValidationErrors('workspace.exams');
        $this->ask('ok', ['workspace' => ['medications' => array_fill(0, 31, 'x')]])->assertJsonValidationErrors('workspace.medications');
        $this->ask('ok', ['workspace' => ['medications' => [str_repeat('x', 201)]]])->assertJsonValidationErrors('workspace.medications.0');
        $this->postJson(route('app.ai.consultations.copilot.store', $this->consultation), [])->assertJsonValidationErrors('message');

        Http::assertNothingSent();
        $this->assertSame(0, AiConversation::query()->count());
    }

    public function test_a_failed_call_keeps_no_half_conversation(): void
    {
        $this->fakeAiFailure();

        $this->ask('Bonjour')->assertStatus(502);

        $this->assertSame(0, AiConversation::query()->count());
    }
}
