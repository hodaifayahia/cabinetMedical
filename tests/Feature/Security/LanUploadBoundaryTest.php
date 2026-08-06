<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\LanUploadBoundary;
use App\Services\QrUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LanUploadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const LOCAL_ORIGIN = 'http://127.0.0.1:43123';

    private const LAN_ORIGIN = 'http://192.168.1.40:43124';

    private const REMOTE_ORIGIN = 'https://uploads.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::LOCAL_ORIGIN,
            'medismart.runtime.local_url' => self::LOCAL_ORIGIN,
            'medismart.runtime.lan_upload_url' => self::LAN_ORIGIN,
            'medismart.runtime.remote_upload_url' => self::REMOTE_ORIGIN,
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_listener_status' => 'active',
            'medismart.health.details_key' => 'test-health-key',
        ]);
        URL::forceRootUrl(self::LOCAL_ORIGIN);
    }

    public function test_lan_health_and_all_four_public_upload_routes_cross_the_boundary(): void
    {
        $health = $this->lan('GET', '/health', server: [
            'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
        ])->assertOk()->assertJsonStructure([
            'status',
            'application' => ['name', 'version'],
            'checked_at',
        ]);
        $this->assertArrayNotHasKey('database', $health->json());
        $this->assertArrayNotHasKey('lan_upload_boundary', $health->json());

        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        [$selector, $verifier] = $this->credentials($created['token']);
        $this->assertStringStartsWith(self::LAN_ORIGIN.'/upload/', (string) $created['url']);

        $this->lan('GET', '/upload/'.$selector)->assertOk();
        $this->lan(
            'POST',
            '/upload/'.$selector.'/authorize',
            ['verifier' => $verifier],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk()->assertJsonPath('mode', 'local');
        $this->lan(
            'POST',
            '/upload/'.$selector.'/files',
            ['verifier' => $verifier],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertUnprocessable();
        $this->lan(
            'POST',
            '/upload/'.$selector.'/complete',
            ['verifier' => $verifier],
        )->assertRedirect();
    }

    public function test_lan_authority_exposes_no_authentication_admin_or_sensitive_route(): void
    {
        $blocked = [
            '/',
            '/up',
            '/login',
            '/register',
            '/dashboard',
            '/admin',
            '/telescope',
            '/settings/profile',
            '/app',
            '/app/patients',
            '/app/configuration/connectivity-backup',
            '/app/configuration/backup/local',
            '/app/configuration/backup/google/prepare',
            '/app/configuration/backup/google/callback',
            '/app/configuration/backup/drive',
            '/app/clinical-documents/1/file',
        ];

        foreach ($blocked as $path) {
            $this->assertGenericBoundaryNotFound($this->lan('GET', $path), $path);
        }

        $preflight = $this->lan('OPTIONS', '/login', server: [
            'HTTP_ORIGIN' => 'https://attacker.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertGenericBoundaryNotFound($preflight, 'preflight');
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_lan_requests_reject_forwarding_and_proxy_identity_headers(): void
    {
        $headers = [
            'HTTP_FORWARDED',
            'HTTP_X_FORWARDED',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED_HOST',
            'HTTP_X_FORWARDED_PORT',
            'HTTP_X_FORWARDED_PREFIX',
            'HTTP_X_FORWARDED_PROTO',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_FASTLY_CLIENT_IP',
            'HTTP_FLY_CLIENT_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_X_REAL_IP',
        ];

        foreach ($headers as $header) {
            $this->assertGenericBoundaryNotFound(
                $this->lan('GET', '/health', server: [$header => '198.51.100.25']),
                $header,
            );
        }
    }

    public function test_lan_requests_require_a_direct_private_link_local_or_loopback_peer(): void
    {
        foreach ([
            '10.20.30.40',
            '127.0.0.1',
            '169.254.20.30',
            '172.16.0.1',
            '172.31.255.254',
            '192.168.50.5',
            '::1',
            'fc00::10',
            'fd12:3456::10',
            'fe80::10',
            '::ffff:192.168.1.50',
        ] as $peer) {
            $this->lan('GET', '/health', server: ['REMOTE_ADDR' => $peer])
                ->assertOk();
        }

        foreach ([
            '0.0.0.0',
            '8.8.8.8',
            '100.64.0.1',
            '198.51.100.25',
            '224.0.0.1',
            '::',
            '2001:4860:4860::8888',
            'ff02::1',
            'not-an-address',
        ] as $peer) {
            $this->assertGenericBoundaryNotFound(
                $this->lan('GET', '/health', server: ['REMOTE_ADDR' => $peer]),
                $peer,
            );
        }
    }

    public function test_lan_requests_require_the_exact_configured_scheme_host_and_port(): void
    {
        $wrongRequests = [
            [
                'uri' => 'https://192.168.1.40:43124/health',
                'server' => [
                    'HTTP_HOST' => '192.168.1.40:43124',
                    'SERVER_PORT' => 43124,
                    'HTTPS' => 'on',
                ],
            ],
            [
                'uri' => 'http://192.168.1.40:43125/health',
                'server' => [
                    'HTTP_HOST' => '192.168.1.40:43125',
                    'SERVER_PORT' => 43125,
                ],
            ],
            [
                'uri' => 'http://192.168.1.41:43124/health',
                'server' => [
                    'HTTP_HOST' => '192.168.1.41:43124',
                    'SERVER_NAME' => '192.168.1.41',
                ],
            ],
            [
                'uri' => 'http://192.168.1.40/health',
                'server' => [
                    'HTTP_HOST' => '192.168.1.40',
                    'SERVER_PORT' => 80,
                ],
            ],
        ];

        foreach ($wrongRequests as $request) {
            $response = $this->call('GET', $request['uri'], server: [
                'HTTP_HOST' => '192.168.1.40:43124',
                'SERVER_NAME' => '192.168.1.40',
                'SERVER_PORT' => 43124,
                'REMOTE_ADDR' => '192.168.1.50',
                ...$request['server'],
            ]);
            $this->assertGenericBoundaryNotFound($response, $request['uri']);
        }

        $this->call('GET', self::LAN_ORIGIN.'/health', server: [
            'HTTP_HOST' => 'unknown.example.test',
            'SERVER_NAME' => '192.168.1.40',
            'SERVER_PORT' => 43124,
            'REMOTE_ADDR' => '192.168.1.50',
            'HTTP_X_FORWARDED_HOST' => '192.168.1.40:43124',
        ])->assertNotFound();
    }

    public function test_lan_route_set_rejects_wrong_methods_and_near_match_paths(): void
    {
        $selector = str_repeat('a', 22);
        $blocked = [
            ['HEAD', '/health'],
            ['POST', '/health'],
            ['HEAD', '/upload/'.$selector],
            ['POST', '/upload/'.$selector],
            ['GET', '/upload/'.$selector.'/authorize'],
            ['PUT', '/upload/'.$selector.'/authorize'],
            ['GET', '/upload/'.$selector.'/files'],
            ['PATCH', '/upload/'.$selector.'/files'],
            ['GET', '/upload/'.$selector.'/complete'],
            ['DELETE', '/upload/'.$selector.'/complete'],
            ['GET', '/upload/'.str_repeat('a', 21)],
            ['GET', '/upload/'.str_repeat('a', 23)],
            ['GET', '/upload/'.$selector.'/unknown'],
            ['GET', '/upload/'.$selector.'/files/extra'],
            ['GET', '/uploads/'.$selector],
        ];

        foreach ($blocked as [$method, $path]) {
            $this->assertGenericBoundaryNotFound(
                $this->lan($method, $path),
                $method.' '.$path,
            );
        }
    }

    public function test_lan_route_matcher_rejects_non_origin_form_request_targets(): void
    {
        foreach ([
            '//attacker.example/health',
            'http://192.168.1.40:43124/health',
            '*',
            '',
            "/health\nX-Smuggled: true",
        ] as $target) {
            $request = Request::create('/health', 'GET');
            $request->server->set('REQUEST_URI', $target);

            $this->assertFalse(app(LanUploadBoundary::class)->routeAllowed($request), $target);
        }
    }

    public function test_invalid_lan_origins_fail_closed(): void
    {
        $invalid = [
            '',
            ' http://192.168.1.40:43124',
            'HTTP://192.168.1.40:43124',
            'http://192.168.1.40',
            'http://192.168.1.40:80',
            'http://192.168.1.40:70000',
            'http://0.0.0.0:43124',
            'http://203.0.113.10:43124',
            'http://clinic.local:43124',
            'ftp://192.168.1.40:43124',
            'http://user@192.168.1.40:43124',
            'http://192.168.1.40:43124/upload',
            'http://192.168.1.40:43124/',
            'http://192.168.1.40:43124?mode=upload',
            'http://192.168.1.40:43124#fragment',
        ];

        foreach ($invalid as $origin) {
            config(['medismart.runtime.lan_upload_url' => $origin]);
            $this->assertNull(app(LanUploadBoundary::class)->configuredOrigin(), $origin);
        }
    }

    public function test_missing_lan_origin_disables_supervised_lan_and_qr_audience(): void
    {
        config(['medismart.runtime.lan_upload_url' => null]);

        $this->assertGenericBoundaryNotFound(
            $this->lan('GET', '/health'),
            'missing LAN origin',
        );

        $created = app(QrUploadService::class)->create('local', User::factory()->create());
        $this->assertNull($created['url']);

        $this->local('GET', '/health', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
        ])->assertOk()->assertJsonPath('lan_upload_boundary.status', 'unavailable');
    }

    public function test_private_link_local_and_loopback_ip_origins_are_canonicalized(): void
    {
        foreach ([
            'http://10.20.30.40:1024',
            'http://10.20.30.40:43124',
            'https://127.0.0.1:43124',
            'http://169.254.20.30:43124',
            'http://172.31.255.254:43124',
            'http://192.168.50.5:43124',
            'http://[::1]:43124',
            'http://[fc00::10]:43124',
            'http://[fd12:3456::10]:43124',
            'http://[fe80::10]:43124',
            'http://[::ffff:192.168.1.50]:43124',
            'http://192.168.50.5:65535',
        ] as $origin) {
            config(['medismart.runtime.lan_upload_url' => $origin]);
            $this->assertSame($origin, app(LanUploadBoundary::class)->configuredOrigin());
        }
    }

    public function test_local_tokens_are_bound_to_lan_and_remote_tokens_are_rejected_there(): void
    {
        $user = User::factory()->create();
        $local = app(QrUploadService::class)->create('local', $user);
        $remote = app(QrUploadService::class)->create('remote', $user);
        [$localSelector, $localVerifier] = $this->credentials($local['token']);
        [$remoteSelector, $remoteVerifier] = $this->credentials($remote['token']);

        $this->local(
            'POST',
            '/upload/'.$localSelector.'/authorize',
            ['verifier' => $localVerifier],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertNotFound();

        $this->lan(
            'POST',
            '/upload/'.$localSelector.'/authorize',
            ['verifier' => $localVerifier],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertOk();

        $this->lan(
            'POST',
            '/upload/'.$remoteSelector.'/authorize',
            ['verifier' => $remoteVerifier],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertNotFound();
    }

    public function test_loopback_health_attests_the_boundary_without_claiming_listener_state(): void
    {
        config(['medismart.runtime.lan_listener_status' => 'stopped']);

        $response = $this->local('GET', '/health', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
        ])->assertOk()->assertJsonPath('lan_upload_boundary', [
            'schema_version' => 1,
            'status' => 'ready',
            'origin' => self::LAN_ORIGIN,
            'route_set' => 'public_upload_v1',
            'upload_routes_only' => true,
            'exact_origin_enforced' => true,
            'explicit_high_port_enforced' => true,
            'direct_private_peer_enforced' => true,
            'forwarding_headers_rejected' => true,
            'local_tokens_bound_to_lan_origin' => true,
        ]);

        $attestation = $response->json('lan_upload_boundary');
        $this->assertIsArray($attestation);
        $this->assertArrayNotHasKey('listener_active', $attestation);
        $this->assertArrayNotHasKey('listener_status', $attestation);
        $this->assertNotSame('active', $response->json('lan_listener.status'));
    }

    public function test_lan_and_loopback_authorities_must_not_overlap(): void
    {
        config(['medismart.runtime.lan_upload_url' => self::LOCAL_ORIGIN]);

        $this->assertGenericBoundaryNotFound(
            $this->local('GET', '/health', server: [
                'HTTP_X_MEDISMART_HEALTH_KEY' => 'test-health-key',
            ]),
            'ambiguous authority',
        );
    }

    /** @param array<string, mixed> $parameters
     * @param  array<string, string|int>  $server
     */
    private function lan(
        string $method,
        string $path,
        array $parameters = [],
        array $server = [],
    ): TestResponse {
        return $this->call($method, self::LAN_ORIGIN.$path, $parameters, server: [
            'HTTP_HOST' => '192.168.1.40:43124',
            'SERVER_NAME' => '192.168.1.40',
            'SERVER_PORT' => 43124,
            'REMOTE_ADDR' => '192.168.1.50',
            ...$server,
        ]);
    }

    /** @param array<string, mixed> $parameters
     * @param  array<string, string|int>  $server
     */
    private function local(
        string $method,
        string $path,
        array $parameters = [],
        array $server = [],
    ): TestResponse {
        return $this->call($method, self::LOCAL_ORIGIN.$path, $parameters, server: [
            'HTTP_HOST' => '127.0.0.1:43123',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
            ...$server,
        ]);
    }

    private function assertGenericBoundaryNotFound(TestResponse $response, string $context): void
    {
        $this->assertSame(404, $response->getStatusCode(), $context);
        $response->assertNotFound();
        $this->assertSame('', $response->getContent(), $context);
        $this->assertSame('max-age=0, no-store, private', $response->headers->get('Cache-Control'), $context);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $context);
        $this->assertNull($response->headers->get('Location'), $context);
        $this->assertFalse($response->headers->has('Set-Cookie'), $context);
    }

    /** @return array{string, string} */
    private function credentials(string $token): array
    {
        $parts = explode('.', $token, 2);
        $this->assertCount(2, $parts);

        return [$parts[0], $parts[1]];
    }
}
