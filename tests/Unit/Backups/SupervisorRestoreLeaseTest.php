<?php

namespace Tests\Unit\Backups;

use App\Backups\BackupArchiveException;
use App\Backups\SupervisorOfflineRestoreGuard;
use App\Backups\SupervisorRestoreLease;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupervisorRestoreLeaseTest extends TestCase
{
    #[Test]
    public function it_accepts_only_a_bounded_operation_specific_capability(): void
    {
        $operationId = (string) Str::uuid();
        $secret = str_repeat(chr(0x42), 32);
        $expiresAt = time() + 60;
        $lease = SupervisorRestoreLease::fromStream(
            $this->capabilityStream($operationId, $secret, $expiresAt),
            $operationId,
        );
        $challenge = rtrim(strtr(base64_encode(str_repeat(chr(0x07), 32)), '+/', '-_'), '=');
        $message = "medismart-restore-lease-response-v1\n{$operationId}\n{$challenge}\n{$expiresAt}";

        $this->assertSame(49152, $lease->port);
        $this->assertSame(
            hash_hmac('sha256', $message, $secret),
            $lease->responseProof($challenge),
        );
    }

    #[Test]
    public function it_rejects_a_capability_for_another_operation(): void
    {
        $this->expectException(BackupArchiveException::class);
        $this->expectExceptionMessage('malformed');

        SupervisorRestoreLease::fromStream(
            $this->capabilityStream((string) Str::uuid(), random_bytes(32), time() + 60),
            (string) Str::uuid(),
        );
    }

    #[Test]
    public function it_rejects_expired_and_overlong_native_leases(): void
    {
        $operationId = (string) Str::uuid();

        foreach ([time() - 1, time() + (4 * 60 * 60) + 1] as $expiresAt) {
            try {
                SupervisorRestoreLease::fromStream(
                    $this->capabilityStream($operationId, random_bytes(32), $expiresAt),
                    $operationId,
                );
                $this->fail('An unsafe lease window must be rejected.');
            } catch (BackupArchiveException $exception) {
                $this->assertStringContainsString('safety window', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function the_guard_rechecks_native_ownership_before_every_restore_boundary(): void
    {
        $operationId = (string) Str::uuid();
        $lease = SupervisorRestoreLease::fromStream(
            $this->capabilityStream($operationId, random_bytes(32), time() + 60),
            $operationId,
        );
        $checks = 0;
        $guard = new SupervisorOfflineRestoreGuard(
            $lease,
            static function () use (&$checks): void {
                $checks++;
            },
        );

        $guard->assertExclusiveProcessOwnership();
        $guard->assertStillExclusive();

        $this->assertSame(2, $checks);
    }

    /** @return resource */
    private function capabilityStream(string $operationId, string $secret, int $expiresAt)
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, json_encode([
            'protocol' => SupervisorRestoreLease::PROTOCOL,
            'version' => SupervisorRestoreLease::VERSION,
            'operation_id' => $operationId,
            'port' => 49152,
            'expires_at_unix' => $expiresAt,
            'secret' => rtrim(strtr(base64_encode($secret), '+/', '-_'), '='),
        ], JSON_THROW_ON_ERROR)."\n");
        rewind($stream);

        return $stream;
    }
}
