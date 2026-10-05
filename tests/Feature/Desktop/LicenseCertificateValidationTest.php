<?php

namespace Tests\Feature\Desktop;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Services\LicenseService;
use App\Services\MachineFingerprintService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Field-by-field coverage of the signed desktop licence certificate: every
 * envelope and payload rule must fail closed before anything is persisted.
 */
class LicenseCertificateValidationTest extends TestCase
{
    use RefreshDatabase;

    private \OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPath;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        $path = tempnam(sys_get_temp_dir(), 'medismart-license-validation-');
        $this->assertIsString($path);
        file_put_contents($path, $details['key']);

        $this->privateKey = $key;
        $this->publicKeyPath = $path;
        $this->now = CarbonImmutable::parse('2026-09-01T10:00:00Z');
        $this->travelTo($this->now);

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

    public function test_serials_are_normalized_to_upper_case_dash_separated_groups(): void
    {
        $service = $this->service();

        $this->assertSame('ABCD-EFGH-IJKL', $service->normalizeSerial('  abcd efgh_ijkl  '));
        $this->assertSame('AB12-CD34', $service->normalizeSerial('ab12//cd34'));
    }

    /** @return array<string, array{string, bool}> */
    public static function serialFormats(): array
    {
        return [
            'three groups' => ['abcd-efgh-ijkl', true],
            'six groups of eight' => ['ABCDEFGH-ABCDEFGH-ABCDEFGH-ABCDEFGH-ABCDEFGH-ABCDEFGH', true],
            'spaces normalized' => ['abcd efgh ijkl', true],
            'two groups' => ['ABCD-EFGH', false],
            'short group' => ['ABC-EFGH-IJKL', false],
            'long group' => ['ABCDEFGHI-EFGH-IJKL', false],
            'seven groups' => ['ABCD-ABCD-ABCD-ABCD-ABCD-ABCD-ABCD', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('serialFormats')]
    public function test_serial_format_validation(string $serial, bool $valid): void
    {
        $this->assertSame($valid, $this->service()->hasValidSerialFormat($serial));
    }

    public function test_verification_readiness_tracks_the_public_key_configuration(): void
    {
        $this->assertTrue($this->service()->verificationReady());

        config()->set('medismart.licensing.public_key_path', $this->publicKeyPath.'.missing');
        $this->assertFalse($this->service()->verificationReady());

        config()->set('medismart.licensing.public_key_path', '');
        $this->assertFalse($this->service()->verificationReady());
    }

    /** @return array<string, array{string, string}> */
    public static function malformedEnvelopes(): array
    {
        return [
            'empty' => ['', 'envelope is invalid'],
            'not json' => ['{not json', 'not valid JSON'],
            'json scalar' => ['"text"', 'envelope is invalid'],
            'wrong algorithm' => ['{"algorithm":"HS256","payload":"e30","signature":"AA"}', 'envelope is invalid'],
            'missing payload' => ['{"algorithm":"RS256","signature":"AA"}', 'envelope is invalid'],
            'numeric signature' => ['{"algorithm":"RS256","payload":"e30","signature":12}', 'envelope is invalid'],
            'non base64url signature' => ['{"algorithm":"RS256","payload":"e30","signature":"a+b/c="}', 'invalid base64url'],
            'empty signature' => ['{"algorithm":"RS256","payload":"e30","signature":""}', 'invalid base64url'],
        ];
    }

    #[DataProvider('malformedEnvelopes')]
    public function test_malformed_envelopes_are_rejected(string $certificate, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->service()->verifyCertificate($certificate);
    }

    public function test_an_oversized_envelope_is_rejected_before_parsing(): void
    {
        $this->expectExceptionMessage('envelope is invalid');

        $this->service()->verifyCertificate(str_repeat('a', 131_073));
    }

    public function test_a_signature_from_another_key_is_rejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $other);

        $this->expectExceptionMessage('signature is invalid');

        $this->service()->verifyCertificate($this->certificate($this->payload(), $other));
    }

    public function test_a_signed_payload_that_is_not_json_is_rejected(): void
    {
        $this->expectExceptionMessage('signed license payload is invalid');

        $this->service()->verifyCertificate($this->signRaw('this is not json'));
    }

