<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const LOCAL_ORIGIN = 'http://127.0.0.1:43123';

    private const REMOTE_ORIGIN = 'https://uploads.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::LOCAL_ORIGIN,
            'medismart.runtime.local_url' => self::LOCAL_ORIGIN,
            'medismart.runtime.remote_upload_url' => self::REMOTE_ORIGIN,
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.queue_worker_status' => 'active',
            'medismart.runtime.scheduler_status' => 'active',
            'medismart.health.details_key' => 'test-health-key',
            'queue.default' => 'database',
        ]);
    }

    public function test_loopback_health_check_returns_operational_details_without_secrets(): void
    {
        $this->localHealth(['HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key'])
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('database.connected', true)
            ->assertJsonPath('database.foundation_ready', true)
            ->assertJsonPath('database.migrations_current', true)
            ->assertJsonPath('database.pending_migrations', 0)
            ->assertJsonPath('storage.writable', true)
            ->assertJsonPath('queue.worker_status', 'active')
            ->assertJsonPath('queue.operational', true)
            ->assertJsonPath(
                'queue.observation_source',
                'native_supervisor_process_contract',
            )
            ->assertJsonPath('scheduler.status', 'active')
            ->assertJsonPath(
                'scheduler.observation_source',
                'native_supervisor_process_contract',
            )
            ->assertJsonPath('scheduler.process_bound', true)
            ->assertJsonPath('license.state', 'not_activated')
            ->assertJsonPath('remote_upload_boundary', [
                'schema_version' => 1,
                'status' => 'ready',
                'hostname' => 'uploads.example.test',
                'listener_origin' => self::LOCAL_ORIGIN,
                'route_set' => 'public_upload_v1',
                'upload_routes_only' => true,
                'exact_host_enforced' => true,
                'trusted_proxy_enforced' => true,
                'forwarded_https_enforced' => true,
                'local_tokens_rejected_on_remote_host' => true,
            ])
            ->assertJsonMissing(['encrypted_tunnel_token', 'signed_certificate']);
    }

    public function test_stopped_database_worker_degrades_health_even_when_tables_exist(): void
    {
        config(['medismart.runtime.queue_worker_status' => 'stopped']);

        $this->localHealth(['HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key'])
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('queue.available', true)
            ->assertJsonPath('queue.worker_status', 'stopped')
            ->assertJsonPath('queue.operational', false)
            ->assertJsonPath(
                'queue.observation_source',
                'native_supervisor_process_contract',
            );
    }

    public function test_unsupervised_environment_cannot_claim_worker_or_scheduler_observation(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => false,
            'medismart.runtime.queue_worker_status' => 'active',
            'medismart.runtime.scheduler_status' => 'active',
        ]);

        $this->localHealth(['HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key'])
            ->assertServiceUnavailable()
            ->assertJsonPath('queue.worker_status', 'stopped')
            ->assertJsonPath('queue.operational', false)
            ->assertJsonPath('queue.observation_source', 'unverified')
            ->assertJsonPath('scheduler.status', 'stopped')
            ->assertJsonPath('scheduler.observation_source', 'unverified');
    }

    public function test_a_pending_migration_degrades_health_before_the_desktop_opens(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_08_05_000000_create_google_drive_oauth_attempts_table')
            ->delete();

        $this->localHealth(['HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key'])
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('database.connected', true)
            ->assertJsonPath('database.migrations_current', false)
            ->assertJsonPath('database.pending_migrations', 1)
            ->assertJsonPath(
                'database.latest_available_migration',
                '2026_08_09_000010_add_license_type_to_hosted_licenses',
            );
    }

    public function test_remote_health_check_exposes_only_minimal_status_even_with_the_details_key(): void
    {
        $response = $this->remoteHealth([
            'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'application' => ['name', 'version'],
                'checked_at',
            ]);

        $this->assertArrayNotHasKey('database', $response->json());
        $this->assertArrayNotHasKey('urls', $response->json());
        $this->assertArrayNotHasKey('license', $response->json());
        $this->assertArrayNotHasKey('remote_upload_boundary', $response->json());
    }

    public function test_the_details_key_is_unavailable_on_a_forwarded_or_non_exact_local_request(): void
    {
        $this->localHealth([
            'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
        ])->assertNotFound();

        $this->call('GET', 'http://127.0.0.1:43124/health', server: [
            'HTTP_HOST' => '127.0.0.1:43124',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43124,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
        ])->assertNotFound();
    }

    /**
     * @param  array<string, string>  $extra
     * @return TestResponse<Response>
     */
    private function localHealth(array $extra = []): TestResponse
    {
        return $this->call('GET', self::LOCAL_ORIGIN.'/health', server: [
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'application/json',
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, string>  $extra
     * @return TestResponse<Response>
     */
    private function remoteHealth(array $extra = []): TestResponse
    {
        return $this->call('GET', self::REMOTE_ORIGIN.'/health', server: [
            'HTTP_HOST' => 'uploads.example.test',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
            'HTTP_ACCEPT' => 'application/json',
            ...$extra,
        ]);
    }
}
