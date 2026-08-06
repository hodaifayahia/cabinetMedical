<?php

namespace Tests\Feature\Desktop;

use App\Models\TunnelSetting;
use App\Services\TunnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TunnelServiceNativeStatusTest extends TestCase
{
    use RefreshDatabase;

    private const string KEY = 'native-status-service-test-key-with-thirty-two-bytes';

    private const string INSTALLATION_ID = '97cc6d33-a170-4eec-9a73-dca590bb16a2';

    private string $directory;

    private string $statusPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/medismart-tunnel-service-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->statusPath = $this->directory.'/tunnel-public-status.json';
        config()->set([
            'cache.default' => 'array',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.native_tunnel_status_path' => $this->statusPath,
            'medismart.runtime.native_tunnel_status_maximum_age_ms' => 15_000,
            'medismart.runtime.native_tunnel_status_future_tolerance_ms' => 2_000,
            'medismart.runtime.installation_id' => self::INSTALLATION_ID,
            'medismart.version' => '2.1.0',
            'medismart.health.details_key' => self::KEY,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusPath)) {
            unlink($this->statusPath);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_database_runtime_flags_never_make_the_tunnel_active_without_native_evidence(): void
    {
        $token = str_repeat('S', 48);
        $settings = TunnelSetting::query()->create([
            'provider' => 'cloudflare',
            'mode' => 'named',
            'hostname' => 'upload.example.test',
            'encrypted_tunnel_token' => $token,
            'service_installed' => true,
            'desired_state' => 'running',
            'runtime_state' => 'active',
            'last_health_check_at' => now(),
            'last_error' => "connector leaked {$token}",
        ]);

        $unverified = app(TunnelService::class)->status();

        $this->assertTrue($unverified['configured']);
        $this->assertFalse($unverified['service_installed']);
        $this->assertSame('unavailable', $unverified['runtime_state']);
        $this->assertNull($unverified['last_health_check_at']);
        $this->assertSame('native_tunnel_status_unavailable', $unverified['last_error']);
        $this->assertStringNotContainsString($token, json_encode($unverified, JSON_THROW_ON_ERROR));

        $settings->update([
            'service_installed' => false,
            'runtime_state' => 'stopped',
            'last_health_check_at' => null,
        ]);
        $this->writeReadyStatus();

        $verified = app(TunnelService::class)->status();

        $this->assertTrue($verified['service_installed']);
        $this->assertSame('active', $verified['runtime_state']);
        $this->assertIsString($verified['last_health_check_at']);
        $this->assertNull($verified['last_error']);
        $this->assertSame('running', $verified['desired_state']);
        $this->assertStringNotContainsString($token, json_encode($verified, JSON_THROW_ON_ERROR));
    }

    private function writeReadyStatus(): void
    {
        $payload = [
            'schema_version' => 1,
            'runtime_instance_id' => '2f53505e-e95e-4a36-86a7-c6002fca7b19',
            'installation_id' => self::INSTALLATION_ID,
            'application_version' => '2.1.0',
            'configured_hostname' => 'upload.example.test',
            'phase' => 'ready',
            'listener_origin' => 'http://127.0.0.1:43125',
            'cloudflared_version' => '2026.8.0',
            'executable_verified' => true,
            'retry_count' => 0,
            'last_error_code' => null,
            'updated_at_unix_ms' => now()->getTimestampMs(),
            'sequence' => 7,
        ];
        $fields = [
            'medismart-native-tunnel-status-v1',
            (string) $payload['schema_version'],
            $payload['runtime_instance_id'],
            $payload['installation_id'],
            $payload['application_version'],
            $payload['configured_hostname'],
            $payload['phase'],
            $payload['listener_origin'],
            $payload['cloudflared_version'],
            '1',
            '0',
            '',
            (string) $payload['updated_at_unix_ms'],
            '7',
        ];
        $message = '';
        foreach ($fields as $field) {
            $message .= pack('N', strlen($field)).$field;
        }
        $payload['signature'] = hash_hmac('sha256', $message, self::KEY);

        file_put_contents(
            $this->statusPath,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        chmod($this->statusPath, 0600);
    }
}
