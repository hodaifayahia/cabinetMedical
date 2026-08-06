<?php

namespace Tests\Feature\Desktop;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\License;
use App\Models\Patient;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\LicenseService;
use App\Services\MachineFingerprintService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicenseActivationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private \OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key instanceof \OpenSSLAsymmetricKey) {
            $this->fail('OpenSSL could not generate the test signing key.');
        }

        $details = openssl_pkey_get_details($key);
        $path = tempnam(sys_get_temp_dir(), 'medismart-activation-public-');

        if (! is_array($details)
            || ! is_string($details['key'] ?? null)
            || ! is_string($path)
            || file_put_contents($path, $details['key']) === false) {
            $this->fail('The activation public key could not be prepared.');
        }

        $this->privateKey = $key;
        $this->publicKeyPath = $path;
        config([
            'medismart.licensing.activation_url' => 'https://licenses.medismart.test/v1/activations',
            'medismart.licensing.status_url' => 'https://licenses.medismart.test/v1/licenses/status',
            'medismart.licensing.deactivation_url' => 'https://licenses.medismart.test/v1/activations/deactivate',
            'medismart.licensing.public_key_path' => $path,
            'medismart.licensing.product' => 'medismart-desktop',
            'medismart.version' => '2.2.0-test',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->publicKeyPath) && is_file($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }

        parent::tearDown();
    }

    public function test_an_administrator_can_activate_a_server_signed_license(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $installationId = app(MachineFingerprintService::class)->installationId();
        $machineFingerprintHash = app(MachineFingerprintService::class)->fingerprintHash();
        $certificate = $this->certificate([
            'license_id' => 'license-server-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'offline_grace_days' => 30,
            'features' => ['remote_upload' => true],
        ]);
        Http::fake([
            'licenses.medismart.test/*' => Http::response([
                'license_certificate' => $certificate,
            ]),
        ]);

        $this->actingAs($administrator)
            ->post(route('app.configuration.license.activate'), [
                'serial' => 'medi-1234-abcd-5678',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://licenses.medismart.test/v1/activations'
            && $request['serial'] === 'MEDI-1234-ABCD-5678'
            && $request['installation_id'] === $installationId
            && $request['machine_fingerprint_hash'] === $machineFingerprintHash
            && $request['application_version'] === '2.2.0-test'
            && $request->header('X-MediSmart-License-Protocol')[0] === '1'
            && $request->header('X-MediSmart-License-Operation')[0] === 'activate'
            && Str::isUuid($request->header('Idempotency-Key')[0] ?? null));
        $this->assertDatabaseHas('licenses', [
            'license_id' => 'license-server-001',
            'edition' => 'professional',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('license_activations', [
            'installation_id' => $installationId,
            'status' => 'active',
        ]);
        $auditJson = AuditLog::query()
            ->whereIn('action', ['license.activation_requested', 'license.activated'])
            ->get()
            ->toJson();
        $this->assertStringNotContainsString('MEDI-1234-ABCD-5678', $auditJson);
        $this->assertStringNotContainsString($machineFingerprintHash, $auditJson);
    }

    public function test_activation_fails_closed_when_the_provider_is_not_configured(): void
    {
        config(['medismart.licensing.activation_url' => null]);
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->post(route('app.configuration.license.activate'), [
                'serial' => 'MEDI-1234-ABCD-5678',
            ])
            ->assertStatus(503);

        Http::assertNothingSent();
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_invalid_license_secrets_are_not_flashed_to_the_session(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->from(route('app.configuration.connectivity-backup.edit'))
            ->post(route('app.configuration.license.activate'), [
                'serial' => 'too-short',
            ])
            ->assertRedirect(route('app.configuration.connectivity-backup.edit'))
            ->assertSessionHasErrors('serial')
            ->assertSessionMissing('_old_input.serial');

        Http::assertNothingSent();
    }

    public function test_activation_endpoint_requires_sensitive_settings_permission(): void
    {
        $receptionist = User::factory()->create();
        $receptionist->assignRole(RoleName::RECEPTIONIST->value);

        $this->actingAs($receptionist)
            ->post(route('app.configuration.license.activate'), [
                'serial' => 'MEDI-1234-ABCD-5678',
            ])
            ->assertForbidden();
    }

    public function test_license_changes_require_recent_password_confirmation(): void
    {
        foreach ([
            'app.configuration.license.activate',
            'app.configuration.license.refresh',
            'app.configuration.license.destroy',
        ] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertInstanceOf(Route::class, $route);
            $this->assertContains('password.confirm', $route->gatherMiddleware());
        }

        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        $this->actingAs($administrator)
            ->withSession(['auth.password_confirmed_at' => 0])
            ->post(route('app.configuration.license.activate'), [
                'serial' => 'MEDI-1234-ABCD-5678',
            ])
            ->assertRedirect(route('password.confirm'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_license_endpoint_configuration_rejects_queries_and_non_default_ports(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);

        foreach ([
            'https://licenses.medismart.test/v1/activations?tenant=clinic',
            'https://licenses.medismart.test:8443/v1/activations',
        ] as $invalidUrl) {
            config(['medismart.licensing.activation_url' => $invalidUrl]);

            $this->actingAs($administrator)
                ->post(route('app.configuration.license.activate'), [
                    'serial' => 'MEDI-1234-ABCD-5678',
                ])
                ->assertStatus(503);
        }

        Http::assertNothingSent();
    }

    public function test_an_administrator_can_refresh_to_a_new_signed_terminal_state(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $installationId = app(MachineFingerprintService::class)->installationId();
        $machineFingerprintHash = app(MachineFingerprintService::class)->fingerprintHash();
        $initialCertificate = $this->certificate([
            'license_id' => 'license-refresh-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => now()->subHour()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'offline_grace_days' => 30,
            'features' => ['remote_upload' => true],
        ]);
        app(LicenseService::class)->activateFromCertificate($initialCertificate);
        $refreshedCertificate = $this->certificate([
            'license_id' => 'license-refresh-001',
            'certificate_version' => 2,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'offline_grace_days' => 30,
            'status' => 'revoked',
            'features' => ['remote_upload' => true],
        ]);
        Http::fake([
            'https://licenses.medismart.test/v1/licenses/status' => Http::response([
                'license_certificate' => $refreshedCertificate,
            ]),
        ]);

        $this->actingAs($administrator)
            ->post(route('app.configuration.license.refresh'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://licenses.medismart.test/v1/licenses/status'
            && $request['license_id'] === 'license-refresh-001'
            && $request['installation_id'] === $installationId
            && $request['machine_fingerprint_hash'] === $machineFingerprintHash
            && $request['license_certificate'] === $initialCertificate
            && ! isset($request['serial'])
            && $request->header('X-MediSmart-License-Operation')[0] === 'refresh');
        $this->assertSame('revoked', app(LicenseService::class)->status()['state']);
        $this->assertFalse(app(LicenseService::class)->featureEnabled('remote_upload'));
        $this->assertDatabaseHas('licenses', [
            'license_id' => 'license-refresh-001',
            'status' => 'revoked',
        ]);
        $this->assertStringNotContainsString(
            $initialCertificate,
            AuditLog::query()->get()->toJson(),
        );
    }

    public function test_a_copied_or_rebound_certificate_is_not_sent_to_the_license_server(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $installationId = app(MachineFingerprintService::class)->installationId();
        $machineFingerprintHash = app(MachineFingerprintService::class)->fingerprintHash();
        $certificate = $this->certificate([
            'license_id' => 'license-rebound-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => ['remote_upload' => true],
        ]);
        app(LicenseService::class)->activateFromCertificate($certificate);
        app(ApplicationSettingService::class)->setInternal(
            'desktop.machine_seed',
            str_repeat('e', 64),
        );
        Http::fake();

        $this->actingAs($administrator)
            ->post(route('app.configuration.license.refresh'))
            ->assertRedirect()
            ->assertSessionHasErrors('license_refresh');

        Http::assertNothingSent();
        $this->assertSame('invalid', app(LicenseService::class)->status()['state']);
    }

    public function test_successful_deactivation_removes_the_local_certificate_only_after_server_confirmation(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $installationId = app(MachineFingerprintService::class)->installationId();
        $machineFingerprintHash = app(MachineFingerprintService::class)->fingerprintHash();
        $certificate = $this->certificate([
            'license_id' => 'license-deactivate-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'offline_grace_days' => 30,
            'features' => ['remote_upload' => true],
        ]);
        app(LicenseService::class)->activateFromCertificate($certificate);
        $patient = Patient::factory()->create();
        $backup = BackupRecord::query()->create([
            'filename' => 'preserved.msbackup',
            'disk' => 'local',
            'application_version' => '2.2.0-test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'created_by' => $administrator->getKey(),
        ]);
        Http::fake([
            'https://licenses.medismart.test/v1/activations/deactivate' => Http::response(status: 204),
        ]);

        $this->actingAs($administrator)
            ->delete(route('app.configuration.license.destroy'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://licenses.medismart.test/v1/activations/deactivate'
            && $request['license_id'] === 'license-deactivate-001'
            && $request['installation_id'] === $installationId
            && $request['machine_fingerprint_hash'] === $machineFingerprintHash
            && $request['license_certificate'] === $certificate
            && $request->header('X-MediSmart-License-Operation')[0] === 'deactivate');
        $this->assertDatabaseCount('licenses', 0);
        $this->assertDatabaseCount('license_activations', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.deactivated']);
        $this->assertSame('not_activated', app(LicenseService::class)->status()['state']);
        $this->assertStringNotContainsString($certificate, AuditLog::query()->get()->toJson());
        $this->assertModelExists($patient);
        $this->assertModelExists($backup);
    }

    public function test_failed_deactivation_preserves_the_local_certificate_and_entitlement(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $installationId = app(MachineFingerprintService::class)->installationId();
        $machineFingerprintHash = app(MachineFingerprintService::class)->fingerprintHash();
        $certificate = $this->certificate([
            'license_id' => 'license-deactivate-failure-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'offline_grace_days' => 30,
            'features' => ['remote_upload' => true],
        ]);
        app(LicenseService::class)->activateFromCertificate($certificate);
        Http::fake([
            'https://licenses.medismart.test/v1/activations/deactivate' => Http::response([], 503),
        ]);

        $this->actingAs($administrator)
            ->delete(route('app.configuration.license.destroy'))
            ->assertRedirect()
            ->assertSessionHasErrors('license_deactivation');

        $this->assertDatabaseCount('licenses', 1);
        $this->assertDatabaseHas('licenses', [
            'license_id' => 'license-deactivate-failure-001',
        ]);
        $this->assertSame('active', app(LicenseService::class)->status()['state']);
        $this->assertTrue(app(LicenseService::class)->featureEnabled('remote_upload'));
    }

    public function test_event_history_failure_cannot_reverse_confirmed_deactivation(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(RoleName::ADMINISTRATOR->value);
        $installationId = app(MachineFingerprintService::class)->installationId();
        $machineFingerprintHash = app(MachineFingerprintService::class)->fingerprintHash();
        $certificate = $this->certificate([
            'license_id' => 'license-deactivate-event-failure-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => ['remote_upload' => true],
        ]);
        app(LicenseService::class)->activateFromCertificate($certificate);
        Schema::drop('application_events');
        Http::fake([
            'https://licenses.medismart.test/v1/activations/deactivate' => Http::response(status: 204),
        ]);

        $this->actingAs($administrator)
            ->delete(route('app.configuration.license.destroy'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('licenses', 0);
        $this->assertSame('not_activated', app(LicenseService::class)->status()['state']);
    }

    public function test_scheduled_refresh_is_not_suppressed_by_a_clock_rollback_warning(): void
    {
        $initialTime = CarbonImmutable::parse('2026-08-04T10:00:00Z');
        $this->travelTo($initialTime);
        $installationId = app(MachineFingerprintService::class)->installationId();
        $machineFingerprintHash = app(MachineFingerprintService::class)->fingerprintHash();
        $initialCertificate = $this->certificate([
            'license_id' => 'license-clock-refresh-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => $initialTime->toIso8601String(),
            'expires_at' => $initialTime->addYear()->toIso8601String(),
            'features' => ['remote_upload' => true],
        ]);
        $licenses = app(LicenseService::class);
        $licenses->activateFromCertificate($initialCertificate);

        $this->travelTo($initialTime->addDays(2));
        $this->assertSame('active', $licenses->status()['state']);
        $this->travelTo($initialTime->subDays(2));
        $this->assertTrue($licenses->status()['clock_warning']);

        $refreshedCertificate = $this->certificate([
            'license_id' => 'license-clock-refresh-001',
            'certificate_version' => 2,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $installationId,
            'machine_fingerprint_hash' => $machineFingerprintHash,
            'issued_at' => $initialTime->addDays(3)->toIso8601String(),
            'expires_at' => $initialTime->addYear()->toIso8601String(),
            'features' => ['remote_upload' => true],
        ]);
        Http::fake([
            'https://licenses.medismart.test/v1/licenses/status' => Http::response([
                'license_certificate' => $refreshedCertificate,
            ]),
        ]);

        $this->artisan('medismart:license:refresh')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://licenses.medismart.test/v1/licenses/status');
        $stored = License::query()->firstOrFail();
        $this->assertSame(2, $licenses->verifyCertificate(
            $stored->signed_certificate,
        )['certificate_version']);
    }

    /** @param array<string, mixed> $payload */
    private function certificate(array $payload): string
    {
        $payloadSegment = $this->base64UrlEncode(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $signature = '';

        if (! openssl_sign($payloadSegment, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            $this->fail('OpenSSL could not sign the activation certificate.');
        }

        return json_encode([
            'algorithm' => 'RS256',
            'payload' => $payloadSegment,
            'signature' => $this->base64UrlEncode($signature),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
