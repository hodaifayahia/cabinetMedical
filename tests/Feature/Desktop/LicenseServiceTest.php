<?php

namespace Tests\Feature\Desktop;

use App\Models\ApplicationSetting;
use App\Services\ApplicationSettingService;
use App\Services\LicenseService;
use App\Services\MachineFingerprintService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LicenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private \OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key instanceof \OpenSSLAsymmetricKey) {
            $this->fail('OpenSSL could not generate the test signing key.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            $this->fail('OpenSSL could not export the test public key.');
        }

        $path = tempnam(sys_get_temp_dir(), 'medismart-license-public-');

        if ($path === false || file_put_contents($path, $details['key']) === false) {
            $this->fail('The test public key could not be persisted.');
        }

        $this->privateKey = $key;
        $this->publicKeyPath = $path;

        config()->set('medismart.licensing.product', 'medismart-desktop');
        config()->set('medismart.licensing.public_key_path', $this->publicKeyPath);
    }

    protected function tearDown(): void
    {
        if (isset($this->publicKeyPath) && is_file($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }

        parent::tearDown();
    }

    public function test_it_verifies_a_signed_certificate_and_rejects_a_tampered_payload(): void
    {
        $payload = $this->basePayload();
        $certificate = $this->certificate($payload);
        $service = app(LicenseService::class);

        $verified = $service->verifyCertificate($certificate);

        $this->assertSame($payload['license_id'], $verified['license_id']);
        $this->assertSame('professional', $verified['edition']);

        $envelope = json_decode($certificate, true, flags: JSON_THROW_ON_ERROR);
        $tamperedPayload = $payload;
        $tamperedPayload['edition'] = 'enterprise';
        $envelope['payload'] = $this->base64UrlEncode(json_encode(
            $tamperedPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature is invalid');

        $service->verifyCertificate(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function test_relative_or_overflowing_signed_timestamps_are_rejected(): void
    {
        $service = app(LicenseService::class);

        foreach (['now', '2026-02-31T10:00:00Z'] as $issuedAt) {
            try {
                $service->activateFromCertificate($this->certificate($this->basePayload([
                    'issued_at' => $issuedAt,
                ])));
                $this->fail("The signed timestamp [{$issuedAt}] was accepted.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('timestamp is invalid', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_certificate_arguments_are_redacted_from_exception_traces(): void
    {
        $certificate = 'private-certificate-'.str_repeat('x', 64);

        try {
            app(LicenseService::class)->verifyCertificate($certificate);
            $this->fail('The invalid certificate did not fail verification.');
        } catch (RuntimeException $exception) {
            $trace = print_r($exception->getTrace(), true);
            $this->assertStringNotContainsString($certificate, $trace);
            $this->assertStringNotContainsString($certificate, $exception->getMessage());
        }
    }

    public function test_expiry_offline_grace_and_feature_gates_are_enforced(): void
    {
        $now = CarbonImmutable::parse('2026-08-04 10:00:00', config('app.timezone'));
        $this->travelTo($now);

        $payload = $this->basePayload([
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $now->addDay()->toIso8601String(),
            'offline_grace_days' => 2,
            'features' => [
                'remote_upload' => true,
                'google_drive_backup' => false,
            ],
        ]);

        $service = app(LicenseService::class);
        $license = $service->activateFromCertificate($this->certificate($payload));

        $this->assertSame('active', $service->status()['state']);
        $this->assertTrue($service->featureEnabled('remote_upload'));
        $this->assertFalse($service->featureEnabled('google_drive_backup'));
        $this->assertFalse($service->featureEnabled('unlicensed_feature'));
        $this->assertDatabaseHas('license_activations', [
            'license_id' => $license->getKey(),
            'installation_id' => $payload['installation_id'],
            'status' => 'active',
        ]);

        $this->travelTo($now->addDay()->addMinute());

        $this->assertSame('offline_grace', $service->status()['state']);
        $this->assertTrue($service->featureEnabled('remote_upload'));

        $this->travelTo($now->addDays(3)->addMinute());

        $this->assertSame('expired', $service->status()['state']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
    }

    public function test_unknown_or_malformed_signed_entitlements_are_rejected_before_persistence(): void
    {
        $invalidPayloads = [
            ['features' => ['unknown_capability' => true]],
            ['features' => ['remote_upload' => 'yes']],
            ['edition' => 'Professional plan'],
            ['offline_grace_days' => 3651],
        ];

        foreach ($invalidPayloads as $overrides) {
            try {
                app(LicenseService::class)->activateFromCertificate(
                    $this->certificate($this->basePayload($overrides)),
                );
                $this->fail('Malformed signed entitlement metadata was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('signed', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('licenses', 0);
        $this->assertDatabaseCount('license_activations', 0);
    }

    public function test_mutable_database_projections_cannot_extend_expiry_or_grant_features(): void
    {
        $now = CarbonImmutable::parse('2026-08-04 10:00:00', config('app.timezone'));
        $this->travelTo($now);

        $service = app(LicenseService::class);
        $license = $service->activateFromCertificate($this->certificate($this->basePayload([
            'expires_at' => $now->subDay()->toIso8601String(),
            'offline_grace_days' => 0,
            'features' => ['remote_upload' => false],
        ])));

        $license->forceFill([
            'status' => 'active',
            'expires_at' => $now->addYears(10),
            'offline_grace_until' => $now->addYears(10),
            'last_server_response' => [
                'features' => ['remote_upload' => true, 'google_drive_backup' => true],
            ],
        ])->save();

        $this->assertSame('expired', $service->status()['state']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
        $this->assertFalse($service->featureEnabled('google_drive_backup'));
    }

    public function test_only_a_signed_terminal_status_can_disable_a_certificate_without_being_bypassed_by_a_row_edit(): void
    {
        $service = app(LicenseService::class);
        $license = $service->activateFromCertificate($this->certificate($this->basePayload([
            'status' => 'revoked',
            'features' => ['remote_upload' => true],
        ])));

        $license->update(['status' => 'active']);

        $this->assertSame('revoked', $service->status()['state']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
    }

    public function test_a_tampered_stored_certificate_fails_closed(): void
    {
        $service = app(LicenseService::class);
        $license = $service->activateFromCertificate($this->certificate($this->basePayload([
            'features' => ['remote_upload' => true],
        ])));
        $envelope = json_decode($license->signed_certificate, true, flags: JSON_THROW_ON_ERROR);
        $envelope['payload'] = $this->base64UrlEncode(json_encode(
            array_replace($this->basePayload(), ['edition' => 'tampered']),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $license->update([
            'signed_certificate' => json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);

        $this->assertSame('invalid', $service->status()['state']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
    }

    public function test_a_changed_installation_binding_fails_closed(): void
    {
        $service = app(LicenseService::class);
        $service->activateFromCertificate($this->certificate($this->basePayload([
            'features' => ['remote_upload' => true],
        ])));

        ApplicationSetting::putValue(
            'desktop.installation_id',
            (string) Str::uuid(),
            group: 'desktop',
        );

        $this->assertSame('invalid', $service->status()['state']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
    }

    public function test_a_changed_machine_fingerprint_binding_fails_closed(): void
    {
        $service = app(LicenseService::class);
        $service->activateFromCertificate($this->certificate($this->basePayload([
            'features' => ['remote_upload' => true],
        ])));

        app(ApplicationSettingService::class)->setInternal(
            'desktop.machine_seed',
            str_repeat('f', 64),
        );

        $this->assertSame('invalid', $service->status()['state']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
    }

    public function test_a_missing_clock_anchor_fails_closed_instead_of_resetting_trust(): void
    {
        $service = app(LicenseService::class);
        $service->activateFromCertificate($this->certificate($this->basePayload([
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => ['remote_upload' => true],
        ])));

        ApplicationSetting::query()
            ->where('key', 'licensing.trusted_time')
            ->delete();

        $status = $service->status();
        $this->assertSame('clock_rollback', $status['state']);
        $this->assertTrue($status['clock_warning']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
    }

    public function test_a_same_version_refresh_cannot_replay_or_downgrade_the_certificate(): void
    {
        $now = CarbonImmutable::parse('2026-08-04 10:00:00', config('app.timezone'));
        $this->travelTo($now);
        $service = app(LicenseService::class);
        $license = $service->activateFromCertificate($this->certificate($this->basePayload([
            'certificate_version' => 4,
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $now->addYear()->toIso8601String(),
            'features' => ['remote_upload' => true],
        ])));
        $replay = $this->certificate($this->basePayload([
            'certificate_version' => 4,
            'issued_at' => $now->addHour()->toIso8601String(),
            'expires_at' => $now->subDay()->toIso8601String(),
            'status' => 'revoked',
            'features' => ['remote_upload' => false],
        ]));

        try {
            $service->refreshFromCertificate($replay, $license);
            $this->fail('A same-version replacement certificate was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('replayed certificate', $exception->getMessage());
        }

        $this->assertSame('active', $service->status()['state']);
        $this->assertTrue($service->featureEnabled('remote_upload'));
        $this->assertSame(4, $service->verifyCertificate(
            $license->refresh()->signed_certificate,
        )['certificate_version']);
    }

    public function test_a_second_license_cannot_replace_the_current_one_without_deactivation(): void
    {
        $service = app(LicenseService::class);
        $current = $service->activateFromCertificate($this->certificate($this->basePayload([
            'license_id' => 'license-current-001',
        ])));
        $replacement = $this->certificate($this->basePayload([
            'license_id' => 'license-other-001',
            'certificate_version' => 2,
        ]));

        try {
            $service->activateFromCertificate($replacement);
            $this->fail('A different license replaced the active certificate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Deactivate the current license', $exception->getMessage());
        }

        $this->assertDatabaseCount('licenses', 1);
        $this->assertDatabaseHas('licenses', ['id' => $current->getKey()]);
    }

    public function test_invalid_verification_configuration_fails_closed(): void
    {
        $service = app(LicenseService::class);
        $service->activateFromCertificate($this->certificate($this->basePayload([
            'features' => ['remote_upload' => true],
        ])));

        config()->set('medismart.licensing.public_key_path', '/missing/medismart-license-public.pem');

        $this->assertSame('invalid', $service->status()['state']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
    }

    public function test_a_private_signing_key_is_rejected_as_client_configuration(): void
    {
        $privatePem = '';

        if (! openssl_pkey_export($this->privateKey, $privatePem)) {
            $this->fail('OpenSSL could not export the test private key.');
        }

        $path = tempnam(sys_get_temp_dir(), 'medismart-private-license-key-');

        if ($path === false || file_put_contents($path, $privatePem) === false) {
            $this->fail('The private key fixture could not be written.');
        }

        config()->set('medismart.licensing.public_key_path', $path);

        try {
            app(LicenseService::class)->verifyCertificate($this->certificate($this->basePayload()));
            $this->fail('A private signing key was accepted by the desktop verifier.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must never be configured', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_a_major_clock_rollback_disables_only_premium_features_until_time_is_corrected(): void
    {
        $initialTime = CarbonImmutable::parse('2026-08-04 10:00:00', config('app.timezone'));
        $this->travelTo($initialTime);
        $service = app(LicenseService::class);
        $service->activateFromCertificate($this->certificate($this->basePayload([
            'issued_at' => $initialTime->toIso8601String(),
            'expires_at' => $initialTime->addYear()->toIso8601String(),
            'offline_grace_days' => 30,
            'features' => ['remote_upload' => true],
        ])));

        $this->travelTo($initialTime->addDays(2));
        $this->assertSame('active', $service->status()['state']);

        $this->travelTo($initialTime->subDays(2));
        $rolledBack = $service->status();

        $this->assertSame('clock_rollback', $rolledBack['state']);
        $this->assertTrue($rolledBack['clock_warning']);
        $this->assertFalse($service->featureEnabled('remote_upload'));
        $anchor = ApplicationSetting::query()
            ->where('key', 'licensing.trusted_time')
            ->firstOrFail();
        $this->assertNull($anchor->plain_value);
        $this->assertNotNull($anchor->getRawOriginal('encrypted_value'));

        $this->travelTo($initialTime->addDays(2));
        $corrected = $service->status();
        $this->assertSame('active', $corrected['state']);
        $this->assertFalse($corrected['clock_warning']);
        $this->assertTrue($service->featureEnabled('remote_upload'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_replace([
            'license_id' => 'license-test-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => app(MachineFingerprintService::class)->installationId(),
            'machine_fingerprint_hash' => app(MachineFingerprintService::class)->fingerprintHash(),
            'issued_at' => now()->toIso8601String(),
        ], $overrides);
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
            $this->fail('OpenSSL could not sign the test certificate.');
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
