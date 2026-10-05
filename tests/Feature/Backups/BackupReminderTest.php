<?php

namespace Tests\Feature\Backups;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\CabinetStatus;
use App\Enums\RoleName;
use App\Models\BackupRecord;
use App\Models\Cabinet;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Models\User;
use App\Services\ApplicationSettingService;
use App\Services\Backups\BackupReminder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupReminderTest extends TestCase
{
    use RefreshDatabase;

    private string $managedRoot;

    private CarbonImmutable $now;

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->managedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-reminder-'.Str::uuid();
        File::ensureDirectoryExists($this->managedRoot);
        config([
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.runtime.desktop_supervised' => true,
        ]);
        $this->now = CarbonImmutable::parse('2026-09-01T12:00:00Z');
        $this->travelTo($this->now);
        $this->cabinet = Cabinet::query()->create([
            'name' => 'Cabinet du poste',
            'status' => CabinetStatus::ACTIVE,
            'activated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->managedRoot);

        parent::tearDown();
    }

    public function test_no_reminder_exists_off_the_supervised_desktop(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);

        $this->assertNull($this->reminder()->state());
        $this->assertNull($this->reminder()->sharedProps($this->doctor()));
    }

    public function test_a_desktop_without_any_local_backup_is_reminded(): void
    {
        $this->assertSame(BackupReminder::LOCAL_MISSING, $this->reminder()->state());
    }

    public function test_a_recent_local_backup_silences_the_reminder(): void
    {
        $this->localBackup($this->now->subHours(2));

        $this->assertNull($this->reminder()->state());
    }

    public function test_a_local_backup_older_than_a_day_is_overdue(): void
    {
        $this->localBackup($this->now->subHours(24)->subMinute());

        $this->assertSame(BackupReminder::LOCAL_OVERDUE, $this->reminder()->state());
    }

    public function test_a_backup_record_whose_file_disappeared_does_not_count(): void
    {
        $record = $this->localBackup($this->now->subHour());
        unlink((string) $record->local_path);

        $this->assertSame(BackupReminder::LOCAL_MISSING, $this->reminder()->state());
    }

    public function test_an_archive_waiting_in_the_drive_outbox_is_not_a_restore_point(): void
    {
        $outbox = $this->managedRoot.DIRECTORY_SEPARATOR.'drive-outbox';
        File::ensureDirectoryExists($outbox);
        $this->localBackup($this->now->subHour(), $outbox);

        $this->assertSame(BackupReminder::LOCAL_MISSING, $this->reminder()->state());
    }

    public function test_drive_problems_are_ignored_while_the_automatic_copy_is_off(): void
    {
        $this->localBackup($this->now->subHour());
        $this->driveRecord(BackupRecord::DRIVE_UPLOAD_FAILED, $this->now->subHour());

        $this->assertNull($this->reminder()->state());
    }

    public function test_an_enabled_automatic_copy_without_a_drive_grant_is_failing(): void
    {
        $this->localBackup($this->now->subHour());
        $this->enableAutomaticCopy();

        $this->assertSame(BackupReminder::DRIVE_FAILING, $this->reminder()->state());
    }

    public function test_two_drive_grants_are_failing_because_the_copy_refuses_to_choose(): void
    {
        $this->localBackup($this->now->subHour());
        $this->enableAutomaticCopy();
        $this->connectDrive();
        $this->connectDrive(CabinetSetting::query()->create(CabinetSetting::defaults()));

        $this->assertSame(BackupReminder::DRIVE_FAILING, $this->reminder()->state());
    }

    public function test_a_connected_copy_with_no_upload_yet_is_fine(): void
    {
        $this->localBackup($this->now->subHour());
        $this->enableAutomaticCopy();
        $this->connectDrive();

        $this->assertNull($this->reminder()->state());
    }

    public function test_a_failed_latest_upload_is_failing(): void
    {
        $this->localBackup($this->now->subHour());
        $this->enableAutomaticCopy();
        $this->connectDrive();
        $this->driveRecord(BackupRecord::DRIVE_UPLOAD_FAILED, $this->now->subHour());

        $this->assertSame(BackupReminder::DRIVE_FAILING, $this->reminder()->state());
    }

    public function test_a_completed_latest_upload_is_fine(): void
    {
        $this->localBackup($this->now->subHour());
        $this->enableAutomaticCopy();
        $this->connectDrive();
        $this->driveRecord(BackupRecord::DRIVE_UPLOAD_FAILED, $this->now->subHours(5));
        $this->driveRecord(BackupRecord::DRIVE_UPLOAD_COMPLETED, $this->now->subHour(), 'remote-id');

        $this->assertNull($this->reminder()->state());
    }

    public function test_a_queue_that_moved_recently_is_fine_but_a_stalled_one_is_failing(): void
    {
        $this->localBackup($this->now->subHour());
        $this->enableAutomaticCopy();
        $this->connectDrive();
        $record = $this->driveRecord(BackupRecord::DRIVE_UPLOAD_RETRYING, $this->now->subHours(2), updatedAt: $this->now->subHours(2));

        $this->assertNull($this->reminder()->state());

        $record->forceFill(['drive_upload_updated_at' => $this->now->subHours(25)])->save();

        $this->assertSame(BackupReminder::DRIVE_FAILING, $this->reminder()->state());
    }

    public function test_a_queued_upload_without_progress_falls_back_to_its_start_time(): void
    {
        $this->localBackup($this->now->subHour());
        $this->enableAutomaticCopy();
        $this->connectDrive();
        $this->driveRecord(BackupRecord::DRIVE_UPLOAD_QUEUED, $this->now->subHours(30));

        $this->assertSame(BackupReminder::DRIVE_FAILING, $this->reminder()->state());
    }

    public function test_the_reminder_is_shared_only_with_someone_who_can_act_on_it(): void
    {
        $assistant = User::factory()->create(['cabinet_id' => $this->cabinet->getKey(), 'approved_at' => now()]);
        $assistant->assignRole(RoleName::ASSISTANT->value);

        $this->assertNull($this->reminder()->sharedProps(null));
        $this->assertNull($this->reminder()->sharedProps($assistant));
        $this->assertSame(['state' => BackupReminder::LOCAL_MISSING], $this->reminder()->sharedProps($this->doctor()));
    }

    public function test_nothing_is_shared_with_the_doctor_while_backups_are_healthy(): void
    {
        $this->localBackup($this->now->subHour());

        $this->assertNull($this->reminder()->sharedProps($this->doctor()));
    }

    private function reminder(): BackupReminder
    {
        return app(BackupReminder::class);
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['cabinet_id' => $this->cabinet->getKey(), 'approved_at' => now()]);
        $doctor->assignRole(RoleName::DOCTOR->value);

        return $doctor;
    }

    private function localBackup(CarbonImmutable $startedAt, ?string $directory = null): BackupRecord
    {
        $path = ($directory ?? $this->managedRoot).DIRECTORY_SEPARATOR.'Drclick-'.Str::random(8).'.msbackup';
        file_put_contents($path, 'archive');

        return BackupRecord::query()->create([
            'filename' => basename($path),
            'local_path' => $path,
            'size' => 7,
            'sha256' => str_repeat('a', 64),
            'schema_version' => 2,
            'application_version' => '1.0.0',
            'status' => 'completed',
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
        ]);
    }

    private function driveRecord(
        string $status,
        CarbonImmutable $startedAt,
        ?string $remoteFileId = null,
        ?CarbonImmutable $updatedAt = null,
    ): BackupRecord {
        return BackupRecord::query()->create([
            'filename' => 'Drclick-drive-'.Str::random(6).'.msbackup',
            'local_path' => null,
            'application_version' => '1.0.0',
            'status' => 'completed',
            'started_at' => $startedAt,
            'drive_upload_status' => $status,
            'drive_upload_updated_at' => $updatedAt,
            'remote_file_id' => $remoteFileId,
        ]);
    }

    private function enableAutomaticCopy(): void
    {
        $settings = app(ApplicationSettingService::class);
        $settings->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD_PASSPHRASE, 'a strong drive passphrase');
        $settings->setInternal(Setting::BACKUP_DRIVE_AUTO_UPLOAD, true);
    }

    private function connectDrive(?CabinetSetting $settings = null): DriveBackupConnection
    {
        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => ($settings ?? CabinetSetting::current($this->cabinet))->getKey(),
            'email' => 'cabinet@example.test',
            'folder_name' => 'Drclick Backups',
            'folder_id' => 'drive-folder-id',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }
}
