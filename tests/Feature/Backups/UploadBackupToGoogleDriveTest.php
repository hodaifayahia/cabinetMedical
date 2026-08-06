<?php

namespace Tests\Feature\Backups;

use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class UploadBackupToGoogleDriveTest extends TestCase
{
    use RefreshDatabase;

    private const MAGIC = "MEDISMART-MSBAK\x02";

    /** @var list<string> */
    private array $createdFiles = [];

    private string $uploadedMultipartBody = '';

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/private/backups'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_job_uploads_only_a_verified_v2_archive_with_safe_drive_metadata(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet);
        $this->fakeSuccessfulUpload();

        $job = new UploadBackupToGoogleDrive(
            (int) $cabinet->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        );
        $serializedJob = serialize($job);

        $this->assertSame('backups', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
        $this->assertStringNotContainsString((string) $record->local_path, $serializedJob);
        $this->assertStringNotContainsString('drive-access-token', $serializedJob);
        $this->assertStringNotContainsString('drive-refresh-token', $serializedJob);
        $this->assertStringNotContainsString('recovery passphrase', $serializedJob);

        $job->handle(app(GoogleDriveService::class));

        $record->refresh();
        $this->assertSame('remote-file-id', $record->remote_file_id);
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_COMPLETED, $record->drive_upload_status);
        $this->assertSame($record->size, $record->drive_upload_bytes);
        $this->assertSame(1, $record->drive_upload_attempts);
        $this->assertNull($record->drive_upload_failure_code);
        $this->assertNull($record->drive_upload_cancel_requested_at);
        $this->assertSame(
            $record->filename,
            DriveBackupConnection::query()->sole()->last_backup_name,
        );
        $this->assertDatabaseHas('cloud_connections', [
            'provider' => 'google_drive',
            'status' => 'connected',
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_uploaded',
            'subject_id' => (string) $record->getKey(),
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupDriveUploadCompleted',
            'severity' => 'info',
        ]);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST'
                || ! str_contains($request->url(), '/upload/drive/v3/files?uploadType=multipart')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer drive-access-token')
                && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data');
        });
        $this->assertStringContainsString('application/vnd.medismart.backup', $this->uploadedMultipartBody);
        $this->assertStringContainsString('medismart_format_version', $this->uploadedMultipartBody);
        $this->assertStringContainsString('medismart_sha256', $this->uploadedMultipartBody);
        $this->assertStringContainsString((string) $record->sha256, $this->uploadedMultipartBody);
        $this->assertStringContainsString((string) $record->getKey(), $this->uploadedMultipartBody);
        $this->assertStringNotContainsString('drive-refresh-token', $this->uploadedMultipartBody);
        $this->assertStringNotContainsString((string) $record->local_path, $this->uploadedMultipartBody);

        $audit = AuditLog::query()->where('action', 'backup.drive_uploaded')->sole();
        $event = ApplicationEvent::query()->where('event', 'BackupDriveUploadCompleted')->sole();
        $history = json_encode([$audit->metadata, $event->context], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('drive-access-token', $history);
        $this->assertStringNotContainsString('drive-refresh-token', $history);
        $this->assertStringNotContainsString((string) $record->local_path, $history);
    }

    public function test_job_rejects_an_artifact_outside_the_private_backup_directory(): void
    {
        $cabinet = CabinetSetting::current();
        $path = storage_path('framework/testing/'.Str::uuid().'.msbackup');
        File::ensureDirectoryExists(dirname($path));
        $bytes = self::MAGIC.'outside-root';
        $this->writeTestFile($path, $bytes);
        $record = $this->recordFor($path, $bytes);
        $this->connectedDrive($cabinet);
        Http::fake();

        $this->assertRejectedWithoutTransfer($cabinet, $record);
    }

    public function test_job_rejects_an_archive_tampered_after_the_record_was_completed(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet);
        $this->assertSame(8, file_put_contents((string) $record->local_path, 'tampered', FILE_APPEND));
        Http::fake();

        $this->assertRejectedWithoutTransfer($cabinet, $record);
    }

    public function test_job_rejects_a_matching_checksum_when_the_v2_magic_is_missing(): void
    {
        $cabinet = CabinetSetting::current();
        $filename = 'test-drive-'.Str::uuid().'.msbackup';
        $path = storage_path('app/private/backups/'.$filename);
        $bytes = str_repeat('x', strlen(self::MAGIC)).'not-a-v2-envelope';
        $this->writeTestFile($path, $bytes);
        $record = $this->recordFor($path, $bytes);
        $this->connectedDrive($cabinet);
        Http::fake();

        $this->assertRejectedWithoutTransfer($cabinet, $record);
    }

    public function test_job_permanently_fails_a_local_artifact_validation_error(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet);
        $this->assertSame(8, file_put_contents((string) $record->local_path, 'tampered', FILE_APPEND));
        Http::fake();

        $job = (new UploadBackupToGoogleDrive(
            (int) $cabinet->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        ))->withFakeQueueInteractions();

        $job->handle(app(GoogleDriveService::class));

        $job->assertFailedWith(RuntimeException::class);
        Http::assertNothingSent();
        $this->assertSame(
            BackupRecord::DRIVE_UPLOAD_FAILED,
            $record->refresh()->drive_upload_status,
        );
        $this->assertSame(
            'permanent_precondition_failed',
            $record->drive_upload_failure_code,
        );
    }

    public function test_job_permanently_fails_after_the_drive_account_is_disconnected(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet)->delete();
        Http::fake();

        $job = (new UploadBackupToGoogleDrive(
            (int) $cabinet->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        ))->withFakeQueueInteractions();

        $job->handle(app(GoogleDriveService::class));

        $job->assertFailedWith(RuntimeException::class);
        Http::assertNothingSent();
        $this->assertNull($record->refresh()->remote_file_id);
    }

    public function test_job_leaves_a_transient_drive_failure_for_bounded_retry(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet);
        Http::fake([
            'www.googleapis.com/drive/v3/files*' => Http::response([
                'error' => 'temporary outage',
            ], 503),
        ]);

        $job = (new UploadBackupToGoogleDrive(
            (int) $cabinet->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        ))->withFakeQueueInteractions();

        try {
            $job->handle(app(GoogleDriveService::class));
            $this->fail('A temporary Drive failure must be released to the worker retry policy.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The verified backup could not be uploaded to Google Drive.',
                $exception->getMessage(),
            );
        }

        $job->assertNotFailed();
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
        $this->assertSame(
            BackupRecord::DRIVE_UPLOAD_RETRYING,
            $record->refresh()->drive_upload_status,
        );
        $this->assertSame(1, $record->drive_upload_attempts);
        $this->assertSame('transfer_failed', $record->drive_upload_failure_code);
        Http::assertSentCount(1);
    }

    public function test_job_honours_a_cancellation_request_before_any_transfer(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $record->update([
            'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
            'drive_upload_cancel_requested_at' => now(),
        ]);
        $this->connectedDrive($cabinet);
        Http::fake();

        (new UploadBackupToGoogleDrive(
            (int) $cabinet->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        ))->handle(app(GoogleDriveService::class));

        $record->refresh();
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_CANCELLED, $record->drive_upload_status);
        $this->assertNull($record->drive_upload_failure_code);
        $this->assertNotNull($record->drive_upload_cancel_requested_at);
        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_upload_cancelled',
            'subject_id' => (string) $record->getKey(),
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupDriveUploadCancelled',
            'severity' => 'info',
        ]);
    }

    public function test_provider_success_is_recorded_atomically_when_cancellation_arrives_too_late(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet);
        Http::fake(function (Request $request) use ($record) {
            if ($request->method() === 'GET') {
                return Http::response(['files' => []]);
            }

            if ($request->method() === 'POST'
                && str_contains($request->url(), 'www.googleapis.com/upload/drive/v3/files')) {
                BackupRecord::query()->whereKey($record->getKey())->update([
                    'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
                    'drive_upload_cancel_requested_at' => now(),
                    'drive_upload_updated_at' => now(),
                ]);

                return Http::response([
                    'id' => 'remote-race-winner',
                    'name' => 'MediSmart encrypted backup',
                ]);
            }

            return Http::response([], 500);
        });

        (new UploadBackupToGoogleDrive(
            (int) $cabinet->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        ))->handle(app(GoogleDriveService::class));

        $record->refresh();
        $this->assertSame('remote-race-winner', $record->remote_file_id);
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_COMPLETED, $record->drive_upload_status);
        $this->assertNull($record->drive_upload_cancel_requested_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_uploaded',
            'subject_id' => (string) $record->getKey(),
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'backup.drive_upload_cancelled',
            'subject_id' => (string) $record->getKey(),
        ]);
    }

    public function test_concurrent_cancellation_cannot_be_overwritten_by_retry_state(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet);
        Http::fake(function (Request $request) use ($record) {
            BackupRecord::query()->whereKey($record->getKey())->update([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
                'drive_upload_cancel_requested_at' => now(),
                'drive_upload_updated_at' => now(),
            ]);

            return Http::response(['error' => 'temporary outage'], 503);
        });

        (new UploadBackupToGoogleDrive(
            (int) $cabinet->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        ))->handle(app(GoogleDriveService::class));

        $record->refresh();
        $this->assertNull($record->remote_file_id);
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_CANCELLED, $record->drive_upload_status);
        $this->assertNull($record->drive_upload_failure_code);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_upload_cancelled',
            'subject_id' => (string) $record->getKey(),
        ]);
    }

    public function test_exhausted_job_records_a_bounded_terminal_failure(): void
    {
        $record = $this->completedArchive();
        $record->update([
            'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_RETRYING,
            'drive_upload_attempts' => 3,
            'drive_upload_failure_code' => 'transfer_failed',
        ]);
        $job = new UploadBackupToGoogleDrive(
            (int) CabinetSetting::current()->getKey(),
            (string) $record->getKey(),
            'MediSmart Backups',
        );

        $job->failed(new RuntimeException('sensitive provider detail'));

        $record->refresh();
        $this->assertSame(BackupRecord::DRIVE_UPLOAD_FAILED, $record->drive_upload_status);
        $this->assertSame(3, $record->drive_upload_attempts);
        $this->assertSame('retry_exhausted', $record->drive_upload_failure_code);
        $event = ApplicationEvent::query()
            ->where('event', 'BackupDriveUploadFailed')
            ->latest('id')
            ->firstOrFail();
        $this->assertStringNotContainsString(
            'sensitive provider detail',
            json_encode($event->context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_remote_list_includes_only_files_with_complete_v2_metadata(): void
    {
        $cabinet = CabinetSetting::current();
        $record = $this->completedArchive();
        $this->connectedDrive($cabinet);
        Http::fake([
            'www.googleapis.com/drive/v3/files*' => Http::response([
                'files' => [
                    [
                        'id' => 'remote-valid-id',
                        'name' => 'MediSmart-Backup-valid.msbackup',
                        'size' => '123456',
                        'createdTime' => '2026-08-04T18:30:00Z',
                        'mimeType' => 'application/vnd.medismart.backup',
                        'parents' => ['drive-folder-id'],
                        'appProperties' => [
                            'medismart_backup_record_id' => (string) $record->getKey(),
                            'medismart_format' => 'msbackup',
                            'medismart_format_version' => '2',
                            'medismart_size_bytes' => '123456',
                            'medismart_sha256' => str_repeat('b', 64),
                        ],
                    ],
                    [
                        'id' => 'remote-untrusted-id',
                        'name' => '<script>.msbackup',
                        'size' => '100',
                        'createdTime' => '2026-08-04T18:30:00Z',
                        'appProperties' => [],
                    ],
                ],
            ]),
        ]);

        $backups = app(GoogleDriveService::class)->listBackups($cabinet);

        $this->assertCount(1, $backups);
        $this->assertSame('remote-valid-id', $backups[0]['id']);
        $this->assertSame('MediSmart-Backup-valid.msbackup', $backups[0]['name']);
        $this->assertSame(123456, $backups[0]['size_bytes']);
        $this->assertSame(str_repeat('b', 12), $backups[0]['sha256_hint']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'www.googleapis.com/drive/v3/files')
            && str_contains((string) $request['q'], "'drive-folder-id' in parents")
            && str_contains((string) $request['q'], 'application/vnd.medismart.backup')
            && $request->hasHeader('Authorization', 'Bearer drive-access-token'));
    }

    public function test_remote_download_is_published_only_after_streamed_size_hash_and_magic_verification(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $bytes = self::MAGIC.'verified-drive-download';
        $sourceRecordId = (string) Str::uuid();
        $this->fakeRemoteArtifact('remote-download-id', $bytes, $sourceRecordId);

        $record = app(GoogleDriveService::class)->downloadVerifiedArchive(
            $cabinet,
            'remote-download-id',
            null,
        );

        $this->assertSame('completed', $record->status);
        $this->assertSame('remote-download-id', $record->remote_file_id);
        $this->assertSame(strlen($bytes), $record->size);
        $this->assertSame(hash('sha256', $bytes), $record->sha256);
        $this->assertIsString($record->local_path);
        $this->assertFileExists($record->local_path);
        $this->assertSame($bytes, file_get_contents($record->local_path));
        $this->assertStringContainsString('-Drive-', $record->filename);
        $this->createdFiles[] = $record->local_path;
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_downloaded',
            'subject_id' => (string) $record->getKey(),
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupDriveDownloadCompleted',
        ]);
        Http::assertSentCount(2);
    }

    public function test_remote_download_checksum_mismatch_is_removed_and_never_recorded(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $bytes = self::MAGIC.'tampered-drive-download';
        $sourceRecordId = (string) Str::uuid();
        $this->fakeRemoteArtifact(
            'remote-tampered-id',
            $bytes,
            $sourceRecordId,
            str_repeat('f', 64),
        );

        try {
            app(GoogleDriveService::class)->downloadVerifiedArchive(
                $cabinet,
                'remote-tampered-id',
                null,
            );
            $this->fail('A remote artifact with a mismatched checksum must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The Google Drive backup could not be downloaded and verified.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('backup_records', 0);
        $this->assertSame([], glob(storage_path('app/private/backups/.drive-*.part')) ?: []);
    }

    public function test_remote_delete_targets_only_a_revalidated_managed_file_in_the_exact_folder(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $bytes = self::MAGIC.'managed-delete';
        $newerBytes = self::MAGIC.'newer-managed-backup';
        $targetPath = storage_path('app/private/backups/target-'.Str::uuid().'.msbackup');
        $this->writeTestFile($targetPath, $bytes);
        $targetRecord = $this->recordFor($targetPath, $bytes);
        $targetRecord->update([
            'remote_file_id' => 'remote-delete-id',
            'filename' => 'MediSmart-Backup-remote.msbackup',
        ]);
        $newerPath = storage_path('app/private/backups/newer-'.Str::uuid().'.msbackup');
        $this->writeTestFile($newerPath, $newerBytes);
        $newerRecord = $this->recordFor($newerPath, $newerBytes);
        $newerRecord->update([
            'remote_file_id' => 'remote-newer-id',
            'filename' => 'MediSmart-Backup-remote.msbackup',
        ]);
        $targetMetadata = $this->remoteMetadata(
            'remote-delete-id',
            $bytes,
            (string) $targetRecord->getKey(),
            createdAt: '2026-08-04T18:30:00Z',
        );
        $newerMetadata = $this->remoteMetadata(
            'remote-newer-id',
            $newerBytes,
            (string) $newerRecord->getKey(),
            createdAt: '2026-08-05T18:30:00Z',
        );
        Http::fake(function (Request $request) use ($targetMetadata, $newerMetadata) {
            if ($request->method() === 'GET' && isset($request->data()['q'])) {
                return Http::response(['files' => [$newerMetadata, $targetMetadata]]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/remote-delete-id')) {
                return Http::response($targetMetadata);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/remote-newer-id')) {
                return Http::response($newerMetadata);
            }

            if ($request->method() === 'DELETE'
                && str_ends_with($request->url(), '/remote-delete-id')) {
                return Http::response(status: 204);
            }

            return Http::response([], 500);
        });

        $deleted = app(GoogleDriveService::class)->deleteManagedBackup(
            $cabinet,
            'remote-delete-id',
        );

        $this->assertSame('remote-delete-id', $deleted['id']);
        $this->assertSame((string) $targetRecord->getKey(), $deleted['backup_record_id']);
        $this->assertSame(
            (string) $newerRecord->getKey(),
            $deleted['newer_backup_record_id'],
        );
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/remote-delete-id'));
    }

    public function test_remote_delete_fails_closed_when_newer_metadata_has_no_verified_local_upload_record(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        $bytes = self::MAGIC.'managed-delete';
        $newerBytes = self::MAGIC.'unverified-newer-backup';
        $targetPath = storage_path('app/private/backups/target-'.Str::uuid().'.msbackup');
        $this->writeTestFile($targetPath, $bytes);
        $targetRecord = $this->recordFor($targetPath, $bytes);
        $targetRecord->update([
            'remote_file_id' => 'remote-delete-id',
            'filename' => 'MediSmart-Backup-remote.msbackup',
        ]);
        $targetMetadata = $this->remoteMetadata(
            'remote-delete-id',
            $bytes,
            (string) $targetRecord->getKey(),
            createdAt: '2026-08-04T18:30:00Z',
        );
        $unverifiedNewerMetadata = $this->remoteMetadata(
            'remote-unverified-newer-id',
            $newerBytes,
            (string) Str::uuid(),
            createdAt: '2026-08-05T18:30:00Z',
        );
        Http::fake(function (Request $request) use (
            $targetMetadata,
            $unverifiedNewerMetadata,
        ) {
            if ($request->method() === 'GET' && isset($request->data()['q'])) {
                return Http::response([
                    'files' => [$unverifiedNewerMetadata, $targetMetadata],
                ]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/remote-delete-id')) {
                return Http::response($targetMetadata);
            }

            return Http::response([], 500);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'No newer verified Google Drive backup protects this deletion.',
        );

        try {
            app(GoogleDriveService::class)->deleteManagedBackup(
                $cabinet,
                'remote-delete-id',
            );
        } finally {
            Http::assertSentCount(2);
            Http::assertNotSent(
                fn (Request $request): bool => $request->method() === 'DELETE',
            );
        }
    }

    public function test_drive_connection_test_uses_the_minimal_drive_identity_endpoint(): void
    {
        $cabinet = CabinetSetting::current();
        $this->connectedDrive($cabinet);
        Http::fake([
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'verified@example.test'],
            ]),
        ]);

        $result = app(GoogleDriveService::class)->testConnection($cabinet);

        $this->assertSame('verified@example.test', $result['email']);
        $this->assertSame(
            'verified@example.test',
            DriveBackupConnection::query()->sole()->email,
        );
        $this->assertDatabaseHas('cloud_connections', [
            'provider' => 'google_drive',
            'status' => 'connected',
            'last_error' => null,
        ]);
        $this->assertSame(
            'verified',
            app(GoogleDriveService::class)->status($cabinet)['verification_state'],
        );
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/drive/v3/about')
            && $request->hasHeader('Authorization', 'Bearer drive-access-token'));
    }

    private function completedArchive(): BackupRecord
    {
        $filename = 'test-drive-'.Str::uuid().'.msbackup';
        $path = storage_path('app/private/backups/'.$filename);
        $bytes = self::MAGIC.'authenticated-encrypted-fixture';
        $this->writeTestFile($path, $bytes);

        return $this->recordFor($path, $bytes);
    }

    private function fakeRemoteArtifact(
        string $fileId,
        string $bytes,
        string $sourceRecordId,
        ?string $metadataSha256 = null,
    ): void {
        Http::fake(function (Request $request) use (
            $fileId,
            $bytes,
            $sourceRecordId,
            $metadataSha256,
        ) {
            $data = $request->data();

            if ($request->method() === 'GET' && isset($data['fields'])) {
                return Http::response($this->remoteMetadata(
                    $fileId,
                    $bytes,
                    $sourceRecordId,
                    $metadataSha256,
                ));
            }

            if ($request->method() === 'GET' && ($data['alt'] ?? null) === 'media') {
                return Http::response($bytes, 200, [
                    'Content-Length' => (string) strlen($bytes),
                ]);
            }

            return Http::response([], 500);
        });
    }

    /** @return array<string, mixed> */
    private function remoteMetadata(
        string $fileId,
        string $bytes,
        string $sourceRecordId,
        ?string $metadataSha256 = null,
        string $createdAt = '2026-08-04T18:30:00Z',
    ): array {
        return [
            'id' => $fileId,
            'name' => 'MediSmart-Backup-remote.msbackup',
            'size' => (string) strlen($bytes),
            'createdTime' => $createdAt,
            'mimeType' => 'application/vnd.medismart.backup',
            'parents' => ['drive-folder-id'],
            'appProperties' => [
                'medismart_backup_record_id' => $sourceRecordId,
                'medismart_format' => 'msbackup',
                'medismart_format_version' => '2',
                'medismart_size_bytes' => (string) strlen($bytes),
                'medismart_sha256' => $metadataSha256 ?? hash('sha256', $bytes),
            ],
        ];
    }

    private function recordFor(string $path, string $bytes): BackupRecord
    {
        return BackupRecord::query()->create([
            'filename' => basename($path),
            'disk' => 'local',
            'local_path' => $path,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'schema_version' => 1,
            'application_version' => '2.0.0-test',
            'status' => 'completed',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
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

    private function fakeSuccessfulUpload(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET'
                && str_contains($request->url(), 'www.googleapis.com/drive/v3/files')) {
                return Http::response(['files' => []]);
            }

            if ($request->method() === 'POST'
                && str_contains($request->url(), 'www.googleapis.com/upload/drive/v3/files')) {
                $this->uploadedMultipartBody = $request->body();

                return Http::response([
                    'id' => 'remote-file-id',
                    'name' => 'MediSmart encrypted backup',
                ]);
            }

            return Http::response(['error' => 'unexpected request'], 500);
        });
    }

    private function assertRejectedWithoutTransfer(
        CabinetSetting $cabinet,
        BackupRecord $record,
    ): void {
        try {
            (new UploadBackupToGoogleDrive(
                (int) $cabinet->getKey(),
                (string) $record->getKey(),
                'MediSmart Backups',
            ))->handle(app(GoogleDriveService::class));
            $this->fail('An unsafe or altered backup artifact must not be transferred.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The verified backup could not be uploaded to Google Drive.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString((string) $record->local_path, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNull($record->refresh()->remote_file_id);
        $this->assertSame(
            BackupRecord::DRIVE_UPLOAD_FAILED,
            $record->drive_upload_status,
        );
        $this->assertSame(
            'permanent_precondition_failed',
            $record->drive_upload_failure_code,
        );
        $this->assertDatabaseHas('cloud_connections', [
            'provider' => 'google_drive',
            'status' => 'error',
            'last_error' => 'The Google Drive backup upload failed.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.drive_upload_failed',
            'subject_id' => (string) $record->getKey(),
        ]);
        $this->assertDatabaseHas('application_events', [
            'event' => 'BackupDriveUploadFailed',
            'severity' => 'error',
        ]);

        $audit = AuditLog::query()->where('action', 'backup.drive_upload_failed')->sole();
        $event = ApplicationEvent::query()->where('event', 'BackupDriveUploadFailed')->sole();
        $history = json_encode([$audit->metadata, $event->context], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $record->local_path, $history);
        $this->assertStringNotContainsString('drive-access-token', $history);
        $this->assertStringNotContainsString('drive-refresh-token', $history);
    }

    private function writeTestFile(string $path, string $bytes): void
    {
        $written = file_put_contents($path, $bytes);
        $this->assertSame(strlen($bytes), $written);
        $this->createdFiles[] = $path;
    }
}
