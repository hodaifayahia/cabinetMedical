<?php

namespace Tests\Feature\Sync;

use App\Models\ApplicationSetting;
use App\Services\Sync\MobileSyncClient;
use App\Services\Sync\MobileSyncSettings;
use App\Services\Sync\SyncTransportException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileSyncClientTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = 'https://sync.example.test';

    private const string TOKEN = '12|plain-sanctum-token-value';

    public function test_settings_are_empty_until_configured(): void
    {
        $settings = $this->settings();

        $this->assertNull($settings->endpoint());
        $this->assertNull($settings->token());
        $this->assertFalse($settings->enabled());
        $this->assertFalse($settings->isConfigured());
        $this->assertNull($settings->accountEmail());
        $this->assertNull($settings->cabinetName());
        $this->assertNull($settings->linkedAt());
        $this->assertNull($settings->cabinetId());
        $this->assertTrue($settings->servesCabinet(42));
    }

    public function test_configuring_stores_a_normalized_endpoint_and_an_encrypted_token(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01T10:00:00Z'));

        $this->settings()->configure(' https://sync.example.test/// ', self::TOKEN, 'doc@example.test', 'Cabinet Atlas', 7);

        $settings = $this->settings();
        $this->assertSame(self::ENDPOINT, $settings->endpoint());
        $this->assertSame(self::TOKEN, $settings->token());
        $this->assertTrue($settings->isConfigured());
        $this->assertSame('doc@example.test', $settings->accountEmail());
        $this->assertSame('Cabinet Atlas', $settings->cabinetName());
        $this->assertTrue($settings->linkedAt()->equalTo(CarbonImmutable::parse('2026-09-01T10:00:00Z')));
        $this->assertSame(7, $settings->cabinetId());

        $raw = DB::table('application_settings')->where('key', MobileSyncSettings::KEY_TOKEN)->first();
        $this->assertNull($raw->plain_value);
        $this->assertStringNotContainsString('plain-sanctum-token-value', (string) $raw->encrypted_value);
    }

    public function test_a_link_for_one_cabinet_serves_only_that_cabinet(): void
    {
        $this->settings()->configure(self::ENDPOINT, self::TOKEN, cabinetId: 7);

        $this->assertTrue($this->settings()->servesCabinet(7));
        $this->assertTrue($this->settings()->servesCabinet('7'));
        $this->assertFalse($this->settings()->servesCabinet(8));
        $this->assertFalse($this->settings()->servesCabinet(null));
    }

    public function test_relinking_without_a_cabinet_removes_the_previous_binding(): void
    {
        $this->settings()->configure(self::ENDPOINT, self::TOKEN, cabinetId: 7);
        $this->settings()->configure(self::ENDPOINT, self::TOKEN);

        $this->assertNull($this->settings()->cabinetId());
        $this->assertTrue($this->settings()->servesCabinet(8));
    }

    public function test_blank_descriptive_values_read_back_as_null(): void
    {
        $this->settings()->configure(self::ENDPOINT, self::TOKEN, '   ', '');

        $this->assertNull($this->settings()->accountEmail());
        $this->assertNull($this->settings()->cabinetName());
    }

    public function test_disabling_keeps_the_credential_but_stops_sync(): void
    {
        $this->settings()->configure(self::ENDPOINT, self::TOKEN);
        $this->settings()->disable();

        $this->assertSame(self::TOKEN, $this->settings()->token());
        $this->assertFalse($this->settings()->isConfigured());
    }

    public function test_forgetting_drops_the_credential_but_keeps_the_endpoint(): void
    {
        $this->settings()->configure(self::ENDPOINT, self::TOKEN, 'doc@example.test', 'Cabinet Atlas', 7);

        $this->settings()->forget();

        $this->assertSame(self::ENDPOINT, $this->settings()->endpoint());
        $this->assertNull($this->settings()->token());
        $this->assertNull($this->settings()->accountEmail());
        $this->assertNull($this->settings()->cabinetName());
        $this->assertNull($this->settings()->linkedAt());
        $this->assertNull($this->settings()->cabinetId());
        $this->assertFalse($this->settings()->enabled());
    }

    public function test_a_non_positive_cabinet_binding_is_ignored(): void
    {
        ApplicationSetting::putValue(MobileSyncSettings::KEY_CABINET_ID, 0, type: 'integer', group: 'sync');

        $this->assertNull($this->settings()->cabinetId());
    }

    public function test_an_unlinked_installation_never_calls_the_remote(): void
    {
        Http::fake();

        try {
            $this->client()->pull(0, 50);
            $this->fail('An unlinked installation reached the remote.');
        } catch (SyncTransportException $exception) {
            $this->assertFalse($exception->offline);
            $this->assertStringContainsString('pas configurée', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_pull_sends_the_cursor_with_the_bearer_token_and_returns_the_page(): void
    {
        $this->link();
        Http::fake([
            self::ENDPOINT.'/api/v1/sync/appointments*' => Http::response([
                'data' => ['a' => ['id' => 1], 'b' => ['id' => 2]],
                'meta' => ['next_cursor' => 9],
            ]),
        ]);

        $page = $this->client()->pull(5, 25);

        $this->assertSame([['id' => 1], ['id' => 2]], $page['data']);
        $this->assertSame(['next_cursor' => 9], $page['meta']);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), self::ENDPOINT.'/api/v1/sync/appointments?')
            && (string) $request['cursor'] === '5'
            && (string) $request['limit'] === '25'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_pull_tolerates_a_page_without_data_or_meta(): void
    {
        $this->link();
        Http::fake(['*' => Http::response(['data' => 'oops'])]);

        $this->assertSame(['data' => [], 'meta' => []], $this->client()->pull(0, 10));
    }

    public function test_acknowledge_posts_the_consumed_cursor(): void
    {
        $this->link();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client()->acknowledge(77);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::ENDPOINT.'/api/v1/sync/appointments/ack'
            && $request->data() === ['cursor' => 77]);
    }

    public function test_push_posts_the_events_and_returns_the_remote_answer(): void
    {
        $this->link();
        Http::fake(['*' => Http::response(['accepted' => 2, 'results' => []])]);
        $events = [['type' => 'appointment.created'], ['type' => 'appointment.updated']];

        $this->assertSame(['accepted' => 2, 'results' => []], $this->client()->push($events));
        Http::assertSent(static fn (Request $request): bool => $request->url() === self::ENDPOINT.'/api/v1/sync/appointments/push'
            && $request['events'] === $events
            && $request->isJson());
    }

    public function test_seat_allowance_returns_only_the_data_block(): void
    {
        $this->link();
        Http::fake([
            self::ENDPOINT.'/api/v1/cabinet/seats' => Http::sequence()
                ->push(['data' => ['seat_limit' => 4, 'owner_email' => 'doc@example.test']])
                ->push(['data' => 'not-an-array']),
        ]);

        $this->assertSame(['seat_limit' => 4, 'owner_email' => 'doc@example.test'], $this->client()->seatAllowance());
        $this->assertSame([], $this->client()->seatAllowance());
    }

    public function test_a_connection_failure_is_reported_as_offline(): void
    {
        $this->link();
        Http::fake(['*' => Http::failedConnection()]);

        try {
            $this->client()->pull(0, 10);
            $this->fail('A connection failure was not reported.');
        } catch (SyncTransportException $exception) {
            $this->assertTrue($exception->offline);
            $this->assertStringContainsString('injoignable', $exception->getMessage());
        }
    }

    public function test_an_unauthorized_answer_forgets_the_revoked_token(): void
    {
        $this->link();
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        try {
            $this->client()->pull(0, 10);
            $this->fail('A revoked token was not reported.');
        } catch (SyncTransportException $exception) {
            $this->assertFalse($exception->offline);
            $this->assertStringContainsString('expiré', $exception->getMessage());
        }

        $this->assertNull($this->settings()->token());
        $this->assertFalse($this->settings()->isConfigured());
        $this->assertSame(self::ENDPOINT, $this->settings()->endpoint());
    }

    public function test_a_forbidden_answer_relays_the_services_reason_but_keeps_the_token(): void
    {
        $this->link();
        Http::fake(['*' => Http::response(['message' => '  Votre cabinet est suspendu.  '], 403)]);

        try {
            $this->client()->push([]);
            $this->fail('A refusal was not reported.');
        } catch (SyncTransportException $exception) {
            $this->assertSame('Votre cabinet est suspendu.', $exception->getMessage());
        }

        $this->assertSame(self::TOKEN, $this->settings()->token());
    }

    public function test_a_forbidden_answer_without_a_reason_names_the_operation(): void
    {
        $this->link();
        Http::fake(['*' => Http::response([], 403)]);

        $this->expectException(SyncTransportException::class);
        $this->expectExceptionMessage('(seats, code 403)');

        $this->client()->seatAllowance();
    }

    public function test_a_long_forbidden_reason_is_truncated(): void
    {
        $this->link();
        Http::fake(['*' => Http::response(['message' => str_repeat('x', 500)], 403)]);

        try {
            $this->client()->acknowledge(1);
            $this->fail('A refusal was not reported.');
        } catch (SyncTransportException $exception) {
            $this->assertLessThanOrEqual(303, mb_strlen($exception->getMessage()));
        }
    }

    public function test_a_server_error_reports_only_the_status_and_operation(): void
    {
        $this->link();
        Http::fake(['*' => Http::response(['message' => 'Patient Ahmed Benali missing'], 500)]);

        try {
            $this->client()->acknowledge(3);
            $this->fail('A server error was not reported.');
        } catch (SyncTransportException $exception) {
            $this->assertStringContainsString('(acknowledge, code 500)', $exception->getMessage());
            $this->assertStringNotContainsString('Benali', $exception->getMessage());
            $this->assertFalse($exception->offline);
        }
    }

    public function test_an_unreadable_body_is_reported(): void
    {
        $this->link();
        Http::fake(['*' => Http::response('<html>proxy login</html>', 200)]);

        $this->expectException(SyncTransportException::class);
        $this->expectExceptionMessage('illisible');

        $this->client()->pull(0, 10);
    }

    private function link(): void
    {
        $this->settings()->configure(self::ENDPOINT.'/', self::TOKEN);
    }

    private function settings(): MobileSyncSettings
    {
        return app(MobileSyncSettings::class);
    }

    private function client(): MobileSyncClient
    {
        return app(MobileSyncClient::class);
    }
}
