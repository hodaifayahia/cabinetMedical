<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupArchiveException;
use App\Backups\BackupArchiveVerifier;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupArchiveVerifier;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\User;
use App\Services\BackupService;
use App\Services\MachineFingerprintService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class MsBackupArchiveTest extends TestCase
{
    /** @var list<string> */
    private static array $databaseFiles = [];

    private string $workspace;

    private string $privateRoot;

    private string $publicRoot;

    private string $destination;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'medismart-msbackup-db-');

        if (! is_string($databaseFile)) {
            throw new \RuntimeException('The .msbackup test database could not be created.');
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

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The ZIP extension is required for .msbackup tests.');
        }

        // VACUUM INTO cannot execute inside RefreshDatabase's transaction.
        // A fresh file database per case preserves the production execution
        // boundary and avoids shared migration-state assumptions in full runs.
        $migration = $this->artisan('migrate:fresh', ['--force' => true]);

        if (is_int($migration)) {
            $this->assertSame(0, $migration);
        } else {
            $migration->assertExitCode(0);
        }

        $this->workspace = storage_path('framework/testing/msbackup-'.Str::uuid());
        $this->privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $this->publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';
        $this->destination = $this->workspace.DIRECTORY_SEPARATOR.'destination';

        File::ensureDirectoryExists($this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1');
        File::ensureDirectoryExists($this->privateRoot.DIRECTORY_SEPARATOR.'clinical-documents'.DIRECTORY_SEPARATOR.'patient-1');
        File::ensureDirectoryExists($this->privateRoot.DIRECTORY_SEPARATOR.'upload-quarantine'.DIRECTORY_SEPARATOR.'pending');
        File::ensureDirectoryExists($this->publicRoot.DIRECTORY_SEPARATOR.'cabinet');
        File::ensureDirectoryExists($this->destination);

        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'scan.pdf',
            'managed-pdf',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'clinical-documents'.DIRECTORY_SEPARATOR.'patient-1'.DIRECTORY_SEPARATOR.'letter.docx',
            'managed-docx',
        );
        file_put_contents(
            $this->privateRoot.DIRECTORY_SEPARATOR.'upload-quarantine'.DIRECTORY_SEPARATOR.'pending'.DIRECTORY_SEPARATOR.'unreviewed.pdf',
            'transient-file',
        );
        file_put_contents(
            $this->publicRoot.DIRECTORY_SEPARATOR.'cabinet'.DIRECTORY_SEPARATOR.'logo.png',
            'managed-logo',
        );

        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.public.root' => $this->publicRoot,
            'medismart.version' => '2.1.0-test',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    public function test_it_creates_a_versioned_manifest_and_checksums_every_managed_payload(): void
    {
        $created = app(MsBackupArchiveCreator::class)->create(
            $this->destination,
            'Drclick-Backup-test.msbackup',
        );

        $this->assertFileExists($created['path']);
        $this->assertSame(filesize($created['path']), $created['size']);
        $this->assertSame(hash_file('sha256', $created['path']), $created['sha256']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($created['path']));

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            $checksumsJson = $zip->getFromName('checksums.json');
            $this->assertIsString($manifestJson);
            $this->assertIsString($checksumsJson);

            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            $checksums = json_decode($checksumsJson, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('medismart-backup', $manifest['format']);
            $this->assertSame(1, $manifest['format_version']);
            $this->assertSame(1, $manifest['schema_version']);
            $this->assertSame('2.1.0-test', $manifest['application_version']);
            $this->assertSame('sqlite', $manifest['database_driver']);
            $this->assertTrue(Str::isUuid($manifest['installation_id']));
            $this->assertTrue(Str::isUuid($manifest['backup_id']));
            $this->assertSame(DB::table('migrations')->count(), $manifest['migration_count']);
            $migrationNames = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
            $this->assertSame(
                hash('sha256', implode("\n", $migrationNames)),
                $manifest['migration_set_sha256'],
            );
            $this->assertSame([
                'profile' => 'sha256-v1',
                'authenticated' => false,
                'purpose' => 'corruption-detection',
            ], $manifest['integrity']);
            $this->assertSame([
                'profile' => 'installation-snapshot-v1',
                'machine_bound_state' => 'included',
                'secrets' => 'source-app-key-bound',
            ], $manifest['portability']);
            $this->assertSame(['enabled' => false, 'algorithm' => null], $manifest['encryption']);

            $this->assertSame('medismart-checksums', $checksums['format']);
            $this->assertSame('sha256', $checksums['algorithm']);
            /** @var array<string, array{path: string, sha256: string, size: int}> $checksumEntries */
            $checksumEntries = [];

            foreach ($checksums['entries'] as $checksumEntry) {
                $checksumEntries[$checksumEntry['path']] = $checksumEntry;
            }

            $this->assertSame(hash('sha256', $manifestJson), $checksumEntries['manifest.json']['sha256']);
            $this->assertSame(strlen($manifestJson), $checksumEntries['manifest.json']['size']);

            $expectedPayloads = [
                'storage/private/clinical-documents/patient-1/letter.docx' => 'managed-docx',
                'storage/private/patient-documents/patient-1/scan.pdf' => 'managed-pdf',
                'storage/public/cabinet/logo.png' => 'managed-logo',
            ];

            foreach ($expectedPayloads as $entry => $contents) {
                $this->assertSame($contents, $zip->getFromName($entry));
                $this->assertSame(hash('sha256', $contents), $checksumEntries[$entry]['sha256']);
                $this->assertSame(strlen($contents), $checksumEntries[$entry]['size']);
            }

            $this->assertArrayNotHasKey(
                'storage/private/upload-quarantine/pending/unreviewed.pdf',
                $checksumEntries,
            );

            $database = $zip->getFromName('database.sqlite3');
            $this->assertIsString($database);
            $this->assertSame("SQLite format 3\0", substr($database, 0, 16));
            $this->assertSame(hash('sha256', $database), $checksumEntries['database.sqlite3']['sha256']);

            $componentNames = array_column($manifest['components'], 'name');
            $this->assertSame(['database', 'private_storage', 'public_storage'], $componentNames);
            $this->assertSame(1, $manifest['components'][0]['file_count']);
            $this->assertSame(2, $manifest['components'][1]['file_count']);
            $this->assertSame(1, $manifest['components'][2]['file_count']);
        } finally {
            $zip->close();
        }

        $verified = app(MsBackupArchiveVerifier::class)->verify($created['path']);

        $this->assertSame($created['manifest'], $verified['manifest']);
        $this->assertSame($created['sha256'], $verified['archive_sha256']);
        $this->assertSame(6, $verified['entry_count']);
        $this->assertSame([], $this->temporaryFiles());
    }

    public function test_verifier_detects_a_tampered_payload(): void
    {
        $created = app(MsBackupArchiveCreator::class)->create(
            $this->destination,
            'Drclick-Backup-tamper.msbackup',
        );
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($created['path']));
        $this->assertTrue($zip->deleteName('storage/private/patient-documents/patient-1/scan.pdf'));
        $this->assertTrue($zip->addFromString(
            'storage/private/patient-documents/patient-1/scan.pdf',
            'tamperedpdf',
        ));
        $this->assertTrue($zip->close());

        $this->expectException(BackupArchiveException::class);
        $this->expectExceptionMessage('failed checksum verification');

        app(MsBackupArchiveVerifier::class)->verify($created['path']);
    }

    public function test_verifier_rejects_zip_slip_entry_names_before_restore_exists(): void
    {
        $created = app(MsBackupArchiveCreator::class)->create(
            $this->destination,
            'Drclick-Backup-slip.msbackup',
        );
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($created['path']));
        $this->assertTrue($zip->addFromString('../outside.txt', 'unsafe'));
        $this->assertTrue($zip->close());

        $this->expectException(BackupArchiveException::class);
        $this->expectExceptionMessage('unsafe entry name');

        app(MsBackupArchiveVerifier::class)->verify($created['path']);
    }

    public function test_failed_post_write_verification_removes_snapshot_and_temporary_archive(): void
    {
        $rejectingVerifier = new class implements BackupArchiveVerifier
        {
            public function verify(string $path): array
            {
                throw new BackupArchiveException('Injected verification failure.');
            }
        };
        $creator = new MsBackupArchiveCreator(
            app(MachineFingerprintService::class),
            $rejectingVerifier,
        );

        try {
            $creator->create($this->destination, 'Drclick-Backup-failure.msbackup');
            $this->fail('The rejecting verifier should abort archive creation.');
        } catch (BackupArchiveException $exception) {
            $this->assertSame('Injected verification failure.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->destination.DIRECTORY_SEPARATOR.'Drclick-Backup-failure.msbackup');
        $this->assertSame([], File::files($this->destination));
    }

    public function test_database_referenced_managed_assets_must_exist_before_publication(): void
    {
        DB::table('cabinet_settings')->insert([
            'name' => 'Clinic with missing logo',
            'logo_path' => 'cabinet/missing-logo.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(MsBackupArchiveCreator::class)->create(
                $this->destination,
                'Drclick-Backup-missing-asset.msbackup',
            );
            $this->fail('A snapshot referencing a missing asset must not be published.');
        } catch (BackupArchiveException $exception) {
            $this->assertSame(
                'A database-referenced managed asset is missing from storage.',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], File::files($this->destination));
    }

    public function test_backup_service_records_completed_archive_events_and_audit_history(): void
    {
        $actor = User::factory()->create();

        $created = app(BackupService::class)->createArchive($actor, $this->destination);

        $record = BackupRecord::query()->sole();
        $this->assertSame('completed', $record->status);
        $this->assertSame($created['filename'], $record->filename);
        $this->assertSame($created['path'], $record->local_path);
        $this->assertSame($created['sha256'], $record->sha256);
        $this->assertSame($actor->getKey(), $record->created_by);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupStarted',
            'severity' => 'info',
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupCompleted',
            'severity' => 'info',
        ]);

        $completed = ApplicationEvent::query()->where('event', 'BackupCompleted')->sole();
        /** @var array<string, mixed> $completedContext */
        $completedContext = $completed->context;
        $this->assertSame($record->getKey(), $completedContext['backup_record_id']);

        $audit = AuditLog::query()->where('action', 'backup.created')->sole();
        $this->assertSame($record->getKey(), $audit->subject_id);
        /** @var array<string, mixed> $auditMetadata */
        $auditMetadata = $audit->metadata;
        $this->assertSame('msbackup', $auditMetadata['format']);
        $this->assertSame($created['sha256'], $auditMetadata['sha256']);
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
