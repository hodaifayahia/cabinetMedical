<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupArchiveException;
use App\Backups\EncryptedMsBackupArchive;
use App\Backups\EncryptedMsBackupEnvelope;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupArchiveVerifier;
use App\Backups\MsBackupEncryptionParameters;
use App\Models\AuditLog;
use App\Services\BackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EncryptedMsBackupArchiveTest extends TestCase
{
    private const PASSPHRASE = 'correct horse battery staple 2026';

    /** @var list<string> */
    private static array $databaseFiles = [];

    private string $workspace;

    private string $privateRoot;

    private string $publicRoot;

    private string $destination;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'medismart-encrypted-backup-db-');

        if (! is_string($databaseFile)) {
            throw new RuntimeException('The encrypted backup test database could not be created.');
        }

        self::$databaseFiles[] = $databaseFile;
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('database.connections.sqlite.database', $databaseFile);

        return $app;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$databaseFiles as $databaseFile) {
            if (is_file($databaseFile)) {
                unlink($databaseFile);
            }
        }

        self::$databaseFiles = [];
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('sodium') || ! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The Sodium and ZIP extensions are required for encrypted backup tests.');
        }

        $migration = $this->artisan('migrate:fresh', ['--force' => true]);

        if (is_int($migration)) {
            $this->assertSame(0, $migration);
        } else {
            $migration->assertExitCode(0);
        }

        $this->workspace = storage_path('framework/testing/encrypted-msbackup-'.Str::uuid());
        $this->privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $this->publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';
        $this->destination = $this->workspace.DIRECTORY_SEPARATOR.'destination';

        File::ensureDirectoryExists($this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1');
        File::ensureDirectoryExists($this->publicRoot.DIRECTORY_SEPARATOR.'cabinet');
        File::ensureDirectoryExists($this->destination);

        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
            str_repeat('confidential-medical-document-', 10_000),
        );
        file_put_contents(
            $this->publicRoot.DIRECTORY_SEPARATOR.'cabinet'.DIRECTORY_SEPARATOR.'logo.png',
            'confidential-clinic-logo',
        );

        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.public.root' => $this->publicRoot,
            'medismart.version' => '2.2.0-encryption-test',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_it_authenticates_encrypts_verifies_and_decrypts_a_v1_archive(): void
    {
        $plain = $this->createPlainArchive('MediSmart-Backup-plain.msbackup');
        $encryptedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-encrypted.msbackup';
        $service = app(EncryptedMsBackupArchive::class);
        $encrypted = $service->encrypt(
            $plain['path'],
            $encryptedPath,
            self::PASSPHRASE,
            $this->parameters(),
        );

        $this->assertFileExists($encryptedPath);
        $this->assertSame(filesize($encryptedPath), $encrypted['size']);
        $this->assertSame(hash_file('sha256', $encryptedPath), $encrypted['sha256']);
        $this->assertSame(EncryptedMsBackupEnvelope::FORMAT_IDENTIFIER, $encrypted['envelope']['format']);
        $this->assertSame(EncryptedMsBackupEnvelope::FORMAT_VERSION, $encrypted['envelope']['format_version']);
        $this->assertSame(EncryptedMsBackupEnvelope::ENVELOPE_VERSION, $encrypted['envelope']['envelope_version']);
        $this->assertSame('user-passphrase', $encrypted['envelope']['encryption']['recovery_secret']);
        $this->assertSame('argon2id13', $encrypted['envelope']['encryption']['kdf']['algorithm']);
        $this->assertSame(
            $plain['sha256'],
            $encrypted['envelope']['inner_archive']['sha256'],
        );

        $encryptedBytes = file_get_contents($encryptedPath);
        $this->assertIsString($encryptedBytes);
        $this->assertStringStartsWith("MEDISMART-MSBAK\x02", $encryptedBytes);
        $this->assertStringNotContainsString(self::PASSPHRASE, $encryptedBytes);
        $this->assertStringNotContainsString("SQLite format 3\0", $encryptedBytes);
        $this->assertStringNotContainsString('confidential-medical-document', $encryptedBytes);

        $verified = $service->verify($encryptedPath, self::PASSPHRASE, $this->destination);
        $this->assertSame($plain['manifest'], $verified['manifest']);
        $this->assertSame($encrypted['sha256'], $verified['archive_sha256']);
        $this->assertSame($plain['sha256'], $verified['plaintext_sha256']);

        $decryptedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-decrypted.msbackup';
        $decrypted = $service->decrypt($encryptedPath, $decryptedPath, self::PASSPHRASE);
        $this->assertFileExists($decryptedPath);
        $this->assertSame($plain['sha256'], hash_file('sha256', $decryptedPath));
        $this->assertSame($plain['sha256'], $decrypted['plaintext_sha256']);

        $legacyVerified = app(MsBackupArchiveVerifier::class)->verify($decryptedPath);
        $this->assertSame($plain['manifest'], $legacyVerified['manifest']);
        $this->assertSame([], $this->temporaryFiles());
    }

    public function test_backup_service_publishes_only_the_encrypted_archive_and_records_it(): void
    {
        $workingDirectory = $this->workspace.DIRECTORY_SEPARATOR.'service-work';
        File::ensureDirectoryExists($workingDirectory);

        $archive = app(BackupService::class)->createEncryptedArchive(
            self::PASSPHRASE,
            destinationDirectory: $this->destination,
            parameters: $this->parameters(),
            workingDirectory: $workingDirectory,
        );

        $this->assertFileExists($archive['path']);
        $bytes = file_get_contents($archive['path']);
        $this->assertIsString($bytes);
        $this->assertStringStartsWith("MEDISMART-MSBAK\x02", $bytes);
        $this->assertStringNotContainsString("SQLite format 3\0", $bytes);
        $this->assertSame([], File::files($workingDirectory));
        $this->assertSame('completed', $archive['record']->status);
        $this->assertSame($archive['path'], $archive['record']->local_path);
        $this->assertSame($archive['sha256'], $archive['record']->sha256);
        $this->assertDatabaseHas('backup_records', [
            'id' => $archive['record']->getKey(),
            'status' => 'completed',
        ]);

        $verified = app(EncryptedMsBackupArchive::class)->verify(
            $archive['path'],
            self::PASSPHRASE,
            $workingDirectory,
        );
        $this->assertSame($archive['manifest'], $verified['manifest']);

        $audit = AuditLog::query()->where('action', 'backup.created')->latest('id')->firstOrFail();
        $encodedAudit = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::PASSPHRASE, $encodedAudit);
        $this->assertTrue($audit->metadata['encrypted']);
    }

    public function test_wrong_passphrase_fails_closed_without_publishing_plaintext_or_exposing_secrets(): void
    {
        $plain = $this->createPlainArchive('MediSmart-Backup-wrong-key-source.msbackup');
        $encryptedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-wrong-key.msbackup';
        $service = app(EncryptedMsBackupArchive::class);
        $service->encrypt($plain['path'], $encryptedPath, self::PASSPHRASE, $this->parameters());
        $decryptedPath = $this->destination.DIRECTORY_SEPARATOR.'must-not-exist.msbackup';
        $wrongPassphrase = 'incorrect recovery phrase 2026';

        try {
            $service->decrypt($encryptedPath, $decryptedPath, $wrongPassphrase);
            $this->fail('A wrong passphrase must not decrypt the backup.');
        } catch (BackupArchiveException $exception) {
            $this->assertStringContainsString('could not be authenticated', $exception->getMessage());
            $this->assertStringNotContainsString(self::PASSPHRASE, $exception->getMessage());
            $this->assertStringNotContainsString($wrongPassphrase, $exception->getMessage());
        }

        $this->assertFileDoesNotExist($decryptedPath);
        $this->assertSame([], $this->temporaryFiles());
    }

    public function test_ciphertext_tampering_is_detected_and_plaintext_is_removed(): void
    {
        $plain = $this->createPlainArchive('MediSmart-Backup-tamper-source.msbackup');
        $encryptedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-tamper.msbackup';
        $service = app(EncryptedMsBackupArchive::class);
        $service->encrypt($plain['path'], $encryptedPath, self::PASSPHRASE, $this->parameters());
        $this->flipFirstCiphertextByte($encryptedPath);

        try {
            $service->verify($encryptedPath, self::PASSPHRASE, $this->destination);
            $this->fail('Tampered ciphertext must not verify.');
        } catch (BackupArchiveException $exception) {
            $this->assertStringContainsString('could not be authenticated', $exception->getMessage());
        }

        $this->assertSame([], $this->temporaryFiles());
    }

    public function test_envelope_tampering_is_bound_to_the_ciphertext_authentication(): void
    {
        $plain = $this->createPlainArchive('MediSmart-Backup-envelope-source.msbackup');
        $encryptedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-envelope.msbackup';
        $service = app(EncryptedMsBackupArchive::class);
        $service->encrypt($plain['path'], $encryptedPath, self::PASSPHRASE, $this->parameters());
        $this->flipEnvelopeSaltByte($encryptedPath);

        try {
            $service->verify($encryptedPath, self::PASSPHRASE, $this->destination);
            $this->fail('An altered envelope must not authenticate its ciphertext.');
        } catch (BackupArchiveException $exception) {
            $this->assertStringContainsString('could not be authenticated', $exception->getMessage());
        }

        $this->assertSame([], $this->temporaryFiles());
    }

    public function test_truncation_and_appended_data_are_rejected(): void
    {
        $plain = $this->createPlainArchive('MediSmart-Backup-framing-source.msbackup');
        $encryptedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-framing.msbackup';
        $service = app(EncryptedMsBackupArchive::class);
        $service->encrypt($plain['path'], $encryptedPath, self::PASSPHRASE, $this->parameters());
        $truncatedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-truncated.msbackup';
        $appendedPath = $this->destination.DIRECTORY_SEPARATOR.'MediSmart-Backup-appended.msbackup';
        $this->assertTrue(copy($encryptedPath, $truncatedPath));
        $this->assertTrue(copy($encryptedPath, $appendedPath));
        $this->assertTrue(ftruncate(
            $truncated = fopen($truncatedPath, 'r+b'),
            (int) filesize($truncatedPath) - 1,
        ));
        fclose($truncated);
        $this->assertSame(1, file_put_contents($appendedPath, 'x', FILE_APPEND));

        foreach ([$truncatedPath, $appendedPath] as $invalidPath) {
            try {
                $service->verify($invalidPath, self::PASSPHRASE, $this->destination);
                $this->fail('Invalid encrypted framing must not verify.');
            } catch (BackupArchiveException $exception) {
                $this->assertStringContainsString('could not be authenticated', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->temporaryFiles());
    }

    public function test_encryption_policy_rejects_weak_parameters(): void
    {
        $this->expectException(BackupArchiveException::class);
        $this->expectExceptionMessage('outside the supported safety policy');

        new MsBackupEncryptionParameters(
            operationsLimit: 1,
            memoryLimitBytes: 8 * 1024 * 1024,
        );
    }

    public function test_short_passphrase_is_rejected_without_exposing_it(): void
    {
        $plain = $this->createPlainArchive('MediSmart-Backup-short-passphrase-source.msbackup');
        $shortPassphrase = 'too short';

        try {
            app(EncryptedMsBackupArchive::class)->encrypt(
                $plain['path'],
                $this->destination.DIRECTORY_SEPARATOR.'must-not-exist.msbackup',
                $shortPassphrase,
                $this->parameters(),
            );
            $this->fail('A short recovery passphrase must be rejected.');
        } catch (BackupArchiveException $exception) {
            $this->assertStringContainsString('12 to 1024', $exception->getMessage());
            $this->assertStringNotContainsString($shortPassphrase, $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->destination.DIRECTORY_SEPARATOR.'must-not-exist.msbackup');
        $this->assertSame([], $this->temporaryFiles());
    }

    public function test_atomic_publication_never_overwrites_an_existing_backup(): void
    {
        $plain = $this->createPlainArchive('MediSmart-Backup-no-overwrite-source.msbackup');
        $existingPath = $this->destination.DIRECTORY_SEPARATOR.'existing.msbackup';
        file_put_contents($existingPath, 'user-owned-backup');

        try {
            app(EncryptedMsBackupArchive::class)->encrypt(
                $plain['path'],
                $existingPath,
                self::PASSPHRASE,
                $this->parameters(),
            );
            $this->fail('An existing backup must never be overwritten.');
        } catch (BackupArchiveException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertSame('user-owned-backup', file_get_contents($existingPath));
        $this->assertSame([], $this->temporaryFiles());
    }

    /**
     * @return array{
     *     path: string,
     *     filename: string,
     *     size: int,
     *     sha256: string,
     *     manifest: array<string, mixed>,
     *     entry_count: int
     * }
     */
    private function createPlainArchive(string $filename): array
    {
        return app(MsBackupArchiveCreator::class)->create($this->destination, $filename);
    }

    private function parameters(): MsBackupEncryptionParameters
    {
        return new MsBackupEncryptionParameters(
            operationsLimit: MsBackupEncryptionParameters::MINIMUM_OPERATIONS_LIMIT,
            memoryLimitBytes: MsBackupEncryptionParameters::MINIMUM_MEMORY_LIMIT_BYTES,
            chunkBytes: MsBackupEncryptionParameters::MINIMUM_CHUNK_BYTES,
        );
    }

    private function flipFirstCiphertextByte(string $path): void
    {
        $handle = fopen($path, 'r+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('The encrypted test archive could not be opened.');
        }

        try {
            $this->assertSame(0, fseek($handle, 16, SEEK_SET));
            $lengthBytes = fread($handle, 4);
            $this->assertIsString($lengthBytes);
            $unpacked = unpack('Nlength', $lengthBytes);
            $this->assertIsArray($unpacked);
            $this->assertIsInt($unpacked['length']);
            $this->assertSame(0, fseek($handle, $unpacked['length'] + 4, SEEK_CUR));
            $byte = fread($handle, 1);
            $this->assertIsString($byte);
            $this->assertSame(1, strlen($byte));
            $this->assertSame(0, fseek($handle, -1, SEEK_CUR));
            $this->assertSame(1, fwrite($handle, chr(ord($byte) ^ 1)));
        } finally {
            fclose($handle);
        }
    }

    private function flipEnvelopeSaltByte(string $path): void
    {
        $handle = fopen($path, 'r+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('The encrypted test archive could not be opened.');
        }

        try {
            $this->assertSame(0, fseek($handle, 16, SEEK_SET));
            $lengthBytes = fread($handle, 4);
            $this->assertIsString($lengthBytes);
            $unpacked = unpack('Nlength', $lengthBytes);
            $this->assertIsArray($unpacked);
            $this->assertIsInt($unpacked['length']);
            $envelope = fread($handle, $unpacked['length']);
            $this->assertIsString($envelope);
            $decoded = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $salt = $decoded['encryption']['kdf']['salt'];
            $this->assertIsString($salt);
            $replacement = ($salt[0] === 'A' ? 'B' : 'A').substr($salt, 1);
            $tampered = str_replace($salt, $replacement, $envelope);
            $this->assertSame(strlen($envelope), strlen($tampered));
            $this->assertSame(0, fseek($handle, 20, SEEK_SET));
            $this->assertSame(strlen($tampered), fwrite($handle, $tampered));
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function temporaryFiles(): array
    {
        $temporary = [];

        foreach (File::files($this->destination) as $file) {
            if (str_starts_with($file->getFilename(), '.')) {
                $temporary[] = $file->getFilename();
            }
        }

        return $temporary;
    }
}
