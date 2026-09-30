<?php

namespace Tests\Feature\Backups;

use App\Backups\AutomaticBackupCreator;
use App\Backups\AutomaticDriveUploadPolicy;
use App\Backups\EncryptedAutomaticBackupCreator;
use App\Backups\LocalEncryptedAutomaticBackupCreator;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\ApplicationEvent;
use App\Models\ApplicationSetting;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\CabinetFulfillmentService;
use App\Services\GoogleDriveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;
use Tests\Support\ActivatesSignedLicense;
use Tests\TestCase;

class ScheduledDriveBackupUploadTest extends TestCase
{
    use ActivatesSignedLicense;
    use RefreshDatabase;

    private const PASSPHRASE = 'correct horse battery staple';

    private string $managedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // 09:15 with the default 10:00 / 14:00 / 18:00 slots: yesterday's
        // evening slot is the one due.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 09:15:00', config('app.timezone')));
        $this->managedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-scheduled-drive-'.Str::uuid();
        File::ensureDirectoryExists($this->managedRoot);
        config([
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.scheduler_status' => 'active',
            'medismart.runtime.installation_id' => '6f1d2c3b-4a5e-4f60-8a71-9b82c3d4e5f6',
            'services.google.client_id' => 'drclick-test.apps.googleusercontent.com',
            'services.google.client_secret' => null,
            'services.google.drive_scope' => 'https://www.googleapis.com/auth/drive.file',
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->cleanUpSignedLicenseFeatures();
        File::deleteDirectory($this->managedRoot);

        parent::tearDown();
    }

    public function test_each_scheduled_backup_queues_one_encrypted_copy_for_the_connected_drive(): void
    {
        $this->activateSignedLicenseFeatures(['google_drive_backup' => true]);
        $connection = $this->connectDrive('Cabinet Drive');
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $local = $this->fakeLocalBackups();
        $encrypted = $this->fakeEncryptedCopies();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame(1, $local->calls);
        $this->assertSame([self::PASSPHRASE], $encrypted->passphrases);
        $copy = BackupRecord::query()->where('filename', 'like', 'Drclick-Backup-drive-copy-%')->sole();
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_QUEUED, $copy->drive_upload_status);
        $this->assertSame(0, (int) $copy->drive_upload_attempts);
        $scheduled = BackupRecord::query()->where('filename', 'Drclick-Backup-scheduled.msbackup')->sole();
        // The clinic's local archive is never modified by the Drive copy.
        $this->assertNull($scheduled->drive_upload_status);
        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);
        Queue::assertPushed(
            UploadBackupToGoogleDrive::class,
            fn (UploadBackupToGoogleDrive $job): bool => $job->cabinetId === (int) $connection->cabinet_setting_id
                && $job->backupRecordId === (string) $copy->getKey()
                && $job->folderName === 'Cabinet Drive',
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.scheduled_drive_queued']);
        $this->assertDatabaseHas('application_events', ['event' => 'ScheduledDriveBackupQueued']);

        // Same due day: no second local backup, so no second Drive copy.
        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);
    }

    public function test_a_failing_drive_copy_never_fails_the_local_backup(): void
    {
        $this->activateSignedLicenseFeatures(['google_drive_backup' => true]);
        $this->connectDrive('Cabinet Drive');
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $this->fakeLocalBackups();
        app()->instance(EncryptedAutomaticBackupCreator::class, new class implements EncryptedAutomaticBackupCreator
        {
            public function create(#[SensitiveParameter] string $passphrase): BackupRecord
            {
                throw new RuntimeException('Disk full while encrypting.');
            }
        });

        $this->artisan('medismart:backup:scheduled')
            ->expectsOutputToContain('copie Google Drive n’a pas pu être préparée')
            ->assertSuccessful();

        $this->assertDatabaseCount('backup_records', 1);
        $this->assertDatabaseHas('application_events', ['event' => 'ScheduledBackupCompleted']);
        $this->assertDatabaseHas('application_events', [
            'event' => 'ScheduledDriveBackupFailed',
            'severity' => 'error',
        ]);
        $this->assertDatabaseMissing('application_events', ['event' => 'ScheduledBackupFailed']);
        Queue::assertNothingPushed();
    }

    public function test_a_copy_that_cannot_be_queued_is_marked_failed_and_the_run_still_succeeds(): void
    {
        $this->activateSignedLicenseFeatures(['google_drive_backup' => true]);
        // The upload job refuses a folder name with control characters.
        $this->connectDrive("Cabinet\x01Drive");
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $this->fakeLocalBackups();
        $this->fakeEncryptedCopies();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $copy = BackupRecord::query()->where('filename', 'like', 'Drclick-Backup-drive-copy-%')->sole();
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_FAILED, $copy->drive_upload_status);
        $this->assertSame('queue_dispatch_failed', $copy->drive_upload_failure_code);
        $this->assertDatabaseHas('application_events', ['event' => 'ScheduledDriveBackupFailed']);
        Queue::assertNothingPushed();
    }

    public function test_nothing_is_sent_to_drive_without_the_doctors_opt_in(): void
    {
        $this->activateSignedLicenseFeatures(['google_drive_backup' => true]);
        $this->connectDrive('Cabinet Drive');
        $this->fakeLocalBackups();
        $encrypted = $this->fakeEncryptedCopies();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame([], $encrypted->passphrases);
        $this->assertDatabaseCount('backup_records', 1);
        $this->assertDatabaseMissing('application_events', ['event' => 'ScheduledDriveBackupSkipped']);
        Queue::assertNothingPushed();
    }

    public function test_a_missing_or_ambiguous_drive_grant_skips_the_copy_and_says_why(): void
    {
        $this->activateSignedLicenseFeatures(['google_drive_backup' => true]);
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $this->fakeLocalBackups();
        $encrypted = $this->fakeEncryptedCopies();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSkipped('drive_not_connected');

        // A whole-installation archive may only ever go to one clinic's Drive.
        $this->connectDrive('Cabinet Drive');
        $other = CabinetSetting::query()->create([
            ...CabinetSetting::defaults(),
            'name' => 'Other cabinet',
        ]);
        $this->connectDrive('Other Drive', $other);
        $this->travelToNextDueDay();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSkipped('ambiguous_drive_connection');
        $this->assertSame([], $encrypted->passphrases);
        Queue::assertNothingPushed();
    }

    public function test_an_unlicensed_or_unconfigured_installation_skips_the_copy(): void
    {
        $this->connectDrive('Cabinet Drive');
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $this->fakeLocalBackups();
        $encrypted = $this->fakeEncryptedCopies();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        $this->assertSkipped('drive_backup_unlicensed');

        config(['services.google.client_id' => '']);
        $this->travelToNextDueDay();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        $this->assertSkipped('google_oauth_unconfigured');
        $this->assertSame([], $encrypted->passphrases);
        Queue::assertNothingPushed();
    }

    public function test_a_second_cabinet_on_the_machine_stops_the_daily_copy(): void
    {
        $this->activateSignedLicenseFeatures(['google_drive_backup' => true]);
        $clinic = Cabinet::query()->create(['name' => 'Cabinet du poste', 'status' => CabinetStatus::PENDING]);
        $this->connectDrive('Cabinet Drive', CabinetSetting::current($clinic));
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $this->fakeLocalBackups();
        $encrypted = $this->fakeEncryptedCopies();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);

        // For example a secretary registering her own cabinet on the clinic PC:
        // every archive would now carry that cabinet's records too.
        Cabinet::query()->create(['name' => 'Cabinet inscrit ensuite', 'status' => CabinetStatus::PENDING]);
        $this->travelToNextDueDay();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSkipped('drive_cabinet_mismatch');
        $this->assertSame([self::PASSPHRASE], $encrypted->passphrases);
        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);
    }

    public function test_a_copy_queued_before_a_second_cabinet_appeared_never_leaves_the_machine(): void
    {
        Http::fake();
        $clinic = Cabinet::query()->create(['name' => 'Cabinet du poste', 'status' => CabinetStatus::PENDING]);
        $settings = CabinetSetting::current($clinic);
        $this->connectDrive('Cabinet Drive', $settings);
        $copy = BackupRecord::query()->create([
            'filename' => 'Drclick-Backup-queued-copy.msbackup',
            'disk' => 'local',
            'size' => 2048,
            'sha256' => str_repeat('b', 64),
            'schema_version' => 2,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_QUEUED,
        ]);
        Cabinet::query()->create(['name' => 'Autre cabinet', 'status' => CabinetStatus::PENDING]);

        try {
            (new UploadBackupToGoogleDrive((int) $settings->getKey(), (string) $copy->getKey(), 'Cabinet Drive'))
                ->handle(app(GoogleDriveService::class));
            $this->fail('A copy of a shared installation was sent to one cabinet’s Drive.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $copy->refresh();
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_FAILED, $copy->drive_upload_status);
        $this->assertSame('drive_cabinet_mismatch', $copy->drive_upload_failure_code);
        $this->assertNull($copy->remote_file_id);
        Http::assertNothingSent();
    }

    public function test_the_cabinet_plan_licenses_the_daily_copy_on_a_clinic_desktop(): void
    {
        // No signed machine certificate: the production activation path.
        Mail::fake();
        $owner = User::factory()->create();
        $clinic = Cabinet::query()->create(['name' => 'Cabinet du poste', 'status' => CabinetStatus::PENDING]);
        $clinic->forceFill(['owner_user_id' => $owner->getKey()])->save();
        app(CabinetFulfillmentService::class)->activate($clinic, LicensePlan::LIFETIME);
        $this->connectDrive('Cabinet Drive', CabinetSetting::current($clinic));
        app(AutomaticDriveUploadPolicy::class)->enable(self::PASSPHRASE);
        $this->fakeLocalBackups();
        $encrypted = $this->fakeEncryptedCopies();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame([self::PASSPHRASE], $encrypted->passphrases);
        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);

        // Off the supervised desktop a hosted plan still does not include it.
        config(['medismart.runtime.desktop_supervised' => false]);
        $this->travelToNextDueDay();

        $this->artisan('medismart:backup:scheduled', ['--force' => true])->assertSuccessful();

        $this->assertSkipped('drive_backup_unlicensed');
        Queue::assertPushed(UploadBackupToGoogleDrive::class, 1);
    }

    public function test_finished_outbox_copies_are_removed_before_retention_while_pending_ones_stay(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-outbox-test-'.Str::uuid();
        config(['medismart.backups.managed_directory' => $root]);
        $outbox = LocalEncryptedAutomaticBackupCreator::outboxDirectory();
        $this->assertSame($root.DIRECTORY_SEPARATOR.'drive-outbox', $outbox);
        File::ensureDirectoryExists($outbox);

        try {
            $sent = $this->outboxCopy($outbox, BackupRecord::DRIVE_UPLOAD_COMPLETED, 'remote-file-id');
            $failed = $this->outboxCopy($outbox, BackupRecord::DRIVE_UPLOAD_FAILED);
            $retrying = $this->outboxCopy($outbox, BackupRecord::DRIVE_UPLOAD_RETRYING);
            $restorePoint = $root.DIRECTORY_SEPARATOR.'Drclick-Backup-restore-point.msbackup';
            file_put_contents($restorePoint, 'plain scheduled archive');
            $plain = BackupRecord::query()->create([
                'filename' => basename($restorePoint),
                'disk' => 'local',
                'local_path' => $restorePoint,
                'size' => 23,
                'sha256' => str_repeat('c', 64),
                'schema_version' => 1,
                'application_version' => 'test',
                'status' => 'completed',
                'started_at' => now()->subDay(),
                'completed_at' => now()->subDay(),
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_FAILED,
            ]);
            $this->fakeLocalBackups();

            $this->artisan('medismart:backup:scheduled')->assertSuccessful();

            foreach ([$sent, $failed] as $finished) {
                $this->assertFileDoesNotExist((string) $finished['path']);
                // The upload history keeps the record, without a local file.
                $this->assertNull($finished['record']->fresh()?->local_path);
                $this->assertNotNull($finished['record']->fresh()?->drive_upload_status);
            }

            $this->assertFileExists((string) $retrying['path']);
            $this->assertSame($retrying['path'], $retrying['record']->fresh()?->local_path);
            // Only outbox copies are ever pruned here, never a restore point.
            $this->assertFileExists($restorePoint);
            $this->assertSame($restorePoint, $plain->fresh()?->local_path);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_the_automatic_passphrase_is_stored_encrypted_and_never_rendered(): void
    {
        $policy = app(AutomaticDriveUploadPolicy::class);
        $settings = app(ApplicationSettingService::class);

        $policy->enable(self::PASSPHRASE);

        $this->assertTrue($policy->enabled());
        $this->assertSame(self::PASSPHRASE, $policy->passphrase());
        $row = ApplicationSetting::query()
            ->where('key', Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE)
            ->sole();
        $this->assertNull($row->plain_value);
        $raw = $row->getRawOriginal('encrypted_value');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString(self::PASSPHRASE, $raw);
        $this->assertStringNotContainsString(self::PASSPHRASE, json_encode($row->toArray(), JSON_THROW_ON_ERROR));
        $this->assertNull($settings->describe(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE)['value']);
        $this->assertArrayNotHasKey(
            Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE,
            $settings->editableSettings('backups'),
        );

        $policy->disable();

        $this->assertFalse($policy->enabled());
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE]);
        $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_DRIVE_AUTO_UPLOAD]);
    }

    public function test_a_short_passphrase_is_refused_and_an_unreadable_one_turns_the_copy_off(): void
    {
        $policy = app(AutomaticDriveUploadPolicy::class);

        try {
            $policy->enable('too short');
            $this->fail('A passphrase shorter than 12 characters was stored.');
        } catch (ValidationException) {
            $this->assertFalse($policy->enabled());
            $this->assertDatabaseMissing('application_settings', ['key' => Setting::BACKUP_DRIVE_AUTO_UPLOAD]);
        }

        $policy->enable(self::PASSPHRASE);
        // A restored database on another machine carries a ciphertext that
        // this installation's key cannot open.
        DB::table('application_settings')
            ->where('key', Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE)
            ->update(['encrypted_value' => 'eyJpdiI6ImludmFsaWQiLCJ2YWx1ZSI6IngiLCJtYWMiOiJ5In0=']);

        $this->assertNull($policy->passphrase());
        $this->assertFalse($policy->enabled());
    }

    private function connectDrive(string $folderName, ?CabinetSetting $cabinet = null): DriveBackupConnection
    {
        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => ($cabinet ?? CabinetSetting::current())->getKey(),
            'email' => 'clinic@example.test',
            'folder_name' => $folderName,
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    /** @return array{path: string, record: BackupRecord} */
    private function outboxCopy(string $outbox, string $driveStatus, ?string $remoteFileId = null): array
    {
        $path = $outbox.DIRECTORY_SEPARATOR.'Drclick-Backup-'.$driveStatus.'.msbackup';
        file_put_contents($path, 'encrypted outbox copy');

        return [
            'path' => $path,
            'record' => BackupRecord::query()->create([
                'filename' => basename($path),
                'disk' => 'local',
                'local_path' => $path,
                'remote_file_id' => $remoteFileId,
                'size' => 21,
                'sha256' => str_repeat('d', 64),
                'schema_version' => 2,
                'application_version' => 'test',
                'status' => 'completed',
                'started_at' => now()->subDay(),
                'completed_at' => now()->subDay(),
                'drive_upload_status' => $driveStatus,
                'drive_upload_updated_at' => now()->subDay(),
            ]),
        ];
    }

    private function travelToNextDueDay(): void
    {
        $this->travelTo(CarbonImmutable::now()->addDay()->setTime(9, 15));
    }

    private function assertSkipped(string $reason): void
    {
        $this->assertTrue(
            ApplicationEvent::query()
                ->where('event', 'ScheduledDriveBackupSkipped')
                ->get()
                ->contains(fn (ApplicationEvent $event): bool => ($event->context['reason'] ?? null) === $reason),
            "Expected a ScheduledDriveBackupSkipped event for [{$reason}].",
        );
    }

    private function fakeLocalBackups(): AutomaticBackupCreator
    {
        $creator = new class implements AutomaticBackupCreator
        {
            public int $calls = 0;

            public function create(): BackupRecord
            {
                $this->calls++;
                $filename = $this->calls === 1
                    ? 'Drclick-Backup-scheduled.msbackup'
                    : 'Drclick-Backup-scheduled-'.$this->calls.'.msbackup';
                // A restore point only counts while its archive is on the PC.
                $path = rtrim((string) config('medismart.backups.managed_directory'), '\\/')
                    .DIRECTORY_SEPARATOR.$filename;
                File::ensureDirectoryExists(dirname($path));
                file_put_contents($path, 'scheduled archive');

                return BackupRecord::query()->create([
                    'filename' => $filename,
                    'disk' => 'local',
                    'local_path' => $path,
                    'size' => 1024,
                    'sha256' => str_repeat('a', 64),
                    'schema_version' => 1,
                    'application_version' => 'test',
                    'status' => 'completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        };
        app()->instance(AutomaticBackupCreator::class, $creator);

        return $creator;
    }

    private function fakeEncryptedCopies(): EncryptedAutomaticBackupCreator
    {
        $creator = new class implements EncryptedAutomaticBackupCreator
        {
            /** @var list<string> */
            public array $passphrases = [];

            public function create(#[SensitiveParameter] string $passphrase): BackupRecord
            {
                $this->passphrases[] = $passphrase;

                return BackupRecord::query()->create([
                    'filename' => 'Drclick-Backup-drive-copy-'.count($this->passphrases).'.msbackup',
                    'disk' => 'local',
                    'size' => 2048,
                    'sha256' => str_repeat('b', 64),
                    'schema_version' => 2,
                    'application_version' => 'test',
                    'status' => 'completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        };
        app()->instance(EncryptedAutomaticBackupCreator::class, $creator);

        return $creator;
    }
}
