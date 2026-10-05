<?php

namespace Tests\Unit\Desktop;

use App\Services\GoogleDriveOAuthException;
use App\Services\GoogleOAuthLoopbackOrigin;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleOAuthLoopbackOriginTest extends TestCase
{
    private const string ORIGIN = 'http://127.0.0.1:43123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.local_url' => self::ORIGIN,
            'services.google.redirect' => null,
        ]);
    }

    public function test_the_redirect_uri_is_built_from_the_supervised_loopback_origin(): void
    {
        $origin = new GoogleOAuthLoopbackOrigin;

        $this->assertSame(self::ORIGIN.GoogleOAuthLoopbackOrigin::CALLBACK_PATH, $origin->redirectUri());
        $this->assertTrue($origin->available());
    }

    public function test_a_trailing_slash_on_the_local_url_is_accepted(): void
    {
        config(['medismart.runtime.local_url' => self::ORIGIN.'/']);

        $this->assertSame(
            self::ORIGIN.GoogleOAuthLoopbackOrigin::CALLBACK_PATH,
            (new GoogleOAuthLoopbackOrigin)->redirectUri(),
        );
    }

    public function test_an_ipv6_loopback_origin_keeps_its_brackets(): void
    {
        config(['medismart.runtime.local_url' => 'http://[::1]:50000']);

        $this->assertSame(
            'http://[::1]:50000'.GoogleOAuthLoopbackOrigin::CALLBACK_PATH,
            (new GoogleOAuthLoopbackOrigin)->redirectUri(),
        );
    }

    public function test_an_unsupervised_runtime_never_offers_a_loopback_redirect(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);
        $origin = new GoogleOAuthLoopbackOrigin;

        $this->assertFalse($origin->available());
        $this->assertReason('desktop_supervision_unavailable', fn () => $origin->redirectUri());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidLocalUrls(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'surrounding whitespace' => [' '.self::ORIGIN],
            'embedded control character' => ["http://127.0.0.1:43123\t"],
            'https scheme' => ['https://127.0.0.1:43123'],
            'missing port' => ['http://127.0.0.1'],
            'privileged port' => ['http://127.0.0.1:80'],
            'credentials' => ['http://user:pass@127.0.0.1:43123'],
            'query string' => ['http://127.0.0.1:43123/?a=1'],
            'fragment' => ['http://127.0.0.1:43123/#x'],
            'sub path' => ['http://127.0.0.1:43123/app'],
            'localhost name' => ['http://localhost:43123'],
            'lan address' => ['http://192.168.1.10:43123'],
            'other loopback address' => ['http://127.0.0.2:43123'],
        ];
    }

    #[DataProvider('invalidLocalUrls')]
    public function test_a_non_loopback_or_malformed_local_url_is_refused(mixed $localUrl): void
    {
        config(['medismart.runtime.local_url' => $localUrl]);
        $origin = new GoogleOAuthLoopbackOrigin;

        $this->assertFalse($origin->available());
        $this->assertReason('loopback_origin_invalid', fn () => $origin->redirectUri());
    }

    public function test_a_configured_redirect_must_match_the_loopback_redirect_exactly(): void
    {
        config(['services.google.redirect' => 'http://127.0.0.1:9999'.GoogleOAuthLoopbackOrigin::CALLBACK_PATH]);
        $origin = new GoogleOAuthLoopbackOrigin;

        $this->assertFalse($origin->available());
        $this->assertReason('redirect_configuration_mismatch', fn () => $origin->redirectUri());

        config(['services.google.redirect' => self::ORIGIN.GoogleOAuthLoopbackOrigin::CALLBACK_PATH]);
        $this->assertTrue($origin->available());
    }

    public function test_an_exact_loopback_callback_request_is_accepted(): void
    {
        $request = $this->callbackRequest();

        $this->assertSame(
            self::ORIGIN.GoogleOAuthLoopbackOrigin::CALLBACK_PATH,
            (new GoogleOAuthLoopbackOrigin)->assertCallbackRequest($request),
        );
    }

    public function test_an_ipv6_peer_is_accepted_for_the_callback(): void
    {
        $request = $this->callbackRequest(server: ['REMOTE_ADDR' => '::1']);

        $this->assertSame(
            self::ORIGIN.GoogleOAuthLoopbackOrigin::CALLBACK_PATH,
            (new GoogleOAuthLoopbackOrigin)->assertCallbackRequest($request),
        );
    }

    public function test_an_ipv6_callback_authority_is_matched_with_brackets(): void
    {
        config(['medismart.runtime.local_url' => 'http://[::1]:50000']);
        $request = $this->callbackRequest('http://[::1]:50000', ['HTTP_HOST' => '[::1]:50000']);

        $this->assertStringStartsWith(
            'http://[::1]:50000',
            (new GoogleOAuthLoopbackOrigin)->assertCallbackRequest($request),
        );
    }

    public function test_a_post_callback_is_refused(): void
    {
        $this->assertCallbackRefused($this->callbackRequest(method: 'POST'));
    }

    public function test_a_callback_on_another_path_is_refused(): void
    {
        $this->assertCallbackRefused(Request::create(
            self::ORIGIN.'/app/configuration/backup/google/other',
            'GET',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
        ));
    }

    public function test_a_callback_with_a_foreign_host_header_is_refused(): void
    {
        $this->assertCallbackRefused($this->callbackRequest(server: ['HTTP_HOST' => 'evil.example:43123']));
    }

    public function test_a_callback_on_another_port_is_refused(): void
    {
        $this->assertCallbackRefused($this->callbackRequest(server: ['HTTP_HOST' => '127.0.0.1:43124']));
    }

    public function test_a_callback_from_a_lan_peer_is_refused(): void
    {
        $this->assertCallbackRefused($this->callbackRequest(server: ['REMOTE_ADDR' => '192.168.1.20']));
    }

    public function test_a_callback_over_https_is_refused(): void
    {
        $this->assertCallbackRefused($this->callbackRequest(
            'https://127.0.0.1:43123',
            ['HTTP_HOST' => '127.0.0.1:43123'],
        ));
    }

    /** @return array<string, array{string}> */
    public static function forwardingHeaders(): array
    {
        return [
            'Forwarded' => ['HTTP_FORWARDED'],
            'X-Forwarded-For' => ['HTTP_X_FORWARDED_FOR'],
            'X-Forwarded-Host' => ['HTTP_X_FORWARDED_HOST'],
            'X-Forwarded-Port' => ['HTTP_X_FORWARDED_PORT'],
            'X-Forwarded-Proto' => ['HTTP_X_FORWARDED_PROTO'],
        ];
    }

    #[DataProvider('forwardingHeaders')]
    public function test_a_proxied_callback_is_refused(string $serverKey): void
    {
        $this->assertCallbackRefused($this->callbackRequest(server: [$serverKey => 'x']));
    }

    public function test_the_callback_fails_with_the_origin_reason_when_supervision_is_off(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);

        $this->assertReason(
            'desktop_supervision_unavailable',
            fn () => (new GoogleOAuthLoopbackOrigin)->assertCallbackRequest($this->callbackRequest()),
        );
    }

    public function test_the_exception_message_never_echoes_configuration(): void
    {
        $exception = new GoogleDriveOAuthException('loopback_origin_invalid');

        $this->assertSame('loopback_origin_invalid', $exception->reasonCode);
        $this->assertStringNotContainsString('loopback', $exception->getMessage());
    }

    /** @param array<string, mixed> $server */
    private function callbackRequest(string $origin = self::ORIGIN, array $server = [], string $method = 'GET'): Request
    {
        $request = Request::create(
            $origin.GoogleOAuthLoopbackOrigin::CALLBACK_PATH.'?state=abc&code=def',
            $method,
            server: array_replace(['REMOTE_ADDR' => '127.0.0.1'], $server),
        );

        // Request::create() derives Host from the URI; apply an explicit
        // override afterwards, as a client could send any Host header.
        if (isset($server['HTTP_HOST'])) {
            $request->server->set('HTTP_HOST', $server['HTTP_HOST']);
            $request->headers->set('Host', $server['HTTP_HOST']);
        }

        return $request;
    }

    private function assertCallbackRefused(Request $request): void
    {
        $this->assertReason(
            'callback_origin_mismatch',
            fn () => (new GoogleOAuthLoopbackOrigin)->assertCallbackRequest($request),
        );
    }

    private function assertReason(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected GoogleDriveOAuthException [{$reason}].");
        } catch (GoogleDriveOAuthException $exception) {
            $this->assertSame($reason, $exception->reasonCode);
        }
    }
}
