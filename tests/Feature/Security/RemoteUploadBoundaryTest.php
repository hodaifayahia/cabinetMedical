<?php

namespace Tests\Feature\Security;

use App\Configuration\ApplicationSettingRegistry;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\QrUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RemoteUploadBoundaryTest extends TestCase
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
            'medismart.health.details_key' => 'test-health-key',
            'medismart.runtime.lan_listener_status' => 'active',
        ]);
        URL::forceRootUrl(self::LOCAL_ORIGIN);
    }

    public function test_unknown_hosts_are_rejected_even_when_forwarded_host_names_the_public_origin(): void
    {
        $this->call('GET', 'http://unknown.example.test/health', server: [
            'HTTP_HOST' => 'unknown.example.test',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'uploads.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
            'HTTP_ACCEPT' => 'application/json',
        ])->assertNotFound();
    }

    public function test_remote_host_exposes_no_application_authentication_or_sensitive_routes(): void
    {
        $blocked = [
            '/',
            '/login',
            '/dashboard',
            '/admin',
            '/telescope',
            '/app',
            '/app/configuration/connectivity-backup',
            '/app/configuration/backup/local',
            '/app/configuration/backup/google/prepare',
            '/app/configuration/backup/google/callback',
            '/app/configuration/backup/drive',
            '/app/clinical-documents/1/file',
        ];

        foreach ($blocked as $path) {
            $response = $this->remote('GET', $path);
            $response->assertNotFound();
            $this->assertNull($response->headers->get('Location'), $path);
            $this->assertFalse($response->headers->has('Set-Cookie'), $path);
        }

        $preflight = $this->remote('OPTIONS', '/login', server: [
            'HTTP_ORIGIN' => 'https://attacker.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertNotFound();
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_remote_requests_require_the_loopback_proxy_and_raw_forwarded_https(): void
    {
        $this->remote('GET', '/health', server: [
            'HTTP_X_FORWARDED_PROTO' => null,
        ])->assertNotFound();

        $this->remote('GET', '/health', server: [
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ])->assertNotFound();

        $this->remote('GET', '/health', server: [
            'REMOTE_ADDR' => '198.51.100.9',
        ])->assertNotFound();

        $this->remote('GET', '/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy');
    }

    public function test_remote_upload_responses_use_https_redirects_and_secure_cookies(): void
    {
        $created = app(QrUploadService::class)->create('remote', User::factory()->create());
        [$selector] = $this->credentials($created['token']);
        $page = $this->remote('GET', '/upload/'.$selector)->assertOk();
        $this->assertSecureCookies($page);

        $redirect = $this->remote('POST', '/upload/'.$selector.'/authorize', server: [
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_REFERER' => self::REMOTE_ORIGIN.'/upload/'.$selector,
        ])->assertRedirect(self::REMOTE_ORIGIN.'/upload/'.$selector);
        $this->assertSecureCookies($redirect);
    }

    public function test_public_upload_rate_limits_use_distinct_trusted_forwarded_client_ips(): void
    {
        $selector = str_repeat('b', 22);
        $wrongVerifier = str_repeat('z', 43);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->remote(
                'POST',
                '/upload/'.$selector.'/authorize',
                ['verifier' => $wrongVerifier],
                '198.51.100.10',
                ['HTTP_ACCEPT' => 'application/json'],
            )->assertNotFound();
        }

        $this->remote(
            'POST',
            '/upload/'.$selector.'/authorize',
            ['verifier' => $wrongVerifier],
            '198.51.100.10',
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertTooManyRequests();

        $this->remote(
            'POST',
            '/upload/'.$selector.'/authorize',
            ['verifier' => $wrongVerifier],
            '198.51.100.11',
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertNotFound();
    }

    public function test_local_and_remote_tokens_cannot_cross_origins_and_remote_stays_unavailable(): void
    {
        app(ApplicationSettingService::class)->set(
            ApplicationSettingRegistry::CONNECTIVITY_MANUAL_IPV4,
            '192.168.1.40',
        );
        $user = User::factory()->create();
        $local = app(QrUploadService::class)->create('local', $user);
        $remote = app(QrUploadService::class)->create('remote', $user);
        [$localSelector, $localVerifier] = $this->credentials($local['token']);
        [$remoteSelector, $remoteVerifier] = $this->credentials($remote['token']);

        $this->remote(
            'POST',
            '/upload/'.$localSelector.'/authorize',
            ['verifier' => $localVerifier],
            server: ['HTTP_ACCEPT' => 'application/json'],
        )->assertNotFound();

        $this->local(
            'POST',
            '/upload/'.$remoteSelector.'/authorize',
            ['verifier' => $remoteVerifier],
            ['HTTP_ACCEPT' => 'application/json'],
        )->assertNotFound();

        // No license or active tunnel is manufactured for the test. Even on
        // the correct proxy origin, a remote token remains unusable.
        $this->assertDatabaseCount('licenses', 0);
        $this->assertDatabaseCount('tunnel_settings', 0);
        $this->remote(
            'POST',
            '/upload/'.$remoteSelector.'/authorize',
            ['verifier' => $remoteVerifier],
            server: ['HTTP_ACCEPT' => 'application/json'],
        )->assertNotFound();
    }

    /** @param array<string, mixed> $parameters
     * @param  array<string, string|null>  $server
     */
    private function remote(
        string $method,
        string $path,
        array $parameters = [],
        string $clientIp = '198.51.100.10',
        array $server = [],
    ): TestResponse {
        $defaults = [
            'HTTP_HOST' => 'uploads.example.test',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 43123,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => $clientIp,
        ];

        return $this->call(
            $method,
            self::REMOTE_ORIGIN.$path,
            $parameters,
            server: array_filter(
                [...$defaults, ...$server],
                static fn (mixed $value): bool => $value !== null,
            ),
        );
    }

    /** @param array<string, mixed> $parameters
     * @param  array<string, string>  $server
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

    private function assertSecureCookies(TestResponse $response): void
    {
        $cookies = $response->headers->getCookies();
        $this->assertNotEmpty($cookies);

        foreach ($cookies as $cookie) {
            $this->assertTrue($cookie->isSecure(), $cookie->getName());
        }
    }

    /** @return array{string, string} */
    private function credentials(string $token): array
    {
        $parts = explode('.', $token, 2);
        $this->assertCount(2, $parts);

        return [$parts[0], $parts[1]];
    }
}