    /** @return array<string, array{string}> */
    public static function requiredFields(): array
    {
        return array_combine(
            ['license_id', 'product', 'edition', 'installation_id', 'machine_fingerprint_hash', 'issued_at'],
            [['license_id'], ['product'], ['edition'], ['installation_id'], ['machine_fingerprint_hash'], ['issued_at']],
        );
    }

    #[DataProvider('requiredFields')]
    public function test_each_required_field_must_be_a_non_empty_string(string $field): void
    {
        foreach ([null, '', 42] as $value) {
            $payload = $this->payload();

            if ($value === null) {
                unset($payload[$field]);
            } else {
                $payload[$field] = $value;
            }

            try {
                $this->service()->verifyCertificate($this->certificate($payload));
                $this->fail("A payload with an invalid [{$field}] was accepted.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("missing {$field}", $exception->getMessage());
            }
        }
    }

    /** @return array<string, array{mixed}> */
    public static function invalidCertificateVersions(): array
    {
        return [
            'zero' => [0],
            'negative' => [-3],
            'string' => ['1'],
            'float' => [1.5],
            'missing' => [null],
            'too large' => [2_147_483_648],
        ];
    }

    #[DataProvider('invalidCertificateVersions')]
    public function test_the_certificate_version_must_be_a_positive_32_bit_integer(mixed $version): void
    {
        $payload = $this->payload(['certificate_version' => $version]);

        if ($version === null) {
            unset($payload['certificate_version']);
        }

        $this->expectExceptionMessage('certificate version is invalid');

        $this->service()->verifyCertificate($this->certificate($payload));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidIdentityFields(): array
    {
        return [
            'license id leading dash' => ['license_id', '-license'],
            'license id with space' => ['license_id', 'license 1'],
            'license id too long' => ['license_id', str_repeat('a', 129)],
            'edition upper case' => ['edition', 'Professional'],
            'edition leading digit' => ['edition', '1pro'],
            'edition too long' => ['edition', 'a'.str_repeat('b', 32)],
            'installation not uuid' => ['installation_id', 'not-a-uuid'],
            'fingerprint upper hex' => ['machine_fingerprint_hash', str_repeat('A', 64)],
            'fingerprint too short' => ['machine_fingerprint_hash', str_repeat('a', 63)],
        ];
    }

    #[DataProvider('invalidIdentityFields')]
    public function test_identity_fields_are_strictly_formatted(string $field, string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(identifier|edition|fingerprint) is invalid/');

        $this->service()->verifyCertificate($this->certificate($this->payload([$field => $value])));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidCustomerIds(): array
    {
        return [
            'empty' => [''],
            'integer' => [12],
            'too long' => [str_repeat('c', 191)],
        ];
    }

    #[DataProvider('invalidCustomerIds')]
    public function test_an_invalid_customer_identifier_is_rejected(mixed $customerId): void
    {
        $this->expectExceptionMessage('customer identifier is invalid');

        $this->service()->verifyCertificate($this->certificate($this->payload(['customer_id' => $customerId])));
    }

    public function test_a_valid_customer_identifier_is_persisted_on_activation(): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload([
            'customer_id' => 'customer-42',
        ])));

        $this->assertSame('customer-42', $license->customer_id);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidFeatureSets(): array
    {
        return [
            'not an array' => ['remote_upload'],
            'unknown feature' => [['teleportation' => true]],
            'string flag' => [['remote_upload' => 'yes']],
            'list instead of map' => [['remote_upload']],
        ];
    }

    #[DataProvider('invalidFeatureSets')]
    public function test_feature_maps_accept_only_known_boolean_flags(mixed $features): void
    {
        $this->expectExceptionMessage('features are invalid');

        $this->service()->verifyCertificate($this->certificate($this->payload(['features' => $features])));
    }

    public function test_every_declared_feature_is_accepted(): void
    {
        $features = array_fill_keys(LicenseService::FEATURES, true);

        $verified = $this->service()->verifyCertificate($this->certificate($this->payload(['features' => $features])));

        $this->assertSame($features, $verified['features']);
    }

    public function test_a_certificate_for_another_product_is_rejected(): void
    {
        $this->expectExceptionMessage('another product');

        $this->service()->verifyCertificate($this->certificate($this->payload(['product' => 'medismart-server'])));
    }

    public function test_a_certificate_for_another_installation_is_rejected_for_this_installation(): void
    {
        $certificate = $this->certificate($this->payload(['installation_id' => (string) Str::uuid()]));

        $this->assertIsArray($this->service()->verifyCertificate($certificate));
        $this->expectExceptionMessage('another installation');

        $this->service()->verifyCertificateForCurrentInstallation($certificate);
    }

    public function test_a_certificate_for_another_machine_is_rejected_for_this_installation(): void
    {
        $this->expectExceptionMessage('another machine');

        $this->service()->verifyCertificateForCurrentInstallation($this->certificate($this->payload([
            'machine_fingerprint_hash' => str_repeat('0', 64),
        ])));
    }

    public function test_status_without_any_license_is_not_activated(): void
    {
        $this->assertSame([
            'state' => 'not_activated',
            'edition' => null,
            'expires_at' => null,
            'offline_grace_until' => null,
            'last_verified_at' => null,
            'clock_warning' => false,
        ], $this->service()->status());
        $this->assertFalse($this->service()->featureEnabled('remote_upload'));
    }

    public function test_a_perpetual_certificate_is_active_without_expiry_or_grace(): void
    {
        $this->service()->activateFromCertificate($this->certificate($this->payload([
            'features' => ['multi_user' => true],
        ])));

        $status = $this->service()->status();

        $this->assertSame('active', $status['state']);
        $this->assertSame('professional', $status['edition']);
        $this->assertNull($status['expires_at']);
        $this->assertNull($status['offline_grace_until']);
        $this->assertSame($this->now->toIso8601String(), $status['last_verified_at']);
        $this->assertFalse($status['clock_warning']);
        $this->assertTrue($this->service()->featureEnabled('multi_user'));
    }

    public function test_the_state_moves_from_active_to_offline_grace_to_expired(): void
    {
        $this->service()->activateFromCertificate($this->certificate($this->payload([
            'expires_at' => $this->now->addDay()->toIso8601String(),
            'offline_grace_days' => 3,
            'features' => ['remote_upload' => true],
        ])));

        $this->assertSame('active', $this->service()->status()['state']);
        $this->assertSame(
            $this->now->addDays(4)->toIso8601String(),
            $this->service()->status()['offline_grace_until'],
        );

        $this->travelTo($this->now->addDays(2));
        $this->assertSame('offline_grace', $this->service()->status()['state']);
        $this->assertTrue($this->service()->featureEnabled('remote_upload'));

        $this->travelTo($this->now->addDays(5));
        $this->assertSame('expired', $this->service()->status()['state']);
        $this->assertFalse($this->service()->featureEnabled('remote_upload'));
    }

    public function test_expiry_without_grace_days_expires_immediately(): void
    {
        $this->service()->activateFromCertificate($this->certificate($this->payload([
            'expires_at' => $this->now->addHour()->toIso8601String(),
        ])));

        $this->travelTo($this->now->addHour());

        $this->assertSame('expired', $this->service()->status()['state']);
    }

    /** @return array<string, array{string}> */
    public static function terminalStatuses(): array
    {
        return [
            'suspended' => ['suspended'],
            'revoked' => ['revoked'],
            'expired' => ['expired'],
            'device limit' => ['device_limit_reached'],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_signed_terminal_status_disables_every_feature(string $status): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload([
            'status' => $status,
            'features' => ['remote_upload' => true],
        ])));

        $this->assertSame($status, $license->status);
        $this->assertSame($status, $this->service()->status()['state']);
        $this->assertFalse($this->service()->featureEnabled('remote_upload'));
        $this->assertDatabaseHas('license_activations', ['license_id' => $license->getKey(), 'status' => $status]);
    }

