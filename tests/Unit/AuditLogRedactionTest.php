<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogRedactionTest extends TestCase
{
    #[Test]
    public function it_redacts_upload_verifiers_from_keys_and_diagnostic_text(): void
    {
        $secret = str_repeat('a', 43);
        $metadata = AuditLog::redactSensitiveMetadata([
            'verifier' => $secret,
            'diagnostic' => "verifier={$secret}",
            'nested' => ['public_token_verifier' => $secret],
        ]);

        $this->assertSame('[redacted]', $metadata['verifier']);
        $this->assertSame('verifier=[redacted]', $metadata['diagnostic']);
        $this->assertSame('[redacted]', $metadata['nested']['public_token_verifier']);
        $this->assertStringNotContainsString($secret, json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_redacts_license_serials_certificates_and_fingerprints(): void
    {
        $metadata = AuditLog::redactSensitiveMetadata([
            'serial' => 'MEDI-1234-ABCD-5678',
            'license_certificate' => 'signed-envelope',
            'machine_fingerprint_hash' => str_repeat('a', 64),
            'diagnostic' => 'serial=MEDI-1234-ABCD-5678',
        ]);

        $this->assertSame('[redacted]', $metadata['serial']);
        $this->assertSame('[redacted]', $metadata['license_certificate']);
        $this->assertSame('[redacted]', $metadata['machine_fingerprint_hash']);
        $this->assertSame('serial=[redacted]', $metadata['diagnostic']);
    }
}
