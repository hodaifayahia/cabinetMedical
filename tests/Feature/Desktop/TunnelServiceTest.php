<?php

namespace Tests\Feature\Desktop;

use App\Models\TunnelSetting;
use App\Services\TunnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TunnelServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['medismart.runtime.desktop_supervised' => false]);
    }

    public function test_an_unconfigured_installation_reports_a_stopped_named_cloudflare_tunnel(): void
    {
        $this->assertSame([
            'configured' => false,
            'provider' => 'cloudflare',
            'mode' => 'named',
            'hostname' => null,
            'service_installed' => false,
            'cloudflared_version' => null,
            'retry_count' => null,
            'desired_state' => 'stopped',
            'runtime_state' => 'stopped',
            'last_health_check_at' => null,
            'last_error' => null,
        ], $this->tunnel()->status());
    }

    public function test_a_hostname_without_a_token_is_reported_as_incomplete(): void
    {
        TunnelSetting::query()->create([
            'provider' => 'cloudflare',
            'mode' => 'named',
            'hostname' => 'cabinet.example.com',
            'desired_state' => 'running',
            'runtime_state' => 'active',
            'service_installed' => true,
        ]);

        $status = $this->tunnel()->status();

        $this->assertFalse($status['configured']);
        $this->assertSame('cabinet.example.com', $status['hostname']);
        $this->assertSame('running', $status['desired_state']);
        $this->assertSame('stopped', $status['runtime_state']);
        $this->assertFalse($status['service_installed']);
        $this->assertSame('tunnel_configuration_incomplete', $status['last_error']);
    }

    public function test_a_token_without_a_hostname_is_reported_as_incomplete(): void
    {
        TunnelSetting::query()->create([
            'provider' => 'cloudflare',
            'mode' => 'named',
            'encrypted_tunnel_token' => 'secret-token',
        ]);

        $this->assertSame('tunnel_configuration_incomplete', $this->tunnel()->status()['last_error']);
    }

    public function test_rows_for_another_provider_or_mode_are_ignored(): void
    {
        TunnelSetting::query()->create([
            'provider' => 'ngrok',
            'mode' => 'named',
            'hostname' => 'cabinet.example.com',
            'encrypted_tunnel_token' => 'secret-token',
        ]);
        TunnelSetting::query()->create([
            'provider' => 'cloudflare',
            'mode' => 'quick',
            'hostname' => 'cabinet.example.com',
            'encrypted_tunnel_token' => 'secret-token',
        ]);

        $status = $this->tunnel()->status();

        $this->assertFalse($status['configured']);
        $this->assertNull($status['hostname']);
        $this->assertNull($status['last_error']);
    }

    public function test_a_configured_tunnel_without_native_evidence_is_unavailable(): void
    {
        TunnelSetting::query()->create([
            'provider' => 'cloudflare',
            'mode' => 'named',
            'hostname' => 'cabinet.example.com',
            'encrypted_tunnel_token' => 'secret-token',
            'desired_state' => 'running',
            'runtime_state' => 'active',
            'service_installed' => true,
        ]);

        $status = $this->tunnel()->status();

        $this->assertTrue($status['configured']);
        $this->assertSame('unavailable', $status['runtime_state']);
        $this->assertFalse($status['service_installed']);
        $this->assertNull($status['last_health_check_at']);
        $this->assertSame('native_tunnel_status_configuration_invalid', $status['last_error']);
    }

    public function test_the_tunnel_token_is_stored_encrypted(): void
    {
        $settings = TunnelSetting::query()->create(['provider' => 'cloudflare', 'mode' => 'named']);

        $this->tunnel()->storeToken($settings, 'eyJhIjoiY2xvdWRmbGFyZS10b2tlbiJ9');

        $raw = DB::table('tunnel_settings')->where('id', $settings->getKey())->value('encrypted_tunnel_token');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('eyJhIjoiY2xvdWRmbGFyZS10b2tlbiJ9', $raw);
        $this->assertSame('eyJhIjoiY2xvdWRmbGFyZS10b2tlbiJ9', $settings->fresh()->encrypted_tunnel_token);
        $this->assertArrayNotHasKey('encrypted_tunnel_token', $settings->fresh()->toArray());
    }

    public function test_redaction_removes_the_stored_token_wherever_it_appears(): void
    {
        $settings = TunnelSetting::query()->create(['provider' => 'cloudflare', 'mode' => 'named']);
        $this->tunnel()->storeToken($settings, 'stored-token-value');

        $redacted = $this->tunnel()->redact('starting with stored-token-value as credential');

        $this->assertStringNotContainsString('stored-token-value', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
    }

    public function test_redaction_masks_cli_json_and_bearer_credentials_without_a_stored_token(): void
    {
        $text = implode("\n", [
            'cloudflared tunnel run --token abc.def.ghi',
            'cloudflared tunnel run --token=xyz123',
            '{"token": "json-token-value", "secret":"json-secret"}',
            'Authorization: Bearer bearer-value-123',
        ]);

        $redacted = $this->tunnel()->redact($text);

        foreach (['abc.def.ghi', 'xyz123', 'json-token-value', 'json-secret', 'bearer-value-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
        }
        $this->assertStringContainsString('cloudflared tunnel run --token', $redacted);
    }

    public function test_redaction_leaves_harmless_text_untouched(): void
    {
        $this->assertSame(
            'Tunnel connected to edge in 120 ms',
            $this->tunnel()->redact('Tunnel connected to edge in 120 ms'),
        );
    }

    private function tunnel(): TunnelService
    {
        return app(TunnelService::class);
    }
}
