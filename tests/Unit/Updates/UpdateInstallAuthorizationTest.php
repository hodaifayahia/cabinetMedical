<?php

namespace Tests\Unit\Updates;

use App\Updates\UpdateInstallAuthorization;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

class UpdateInstallAuthorizationTest extends TestCase
{
    public function test_it_signs_an_exact_short_lived_backup_bound_payload(): void
    {
        config([
            'app.key' => 'base64:test-app-key',
            'medismart.updates.install_authorization_ttl_seconds' => 300,
        ]);

        $artifact = app(UpdateInstallAuthorization::class)->issue(
            targetVersion: '1.2.3',
            backupRecordId: '57dca9dd-6c10-49c8-ae81-3d773bf36582',
            backupSha256: str_repeat('42', 32),
            installationId: 'e169a732-1f4e-46ed-b5b8-a0bc752f6f09',
            now: CarbonImmutable::createFromTimestampUTC(1_700_000_000),
            nonce: 'ad7b2dc9-9c8b-4c82-acf3-f76aa915ee09',
        );

        $this->assertSame(UpdateInstallAuthorization::PROTOCOL, $artifact['protocol']);
        $this->assertSame(1_700_000_300, $artifact['expires_at']);
        $this->assertSame(
            hash_hmac(
                'sha256',
                implode("\n", [
                    'medismart-update-install-authorization',
                    '1',
                    '1.2.3',
                    '57dca9dd-6c10-49c8-ae81-3d773bf36582',
                    str_repeat('42', 32),
                    'e169a732-1f4e-46ed-b5b8-a0bc752f6f09',
                    '1700000000',
                    '1700000300',
                    'ad7b2dc9-9c8b-4c82-acf3-f76aa915ee09',
                ]),
                'base64:test-app-key',
            ),
            $artifact['signature'],
        );
    }

    public function test_it_rejects_invalid_versions_hashes_and_identifiers(): void
    {
        config(['app.key' => 'base64:test-app-key']);

        $this->expectException(RuntimeException::class);

        app(UpdateInstallAuthorization::class)->issue(
            targetVersion: "1.2.3\nforged",
            backupRecordId: 'not-a-uuid',
            backupSha256: str_repeat('A', 64),
            installationId: 'not-a-uuid',
        );
    }
}
