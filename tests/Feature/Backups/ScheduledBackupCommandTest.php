<?php

namespace Tests\Feature\Backups;

use App\Backups\AutomaticBackupCreator;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationSetting;
use App\Models\BackupRecord;
use App\Services\ApplicationSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduledBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $managedRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->managedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drclick-scheduled-'.Str::uuid();
        File::ensureDirectoryExists($this->managedRoot);
        config([
            'medismart.backups.managed_directory' => $this->managedRoot,
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.scheduler_status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->managedRoot);

        parent::tearDown();
    }

    public function test_each_of_the_three_daily_slots_creates_one_verified_archive(): void
    {
        app(ApplicationSettingService::class)->set(Setting::BACKUP_SCHEDULE_TIMES, ['08:30', '13:00', '18:00']);
        $creator = $this->fakeCreator();

        // Nothing yet today: yesterday's evening slot is due as a catch-up.
        $this->at('2026-08-04 07:00');
        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        $this->assertSame(1, $creator->calls);

        foreach (['2026-08-04 08:30' => 2, '2026-08-04 12:59' => 2, '2026-08-04 13:05' => 3, '2026-08-04 18:00' => 4] as $time => $expected) {
            $this->at($time);
            $this->artisan('medismart:backup:scheduled')->assertSuccessful();
            $this->artisan('medismart:backup:scheduled')->assertSuccessful();
            $this->assertSame($expected, $creator->calls, $time);
        }

        $this->assertDatabaseCount('backup_records', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.scheduled_completed']);
        $this->assertDatabaseHas('application_events', ['event' => 'ScheduledBackupCompleted']);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupRetentionCompleted',
            'severity' => 'info',
        ]);
    }

    public function test_a_pc_that_was_off_makes_one_catch_up_copy_not_one_per_missed_slot(): void
    {
        $creator = $this->fakeCreator();
        $this->at('2026-08-02 18:30');
        $creator->create();

        // Off for two days, back on at 19:00: one copy for the 18:00 slot.
        $this->at('2026-08-04 19:00');
        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame(2, $creator->calls);
    }

    public function test_local_backups_cannot_be_switched_off(): void
    {
        // A row left by the earlier on/off setting no longer stops anything.
        ApplicationSetting::putValue(
            key: 'backups.automatic_enabled',
            value: false,
            encrypted: false,
            type: 'boolean',
            group: 'backups',
        );
        $creator = $this->fakeCreator();
        $this->at('2026-08-04 10:30');

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame(1, $creator->calls);
    }

    public function test_a_drive_outbox_copy_or_an_archive_gone_from_the_pc_is_not_a_restore_point(): void
    {
        $this->at('2026-08-04 10:30');
        $outbox = $this->managedRoot.DIRECTORY_SEPARATOR.'drive-outbox';
        File::ensureDirectoryExists($outbox);
        file_put_contents($outbox.DIRECTORY_SEPARATOR.'copy.msbackup', 'encrypted copy');
        $this->record('copy.msbackup', $outbox.DIRECTORY_SEPARATOR.'copy.msbackup', BackupRecord::DRIVE_UPLOAD_QUEUED);
        // For instance an archive listed by a backup restored from another PC.
        $this->record('elsewhere.msbackup', 'C:\\Users\\old-pc\\backups\\elsewhere.msbackup');
        $creator = $this->fakeCreator();

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame(1, $creator->calls);
    }

    public function test_command_does_nothing_when_scheduler_status_is_not_supervised(): void
    {
        config(['medismart.runtime.desktop_supervised' => false]);
        $creator = new class implements AutomaticBackupCreator
        {
            public int $calls = 0;

            public function create(): BackupRecord
            {
                $this->calls++;

                throw new \RuntimeException('This creator must not run.');
            }
        };
        app()->instance(AutomaticBackupCreator::class, $creator);

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame(0, $creator->calls);
        $this->assertDatabaseCount('backup_records', 0);
    }

    public function test_a_failed_backup_is_reported_and_retried_at_the_next_run(): void
    {
        $this->at('2026-08-04 10:30');
        app()->instance(AutomaticBackupCreator::class, new class implements AutomaticBackupCreator
        {
            public function create(): BackupRecord
            {
                throw new \RuntimeException('Disk full.');
            }
        });

        $this->artisan('medismart:backup:scheduled')->assertFailed();

        $this->assertDatabaseHas('application_events', [
            'event' => 'ScheduledBackupFailed',
            'severity' => 'error',
        ]);
        $creator = $this->fakeCreator();
        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        $this->assertSame(1, $creator->calls);
    }

    public function test_the_schedule_needs_three_distinct_24_hour_times(): void
    {
        $settings = app(ApplicationSettingService::class);

        $this->assertSame(['10:00', '14:00', '18:00'], $settings->get(Setting::BACKUP_SCHEDULE_TIMES));

        foreach ([['08:00', '08:00', '12:00'], ['08:00', '12:00'], ['8:00', '12:00', '18:00'], ['08:00', '12:00', '24:00']] as $invalid) {
            try {
                $settings->set(Setting::BACKUP_SCHEDULE_TIMES, $invalid);
                $this->fail('Accepted an invalid schedule: '.implode(', ', $invalid));
            } catch (ValidationException) {
                $this->assertSame(['10:00', '14:00', '18:00'], $settings->get(Setting::BACKUP_SCHEDULE_TIMES));
            }
        }
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, config('app.timezone')));
    }

    private function record(string $filename, string $path, ?string $driveStatus = null): BackupRecord
    {
        return BackupRecord::query()->create([
            'filename' => $filename,
            'disk' => 'local',
            'local_path' => $path,
            'size' => 1024,
            'sha256' => str_repeat('b', 64),
            'schema_version' => 1,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'drive_upload_status' => $driveStatus,
        ]);
    }

    private function fakeCreator(): object
    {
        $root = $this->managedRoot;
        $creator = new class($root) implements AutomaticBackupCreator
        {
            public int $calls = 0;

            public function __construct(private readonly string $root) {}

            public function create(): BackupRecord
            {
                $this->calls++;
                $filename = 'Drclick-Backup-scheduled-'.$this->calls.'.msbackup';
                $path = $this->root.DIRECTORY_SEPARATOR.$filename;
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
}