    public function test_an_unknown_signed_status_is_rejected(): void
    {
        $this->expectExceptionMessage('status is invalid');

        $this->service()->activateFromCertificate($this->certificate($this->payload(['status' => 'paused'])));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidGraceDays(): array
    {
        return [
            'negative' => [-1],
            'over ten years' => [3651],
            'string' => ['2'],
        ];
    }

    #[DataProvider('invalidGraceDays')]
    public function test_an_invalid_offline_grace_period_is_rejected(mixed $days): void
    {
        $this->expectExceptionMessage('offline grace period is invalid');

        $this->service()->activateFromCertificate($this->certificate($this->payload([
            'expires_at' => $this->now->addDay()->toIso8601String(),
            'offline_grace_days' => $days,
        ])));
    }

    public function test_an_empty_expiry_is_rejected(): void
    {
        $this->expectExceptionMessage('expiry is invalid');

        $this->service()->activateFromCertificate($this->certificate($this->payload(['expires_at' => ''])));
    }

    public function test_a_timestamp_with_fractional_seconds_is_rejected(): void
    {
        $this->expectExceptionMessage('expires_at timestamp is invalid');

        $this->service()->activateFromCertificate($this->certificate($this->payload([
            'expires_at' => '2026-09-02T10:00:00.123Z',
        ])));
    }

    public function test_activation_registers_the_device_and_audits_the_change(): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload()));
        $fingerprint = app(MachineFingerprintService::class);

        $device = Device::query()->where('installation_id', $fingerprint->installationId())->firstOrFail();
        $this->assertSame('active', $device->status);
        $this->assertSame($fingerprint->fingerprintHash(), $device->machine_fingerprint_hash);

        $activation = LicenseActivation::query()->where('license_id', $license->getKey())->firstOrFail();
        $this->assertSame($device->getKey(), $activation->device_id);
        $this->assertNull($activation->deactivated_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.activated']);
    }

    public function test_reactivating_the_identical_certificate_is_idempotent(): void
    {
        $certificate = $this->certificate($this->payload());

        $first = $this->service()->activateFromCertificate($certificate);
        $second = $this->service()->activateFromCertificate($certificate);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, License::query()->count());
        $this->assertSame(1, LicenseActivation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'license.activated')->count());
    }

    public function test_a_newer_certificate_refreshes_the_license_and_audits_the_refresh(): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload()));
        $this->travelTo($this->now->addHour());

        $refreshed = $this->service()->refreshFromCertificate($this->certificate($this->payload([
            'certificate_version' => 2,
            'issued_at' => $this->now->addHour()->toIso8601String(),
            'edition' => 'enterprise',
        ])), $license);

        $this->assertTrue($license->is($refreshed));
        $this->assertSame('enterprise', $refreshed->edition);
        $this->assertSame('enterprise', $this->service()->status()['edition']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.refreshed']);
    }

    public function test_a_higher_version_with_an_older_issue_time_is_rejected(): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload()));

        $this->expectExceptionMessage('older certificate');

        $this->service()->refreshFromCertificate($this->certificate($this->payload([
            'certificate_version' => 2,
            'issued_at' => $this->now->subMinute()->toIso8601String(),
        ])), $license);
    }

    public function test_a_refresh_for_another_license_id_is_rejected(): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload()));

        $this->expectExceptionMessage('belongs to another license');

        $this->service()->refreshFromCertificate($this->certificate($this->payload([
            'license_id' => 'license-other',
            'certificate_version' => 2,
        ])), $license);
    }

    public function test_a_refresh_after_deactivation_is_rejected(): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload()));
        License::query()->whereKey($license->getKey())->delete();

        $this->expectExceptionMessage('no longer active');

        $this->service()->refreshFromCertificate($this->certificate($this->payload([
            'certificate_version' => 2,
        ])), $license);
    }

    public function test_a_stored_row_whose_license_id_was_edited_is_reported_invalid(): void
    {
        $license = $this->service()->activateFromCertificate($this->certificate($this->payload([
            'features' => ['remote_upload' => true],
        ])));
        $license->forceFill(['license_id' => 'license-renamed'])->save();

        $this->assertSame('invalid', $this->service()->status()['state']);
        $this->assertFalse($this->service()->featureEnabled('remote_upload'));
    }

    private function service(): LicenseService
    {
        return app(LicenseService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $fingerprint = app(MachineFingerprintService::class);

        return array_replace([
            'license_id' => 'license-validation-001',
            'certificate_version' => 1,
            'product' => 'medismart-desktop',
            'edition' => 'professional',
            'installation_id' => $fingerprint->installationId(),
            'machine_fingerprint_hash' => $fingerprint->fingerprintHash(),
            'issued_at' => $this->now->toIso8601String(),
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function certificate(array $payload, ?\OpenSSLAsymmetricKey $key = null): string
    {
        return $this->signRaw(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);
    }

    private function signRaw(string $json, ?\OpenSSLAsymmetricKey $key = null): string
    {
        $segment = $this->base64UrlEncode($json);
        $signature = '';
        $this->assertTrue(openssl_sign($segment, $signature, $key ?? $this->privateKey, OPENSSL_ALGO_SHA256));

        return json_encode([
            'algorithm' => 'RS256',
            'payload' => $segment,
            'signature' => $this->base64UrlEncode($signature),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
