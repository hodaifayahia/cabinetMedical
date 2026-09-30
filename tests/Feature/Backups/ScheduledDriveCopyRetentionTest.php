<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupRetentionPlanner;
use App\Backups\BackupRetentionPolicy;
use App\Backups\LocalBackupArchiveInspector;
use App\Backups\LocalBackupRetentionConfirmation;
use App\Backups\LocalBackupRetentionManager;
use App\Backups\LocalEncryptedAutomaticBackupCreator;
use App\Backups\MsBackupArchiveCreator;
use App\Backups\MsBackupEncryptionParameters;
use App\Backups\ScheduledDriveBackupUploader;
use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Services\ApplicationSettingService;
use App\Services\BackupService;
use App\Services\GoogleDriveService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RequiresSqlite;
use Tests\TestCase;
use ZipArchive;

/**
 * The encrypted Drive copy of a scheduled backup is written seconds after the
 * plain archive. Were it a regular restore point, it would win that day's
 * retention bucket and the plain archive would be deleted the next day,
 * leaving only copies that need the Drive passphrase. It lives in the Drive
 * outbox instead, is sent from there, and is removed once sent.
 */
class ScheduledDriveCopyRetentionTest extends TestCase
{
    use RequiresSqlite;

    private const PASSPHRASE = 'drive outbox recovery phrase 2026';

    /** @var list<string> */
    private static array $databaseFiles = [];

    private string $workspace;

    private string $managedRoot;

