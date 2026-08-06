<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupArchiveException;
use App\Backups\RemoteBackupRetentionManager;
use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Services\ApplicationSettingService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RemoteBackupRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['backup.remote_retention.enabled' => true]);
    }

    public function test_configured_policy_defaults_are_seven_daily_four_weekly_and_twelve_monthly(): void
    {
        $retention = app(RemoteBackupRetentionManager::class);
        $policy = $retention->configuredPolicy();

        $this->assertTrue($retention->configuredEnabled());
        $this->assertSame(7, $policy->daily);
        $this->assertSame(4, $policy->weekly);
        $this->assertSame(12, $policy->monthly);
        $this->assertNull($policy->maximumStorageBytes);
    }

    public function test_retention_uses_the_values_saved_by_the_configuration_ui(): void
    {
        $settings = app(ApplicationSettingService::class);
        $settings->setMany([
            Setting::BACKUP_RETENTION_DAILY => 9,
            Setting::BACKUP_RETENTION_WEEKLY => 5,
            Setting::BACKUP_RETENTION_MONTHLY => 15,
            Setting::BACKUP_MAXIMUM_STORAGE_BYTES => 250 * 1024 * 1024,
        ]);

        $policy = app(RemoteBackupRetentionManager::class)->configuredPolicy();

        $this->assertSame(9, $policy->daily);
        $this->assertSame(5, $policy->weekly);
        $this->assertSame(15, $policy->monthly);
        $this->assertSame(250 * 1024 * 1024, $policy->maximumStorageBytes);
    }

    public function test_retention_deletes_only_after_fresh_planning_and_newer_local_remote_proof(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $this->oneBucketPolicy();
        $targetRecordId = (string) Str::uuid();
        $this->completedRemoteRecord(
            'drive-old-id',
            'older-bytes',
            $targetRecordId,
        );
        $newerRecord = $this->completedRemoteRecord(
            'drive-newest-id',
            'newest-bytes',
        );
        $target = $this->remoteMetadata(
            'drive-old-id',
            'older-bytes',
            $targetRecordId,
            '2026-07-01T08:00:00Z',
        );
        $newer = $this->remoteMetadata(
            'drive-newest-id',
            'newest-bytes',
            (string) $newerRecord->getKey(),
            '2026-08-01T08:00:00Z',
        );

        Http::fake(function (Request $request) use ($target, $newer) {
            if ($request->method() === 'GET' && isset($request->data()['q'])) {
                return Http::response(['files' => [$newer, $target]]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/drive-old-id')) {
                return Http::response($target);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/drive-newest-id')) {
                return Http::response($newer);
            }

            if ($request->method() === 'DELETE'
                && str_ends_with($request->url(), '/drive-old-id')) {
                return Http::response(status: 204);
            }

            return Http::response(['error' => 'unexpected_request'], 500);
        });

        $this->artisan('medismart:backup:drive-retention --force')
            ->assertSuccessful();

        Http::assertSentCount(7);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/drive-old-id'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_retention_deleted',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_retention_completed',
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupDriveRetentionCompleted',
            'severity' => 'info',
        ]);

        $history = json_encode([
            AuditLog::query()->where('action', 'like', 'backup.drive_retention_%')
                ->pluck('metadata'),
            ApplicationEvent::query()->where('event', 'like', 'BackupDriveRetention%')
                ->pluck('context'),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('drive-access-token', $history);
        $this->assertStringNotContainsString('drive-refresh-token', $history);
    }

    public function test_retention_fails_closed_without_a_matching_completed_local_newer_record(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $this->oneBucketPolicy();
        $targetRecord = $this->completedRemoteRecord(
            'drive-old-id',
            'older-bytes',
        );
        $target = $this->remoteMetadata(
            'drive-old-id',
            'older-bytes',
            (string) $targetRecord->getKey(),
            '2026-07-01T08:00:00Z',
        );
        $newer = $this->remoteMetadata(
            'drive-newest-id',
            'newest-bytes',
            (string) Str::uuid(),
            '2026-08-01T08:00:00Z',
        );

        Http::fake(function (Request $request) use ($target, $newer) {
            if ($request->method() === 'GET' && isset($request->data()['q'])) {
                return Http::response(['files' => [$newer, $target]]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/drive-old-id')) {
                return Http::response($target);
            }

            return Http::response(['error' => 'unexpected_request'], 500);
        });

        $this->artisan('medismart:backup:drive-retention --force')
            ->assertFailed();

        Http::assertNotSent(
            fn (Request $request): bool => $request->method() === 'DELETE',
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_retention_failed',
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupDriveRetentionFailed',
            'severity' => 'warning',
        ]);
    }

    public function test_malformed_matching_drive_inventory_fails_before_any_delete(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $malformed = $this->remoteMetadata(
            'drive-malformed-id',
            'malformed-bytes',
            (string) Str::uuid(),
            '2026-08-01T08:00:00Z',
        );
        unset($malformed['appProperties']['medismart_sha256']);
        Http::fake([
            'www.googleapis.com/drive/v3/files*' => Http::response([
                'files' => [$malformed],
            ]),
        ]);

        $this->artisan('medismart:backup:drive-retention --force')
            ->assertFailed();

        Http::assertSentCount(1);
        Http::assertNotSent(
            fn (Request $request): bool => $request->method() === 'DELETE',
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_retention_failed',
        ]);
    }

    public function test_inventory_is_paginated_and_rejects_no_entries_silently(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $firstRecord = $this->completedRemoteRecord(
            'drive-first-id',
            'first-bytes',
        );
        $secondRecord = $this->completedRemoteRecord(
            'drive-second-id',
            'second-bytes',
        );
        $first = $this->remoteMetadata(
            'drive-first-id',
            'first-bytes',
            (string) $firstRecord->getKey(),
            '2026-08-01T08:00:00Z',
        );
        $second = $this->remoteMetadata(
            'drive-second-id',
            'second-bytes',
            (string) $secondRecord->getKey(),
            '2026-07-01T08:00:00Z',
        );
        Http::fake(function (Request $request) use ($first, $second) {
            return isset($request->data()['pageToken'])
                ? Http::response(['files' => [$second]])
                : Http::response([
                    'files' => [$first],
                    'nextPageToken' => 'next-page-2',
                ]);
        });

        $inventory = app(GoogleDriveService::class)
            ->retentionInventory($cabinet);

        $this->assertCount(2, $inventory);
        $this->assertSame('drive-first-id', $inventory[0]['id']);
        $this->assertSame('drive-second-id', $inventory[1]['id']);
        $this->assertSame('verified', $inventory[0]['verification_status']);
        $this->assertSame($inventory[0]['sha256'], $inventory[0]['verified_sha256']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request->data()['pageToken'] ?? null)
            === 'next-page-2');
    }

    public function test_valid_foreign_artifact_is_protected_and_cannot_be_deleted_directly(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $newerRecord = $this->completedRemoteRecord(
            'drive-newest-id',
            'newest-bytes',
        );
        $foreign = $this->remoteMetadata(
            'drive-foreign-id',
            'foreign-bytes',
            (string) Str::uuid(),
            '2026-07-01T08:00:00Z',
        );
        $newer = $this->remoteMetadata(
            'drive-newest-id',
            'newest-bytes',
            (string) $newerRecord->getKey(),
            '2026-08-01T08:00:00Z',
        );
        Http::fake(function (Request $request) use ($foreign, $newer) {
            if ($request->method() === 'GET'
                && str_contains($request->url(), '/drive-foreign-id')) {
                return Http::response($foreign);
            }

            if ($request->method() === 'GET' && isset($request->data()['q'])) {
                return Http::response(['files' => [$newer, $foreign]]);
            }

            return Http::response(['error' => 'unexpected_request'], 500);
        });

        try {
            app(GoogleDriveService::class)->deleteManagedBackup(
                $cabinet,
                'drive-foreign-id',
            );
            $this->fail('A foreign Drive artifact must never become a deletion target.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The selected Google Drive backup has no exact completed local upload record.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
        Http::assertNotSent(
            fn (Request $request): bool => $request->method() === 'DELETE',
        );
    }

    public function test_unsupervised_scheduler_never_contacts_drive(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        config([
            'medismart.runtime.desktop_supervised' => false,
            'medismart.runtime.scheduler_status' => 'active',
        ]);
        Http::fake();

        $this->artisan('medismart:backup:drive-retention')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'backup.drive_retention_completed',
        ]);
    }

    public function test_invalid_enabled_kill_switch_fails_closed_before_drive_inventory(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        config(['backup.remote_retention.enabled' => 'sometimes']);
        Http::fake();

        $this->artisan('medismart:backup:drive-retention --force')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_retention_failed',
        ]);
        $this->expectException(BackupArchiveException::class);
        app(RemoteBackupRetentionManager::class)->configuredEnabled();
    }

    private function connectedDrive(CabinetSetting $cabinet): DriveBackupConnection
    {
        return DriveBackupConnection::query()->create([
            'cabinet_setting_id' => $cabinet->getKey(),
            'email' => 'doctor@example.test',
            'folder_name' => 'MediSmart Backups',
            'folder_id' => 'drive-folder-id',
            'access_token' => 'drive-access-token',
            'refresh_token' => 'drive-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function oneBucketPolicy(): void
    {
        app(ApplicationSettingService::class)->setMany([
            Setting::BACKUP_RETENTION_DAILY => 1,
            Setting::BACKUP_RETENTION_WEEKLY => 1,
            Setting::BACKUP_RETENTION_MONTHLY => 1,
        ]);
    }

    private function completedRemoteRecord(
        string $remoteFileId,
        string $bytes,
        ?string $recordId = null,
    ): BackupRecord {
        $record = new BackupRecord([
            'filename' => 'MediSmart-Backup-'.$remoteFileId.'.msbackup',
            'disk' => 'local',
            'remote_file_id' => $remoteFileId,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'schema_version' => 1,
            'application_version' => 'test',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        if ($recordId !== null) {
            $record->setAttribute($record->getKeyName(), $recordId);
        }

        $record->save();

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteMetadata(
        string $fileId,
        string $bytes,
        string $backupRecordId,
        string $createdAt,
    ): array {
        $size = (string) strlen($bytes);

        return [
            'id' => $fileId,
            'name' => 'MediSmart-Backup-'.$fileId.'.msbackup',
            'size' => $size,
            'createdTime' => $createdAt,
            'mimeType' => 'application/vnd.medismart.backup',
            'parents' => ['drive-folder-id'],
            'appProperties' => [
                'medismart_backup_record_id' => $backupRecordId,
                'medismart_format' => 'msbackup',
                'medismart_format_version' => '2',
                'medismart_size_bytes' => $size,
                'medismart_sha256' => hash('sha256', $bytes),
            ],
        ];
    }
}
