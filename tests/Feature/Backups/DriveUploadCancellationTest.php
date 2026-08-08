<?php

namespace Tests\Feature\Backups;

use App\Enums\RoleName;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriveUploadCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole(RoleName::ADMINISTRATOR->value);
    }

    public function test_active_drive_upload_can_be_cancelled_without_a_drive_entitlement(): void
    {
        $record = $this->record(BackupRecord::DRIVE_UPLOAD_UPLOADING);

        $this->actingAs($this->administrator)
            ->delete(route('app.configuration.backup.drive.cancel', $record))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(
            BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
            $record->drive_upload_status,
        );
        $this->assertNotNull($record->drive_upload_cancel_requested_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_upload_cancel_requested',
            'subject_id' => (string) $record->getKey(),
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupDriveUploadCancellationRequested',
        ]);
    }

    public function test_completed_drive_upload_cannot_be_relabelled_as_cancelled(): void
    {
        $record = $this->record(
            BackupRecord::DRIVE_UPLOAD_COMPLETED,
            'completed-remote-id',
        );

        $this->actingAs($this->administrator)
            ->delete(route('app.configuration.backup.drive.cancel', $record))
            ->assertRedirect()
            ->assertSessionHasErrors('drive_cancel');

        $this->assertSame(
            BackupRecord::DRIVE_UPLOAD_COMPLETED,
            $record->refresh()->drive_upload_status,
        );
        $this->assertNull($record->drive_upload_cancel_requested_at);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'backup.drive_upload_cancel_requested')
            ->count());
        $this->assertSame(0, ApplicationEvent::query()
            ->where('event', 'BackupDriveUploadCancellationRequested')
            ->count());
    }

    private function record(string $driveStatus, ?string $remoteId = null): BackupRecord
    {
        return BackupRecord::query()->create([
            'filename' => 'Drclick-Backup-cancel-test.msbackup',
            'disk' => 'local',
            'local_path' => storage_path('app/private/backups/cancel-test.msbackup'),
            'remote_file_id' => $remoteId,
            'drive_upload_status' => $driveStatus,
            'size' => 1024,
            'sha256' => str_repeat('a', 64),
            'schema_version' => 2,
            'application_version' => '2.0.0-test',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'created_by' => $this->administrator->getKey(),
        ]);
    }
}
