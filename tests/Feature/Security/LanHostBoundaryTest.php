<?php

namespace Tests\Feature\Security;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\LanHostBoundary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The desktop "poste principal" LAN listener (ADR-005): another PC of the
 * cabinet reaches the whole application, behind the normal sign-in, while
 * the Internet, spoofed proxies and rebinding host names stay outside.
 */
class LanHostBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const LISTENER_LOOPBACK = 'http://127.0.0.1:47850';

    private const HOST = '192.168.1.10:47850';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::LISTENER_LOOPBACK,
            'medismart.runtime.local_url' => self::LISTENER_LOOPBACK,
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_host_enabled' => true,
            'medismart.runtime.lan_host_port' => 47850,
            'medismart.health.details_key' => 'test-health-key',
        ]);
    }

    public function test_another_pc_of_the_cabinet_reaches_the_sign_in_page(): void
    {
        $this->lan('GET', '/login')->assertOk();
    }

    public function test_redirects_stay_on_the_address_the_other_pc_typed(): void
    {
        $this->lan('GET', '/app/configuration/local-network')
            ->assertRedirect('http://'.self::HOST.'/login');
    }

    public function test_health_answers_without_runtime_details_even_with_the_key(): void
    {
        $health = $this->lan('GET', '/health', server: [
            'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertContains($health->status(), [200, 503]);
        $health->assertJsonPath('application.name', 'Drclick');
        $this->assertArrayNotHasKey('database', $health->json());
    }

    public function test_lan_names_the_doctor_may_type_are_accepted(): void
    {
        foreach ([
            ['cabinet-pc:47850', '192.168.1.25'],
            ['cabinet-pc.local:47850', '192.168.1.25'],
            ['10.0.0.4:47850', '10.0.0.9'],
            ['172.16.4.2:47850', '172.16.4.3'],
            ['169.254.10.2:47850', '169.254.10.3'],
            ['[fd00::10]:47850', 'fd00::25'],
            [self::HOST, '::ffff:192.168.1.25'],
            // The poste principal itself, through its own LAN address.
            [self::HOST, '127.0.0.1'],
        ] as [$host, $peer]) {
            $this->lan('GET', '/login', host: $host, peer: $peer)->assertOk();
        }
    }

    public function test_the_internet_and_rebinding_names_are_refused(): void
    {
        foreach ([
            // A public peer, even naming the LAN address.
            [self::HOST, '203.0.113.7', []],
            // A public DNS name resolved to a LAN address (DNS rebinding).
            ['evil.example.com:47850', '192.168.1.25', []],
            ['cabinet-pc.local.example.com:47850', '192.168.1.25', []],
            // Wrong or missing port.
            ['192.168.1.10:8000', '192.168.1.25', []],
            ['192.168.1.10', '192.168.1.25', []],
            // Public address or loopback name in the Host header.
            ['8.8.8.8:47850', '192.168.1.25', []],
            ['localhost:47850', '192.168.1.25', []],
            // Nothing on the LAN is a trusted proxy.
            [self::HOST, '192.168.1.25', ['HTTP_X_FORWARDED_FOR' => '192.168.1.30']],
            [self::HOST, '192.168.1.25', ['HTTP_X_FORWARDED_HOST' => 'cabinet-pc:47850']],
            [self::HOST, '192.168.1.25', ['HTTP_FORWARDED' => 'for=192.168.1.30']],
        ] as [$host, $peer, $server]) {
            $response = $this->lan('GET', '/login', host: $host, peer: $peer, server: $server);
            $response->assertNotFound();
            $this->assertFalse($response->headers->has('Set-Cookie'), $host.' '.$peer);
        }
    }

    public function test_nothing_is_shared_while_the_listener_is_off(): void
    {
        config(['medismart.runtime.lan_host_enabled' => false]);
        $this->lan('GET', '/login')->assertNotFound();

        config([
            'medismart.runtime.lan_host_enabled' => true,
            'medismart.runtime.lan_host_port' => 0,
        ]);
        $this->lan('GET', '/login')->assertNotFound();

        config([
            'medismart.runtime.lan_host_port' => 47850,
            'medismart.runtime.desktop_supervised' => false,
        ]);
        $this->assertFalse(app(LanHostBoundary::class)->enabled());
    }

    public function test_the_loopback_listener_keeps_its_exact_origin_rule(): void
    {
        $this->call('GET', self::LISTENER_LOOPBACK.'/login', server: [
            'HTTP_HOST' => '127.0.0.1:47850',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 47850,
            'REMOTE_ADDR' => '127.0.0.1',
        ])->assertOk();

        // The loopback origin is not reachable from the LAN.
        $this->call('GET', self::LISTENER_LOOPBACK.'/login', server: [
            'HTTP_HOST' => '127.0.0.1:47850',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 47850,
            'REMOTE_ADDR' => '192.168.1.25',
        ])->assertNotFound();
    }

    public function test_the_assistant_signs_in_from_her_pc_and_the_page_knows_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $doctor = User::factory()->create();
        $doctor->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($doctor)
            ->lan('GET', '/app/configuration/local-network')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/LocalNetwork')
                ->where('desktopSupervised', true)
                ->where('lanClient', true));
    }

    public function test_the_poste_principal_itself_is_not_a_lan_client(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $doctor = User::factory()->create();
        $doctor->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($doctor)
            ->call('GET', self::LISTENER_LOOPBACK.'/app/configuration/local-network', server: [
                'HTTP_HOST' => '127.0.0.1:47850',
                'SERVER_NAME' => '127.0.0.1',
                'SERVER_PORT' => 47850,
                'REMOTE_ADDR' => '127.0.0.1',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('configuration/LocalNetwork')
                ->where('lanClient', false)
                ->where('staffUrl', route('app.staff.index')));
    }

    public function test_malformed_host_headers_are_refused_before_routing(): void
    {
        $boundary = app(LanHostBoundary::class);

        foreach ([
            '192.168.1.256:47850',
            '192.168.1.10:47850:47850',
            'user@192.168.1.10:47850',
            '192.168.1.10:47850/x',
            ' 192.168.1.10:47850',
            '-cabinet:47850',
            '[2001:db8::1]:47850',
        ] as $host) {
            $request = Request::create('http://'.self::HOST.'/login', server: [
                'REMOTE_ADDR' => '192.168.1.25',
            ]);
            $request->headers->set('Host', $host);

            $this->assertFalse($boundary->allows($request), $host);
        }
    }

    public function test_the_lan_client_marker_is_only_set_by_the_boundary(): void
    {
        $request = Request::create('http://'.self::HOST.'/login');

        $this->assertFalse(LanHostBoundary::isLanClientRequest($request));

        $request->attributes->set(LanHostBoundary::REQUEST_ATTRIBUTE, true);

        $this->assertTrue(LanHostBoundary::isLanClientRequest($request));
    }

    /** @param array<string, mixed> $server */
    private function lan(
        string $method,
        string $path,
        string $host = self::HOST,
        string $peer = '192.168.1.25',
        array $server = [],
    ): TestResponse {
        $hostname = preg_replace('/:\d+$/', '', $host);
        $port = preg_match('/:(\d+)$/', $host, $matches) === 1 ? (int) $matches[1] : 80;

        return $this->call($method, 'http://'.$host.$path, server: [
            'HTTP_HOST' => $host,
            'SERVER_NAME' => $hostname,
            'SERVER_PORT' => $port,
            'REMOTE_ADDR' => $peer,
            ...$server,
        ]);
    }
}
