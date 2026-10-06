<?php

namespace Tests\Feature\Ai;

use App\Enums\RoleName;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Models\Consultation;
use App\Models\User;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * Voice dictation recorded in segments by the desktop app, whose web view has
 * no working browser speech recognition: each segment is turned into text by
 * the speech model, directly or through the hosted relay.
 */
class DictationTranscriptionTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private const HOSTED = 'https://hosted.test';

    private Cabinet $cabinet;

    private User $doctor;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->configureDirectAi();
        config(['ai.transcription_model' => 'asr-model', 'ai.transcription_driver' => 'chat_input_audio']);
        [$this->cabinet, $this->doctor, $this->consultation] = $this->aiConsultation();
    }

    private function audio(string $name = 'dictee.webm', string $content = 'fake-webm-audio'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function transcribe(?UploadedFile $audio = null): TestResponse
    {
        return $this->post(
            route('app.ai.consultations.dictation.transcribe', $this->consultation),
            $audio === null ? [] : ['audio' => $audio],
            ['Accept' => 'application/json'],
        );
    }

    private function fakeTranscript(string $text): void
    {
        Http::fake([
            self::AI_PROVIDER => Http::response([
                'model' => 'asr-model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 15],
            ]),
        ]);
    }

    private function balance(): int
    {
        return (int) Cabinet::query()->whereKey($this->cabinet->getKey())->value('ai_credits');
    }

    private function useRelay(): void
    {
        config(['ai.api_key' => '']);
        app(MobileSyncSettings::class)->configure(self::HOSTED, 'sync-token');
    }

    // ----- direct ---------------------------------------------------------

    public function test_a_segment_is_transcribed_with_the_speech_model(): void
    {
        $this->fakeTranscript('  Patient de 54 ans, douleur thoracique depuis 2 jours. ');

        $this->transcribe($this->audio())
            ->assertOk()
            ->assertJsonPath('text', 'Patient de 54 ans, douleur thoracique depuis 2 jours.');

        Http::assertSent(function (HttpRequest $request): bool {
            $part = $request['messages'][0]['content'][0] ?? [];

            return $request->url() === self::AI_PROVIDER
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'asr-model'
                && $request['stream'] === false
                && $request['asr_options'] === ['language' => 'fr', 'enable_itn' => true]
                && $request['messages'][0]['role'] === 'user'
                && ($part['type'] ?? null) === 'input_audio'
                && $part['input_audio']['data'] === 'data:audio/webm;base64,'.base64_encode('fake-webm-audio');
        });
    }

    public function test_transcription_is_free_but_logged(): void
    {
        $this->fakeTranscript('Bonjour');

        $this->transcribe($this->audio())->assertOk()->assertJsonPath('balance', 500);

        $this->assertSame(500, $this->balance());
        $line = AiUsage::query()->sole();
        $this->assertSame('dictation_transcription', $line->feature);
        $this->assertSame(0, (int) $line->credits);
        $this->assertSame((int) $this->doctor->getKey(), (int) $line->user_id);
    }

    public function test_a_silent_segment_answers_an_empty_text(): void
    {
        $this->fakeTranscript('');

        $this->transcribe($this->audio())->assertOk()->assertJsonPath('text', '');
    }

    public function test_ogg_and_mp4_recordings_are_announced_as_audio(): void
    {
        $this->fakeTranscript('ok');

        $this->transcribe($this->audio('dictee.ogg'))->assertOk();
        $this->transcribe($this->audio('dictee.m4a'))->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request['messages'][0]['content'][0]['input_audio']['data'], 'data:audio/ogg;base64,'));
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request['messages'][0]['content'][0]['input_audio']['data'], 'data:audio/mp4;base64,'));
    }

    public function test_the_openai_transcriptions_driver_uploads_the_file(): void
    {
        config([
            'ai.transcription_driver' => 'openai_transcriptions',
            'ai.transcription_base_url' => 'https://speech.test/v1',
            'ai.transcription_api_key' => 'speech-key',
            'ai.transcription_model' => 'whisper-1',
        ]);
        Http::fake(['https://speech.test/v1/audio/transcriptions' => Http::response(['text' => 'Tension 12/8'])]);

        $this->transcribe($this->audio())->assertOk()->assertJsonPath('text', 'Tension 12/8');

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://speech.test/v1/audio/transcriptions'
            && $request->isMultipart()
            && $request->hasHeader('Authorization', 'Bearer speech-key')
            && $request->hasFile('file', 'fake-webm-audio', 'dictee.webm')
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'model' && $part['contents'] === 'whisper-1'));
    }

    public function test_a_provider_failure_is_reported_in_french(): void
    {
        $this->fakeAiFailure(500);

        $this->transcribe($this->audio())
            ->assertStatus(502)
            ->assertJsonPath('reason', 'provider_error')
            ->assertJsonPath('message', 'La transcription de la dictée a échoué. Réessayez ou tapez vos notes.');
    }

    public function test_an_unreachable_provider_is_reported_as_unavailable(): void
    {
        Http::fake([self::AI_PROVIDER => fn () => throw new ConnectionException('cURL error 28')]);

        $this->transcribe($this->audio())->assertStatus(503)->assertJsonPath('reason', 'unavailable');
    }

    public function test_a_disabled_cabinet_is_refused_without_calling_the_provider(): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_enabled' => false]);
        Http::fake();

        $this->transcribe($this->audio())->assertStatus(403)->assertJsonPath('reason', 'disabled');

        Http::assertNothingSent();
    }

    public function test_a_cabinet_without_credits_can_still_dictate(): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_credits' => 0]);
        $this->fakeTranscript('Toux');

        $this->transcribe($this->audio())->assertOk()->assertJsonPath('text', 'Toux');
    }

    // ----- validation & access ------------------------------------------

    public function test_the_audio_is_required(): void
    {
        Http::fake();

        $this->transcribe()->assertStatus(422)->assertJsonValidationErrors(['audio' => 'Aucun enregistrement audio']);

        Http::assertNothingSent();
    }

    public function test_a_non_audio_file_is_refused(): void
    {
        Http::fake();

        $this->transcribe(UploadedFile::fake()->createWithContent('notes.txt', 'texte'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio' => 'Format audio non pris en charge']);

        Http::assertNothingSent();
    }

    public function test_a_segment_over_ten_megabytes_is_refused(): void
    {
        Http::fake();

        $this->transcribe(UploadedFile::fake()->create('long.webm', 10 * 1024 + 1, 'audio/webm'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio' => '10 Mo']);

        Http::assertNothingSent();
    }

    public function test_an_assistant_without_clinical_rights_is_refused(): void
    {
        $this->actingAs($this->makeDoctor($this->cabinet, RoleName::ASSISTANT));
        Http::fake();

        $this->transcribe($this->audio())->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->app['auth']->forgetGuards();
        Http::fake();

        $this->assertContains($this->transcribe($this->audio())->status(), [401, 302, 403]);

        Http::assertNothingSent();
    }

    public function test_an_unknown_consultation_is_not_found(): void
    {
        Http::fake();

        $this->post(route('app.ai.consultations.dictation.transcribe', 999999), ['audio' => $this->audio()], ['Accept' => 'application/json'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    // ----- relay (desktop without key) ----------------------------------

    public function test_a_desktop_relays_the_segment_to_the_hosted_service(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/api/v1/ai/transcribe' => Http::response(['text' => 'Fièvre à 39', 'model' => 'asr', 'balance' => 42])]);

        $this->transcribe($this->audio())
            ->assertOk()
            ->assertJsonPath('text', 'Fièvre à 39')
            ->assertJsonPath('balance', 42);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::HOSTED.'/api/v1/ai/transcribe'
            && $request->method() === 'POST'
            && $request->isMultipart()
            && $request->hasHeader('Authorization', 'Bearer sync-token')
            && $request->hasFile('audio', 'fake-webm-audio', 'dictee.webm')
            && ! str_contains($request->header('Content-Type')[0] ?? '', 'application/json'));
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_an_offline_desktop_suggests_windows_dictation(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => fn () => throw new ConnectionException('cURL error 6')]);

        $response = $this->transcribe($this->audio())->assertStatus(503)->assertJsonPath('reason', 'unavailable');

        $this->assertStringContainsString('Windows + H', (string) $response->json('message'));
    }

    public function test_a_hosted_refusal_is_passed_on(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response(['message' => 'L’assistant IA est désactivé pour ce cabinet.', 'reason' => 'disabled', 'balance' => 3], 403)]);

        $this->transcribe($this->audio())
            ->assertStatus(403)
            ->assertJsonPath('reason', 'disabled')
            ->assertJsonPath('balance', 3);
    }

    public function test_an_unlinked_desktop_suggests_windows_dictation(): void
    {
        config(['ai.api_key' => '']);
        Http::fake();

        $response = $this->transcribe($this->audio())->assertStatus(503);

        $this->assertStringContainsString('Windows + H', (string) $response->json('message'));
        Http::assertNothingSent();
    }

    // ----- hosted relay endpoint ----------------------------------------

    public function test_the_hosted_endpoint_transcribes_for_the_token_owner(): void
    {
        Sanctum::actingAs($this->doctor);
        $this->fakeTranscript('Auscultation normale');

        $this->post('/api/v1/ai/transcribe', ['audio' => $this->audio()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('text', 'Auscultation normale')
            ->assertJsonPath('model', 'asr-model')
            ->assertJsonPath('balance', 500);

        $this->assertSame('dictation_transcription', AiUsage::query()->sole()->feature);
    }

    public function test_the_hosted_endpoint_requires_a_token(): void
    {
        $this->app['auth']->forgetGuards();
        Http::fake();

        $this->post('/api/v1/ai/transcribe', ['audio' => $this->audio()], ['Accept' => 'application/json'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_the_hosted_endpoint_validates_the_audio(): void
    {
        Sanctum::actingAs($this->doctor);
        Http::fake();

        $this->post('/api/v1/ai/transcribe', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('audio');
        $this->post('/api/v1/ai/transcribe', ['audio' => UploadedFile::fake()->createWithContent('x.txt', 'x')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_hosted_service_without_a_key_never_relays_onward(): void
    {
        Sanctum::actingAs($this->doctor);
        config(['ai.api_key' => '']);
        app(MobileSyncSettings::class)->configure(self::HOSTED, 'sync-token');
        Http::fake();

        $this->post('/api/v1/ai/transcribe', ['audio' => $this->audio()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'unavailable');

        Http::assertNothingSent();
    }

    public function test_the_free_transcription_feature_cannot_be_used_as_a_chat_completion(): void
    {
        Sanctum::actingAs($this->doctor);
        Http::fake();

        $this->postJson('/api/v1/ai/complete', [
            'feature' => 'dictation_transcription',
            'messages' => [['role' => 'user', 'content' => 'Écris-moi un roman']],
        ])->assertStatus(422)->assertJsonValidationErrors('feature');

        Http::assertNothingSent();
    }
}