    public function createApplication()
    {
        $app = parent::createApplication();
        $databaseFile = tempnam(sys_get_temp_dir(), 'drclick-drive-copy-db-');

        if (! is_string($databaseFile)) {
            throw new \RuntimeException('The Drive copy test database could not be created.');
        }

        self::$databaseFiles[] = $databaseFile;
        $app['config']->set('database.connections.sqlite.url', null);
        $app['config']->set('database.connections.sqlite.database', $databaseFile);

        return $app;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$databaseFiles as $databaseFile) {
            // SQLite may leave its WAL sidecars next to the database file.
            foreach ([$databaseFile, $databaseFile.'-wal', $databaseFile.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        self::$databaseFiles = [];
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class) || ! extension_loaded('sodium')) {
            $this->markTestSkipped('The ZIP and Sodium extensions are required for Drive copy retention.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-drive-copy-'.Str::uuid();
        $this->managedRoot = $this->workspace.DIRECTORY_SEPARATOR.'managed-backups';
        $privateRoot = $this->workspace.DIRECTORY_SEPARATOR.'private';
        $publicRoot = $this->workspace.DIRECTORY_SEPARATOR.'public';

        File::ensureDirectoryExists($this->managedRoot);
        File::ensureDirectoryExists($privateRoot.DIRECTORY_SEPARATOR.'patient-documents');
        File::ensureDirectoryExists($publicRoot.DIRECTORY_SEPARATOR.'cabinet');
        file_put_contents($privateRoot.DIRECTORY_SEPARATOR.'patient-documents'.DIRECTORY_SEPARATOR.'fixture.txt', 'fixture');

        config([
            'filesystems.disks.local.root' => $privateRoot,
            'filesystems.disks.public.root' => $publicRoot,
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.version' => '2.1.0-drive-copy-test',
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.installation_id' => '6f1d2c3b-4a5e-4f60-8a71-9b82c3d4e5f6',
            'services.google.client_id' => 'drclick-test.apps.googleusercontent.com',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        if (isset($this->workspace)) {
            $temporaryRoot = realpath(sys_get_temp_dir());
            $resolvedWorkspace = realpath($this->workspace);

            if (is_string($temporaryRoot)
                && is_string($resolvedWorkspace)
                && str_starts_with($resolvedWorkspace, $temporaryRoot.DIRECTORY_SEPARATOR.'drclick-drive-copy-')) {
                File::deleteDirectory($resolvedWorkspace);
            }
        }

        parent::tearDown();
    }

    public function test_the_drive_copy_never_takes_the_place_of_a_local_restore_point(): void
    {
        $yesterday = $this->plainArchive('2026-09-24T08:30:00+00:00', 'Drclick-Backup-yesterday.msbackup');
        $copy = $this->outboxCopy('2026-09-24T08:30:40+00:00');
        $today = $this->plainArchive('2026-09-25T08:30:00+00:00', 'Drclick-Backup-today.msbackup');

        $this->assertSame(
            LocalEncryptedAutomaticBackupCreator::outboxDirectory(),
            dirname((string) $copy->local_path),
        );

        $plan = $this->manager()->preview(new BackupRetentionPolicy(7, 4, 12))->plan;

        // Yesterday's plain archive keeps its daily bucket; the newer copy is
        // neither a restore point nor ever a deletion candidate.
        $this->assertEqualsCanonicalizing(
            [$yesterday->id, $today->id],
            array_column($plan->keep, 'managed_file_id'),
        );
        $this->assertSame([], $plan->deletionCandidates);

        // The upload job still finds and verifies the copy in the outbox.
        $settings = CabinetSetting::current();
        $this->connectDrive($settings);
        $this->fakeSuccessfulUpload();
        (new UploadBackupToGoogleDrive((int) $settings->getKey(), (string) $copy->getKey(), 'Drclick Backups'))
            ->handle(app(GoogleDriveService::class));
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_COMPLETED, $copy->fresh()?->drive_upload_status);

        // Once sent, the copy's file goes; both restore points stay.
        app(ScheduledDriveBackupUploader::class)->pruneFinishedCopies();

        $this->assertFileDoesNotExist((string) $copy->local_path);
        $this->assertNull($copy->fresh()?->local_path);
        $this->assertSame('remote-file-id', $copy->fresh()?->remote_file_id);
        $this->assertFileExists((string) $yesterday->local_path);
        $this->assertFileExists((string) $today->local_path);
        $this->assertEqualsCanonicalizing(
            [$yesterday->id, $today->id],
            array_column($this->manager()->preview(new BackupRetentionPolicy(7, 4, 12))->plan->keep, 'managed_file_id'),
        );
    }

    private function manager(): LocalBackupRetentionManager
    {
        return new LocalBackupRetentionManager(
            app(BackupRetentionPlanner::class),
            app(LocalBackupArchiveInspector::class),
            new LocalBackupRetentionConfirmation(str_repeat('retention-secret-', 3)),
            app(ApplicationSettingService::class),
            $this->managedRoot,
        );
    }

    private function plainArchive(string $createdAt, string $filename): BackupRecord
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($createdAt));
        $created = app(MsBackupArchiveCreator::class)->create(
            $this->managedRoot,
            $filename,
            (string) Str::uuid(),
        );
        $record = BackupRecord::query()->create([
            'filename' => $created['filename'],
            'disk' => 'local',
            'local_path' => $created['path'],
            'size' => $created['size'],
            'sha256' => $created['sha256'],
            'schema_version' => $created['manifest']['schema_version'],
            'application_version' => $created['manifest']['application_version'],
            'status' => 'completed',
            'started_at' => CarbonImmutable::parse($createdAt),
            'completed_at' => CarbonImmutable::parse($createdAt),
        ]);
        CarbonImmutable::setTestNow();

        return $record->refresh();
    }

    /**
     * What LocalEncryptedAutomaticBackupCreator writes, with the fast
     * encryption profile the other backup tests use.
     */
    private function outboxCopy(string $createdAt): BackupRecord
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($createdAt));
        $workingDirectory = $this->workspace.DIRECTORY_SEPARATOR.'encrypted-work';
        File::ensureDirectoryExists($workingDirectory);
        $record = app(BackupService::class)->createEncryptedArchive(
            self::PASSPHRASE,
            destinationDirectory: LocalEncryptedAutomaticBackupCreator::outboxDirectory(),
            parameters: MsBackupEncryptionParameters::interactive(),
            workingDirectory: $workingDirectory,
        )['record'];
        $record->forceFill([
            'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_QUEUED,
            'drive_upload_updated_at' => now(),
        ])->save();
        CarbonImmutable::setTestNow();

        return $record->refresh();
    }

    private function connectDrive(CabinetSetting $settings): DriveBackupConnection
    {
        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $settings->getKey(),
            'email' => 'cabinet@example.test',
            'folder_name' => 'Drclick Backups',
            'folder_id' => 'drive-folder-id',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function fakeSuccessfulUpload(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET'
                && str_contains($request->url(), 'www.googleapis.com/drive/v3/files')) {
                return Http::response(['files' => []]);
            }

            if ($request->method() === 'POST'
                && str_contains($request->url(), 'www.googleapis.com/upload/drive/v3/files')) {
                return Http::response(['id' => 'remote-file-id', 'name' => 'Drclick encrypted backup']);
            }

            return Http::response(['error' => 'unexpected request'], 500);
        });
    }
}
