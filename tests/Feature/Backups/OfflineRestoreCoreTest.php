<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupArchiveException;
use App\Backups\EncryptedMsBackupArchive;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupArchiveVerifier;
use App\Backups\MsBackupEncryptionParameters;
use App\Backups\OfflineRestoreExecutor;
use App\Backups\OfflineRestoreGuard;
use App\Backups\OfflineRestorePreparer;
use App\Backups\PreparedRestore;
use App\Backups\RestoreRecoveryJournal;
use App\Backups\RestoreSafetyBackupProvider;
use App\Backups\RestoreSafetyBackupReceipt;
use App\Backups\RestoreTargetSet;
use App\Backups\StagedSqliteValidator;
use App\Backups\VerifiedRestoreSafetyBackupProvider;
use App\Configuration\ApplicationSettingRegistry;
use App\Services\ApplicationSettingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OfflineRestoreCoreTest extends TestCase
{
    private const PASSPHRASE = 'offline restore recovery phrase 2026';

    /** @var list<string> */
    private static array $databaseFiles = [];

    /** @var list<string> */
    private array $operationIds = [];

    private string $workspace;

    private string $privateRoot;

    private string $publicRoot;

    private string $destination;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'medismart-restore-db-');

        if (! is_string($databaseFile)) {
            throw new RuntimeException('The restore test database could not be created.');
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
            $this->markTestSkipped('The Sodium and ZIP extensions are required for restore tests.');
        }

        $migration = $this->artisan('migrate:fresh', ['--force' => true]);

        if (is_int($migration)) {
            $this->assertSame(0, $migration);
        } else {
            $migration->assertExitCode(0);
        }

        $this->workspace = storage_path('framework/testing/offline-restore-'.Str::uuid());
        $this->privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $this->publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';
        $this->destination = $this->workspace.DIRECTORY_SEPARATOR.'backups';

        foreach ([
            $this->privateRoot.DIRECTORY_SEPARATOR.'clinical-documents'.DIRECTORY_SEPARATOR.'patient-1',
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1',
            $this->privateRoot.DIRECTORY_SEPARATOR.'medical-models',
            $this->privateRoot.DIRECTORY_SEPARATOR.'upload-quarantine'.DIRECTORY_SEPARATOR.'pending',
            $this->publicRoot.DIRECTORY_SEPARATOR.'cabinet',
            $this->destination,
        ] as $directory) {
            File::ensureDirectoryExists($directory);
        }

        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'clinical-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'letter.pdf',
            'new-clinical-document',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
            'new-patient-document',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'medical-models'.DIRECTORY_SEPARATOR.'model.docx',
            'new-medical-model',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'upload-quarantine'.DIRECTORY_SEPARATOR.'pending'.DIRECTORY_SEPARATOR.'ignored.pdf',
            'must-not-be-restored',
        );
        file_put_contents(
            $this->publicRoot.DIRECTORY_SEPARATOR.'cabinet'.DIRECTORY_SEPARATOR.'logo.png',
            'new-clinic-logo',
        );

        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.public.root' => $this->publicRoot,
            'medismart.version' => '2.2.0-restore-test',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->operationIds as $operationId) {
            File::deleteDirectory(storage_path('app/private/restore-work/'.$operationId));
            File::delete(storage_path('app/private/restore-journals/'.$operationId.'.jsonl'));
        }

        if (isset($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_encrypted_backup_is_verified_and_staged_without_changing_active_data(): void
    {
        $sourceHash = hash_file(
            'sha256',
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
        );
        $backup = $this->createEncryptedBackup('successful');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );

        $this->assertSame($operationId, $prepared->operationId);
        $this->assertSame($prepared->planSha256, PreparedRestore::load($operationId)->planSha256);
        $this->assertSame([
            'protocol' => PreparedRestore::NATIVE_AUTHORIZATION_PROTOCOL,
            'version' => PreparedRestore::NATIVE_AUTHORIZATION_VERSION,
            'operation_id' => $operationId,
            'plan_sha256' => $prepared->planSha256,
        ], $prepared->nativeAuthorizationArtifact());
        $this->assertFileExists($prepared->stagedDatabasePath());
        $roots = $prepared->stagedManagedRoots();
        $this->assertSame(
            'new-clinical-document',
            file_get_contents($roots['clinical_documents'].DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'letter.pdf'),
        );
        $this->assertSame(
            'new-patient-document',
            file_get_contents($roots['patient_documents'].DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf'),
        );
        $this->assertSame(
            'new-medical-model',
            file_get_contents($roots['medical_models'].DIRECTORY_SEPARATOR.'model.docx'),
        );
        $this->assertSame('new-clinic-logo', file_get_contents($roots['cabinet'].DIRECTORY_SEPARATOR.'logo.png'));
        $this->assertDirectoryDoesNotExist($prepared->workspace.DIRECTORY_SEPARATOR.'staged'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'upload-quarantine');
        $this->assertFileDoesNotExist($prepared->workspace.DIRECTORY_SEPARATOR.'authenticated-inner.msbackup');
        $this->assertSame(
            $sourceHash,
            hash_file(
                'sha256',
                $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
            ),
        );

        $events = array_column(RestoreRecoveryJournal::open($operationId)->records(), 'event');
        $this->assertSame([
            'preparation_started',
            'archive_authenticated',
            'payload_extracted',
            'staging_validated',
            'ready_for_offline_apply',
        ], $events);
        $this->assertSame(0, Artisan::call('medismart:restore:inspect', ['operation' => $operationId]));
        $inspection = Artisan::output();
        $this->assertStringContainsString('State: ready_for_offline_apply', $inspection);
        $this->assertStringNotContainsString($prepared->workspace, $inspection);
        $this->assertStringNotContainsString(self::PASSPHRASE, $inspection);
    }

    public function test_restore_from_another_installation_is_rejected_before_extraction(): void
    {
        $backup = $this->createEncryptedBackup('other-installation');
        app(ApplicationSettingService::class)->setInternal(
            ApplicationSettingRegistry::DESKTOP_INSTALLATION_ID,
            (string) Str::uuid(),
        );
        $operationId = $this->operationId();

        try {
            app(OfflineRestorePreparer::class)->prepare(
                $backup['encrypted_path'],
                self::PASSPHRASE,
                $operationId,
            );
            $this->fail('A source-key-bound backup from another installation must be rejected.');
        } catch (BackupArchiveException $exception) {
            $this->assertStringContainsString('another installation', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist(storage_path('app/private/restore-work/'.$operationId));
        $records = RestoreRecoveryJournal::open($operationId)->records();
        $this->assertSame('preparation_failed', end($records)['event']);
        $this->assertSame('installation_mismatch', end($records)['context']['reason_code']);
    }

    public function test_journal_tampering_fails_closed(): void
    {
        $backup = $this->createEncryptedBackup('journal');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );
        $journal = file_get_contents($prepared->journalPath);
        $this->assertIsString($journal);
        $this->assertGreaterThan(80, strlen($journal));
        $journal[50] = $journal[50] === 'a' ? 'b' : 'a';
        file_put_contents($prepared->journalPath, $journal);

        $this->expectException(BackupArchiveException::class);
        $this->expectExceptionMessage('integrity validation');

        RestoreRecoveryJournal::open($operationId);
    }

    public function test_migration_set_must_exactly_match_the_running_build(): void
    {
        $backup = $this->createEncryptedBackup('migration-policy');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );
        $migrationDirectory = $this->workspace.DIRECTORY_SEPARATOR.'incomplete-migrations';
        File::ensureDirectoryExists($migrationDirectory);
        $migrationFiles = File::files(database_path('migrations'));

        foreach (array_slice($migrationFiles, 0, -1) as $migration) {
            touch($migrationDirectory.DIRECTORY_SEPARATOR.$migration->getFilename());
        }

        $this->expectException(BackupArchiveException::class);
        $this->expectExceptionMessage('exact match');

        (new StagedSqliteValidator($migrationDirectory))->validate(
            $prepared->stagedDatabasePath(),
            $prepared->manifest,
        );
    }

    public function test_offline_executor_swaps_all_targets_and_retains_rollback_data(): void
    {
        $backup = $this->createEncryptedBackup('apply-success');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );
        [$targets, $activeRoot] = $this->activeTargets($prepared, 'success');
        $guard = $this->guard();
        $safety = $this->safetyProvider($backup['plain_path'], $backup['plain_sha256']);
        $items = $targets->items($prepared);

        app(OfflineRestoreExecutor::class)->apply($prepared, $targets, $guard, $safety);

        $this->assertSame(
            'new-patient-document',
            file_get_contents($targets->managedRoots['patient_documents'].DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf'),
        );
        $this->assertSame('new-clinic-logo', file_get_contents($targets->managedRoots['cabinet'].DIRECTORY_SEPARATOR.'logo.png'));

        foreach ($items as $item) {
            $this->assertTrue(file_exists($item['rollback']));
        }

        $events = RestoreRecoveryJournal::open($operationId)->records();
        $this->assertSame('applied_pending_restart', end($events)['event']);
        $this->assertDirectoryExists($activeRoot);
    }

    public function test_native_safety_provider_publishes_a_verified_retained_archive(): void
    {
        $backup = $this->createEncryptedBackup('native-safety-provider');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );
        $safetyDirectory = $this->destination.DIRECTORY_SEPARATOR.'pre-restore-safety';
        $provider = new VerifiedRestoreSafetyBackupProvider(
            app(MsBackupArchiveCreator::class),
            $safetyDirectory,
        );

        $receipt = $provider->createSafetyBackup($prepared);
        $verified = app(MsBackupArchiveVerifier::class)->verify($receipt->path);

        $this->assertFileExists($receipt->path);
        $this->assertStringStartsWith(realpath($safetyDirectory), realpath($receipt->path));
        $this->assertSame($receipt->sha256, $verified['archive_sha256']);
        $this->assertSame(
            $prepared->manifest['installation_id'],
            $verified['manifest']['installation_id'],
        );
    }

    public function test_mid_apply_failure_rolls_every_changed_target_back_deterministically(): void
    {
        $backup = $this->createEncryptedBackup('apply-rollback');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );
        [$targets] = $this->activeTargets($prepared, 'rollback');
        $originalDatabaseSha256 = hash_file('sha256', $targets->database);
        $safety = $this->safetyProvider($backup['plain_path'], $backup['plain_sha256']);

        try {
            app(OfflineRestoreExecutor::class)->apply($prepared, $targets, $this->guard(5), $safety);
            $this->fail('Loss of exclusive process ownership must abort restore apply.');
        } catch (BackupArchiveException $exception) {
            $this->assertSame('Restore apply failed and the active installation was rolled back.', $exception->getMessage());
        }

        $this->assertSame($originalDatabaseSha256, hash_file('sha256', $targets->database));
        $this->assertSame(
            'old-clinical_documents',
            file_get_contents($targets->managedRoots['clinical_documents'].DIRECTORY_SEPARATOR.'old.txt'),
        );
        $this->assertSame(
            'old-patient_documents',
            file_get_contents($targets->managedRoots['patient_documents'].DIRECTORY_SEPARATOR.'old.txt'),
        );
        $records = RestoreRecoveryJournal::open($operationId)->records();
        $this->assertSame('rollback_completed', end($records)['event']);
    }

    public function test_staging_tamper_is_rejected_before_any_active_target_is_changed(): void
    {
        $backup = $this->createEncryptedBackup('staging-tamper');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );
        [$targets] = $this->activeTargets($prepared, 'staging-tamper');
        $originalDatabaseSha256 = hash_file('sha256', $targets->database);
        file_put_contents(
            $prepared->stagedManagedRoots()['clinical_documents']
                .DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'letter.pdf',
            'tampered-after-authentication',
        );

        try {
            app(OfflineRestoreExecutor::class)->apply(
                $prepared,
                $targets,
                $this->guard(),
                $this->safetyProvider($backup['plain_path'], $backup['plain_sha256']),
            );
            $this->fail('A modified staging payload must never be applied.');
        } catch (BackupArchiveException $exception) {
            $this->assertStringContainsString('staging file failed checksum', $exception->getMessage());
        }

        $this->assertSame($originalDatabaseSha256, hash_file('sha256', $targets->database));
        $this->assertSame(
            'old-clinical_documents',
            file_get_contents($targets->managedRoots['clinical_documents'].DIRECTORY_SEPARATOR.'old.txt'),
        );
        $records = RestoreRecoveryJournal::open($operationId)->records();
        $this->assertSame('safety_backup_verified', end($records)['event']);
    }

    public function test_apply_command_fails_closed_until_supervisor_ownership_exists(): void
    {
        $this->artisan('medismart:restore:apply', ['operation' => (string) Str::uuid()])
            ->expectsOutput('Restore apply is disabled until the Tauri supervisor can prove exclusive process ownership.')
            ->expectsOutput('No active database or managed document was changed.')
            ->assertExitCode(1);
    }

    public function test_inspection_returns_distinct_status_when_native_recovery_is_required(): void
    {
        $backup = $this->createEncryptedBackup('interrupted-inspection');
        $operationId = $this->operationId();
        $prepared = app(OfflineRestorePreparer::class)->prepare(
            $backup['encrypted_path'],
            self::PASSPHRASE,
            $operationId,
        );
        RestoreRecoveryJournal::open($operationId)->append('apply_started', [
            'target_count' => 5,
            'rollback_retained' => true,
        ]);

        $this->assertSame(2, Artisan::call('medismart:restore:inspect', ['operation' => $operationId]));
        $inspection = Artisan::output();
        $this->assertStringContainsString('State: apply_started', $inspection);
        $this->assertStringContainsString('Native recovery attention is required', $inspection);
        $this->assertStringNotContainsString($prepared->workspace, $inspection);
        $this->assertStringNotContainsString(self::PASSPHRASE, $inspection);
    }

    /** @return array{plain_path: string, plain_sha256: string, encrypted_path: string} */
    private function createEncryptedBackup(string $suffix): array
    {
        $plain = app(MsBackupArchiveCreator::class)->create(
            $this->destination,
            "Drclick-Backup-{$suffix}-plain.msbackup",
        );
        $encryptedPath = $this->destination.DIRECTORY_SEPARATOR."Drclick-Backup-{$suffix}-encrypted.msbackup";
        app(EncryptedMsBackupArchive::class)->encrypt(
            $plain['path'],
            $encryptedPath,
            self::PASSPHRASE,
            new MsBackupEncryptionParameters(
                MsBackupEncryptionParameters::MINIMUM_OPERATIONS_LIMIT,
                MsBackupEncryptionParameters::MINIMUM_MEMORY_LIMIT_BYTES,
                MsBackupEncryptionParameters::MINIMUM_CHUNK_BYTES,
            ),
        );

        return [
            'plain_path' => $plain['path'],
            'plain_sha256' => $plain['sha256'],
            'encrypted_path' => $encryptedPath,
        ];
    }

    private function operationId(): string
    {
        $operationId = (string) Str::uuid();
        $this->operationIds[] = $operationId;

        return $operationId;
    }

    /** @return array{RestoreTargetSet, string} */
    private function activeTargets(PreparedRestore $prepared, string $suffix): array
    {
        $root = $this->workspace.DIRECTORY_SEPARATOR.'active-'.$suffix;
        $database = $root.DIRECTORY_SEPARATOR.'database.sqlite3';
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'private');
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'public');
        copy((string) config('database.connections.sqlite.database'), $database);
        $managed = [
            'clinical_documents' => $root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'clinical-documents',
            'patient_documents' => $root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'patient-documents',
            'medical_models' => $root.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'medical-models',
            'cabinet' => $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'cabinet',
        ];

        foreach ($managed as $key => $directory) {
            File::ensureDirectoryExists($directory);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'old.txt', 'old-'.$key);
        }

        return [new RestoreTargetSet($database, $managed), $root];
    }

    private function guard(?int $failOnStillCheck = null): OfflineRestoreGuard
    {
        return new class($failOnStillCheck) implements OfflineRestoreGuard
        {
            private int $stillChecks = 0;

            public function __construct(private readonly ?int $failOnStillCheck) {}

            public function assertExclusiveProcessOwnership(): void {}

            public function assertStillExclusive(): void
            {
                $this->stillChecks++;

                if ($this->failOnStillCheck === $this->stillChecks) {
                    throw new BackupArchiveException('The exclusive restore ownership was lost.');
                }
            }
        };
    }

    private function safetyProvider(
        string $path,
        string $sha256,
        ?\Closure $afterCreate = null,
    ): RestoreSafetyBackupProvider {
        return new class($path, $sha256, $afterCreate) implements RestoreSafetyBackupProvider
        {
            public function __construct(
                private readonly string $path,
                private readonly string $sha256,
                private readonly ?\Closure $afterCreate,
            ) {}

            public function createSafetyBackup(PreparedRestore $restore): RestoreSafetyBackupReceipt
            {
                if ($this->afterCreate instanceof \Closure) {
                    ($this->afterCreate)();
                }

                return new RestoreSafetyBackupReceipt($this->path, $this->sha256);
            }
        };
    }
}
