<?php

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The hosted side of AI for local desktops (`/api/v1/ai/*`): it charges the
 * token owner's wallet and refuses requests that do not look like the action
 * they are billed as.
 */
class AiRelayApiTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->configureDirectAi();
        $this->cabinet = $this->makeCabinet();
        $this->doctor = $this->makeDoctor($this->cabinet);
        Sanctum::actingAs($this->doctor);
    }

    private function complete(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/ai/complete', $payload);
    }

    private function balance(): int
    {
        return (int) Cabinet::query()->whereKey($this->cabinet->getKey())->value('ai_credits');
    }

    private function image(int $bytes = 3): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.base64_encode(str_repeat('x', $bytes))]];
    }

    // ----- status -------------------------------------------------------

    public function test_status_reports_the_token_owners_wallet(): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_credits' => 42]);

        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('balance', 42)
            ->assertJsonPath('costs', AiFeature::costs())
            ->assertJsonStructure(['support' => ['phone', 'email']]);
    }

    public function test_status_says_unavailable_when_the_hosted_service_has_no_key(): void
    {
        config(['ai.api_key' => '']);

        $this->getJson('/api/v1/ai/status')->assertOk()->assertJsonPath('available', false);
    }

    public function test_status_for_an_account_without_cabinet_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(['cabinet_id' => null, 'approved_at' => now()]));

        $this->getJson('/api/v1/ai/status')
            ->assertForbidden()
            ->assertJsonPath('reason', 'unavailable');
    }

    public function test_the_relay_requires_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/ai/status')->assertUnauthorized();
        $this->postJson('/api/v1/ai/complete', ['feature' => 'copilot_chat', 'messages' => [['role' => 'user', 'content' => 'x']]])->assertUnauthorized();
    }

    // ----- complete -----------------------------------------------------

    public function test_a_text_request_is_charged_and_answered(): void
    {
        $this->fakeAiReply('{"reply":"ok"}', 'hosted-model');

        $this->complete(['feature' => 'copilot_chat', 'messages' => [['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => 'Q']]])
            ->assertOk()
            ->assertExactJson(['content' => '{"reply":"ok"}', 'model' => 'hosted-model', 'balance' => 499]);

        $this->assertSame(499, $this->balance());
        $line = AiUsage::query()->sole();
        $this->assertSame('copilot_chat', $line->feature);
        $this->assertSame($this->doctor->getKey(), $line->user_id);
    }

    public function test_the_hosted_side_chooses_the_model_not_the_desktop(): void
    {
        $this->fakeAiReply('ok');

        $this->complete(['feature' => 'ecg_chat', 'vision' => true, 'json' => false, 'model' => 'expensive-model', 'messages' => [
            ['role' => 'user', 'content' => [$this->image(), ['type' => 'text', 'text' => 'Bloc ?']]],
        ]])->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'ecg-model'
            && ! array_key_exists('response_format', $request->data()));
    }

    public function test_json_mode_is_the_default(): void
    {
        $this->fakeAiReply(['ok' => true]);

        $this->complete(['feature' => 'patient_analysis', 'messages' => [['role' => 'user', 'content' => 'x']]])->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => $request['response_format'] === ['type' => 'json_object']);
    }

    public function test_a_document_photo_is_accepted_for_document_analysis(): void
    {
        $this->fakeAiReply(['summary' => 'ok']);

        $this->complete(['feature' => 'document_analysis', 'vision' => true, 'messages' => [
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => [$this->image(), ['type' => 'text', 'text' => 'Analyse']]],
        ]])->assertOk();

        $this->assertSame(500 - AiFeature::DOCUMENT_ANALYSIS->cost(), $this->balance());
        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'vision-model');
    }

    public function test_an_image_at_the_size_limit_is_accepted(): void
    {
        config(['ai.max_image_bytes' => 300]);
        $this->fakeAiReply('ok');

        $this->complete(['feature' => 'ecg_analysis', 'vision' => true, 'messages' => [
            ['role' => 'user', 'content' => [$this->image(300)]],
        ]])->assertOk();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRequests(): array
    {
        $text = [['role' => 'user', 'content' => 'x']];

        return [
            'no feature' => [['messages' => $text], 'feature'],
            'unknown feature' => [['feature' => 'admin_adjustment', 'messages' => $text], 'feature'],
            'no messages' => [['feature' => 'copilot_chat'], 'messages'],
            'empty messages' => [['feature' => 'copilot_chat', 'messages' => []], 'messages'],
            'too many messages' => [['feature' => 'copilot_chat', 'messages' => array_fill(0, 41, ['role' => 'user', 'content' => 'x'])], 'messages'],
            'unknown role' => [['feature' => 'copilot_chat', 'messages' => [['role' => 'tool', 'content' => 'x']]], 'messages.0.role'],
            'missing content' => [['feature' => 'copilot_chat', 'messages' => [['role' => 'user']]], 'messages.0.content'],
            'vision not a boolean' => [['feature' => 'ecg_chat', 'vision' => 'yes', 'messages' => $text], 'vision'],
            'json not a boolean' => [['feature' => 'copilot_chat', 'json' => 'no', 'messages' => $text], 'json'],
            'vision for a text action' => [['feature' => 'patient_analysis', 'vision' => true, 'messages' => $text], 'vision'],
            'structured content without vision' => [['feature' => 'ecg_chat', 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]]]], 'messages'],
            'numeric content' => [['feature' => 'copilot_chat', 'messages' => [['role' => 'user', 'content' => 42]]], 'messages'],
            'unknown part type' => [['feature' => 'ecg_chat', 'vision' => true, 'messages' => [['role' => 'user', 'content' => [['type' => 'audio', 'url' => 'x']]]]], 'messages'],
            'image without url' => [['feature' => 'ecg_chat', 'vision' => true, 'messages' => [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => []]]]]], 'messages'],
            'text part without text' => [['feature' => 'ecg_chat', 'vision' => true, 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => ['x']]]]]], 'messages'],
            'two images in two messages' => [['feature' => 'ecg_analysis', 'vision' => true, 'messages' => [
                ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,eA==']]]],
                ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,eA==']]]],
            ]], 'messages'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidRequests')]
    public function test_a_malformed_request_is_refused_for_free(array $payload, string $error): void
    {
        Http::fake();

        $this->complete($payload)->assertUnprocessable()->assertJsonValidationErrors($error);

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_an_image_over_the_limit_is_refused(): void
    {
        config(['ai.max_image_bytes' => 300]);
        Http::fake();

        $this->complete(['feature' => 'ecg_analysis', 'vision' => true, 'messages' => [
            ['role' => 'user', 'content' => [$this->image(400)]],
        ]])->assertUnprocessable()->assertJsonValidationErrors('messages');

        Http::assertNothingSent();
    }

    public function test_forty_messages_are_accepted(): void
    {
        $this->fakeAiReply('ok');

        $this->complete(['feature' => 'copilot_chat', 'messages' => array_fill(0, 40, ['role' => 'user', 'content' => 'x'])])->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function walletStates(): array
    {
        return [
            'empty wallet' => ['empty', 402, 'insufficient_credits'],
            'disabled cabinet' => ['disabled', 403, 'disabled'],
        ];
    }

    #[DataProvider('walletStates')]
    public function test_a_refused_wallet_tells_the_desktop_why(string $state, int $status, string $reason): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update($state === 'empty' ? ['ai_credits' => 0] : ['ai_enabled' => false]);
        Http::fake();

        $this->complete(['feature' => 'copilot_chat', 'messages' => [['role' => 'user', 'content' => 'x']]])
            ->assertStatus($status)
            ->assertJsonPath('reason', $reason)
            ->assertJsonStructure(['message', 'reason', 'balance']);

        Http::assertNothingSent();
    }

    public function test_a_provider_failure_is_refunded_and_reported_as_a_bad_gateway(): void
    {
        $this->fakeAiFailure(500);

        $this->complete(['feature' => 'patient_analysis', 'messages' => [['role' => 'user', 'content' => 'x']]])
            ->assertStatus(502)
            ->assertJsonPath('reason', 'provider_error');

        $this->assertSame(500, $this->balance());
    }

    public function test_the_hosted_service_without_a_key_never_relays_onward(): void
    {
        config(['ai.api_key' => '']);
        Http::fake();

        $this->complete(['feature' => 'copilot_chat', 'messages' => [['role' => 'user', 'content' => 'x']]])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'unavailable');

        Http::assertNothingSent();
        $this->assertSame(500, $this->balance());
    }

    public function test_an_account_without_cabinet_cannot_spend(): void
    {
        Sanctum::actingAs(User::factory()->create(['cabinet_id' => null, 'approved_at' => now()]));
        Http::fake();

        $this->complete(['feature' => 'copilot_chat', 'messages' => [['role' => 'user', 'content' => 'x']]])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'unavailable');

        Http::assertNothingSent();
    }

    public function test_each_desktop_spends_its_own_cabinets_credits(): void
    {
        $otherCabinet = $this->makeCabinet('Autre');
        $otherDoctor = $this->makeDoctor($otherCabinet);
        $this->fakeAiReply('ok');

        $this->complete(['feature' => 'patient_analysis', 'messages' => [['role' => 'user', 'content' => 'x']]])->assertOk();
        Sanctum::actingAs($otherDoctor);
        $this->complete(['feature' => 'copilot_chat', 'messages' => [['role' => 'user', 'content' => 'x']]])->assertOk();

        $this->assertSame(495, $this->balance());
        $this->assertSame(499, (int) Cabinet::query()->whereKey($otherCabinet->getKey())->value('ai_credits'));
    }
}
