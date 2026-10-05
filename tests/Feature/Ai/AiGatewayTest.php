<?php

namespace Tests\Feature\Ai;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Models\LandingSetting;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use App\Services\Sync\MobileSyncSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The single door of every AI feature: direct (key held here) or relay
 * (local desktop forwarding to the hosted service).
 */
class AiGatewayTest extends TestCase
{
    use InteractsWithAi;
    use RefreshDatabase;

    private const HOSTED = 'https://hosted.test';

    private Cabinet $cabinet;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->configureDirectAi();
        $this->cabinet = $this->makeCabinet();
        $this->doctor = $this->makeDoctor($this->cabinet);
    }

    private function gateway(): AiGateway
    {
        return app(AiGateway::class);
    }

    private function useRelay(?int $cabinetId = null): void
    {
        config(['ai.api_key' => '']);
        app(MobileSyncSettings::class)->configure(self::HOSTED, 'sync-token', cabinetId: $cabinetId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Bonjour']];
    }

    private function catch(callable $call): AiException
    {
        try {
            $call();
        } catch (AiException $exception) {
            return $exception;
        }

        $this->fail('An AiException was expected.');
    }

    // ----- mode() -------------------------------------------------------

    public function test_a_server_with_a_key_answers_directly(): void
    {
        $this->assertSame('direct', $this->gateway()->mode($this->doctor));
        $this->assertSame('direct', $this->gateway()->mode());
    }

    public function test_the_key_wins_over_a_configured_relay(): void
    {
        app(MobileSyncSettings::class)->configure(self::HOSTED, 'sync-token');

        $this->assertSame('direct', $this->gateway()->mode($this->doctor));
    }

    public function test_a_linked_desktop_without_key_relays(): void
    {
        $this->useRelay();

        $this->assertSame('relay', $this->gateway()->mode($this->doctor));
        $this->assertSame('relay', $this->gateway()->mode());
    }

    public function test_a_desktop_linked_for_this_cabinet_relays_for_its_users_only(): void
    {
        $this->useRelay((int) $this->cabinet->getKey());
        $other = $this->makeDoctor($this->makeCabinet('Autre'));

        $this->assertSame('relay', $this->gateway()->mode($this->doctor));
        $this->assertNull($this->gateway()->mode($other));
    }

    public function test_an_unlinked_desktop_has_no_ai(): void
    {
        config(['ai.api_key' => '']);

        $this->assertNull($this->gateway()->mode($this->doctor));
    }

    public function test_an_endpoint_without_a_token_has_no_ai(): void
    {
        $this->useRelay();
        app(MobileSyncSettings::class)->forget();

        $this->assertNull($this->gateway()->mode($this->doctor));
    }

    // ----- complete() ---------------------------------------------------

    public function test_a_direct_call_charges_this_cabinet_and_uses_the_feature_model(): void
    {
        $this->fakeAiReply(['ok' => true]);

        $completion = $this->gateway()->complete($this->doctor, AiFeature::ECG_CHAT, $this->messages(), vision: true, json: false);

        $this->assertSame(499, $completion->balance);
        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'ecg-model'
            && ! array_key_exists('response_format', $request->data()));
    }

    public function test_a_direct_call_for_a_user_without_cabinet_is_unavailable(): void
    {
        $orphan = User::factory()->create(['cabinet_id' => null, 'approved_at' => now()]);
        Http::fake();

        $exception = $this->catch(fn () => $this->gateway()->complete($orphan, AiFeature::CONSULTATION_TEXT, $this->messages()));

        $this->assertSame(AiException::UNAVAILABLE, $exception->reason);
        Http::assertNothingSent();
    }

    public function test_without_any_route_the_doctor_is_told_to_link_the_desktop(): void
    {
        config(['ai.api_key' => '']);
        Http::fake();

        $exception = $this->catch(fn () => $this->gateway()->complete($this->doctor, AiFeature::CONSULTATION_TEXT, $this->messages()));

        $this->assertSame(AiException::UNAVAILABLE, $exception->reason);
        $this->assertStringContainsString('Service en ligne', $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_a_relay_sends_the_feature_and_flags_and_returns_the_hosted_balance(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/api/v1/ai/complete' => Http::response(['content' => 'Réponse', 'model' => 'hosted-model', 'balance' => 77])]);

        $completion = $this->gateway()->complete($this->doctor, AiFeature::DOCUMENT_ANALYSIS, $this->messages(), vision: true, json: false);

        $this->assertSame('Réponse', $completion->content);
        $this->assertSame('hosted-model', $completion->model);
        $this->assertSame(77, $completion->balance);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::HOSTED.'/api/v1/ai/complete'
            && $request->hasHeader('Authorization', 'Bearer sync-token')
            && $request['feature'] === 'document_analysis'
            && $request['vision'] === true
            && $request['json'] === false
            && $request['messages'] === [['role' => 'user', 'content' => 'Bonjour']]);
        // The local copy of the wallet is never charged.
        $this->assertSame(500, $this->cabinet->fresh()->ai_credits);
        $this->assertSame(0, AiUsage::query()->count());
    }

    public function test_a_relay_reply_without_balance_or_model_is_still_usable(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response(['content' => '{}'])]);

        $completion = $this->gateway()->complete($this->doctor, AiFeature::CONSULTATION_TEXT, $this->messages());

        $this->assertNull($completion->balance);
        $this->assertSame('', $completion->model);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unreadableRelayBodies(): array
    {
        return [
            'no content' => [['model' => 'x']],
            'array content' => [['content' => ['a' => 1]]],
            'null content' => [['content' => null]],
            'empty body' => [[]],
        ];
    }

    #[DataProvider('unreadableRelayBodies')]
    public function test_an_unreadable_relay_reply_is_a_provider_error(mixed $body): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response($body)]);

        $this->assertSame(AiException::PROVIDER, $this->catch(fn () => $this->gateway()->complete($this->doctor, AiFeature::CONSULTATION_TEXT, $this->messages()))->reason);
    }

    public function test_the_hosted_refusal_reason_and_balance_reach_the_doctor(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response([
            'message' => 'Crédits IA insuffisants : cette action coûte 5 crédits et il vous en reste 2.',
            'reason' => 'insufficient_credits',
            'balance' => 2,
        ], 402)]);

        $exception = $this->catch(fn () => $this->gateway()->complete($this->doctor, AiFeature::PATIENT_ANALYSIS, $this->messages()));

        $this->assertSame(AiException::INSUFFICIENT_CREDITS, $exception->reason);
        $this->assertSame(2, $exception->balance);
        $this->assertSame(402, $exception->httpStatus());
        $this->assertStringContainsString('il vous en reste 2', $exception->getMessage());
    }

    public function test_a_hosted_failure_without_details_is_a_generic_provider_error(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response('Bad gateway', 502)]);

        $exception = $this->catch(fn () => $this->gateway()->complete($this->doctor, AiFeature::CONSULTATION_TEXT, $this->messages()));

        $this->assertSame(AiException::PROVIDER, $exception->reason);
        $this->assertNull($exception->balance);
        $this->assertSame('Le service IA n’a pas pu répondre.', $exception->getMessage());
    }

    public function test_a_revoked_token_is_forgotten_so_the_desktop_can_be_linked_again(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $exception = $this->catch(fn () => $this->gateway()->complete($this->doctor, AiFeature::CONSULTATION_TEXT, $this->messages()));

        $this->assertSame(AiException::UNAVAILABLE, $exception->reason);
        $this->assertStringContainsString('expiré', $exception->getMessage());
        $this->assertNull(app(MobileSyncSettings::class)->token());
        $this->assertNull($this->gateway()->mode($this->doctor));
    }

    public function test_a_relay_that_cannot_connect_needs_internet(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => fn () => throw new ConnectionException('cURL error 6')]);

        $exception = $this->catch(fn () => $this->gateway()->complete($this->doctor, AiFeature::CONSULTATION_TEXT, $this->messages()));

        $this->assertSame(AiException::UNAVAILABLE, $exception->reason);
        $this->assertStringContainsString('Internet', $exception->getMessage());
        // The token stays: a network failure is not a revocation.
        $this->assertSame('sync-token', app(MobileSyncSettings::class)->token());
    }

    // ----- status() -----------------------------------------------------

    public function test_direct_status_reports_the_local_wallet(): void
    {
        Cabinet::query()->whereKey($this->cabinet->getKey())->update(['ai_credits' => 120, 'ai_enabled' => false]);

        $status = $this->gateway()->status($this->doctor);

        $this->assertTrue($status['available']);
        $this->assertFalse($status['enabled']);
        $this->assertSame(120, $status['balance']);
        $this->assertSame(AiFeature::costs(), $status['costs']);
        $this->assertNull($status['message']);
        $this->assertArrayHasKey('phone', $status['support']);
        $this->assertArrayHasKey('email', $status['support']);
    }

    public function test_direct_status_for_a_user_without_cabinet_explains_why(): void
    {
        $orphan = User::factory()->create(['cabinet_id' => null, 'approved_at' => now()]);

        $status = $this->gateway()->status($orphan);

        $this->assertFalse($status['available']);
        $this->assertNull($status['balance']);
        $this->assertStringContainsString('cabinet', (string) $status['message']);
    }

    public function test_status_without_any_route_says_the_desktop_is_not_connected(): void
    {
        config(['ai.api_key' => '']);

        $status = $this->gateway()->status($this->doctor);

        $this->assertFalse($status['available']);
        $this->assertTrue($status['enabled']);
        $this->assertNull($status['balance']);
        $this->assertSame('L’assistant IA n’est pas encore connecté sur ce poste.', $status['message']);
    }

    public function test_relay_status_reads_the_hosted_wallet(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/api/v1/ai/status' => Http::response([
            'available' => true,
            'enabled' => true,
            'balance' => '321',
            'costs' => ['patient_analysis' => '9'],
            'support' => ['phone' => '0550', 'email' => 'support@test'],
        ])]);

        $status = $this->gateway()->status($this->doctor);

        $this->assertTrue($status['available']);
        $this->assertSame(321, $status['balance']);
        $this->assertSame(['patient_analysis' => 9], $status['costs']);
        $this->assertSame(['phone' => '0550', 'email' => 'support@test'], $status['support']);
        $this->assertNull($status['message']);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer sync-token'));
    }

    public function test_relay_status_tolerates_a_sparse_hosted_answer(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response(['costs' => 'n/a', 'support' => null])]);

        $status = $this->gateway()->status($this->doctor);

        $this->assertFalse($status['available']);
        $this->assertTrue($status['enabled']);
        $this->assertNull($status['balance']);
        $this->assertSame(AiFeature::costs(), $status['costs']);
        $this->assertArrayHasKey('phone', $status['support']);
    }

    public function test_relay_status_offline_carries_a_message_instead_of_failing(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => fn () => throw new ConnectionException('offline')]);

        $status = $this->gateway()->status($this->doctor);

        $this->assertFalse($status['available']);
        $this->assertStringContainsString('Internet', (string) $status['message']);
    }

    public function test_relay_status_with_a_revoked_token_forgets_it(): void
    {
        $this->useRelay();
        Http::fake([self::HOSTED.'/*' => Http::response([], 401)]);

        $status = $this->gateway()->status($this->doctor);

        $this->assertFalse($status['available']);
        $this->assertStringContainsString('expiré', (string) $status['message']);
        $this->assertNull(app(MobileSyncSettings::class)->token());
    }

    // ----- support() / cabinetOf() --------------------------------------

    public function test_support_contacts_are_read_and_trimmed(): void
    {
        LandingSetting::query()->updateOrCreate(['key' => 'contact_phone'], ['locale' => LandingSetting::ALL_LOCALES, 'value' => '  0555 00 00 00 ']);
        LandingSetting::query()->updateOrCreate(['key' => 'contact_email'], ['locale' => LandingSetting::ALL_LOCALES, 'value' => '   ']);

        $this->assertSame(['phone' => '0555 00 00 00', 'email' => null], $this->gateway()->support());
    }

    public function test_support_without_settings_is_empty(): void
    {
        LandingSetting::query()->delete();

        $this->assertSame(['phone' => null, 'email' => null], $this->gateway()->support());
    }

    public function test_cabinet_of_returns_the_users_cabinet(): void
    {
        $this->assertTrue($this->gateway()->cabinetOf($this->doctor)->is($this->cabinet));
    }

    public function test_cabinet_of_a_user_whose_cabinet_is_gone_is_unavailable(): void
    {
        $ghost = new User(['name' => 'X']);
        $ghost->cabinet_id = 999999;

        $this->assertSame(AiException::UNAVAILABLE, $this->catch(fn () => $this->gateway()->cabinetOf($ghost))->reason);
    }
}
