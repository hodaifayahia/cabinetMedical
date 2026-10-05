<?php

namespace Tests\Unit\Desktop;

use App\Services\LanUploadBoundary;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pure request rules of the LAN upload boundary: origin parsing, the
 * public route allow-list and the attestation without middleware evidence.
 */
class LanUploadBoundaryRulesTest extends TestCase
{
    private const string SELECTOR = 'AbCdEfGhIjKlMnOpQrStUv';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_upload_url' => 'http://192.168.1.5:50000',
        ]);
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function configuredOrigins(): array
    {
        return [
            'private ipv4' => ['http://192.168.1.5:50000', 'http://192.168.1.5:50000'],
            'https private ipv4' => ['https://10.0.0.8:50443', 'https://10.0.0.8:50443'],
            'unique local ipv6' => ['http://[fd00::5]:50000', 'http://[fd00::5]:50000'],
            'trailing slash' => ['http://192.168.1.5:50000/', null],
            'upper case scheme' => ['HTTP://192.168.1.5:50000', null],
            'public address' => ['http://8.8.8.8:50000', null],
            'host name' => ['http://cabinet.local:50000', null],
            'missing port' => ['http://192.168.1.5', null],
            'privileged port' => ['http://192.168.1.5:443', null],
            'path' => ['http://192.168.1.5:50000/upload', null],
            'query' => ['http://192.168.1.5:50000?x=1', null],
            'credentials' => ['http://u:p@192.168.1.5:50000', null],
            'ftp' => ['ftp://192.168.1.5:50000', null],
            'whitespace' => [' http://192.168.1.5:50000', null],
            'empty' => ['', null],
            'not a string' => [50000, null],
        ];
    }

    #[DataProvider('configuredOrigins')]
    public function test_only_an_exact_private_ip_origin_with_a_high_port_is_configured(mixed $url, ?string $expected): void
    {
        config(['medismart.runtime.lan_upload_url' => $url]);

        $this->assertSame($expected, $this->boundary()->configuredOrigin());
    }

    /** @return array<string, array{string, string, bool}> */
    public static function routes(): array
    {
        return [
            'health get' => ['GET', '/health', true],
            'health post' => ['POST', '/health', false],
            'upload page' => ['GET', '/upload/'.self::SELECTOR, true],
            'upload page post' => ['POST', '/upload/'.self::SELECTOR, false],
            'authorize' => ['POST', '/upload/'.self::SELECTOR.'/authorize', true],
            'files' => ['POST', '/upload/'.self::SELECTOR.'/files', true],
            'complete' => ['POST', '/upload/'.self::SELECTOR.'/complete', true],
            'authorize get' => ['GET', '/upload/'.self::SELECTOR.'/authorize', false],
            'short selector' => ['GET', '/upload/short', false],
            'unknown action' => ['POST', '/upload/'.self::SELECTOR.'/delete', false],
            'login' => ['GET', '/login', false],
            'dashboard' => ['GET', '/dashboard', false],
            'root' => ['GET', '/', false],
        ];
    }

    #[DataProvider('routes')]
    public function test_only_health_and_the_four_upload_routes_are_allowed(string $method, string $path, bool $allowed): void
    {
        $this->assertSame($allowed, $this->boundary()->routeAllowed(Request::create($path, $method)));
    }

    public function test_absolute_form_and_protocol_relative_targets_are_refused(): void
    {
        $absolute = Request::create('/health');
        $absolute->server->set('REQUEST_URI', 'http://192.168.1.5:50000/health');
        $protocolRelative = Request::create('/health');
        $protocolRelative->server->set('REQUEST_URI', '//evil/health');

        $this->assertFalse($this->boundary()->routeAllowed($absolute));
        $this->assertFalse($this->boundary()->routeAllowed($protocolRelative));
    }

    public function test_the_authority_must_match_the_configured_origin_exactly(): void
    {
        $this->assertTrue($this->boundary()->authorityMatches($this->lanRequest()));
        $this->assertFalse($this->boundary()->authorityMatches(
            Request::create('http://192.168.1.5:50001/upload/'.self::SELECTOR),
        ));

        config(['medismart.runtime.lan_upload_url' => null]);
        $this->assertFalse($this->boundary()->authorityMatches($this->lanRequest()));
    }

    public function test_a_direct_lan_request_needs_supervision_a_private_peer_and_no_proxy_headers(): void
    {
        $this->assertTrue($this->boundary()->isDirectLanRequest($this->lanRequest()));
        $this->assertTrue($this->boundary()->isDirectLanRequest($this->lanRequest(['REMOTE_ADDR' => '169.254.3.4'])));
        $this->assertFalse($this->boundary()->isDirectLanRequest($this->lanRequest(['REMOTE_ADDR' => '203.0.113.7'])));
        $this->assertFalse($this->boundary()->isDirectLanRequest($this->lanRequest(['HTTP_X_REAL_IP' => '10.0.0.1'])));
        $this->assertFalse($this->boundary()->isDirectLanRequest($this->lanRequest(['HTTP_CF_CONNECTING_IP' => '10.0.0.1'])));

        config(['medismart.runtime.desktop_supervised' => false]);
        $this->assertFalse($this->boundary()->enabled());
        $this->assertFalse($this->boundary()->isDirectLanRequest($this->lanRequest()));
    }

    public function test_the_audience_must_be_the_configured_origin(): void
    {
        $this->assertTrue($this->boundary()->audienceMatches($this->lanRequest(), 'http://192.168.1.5:50000'));
        $this->assertFalse($this->boundary()->audienceMatches($this->lanRequest(), 'http://192.168.1.6:50000'));
    }

    public function test_the_attestation_is_unavailable_without_middleware_evidence(): void
    {
        $attestation = $this->boundary()->attestation(null);

        $this->assertSame(1, $attestation['schema_version']);
        $this->assertSame('unavailable', $attestation['status']);
        $this->assertSame('http://192.168.1.5:50000', $attestation['origin']);
        $this->assertSame(LanUploadBoundary::ROUTE_SET, $attestation['route_set']);
        $this->assertFalse($attestation['upload_routes_only']);
        $this->assertFalse($attestation['exact_origin_enforced']);
        $this->assertFalse($attestation['local_tokens_bound_to_lan_origin']);
    }

    public function test_a_forged_marker_for_another_route_set_is_ignored(): void
    {
        $request = $this->lanRequest();
        $request->attributes->set(LanUploadBoundary::REQUEST_ATTRIBUTE, [
            'route_set' => 'other',
            'upload_routes_only' => true,
            'exact_origin_enforced' => true,
            'explicit_high_port_enforced' => true,
            'direct_private_peer_enforced' => true,
            'forwarding_headers_rejected' => true,
            'local_tokens_bound_to_lan_origin' => true,
        ]);

        $this->assertSame('unavailable', $this->boundary()->attestation($request)['status']);
        $this->assertFalse($this->boundary()->attestation($request)['exact_origin_enforced']);
    }

    private function boundary(): LanUploadBoundary
    {
        return app(LanUploadBoundary::class);
    }

    /** @param array<string, string> $server */
    private function lanRequest(array $server = []): Request
    {
        return Request::create(
            'http://192.168.1.5:50000/upload/'.self::SELECTOR,
            server: array_replace(['REMOTE_ADDR' => '192.168.1.20'], $server),
        );
    }
}
