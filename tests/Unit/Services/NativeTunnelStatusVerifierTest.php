<?php

namespace Tests\Unit\Services;

use App\Services\NativeTunnelStatusException;
use App\Services\NativeTunnelStatusVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class NativeTunnelStatusVerifierTest extends TestCase
{
    private const string KEY = 'native-status-test-key-with-at-least-thirty-two-bytes';

    private const string INSTALLATION_ID = '97cc6d33-a170-4eec-9a73-dca590bb16a2';

    private const string RUNTIME_ID = '2f53505e-e95e-4a36-86a7-c6002fca7b19';

    private string $directory;

    private string $statusPath;

    private int $nowUnixMs = 1_786_000_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/medismart-native-status-'.bin2hex(random_bytes(8));
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
        Date::setTestNow(CarbonImmutable::createFromTimestampMs($this->nowUnixMs, 'UTC'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        if (is_file($this->statusPath)) {
            unlink($this->statusPath);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_accepts_a_fresh_authenticated_ready_status_and_is_idempotent(): void
    {
        $this->writeStatus($this->statusPayload());

        $first = app(NativeTunnelStatusVerifier::class)->verify('upload.example.test');
        $second = app(NativeTunnelStatusVerifier::class)->verify('upload.example.test');

        $this->assertSame('ready', $first->phase);
        $this->assertSame('http://127.0.0.1:43125', $first->listenerOrigin);
        $this->assertTrue($first->executableVerified);
        $this->assertSame($first->signature, $second->signature);
    }

    public function test_it_rejects_tampering_unknown_fields_and_unsafe_file_permissions(): void
    {
        $payload = $this->statusPayload();
        $this->writeStatus($payload);
        $raw = file_get_contents($this->statusPath);
        $this->assertIsString($raw);
        file_put_contents($this->statusPath, str_replace('"ready"', '"stopped"', $raw));
        chmod($this->statusPath, 0600);
        $this->assertVerificationFails('native_tunnel_status_authentication_failed');

        $payload = $this->statusPayload(['unexpected' => 'field']);
        $this->writeStatus($payload);
        $this->assertVerificationFails('native_tunnel_status_invalid');

        $this->writeStatus($this->statusPayload());
        chmod($this->statusPath, 0644);
        $this->assertVerificationFails('native_tunnel_status_unavailable');
    }

    public function test_it_rejects_stale_future_and_wrong_identity_statuses(): void
    {
        $this->writeStatus($this->statusPayload([
            'updated_at_unix_ms' => $this->nowUnixMs - 15_001,
        ]));
        $this->assertVerificationFails('native_tunnel_status_stale');

        $this->writeStatus($this->statusPayload([
            'updated_at_unix_ms' => $this->nowUnixMs + 2_001,
        ]));
        $this->assertVerificationFails('native_tunnel_status_from_future');

        $this->writeStatus($this->statusPayload([
            'installation_id' => '3c48c0f5-50f6-47bb-8f35-73b87e2de670',
        ]));
        $this->assertVerificationFails('native_tunnel_status_identity_mismatch');

        $this->writeStatus($this->statusPayload([
            'application_version' => '2.0.9',
        ]));
        $this->assertVerificationFails('native_tunnel_status_identity_mismatch');
    }

    public function test_ready_requires_the_expected_hostname_exact_loopback_origin_and_verified_binary(): void
    {
        $this->writeStatus($this->statusPayload([
            'configured_hostname' => 'other.example.test',
        ]));
        $this->assertVerificationFails('native_tunnel_status_hostname_mismatch');

        $this->writeStatus($this->statusPayload([
            'listener_origin' => 'http://localhost:43125',
        ]));
        $this->assertVerificationFails('native_tunnel_status_invalid');

        $this->writeStatus($this->statusPayload([
            'executable_verified' => false,
        ]));
        $this->assertVerificationFails('native_tunnel_status_invalid');
    }

    public function test_it_rejects_sequence_and_runtime_replays_but_accepts_forward_progress(): void
    {
        $verifier = app(NativeTunnelStatusVerifier::class);
        $this->writeStatus($this->statusPayload([
            'sequence' => 4,
            'updated_at_unix_ms' => $this->nowUnixMs - 4,
        ]));
        $verifier->verify('upload.example.test');

        $this->writeStatus($this->statusPayload([
            'sequence' => 5,
            'updated_at_unix_ms' => $this->nowUnixMs - 3,
        ]));
        $verifier->verify('upload.example.test');

        $this->writeStatus($this->statusPayload([
            'sequence' => 4,
            'updated_at_unix_ms' => $this->nowUnixMs - 2,
        ]));
        $this->assertVerificationFails('native_tunnel_status_replayed');

        $this->writeStatus($this->statusPayload([
            'runtime_instance_id' => 'c903076b-577f-4a51-9eae-9d6e8b6ab70f',
            'sequence' => 1,
            'updated_at_unix_ms' => $this->nowUnixMs - 3,
        ]));
        $this->assertVerificationFails('native_tunnel_status_replayed');

        $this->writeStatus($this->statusPayload([
            'runtime_instance_id' => 'c903076b-577f-4a51-9eae-9d6e8b6ab70f',
            'sequence' => 1,
            'updated_at_unix_ms' => $this->nowUnixMs - 1,
        ]));
        $evidence = $verifier->verify('upload.example.test');
        $this->assertSame(1, $evidence->sequence);
    }

    public function test_it_fails_closed_outside_a_valid_supervised_identity_contract(): void
    {
        $this->writeStatus($this->statusPayload());
        config()->set('medismart.runtime.desktop_supervised', false);
        $this->assertVerificationFails('native_tunnel_status_configuration_invalid');

        config()->set('medismart.runtime.desktop_supervised', true);
        config()->set('medismart.health.details_key', 'too-short');
        $this->assertVerificationFails('native_tunnel_status_configuration_invalid');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function statusPayload(array $overrides = []): array
    {
        $payload = array_replace([
            'schema_version' => 1,
            'runtime_instance_id' => self::RUNTIME_ID,
            'installation_id' => self::INSTALLATION_ID,
            'application_version' => '2.1.0',
            'configured_hostname' => 'upload.example.test',
            'phase' => 'ready',
            'listener_origin' => 'http://127.0.0.1:43125',
            'cloudflared_version' => '2026.8.0',
            'executable_verified' => true,
            'retry_count' => 0,
            'last_error_code' => null,
            'updated_at_unix_ms' => $this->nowUnixMs,
            'sequence' => 1,
        ], $overrides);
        $payload['signature'] = $this->signature($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeStatus(array $payload): void
    {
        file_put_contents(
            $this->statusPath,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        chmod($this->statusPath, 0600);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signature(array $payload): string
    {
        $fields = [
            'medismart-native-tunnel-status-v1',
            (string) $payload['schema_version'],
            $payload['runtime_instance_id'],
            $payload['installation_id'],
            $payload['application_version'],
            $payload['configured_hostname'] ?? '',
            $payload['phase'],
            $payload['listener_origin'] ?? '',
            $payload['cloudflared_version'] ?? '',
            $payload['executable_verified'] ? '1' : '0',
            (string) $payload['retry_count'],
            $payload['last_error_code'] ?? '',
            (string) $payload['updated_at_unix_ms'],
            (string) $payload['sequence'],
        ];
        $message = '';
        foreach ($fields as $field) {
            $message .= pack('N', strlen($field)).$field;
        }

        return hash_hmac('sha256', $message, self::KEY);
    }

    private function assertVerificationFails(string $reason): void
    {
        try {
            app(NativeTunnelStatusVerifier::class)->verify('upload.example.test');
            $this->fail("Expected native status verification to fail with {$reason}.");
        } catch (NativeTunnelStatusException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
