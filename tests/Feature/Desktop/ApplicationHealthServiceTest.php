<?php

namespace Tests\Feature\Desktop;

use App\Enums\CabinetStatus;
use App\Models\ApplicationSetting;
use App\Models\Cabinet;
use App\Services\ApplicationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(storage_path('app/private'), 0755, true);
        config([
            'app.name' => 'Drclick',
            'medismart.version' => '3.4.5',
            'queue.default' => 'sync',
            'medismart.runtime.desktop_supervised' => false,
            'medismart.runtime.local_url' => 'http://127.0.0.1:43123',
            'medismart.runtime.lan_upload_url' => null,
            'medismart.runtime.remote_upload_url' => null,
            'medismart.runtime.lan_listener_status' => 'stopped',
            'medismart.runtime.scheduler_status' => 'stopped',
            'medismart.runtime.queue_worker_status' => 'stopped',
            'hub.enabled' => false,
        ]);
    }

    public function test_a_migrated_installation_with_a_synchronous_queue_is_healthy(): void
    {
        $status = $this->health()->status();

        $this->assertSame('healthy', $status['status']);
        $this->assertSame(['name' => 'Drclick', 'version' => '3.4.5', 'environment' => 'testing'], $status['application']);
        $this->assertTrue($status['database']['connected']);
        $this->assertTrue($status['database']['foundation_ready']);
        $this->assertTrue($status['database']['migrations_current']);
        $this->assertSame(0, $status['database']['pending_migrations']);
        $this->assertNull($status['database']['error']);
        $this->assertTrue($status['storage']['writable']);
        $this->assertSame('storage/app/private', $status['storage']['path']);
        $this->assertSame([
            'connection' => 'sync',
            'available' => true,
            'worker_status' => 'not_required',
            'operational' => true,
            'observation_source' => 'not_required',
            'pending' => null,
            'failed' => null,
        ], $status['queue']);
        $this->assertSame('http://127.0.0.1:43123', $status['urls']['local']);
        $this->assertNull($status['urls']['remote']);
        $this->assertNotEmpty($status['checked_at']);
    }

    public function test_the_storage_probe_leaves_no_file_behind(): void
    {
        $before = glob(storage_path('app/private/.health-*.tmp')) ?: [];

        $this->health()->status();

        $this->assertSame($before, glob(storage_path('app/private/.health-*.tmp')) ?: []);
    }

    public function test_a_database_queue_needs_an_observed_worker_on_the_desktop(): void
    {
        config(['queue.default' => 'database']);

        $unsupervised = $this->health()->status();
        $this->assertSame('degraded', $unsupervised['status']);
        $this->assertSame('stopped', $unsupervised['queue']['worker_status']);
        $this->assertSame('unverified', $unsupervised['queue']['observation_source']);
        $this->assertSame(0, $unsupervised['queue']['pending']);
        $this->assertSame(0, $unsupervised['queue']['failed']);

        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.queue_worker_status' => 'active',
        ]);

        $supervised = $this->health()->status();
        $this->assertTrue($supervised['queue']['operational']);
        $this->assertSame('native_supervisor_process_contract', $supervised['queue']['observation_source']);
        $this->assertSame('healthy', $supervised['status']);
    }

    public function test_a_worker_status_claim_off_the_desktop_is_not_trusted(): void
    {
        config([
            'queue.default' => 'database',
            'medismart.runtime.queue_worker_status' => 'active',
            'medismart.runtime.scheduler_status' => 'active',
        ]);

        $status = $this->health()->status();

        $this->assertSame('stopped', $status['queue']['worker_status']);
        $this->assertSame(['status' => 'stopped', 'observation_source' => 'unverified', 'process_bound' => true], $status['scheduler']);
    }

    public function test_the_scheduler_is_active_only_when_the_supervisor_reports_it(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);
        $this->assertSame('stopped', $this->health()->status()['scheduler']['status']);

        config(['medismart.runtime.scheduler_status' => 'active']);
        $this->assertSame([
            'status' => 'active',
            'observation_source' => 'native_supervisor_process_contract',
            'process_bound' => true,
        ], $this->health()->status()['scheduler']);
    }

    public function test_the_lan_listener_is_unavailable_without_a_private_address(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);

        $lan = $this->health()->status()['lan_listener'];

        $this->assertSame(['status' => 'unavailable', 'address' => null, 'upload_base_url' => null], $lan);
    }

    public function test_the_lan_listener_reports_its_address_and_url_only_while_active(): void
    {
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');

        $this->assertSame(
            ['status' => 'stopped', 'address' => '192.168.1.5', 'upload_base_url' => null],
            $this->health()->status()['lan_listener'],
        );

        config(['medismart.runtime.lan_listener_status' => 'active']);

        $this->assertSame(
            ['status' => 'active', 'address' => '192.168.1.5', 'upload_base_url' => 'http://192.168.1.5:8000'],
            $this->health()->status()['lan_listener'],
        );
    }

    public function test_the_license_and_tunnel_blocks_reflect_an_unconfigured_installation(): void
    {
        $status = $this->health()->status();

        $this->assertSame('not_activated', $status['license']['state']);
        $this->assertFalse($status['license']['clock_warning']);
        $this->assertFalse($status['tunnel']['configured']);
        $this->assertSame('stopped', $status['tunnel']['runtime_state']);
    }

    public function test_boundary_attestations_are_unavailable_without_middleware_evidence(): void
    {
        $status = $this->health()->status();

        $this->assertSame('unavailable', $status['lan_upload_boundary']['status']);
        $this->assertSame('unavailable', $status['remote_upload_boundary']['status']);
    }

    public function test_a_hosted_installation_advertises_no_hub(): void
    {
        $this->assertNull($this->health()->status()['hub']);
    }

    public function test_a_declared_hub_is_advertised_with_its_readiness(): void
    {
        $cabinet = Cabinet::query()->create([
            'name' => 'Cabinet hub',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
        config([
            'hub.enabled' => true,
            'hub.id' => 'hub-health-1',
            'hub.cabinet_id' => $cabinet->getKey(),
        ]);

        $hub = $this->health()->status()['hub'];

        $this->assertSame('hub', $hub['mode']);
        $this->assertSame('hub-health-1', $hub['hub_id']);
        $this->assertFalse($hub['ready']);
        $this->assertSame('hub_not_adopted', $hub['reason']);
    }

    private function health(): ApplicationHealthService
    {
        return app(ApplicationHealthService::class);
    }
}
