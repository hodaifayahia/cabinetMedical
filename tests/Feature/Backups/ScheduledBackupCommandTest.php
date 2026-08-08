<?php

namespace Tests\Feature\Backups;

use App\Backups\AutomaticBackupCreator;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\BackupRecord;
use App\Services\ApplicationSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervised_schedule_creates_at_most_one_verified_archive_for_the_due_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-04 09:15:00', config('app.timezone')));
        config([
            'medismart.runtime.desktop_supervised' => true,
            'medismart.runtime.scheduler_status' => 'active',
        ]);
        $settings = app(ApplicationSettingService::class);
        $settings->setMany([
            Setting::BACKUP_AUTOMATIC_ENABLED => true,
            Setting::BACKUP_SCHEDULE_TIME => '08:30',
        ]);
        $creator = new class implements AutomaticBackupCreator
        {
            public int $calls = 0;

            public function create(): BackupRecord
            {
                $this->calls++;

                return BackupRecord::query()->create([
                    'filename' => 'Drclick-Backup-scheduled.msbackup',
                    'disk' => 'local',
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

        $this->artisan('medismart:backup:scheduled')->assertSuccessful();
        $this->artisan('medismart:backup:scheduled')->assertSuccessful();

        $this->assertSame(1, $creator->calls);
        $this->assertDatabaseCount('backup_records', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.scheduled_completed',
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'ScheduledBackupCompleted',
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupRetentionCompleted',
            'severity' => 'info',
        ]);
    }

    public function test_command_does_nothing_when_scheduler_status_is_not_supervised(): void
    {
        config([
            'medismart.runtime.desktop_supervised' => false,
            'medismart.runtime.scheduler_status' => 'active',
        ]);
        app(ApplicationSettingService::class)->set(
            Setting::BACKUP_AUTOMATIC_ENABLED,
            true,
        );
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
}
