<?php

namespace Tests\Feature\Uploads;

use App\Models\ApplicationSetting;
use App\Models\UploadSession;
use App\Services\UploadAudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Which network audience may reach an upload session of a given mode.
 * Every refusal must look like a missing page (404).
 */
class UploadAudienceServiceTest extends TestCase
{
    use RefreshDatabase;

    private const string LAN_ORIGIN = 'http://192.168.1.5:50000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'medismart.runtime.desktop_supervised' => false,
            'medismart.runtime.local_url' => 'http://127.0.0.1:43123',
            'medismart.runtime.lan_upload_url' => null,
            'medismart.runtime.remote_upload_url' => null,
            'medismart.runtime.lan_listener_status' => 'active',
            'medismart.runtime.lan_port' => 8000,
        ]);
    }

    public function test_an_unknown_mode_is_never_reachable(): void
    {
        foreach (['relay', '', 'LOCAL'] as $mode) {
            $this->assertRefused($this->uploadSession($mode), Request::create('http://192.168.1.5:8000/upload/x'));
        }
    }

    public function test_a_development_local_session_is_reachable_on_the_preferred_lan_address(): void
    {
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');

        $this->assertAllowed($this->uploadSession('local'), Request::create('http://192.168.1.5:8000/upload/abc', server: [
            'REMOTE_ADDR' => '192.168.1.20',
        ]));
    }

    public function test_a_development_local_session_is_refused_on_another_address_or_port(): void
    {
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');

        $this->assertRefused($this->uploadSession('local'), Request::create('http://192.168.1.6:8000/upload/abc'));
        $this->assertRefused($this->uploadSession('local'), Request::create('http://192.168.1.5:8001/upload/abc'));
        $this->assertRefused($this->uploadSession('local'), Request::create('https://192.168.1.5:8000/upload/abc'));
    }

    public function test_a_local_session_is_refused_while_the_listener_is_stopped(): void
    {
        ApplicationSetting::putValue('network.selected_ipv4', '192.168.1.5');
        config(['medismart.runtime.lan_listener_status' => 'stopped']);

        $this->assertRefused($this->uploadSession('local'), Request::create('http://192.168.1.5:8000/upload/abc'));
    }

    public function test_a_local_session_is_refused_without_any_lan_address(): void
    {
        config(['medismart.runtime.desktop_supervised' => true]);

        $this->assertRefused($this->uploadSession('local'), Request::create('http://192.168.1.5:8000/upload/abc'));
    }

    public function test_a_supervised_local_session_is_reachable_only_from_a_direct_lan_peer(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_upload_url' => self::LAN_ORIGIN,
        ]);
        $session = $this->uploadSession('local');

        $this->assertAllowed($session, $this->lanRequest(['REMOTE_ADDR' => '192.168.1.20']));
        $this->assertRefused($session, $this->lanRequest(['REMOTE_ADDR' => '8.8.8.8']));
        $this->assertRefused($session, $this->lanRequest([
            'REMOTE_ADDR' => '192.168.1.20',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function test_a_supervised_local_session_is_refused_on_the_loopback_listener(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_upload_url' => self::LAN_ORIGIN,
        ]);

        $this->assertRefused($this->uploadSession('local'), Request::create('http://127.0.0.1:43123/upload/abc', server: [
            'REMOTE_ADDR' => '127.0.0.1',
        ]));
    }

    public function test_a_remote_session_is_refused_without_a_verified_tunnel_proxy(): void
    {
        config(['medismart.runtime.remote_upload_url' => 'https://cabinet.example.com']);

        $this->assertRefused($this->uploadSession('remote'), Request::create('https://cabinet.example.com/upload/abc', server: [
            'REMOTE_ADDR' => '127.0.0.1',
        ]));
    }

    public function test_a_remote_session_is_refused_on_the_lan_origin(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.lan_upload_url' => self::LAN_ORIGIN,
        ]);

        $this->assertRefused($this->uploadSession('remote'), $this->lanRequest(['REMOTE_ADDR' => '192.168.1.20']));
    }

    private function uploadSession(string $mode): UploadSession
    {
        return (new UploadSession)->forceFill(['mode' => $mode]);
    }

    /** @param array<string, string> $server */
    private function lanRequest(array $server): Request
    {
        return Request::create(self::LAN_ORIGIN.'/upload/abc', server: $server);
    }

    private function assertAllowed(UploadSession $session, Request $request): void
    {
        app(UploadAudienceService::class)->assertAllowed($request, $session);

        $this->addToAssertionCount(1);
    }

    private function assertRefused(UploadSession $session, Request $request): void
    {
        try {
            app(UploadAudienceService::class)->assertAllowed($request, $session);
            $this->fail("A [{$session->mode}] session was reachable from {$request->getUri()}.");
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }
}
