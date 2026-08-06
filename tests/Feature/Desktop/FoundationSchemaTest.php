<?php

namespace Tests\Feature\Desktop;

use App\Models\ApplicationSetting;
use App\Models\AuditLog;
use App\Models\CloudConnection;
use App\Models\TunnelSetting;
use App\Services\NetworkService;
use App\Services\TunnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_one_tables_and_profile_fields_exist(): void
    {
        foreach ([
            'application_settings',
            'upload_sessions',
            'uploaded_documents',
            'tunnel_settings',
            'backup_records',
            'cloud_connections',
            'licenses',
            'license_activations',
            'devices',
            'audit_logs',
            'application_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} to exist.");
        }

        $this->assertTrue(Schema::hasColumns('doctor_profiles', [
            'specialty_code',
            'specialty_locked_at',
            'medical_order_number',
            'footer_extra_line',
        ]));
    }

    public function test_sensitive_application_settings_are_encrypted_at_rest(): void
    {
        ApplicationSetting::putValue(
            'desktop.test_secret',
            'not-plain-text',
            encrypted: true,
            group: 'testing',
        );

        $stored = DB::table('application_settings')
            ->where('key', 'desktop.test_secret')
            ->first();

        $this->assertNotNull($stored);
        $this->assertNull($stored->plain_value);
        $this->assertNotSame('not-plain-text', $stored->encrypted_value);
        $this->assertSame('not-plain-text', ApplicationSetting::valueFor('desktop.test_secret'));
    }

    public function test_tunnel_tokens_are_encrypted_and_diagnostics_are_redacted(): void
    {
        $settings = TunnelSetting::query()->create([
            'provider' => 'cloudflare',
            'mode' => 'named',
            'hostname' => 'upload.example.test',
            'encrypted_tunnel_token' => 'super-secret-token',
        ]);

        $raw = DB::table('tunnel_settings')->where('id', $settings->getKey())->value('encrypted_tunnel_token');

        $this->assertIsString($raw);
        $this->assertNotSame('super-secret-token', $raw);
        $this->assertSame(
            'cloudflared --token [redacted]',
            app(TunnelService::class)->redact('cloudflared --token super-secret-token'),
        );
    }

    public function test_tunnel_and_drive_errors_are_redacted_before_persistence(): void
    {
        $tunnel = TunnelSetting::query()->create([
            'provider' => 'cloudflare',
            'mode' => 'named',
            'last_error' => 'Authorization: Bearer tunnel-secret-value',
        ]);
        $drive = CloudConnection::query()->create([
            'provider' => 'google_drive',
            'status' => 'error',
            'last_error' => 'GET https://example.test/callback?code=drive-code&state=drive-state',
        ]);

        $storedTunnelError = DB::table('tunnel_settings')
            ->where('id', $tunnel->getKey())
            ->value('last_error');
        $storedDriveError = DB::table('cloud_connections')
            ->where('id', $drive->getKey())
            ->value('last_error');

        $this->assertIsString($storedTunnelError);
        $this->assertIsString($storedDriveError);
        $this->assertStringNotContainsString('tunnel-secret-value', $storedTunnelError);
        $this->assertStringNotContainsString('drive-code', $storedDriveError);
        $this->assertStringNotContainsString('drive-state', $storedDriveError);
        $this->assertStringContainsString('[redacted]', $storedTunnelError);
        $this->assertStringContainsString('[redacted]', $storedDriveError);
    }

    public function test_audit_metadata_redacts_secret_fields_recursively(): void
    {
        $audit = AuditLog::record('test.redaction', metadata: [
            'access_token' => 'do-not-store',
            'safe' => 'visible',
            'nested' => ['password' => 'do-not-store-either'],
        ]);

        /** @var array<string, mixed> $metadata */
        $metadata = $audit->getAttribute('metadata');
        $this->assertSame('[redacted]', $metadata['access_token']);
        $this->assertSame('visible', $metadata['safe']);
        $nested = $metadata['nested'];
        $this->assertIsArray($nested);
        $this->assertSame('[redacted]', $nested['password']);
    }

    public function test_audit_text_redacts_urls_headers_jwts_and_upload_selectors(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJjbGluaWMifQ.signature-value-123';
        $text = implode("\n", [
            'GET https://doctor:password@example.test/callback?code=oauth-code&state=oauth-state',
            'Authorization: Bearer bearer-value-123',
            'Cookie: medismart_session=session-value-123',
            'JWT '.$jwt,
            'GET /upload/abcdefghijklmnopqrstuv',
        ]);

        $redacted = AuditLog::redactSensitiveText($text);

        foreach ([
            'doctor',
            'password',
            'oauth-code',
            'oauth-state',
            'bearer-value-123',
            'session-value-123',
            $jwt,
            'abcdefghijklmnopqrstuv',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
        }

        $this->assertStringContainsString('[redacted]', $redacted);
    }

    public function test_public_addresses_are_not_selected_for_the_lan_upload_listener(): void
    {
        ApplicationSetting::putValue('network.selected_ipv4', '203.0.113.10', group: 'network');
        ApplicationSetting::putValue('network.manual_ipv4', '192.168.250.250', group: 'network');

        $address = app(NetworkService::class)->preferredIpv4();

        $this->assertNotSame('203.0.113.10', $address);
        $this->assertNotNull($address);
        $this->assertTrue(
            str_starts_with($address, '10.')
                || str_starts_with($address, '192.168.')
                || preg_match('/^172\.(?:1[6-9]|2\d|3[01])\./', $address) === 1,
        );
    }
}
