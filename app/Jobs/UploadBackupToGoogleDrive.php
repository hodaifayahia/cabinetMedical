<?php

namespace App\Jobs;

use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Services\Backups\DriveUploadCancelled;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Upload a previously completed encrypted backup without serializing its
 * recovery secret, OAuth credentials, or local filesystem path into the queue.
 */
final class UploadBackupToGoogleDrive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public readonly int $cabinetId;

    public readonly string $backupRecordId;

    public readonly string $folderName;

    public function __construct(int $cabinetId, string $backupRecordId, string $folderName)
    {
        $folderName = trim($folderName);

        if ($cabinetId < 1
            || ! Str::isUuid($backupRecordId)
            || $folderName === ''
            || Str::length($folderName) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $folderName) === 1) {
            throw new InvalidArgumentException('The queued Google Drive backup request is invalid.');
        }

        $this->cabinetId = $cabinetId;
        $this->backupRecordId = $backupRecordId;
        $this->folderName = $folderName;
        $this->onQueue('backups');
    }

    public function handle(GoogleDriveService $drive): void
    {
        $cabinet = CabinetSetting::query()->find($this->cabinetId);
        $record = BackupRecord::query()->find($this->backupRecordId);

        if (! $cabinet instanceof CabinetSetting || ! $record instanceof BackupRecord) {
            $this->recordFailure($record, 'record_unavailable');
            $this->rejectPermanently(
                new RuntimeException('The queued Google Drive backup is no longer available.'),
            );

            return;
        }

        if (is_string($record->remote_file_id) && $record->remote_file_id !== '') {
            $this->markCompleted($record);

            return;
        }

        $attempt = max(1, $this->attempts());
        $started = BackupRecord::query()
            ->whereKey($record->getKey())
            ->whereNull('remote_file_id')
            ->whereNull('drive_upload_cancel_requested_at')
            ->where(function ($query): void {
                $query->whereNull('drive_upload_status')
                    ->orWhereIn('drive_upload_status', [
                        BackupRecord::DRIVE_UPLOAD_QUEUED,
                        BackupRecord::DRIVE_UPLOAD_RETRYING,
                        BackupRecord::DRIVE_UPLOAD_UPLOADING,
                    ]);
            })
            ->update([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_UPLOADING,
                'drive_upload_attempts' => max((int) $record->drive_upload_attempts, $attempt),
                'drive_upload_failure_code' => null,
                'drive_upload_updated_at' => now(),
            ]);

        if ($started !== 1) {
            $fresh = BackupRecord::query()->find($record->getKey());

            if ($fresh instanceof BackupRecord && filled($fresh->remote_file_id)) {
                $this->markCompleted($fresh);
            } elseif ($fresh instanceof BackupRecord && $this->cancellationRequested($fresh)) {
                $this->recordCancellation($fresh);
            }

            return;
        }

        $record->refresh();

        try {
            $remote = $drive->uploadCompletedArchive(
                $cabinet,
                $record,
                $this->folderName,
                fn (int $uploaded, int $total): mixed => $this->recordProgress(
                    $record,
                    $uploaded,
                    $total,
                ),
            );
            $record = $this->recordSuccessfulUpload($record, $remote['id'], $attempt);

            AuditLog::record('backup.drive_uploaded', $record, [
                'provider' => 'google_drive',
                'format' => 'msbackup',
                'format_version' => 2,
                'size' => $record->size,
                'sha256' => $record->sha256,
            ], $record->created_by);
            ApplicationEvent::record('BackupDriveUploadCompleted', context: [
                'backup_record_id' => $record->getKey(),
                'provider' => 'google_drive',
                'format' => 'msbackup',
                'format_version' => 2,
            ]);
        } catch (DriveUploadCancelled) {
            $this->recordCancellation($record);

            return;
        } catch (InvalidArgumentException) {
            try {
                $drive->recordUploadFailure($cabinet);
            } catch (Throwable) {
                // Preserve the bounded, generic upload failure below.
            }

            if (! $this->markFailed($record, 'permanent_precondition_failed')) {
                $fresh = BackupRecord::query()->find($record->getKey());

                if ($fresh instanceof BackupRecord && $this->cancellationRequested($fresh)) {
                    $this->recordCancellation($fresh);

                    return;
                }

                if ($fresh instanceof BackupRecord && filled($fresh->remote_file_id)) {
                    $this->markCompleted($fresh);

                    return;
                }
            }
            $this->recordFailure($record, 'permanent_precondition_failed');
            $this->rejectPermanently(
                new RuntimeException('The verified backup could not be uploaded to Google Drive.'),
            );

            return;
        } catch (Throwable) {
            try {
                $drive->recordUploadFailure($cabinet);
            } catch (Throwable) {
                // Preserve the bounded, generic upload failure below.
            }

            $retrying = BackupRecord::query()
                ->whereKey($record->getKey())
                ->whereNull('remote_file_id')
                ->whereNull('drive_upload_cancel_requested_at')
                ->where('drive_upload_status', BackupRecord::DRIVE_UPLOAD_UPLOADING)
                ->update([
                    'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_RETRYING,
                    'drive_upload_attempts' => max((int) $record->drive_upload_attempts, $attempt),
                    'drive_upload_failure_code' => 'transfer_failed',
                    'drive_upload_updated_at' => now(),
                ]);

            if ($retrying !== 1) {
                $fresh = BackupRecord::query()->find($record->getKey());

                if ($fresh instanceof BackupRecord && $this->cancellationRequested($fresh)) {
                    $this->recordCancellation($fresh);
                } elseif ($fresh instanceof BackupRecord && filled($fresh->remote_file_id)) {
                    $this->markCompleted($fresh);
                }

                return;
            }

            $this->recordFailure($record, 'transfer_failed');

            throw new RuntimeException('The verified backup could not be uploaded to Google Drive.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        unset($exception);
        $record = BackupRecord::query()->find($this->backupRecordId);

        if (! $record instanceof BackupRecord
            || filled($record->remote_file_id)
            || $record->drive_upload_status === BackupRecord::DRIVE_UPLOAD_COMPLETED
            || $record->drive_upload_status === BackupRecord::DRIVE_UPLOAD_CANCELLED) {
            return;
        }

        if ($this->cancellationRequested($record)) {
            $this->recordCancellation($record);

            return;
        }

        if ($this->markFailed($record, 'retry_exhausted')) {
            $this->recordFailure($record, 'retry_exhausted');
        }
    }

    private function recordProgress(
        BackupRecord $record,
        int $uploaded,
        int $total,
    ): null {
        $fresh = BackupRecord::query()->find($record->getKey());

        if (! $fresh instanceof BackupRecord) {
            throw new RuntimeException('The Drive upload record is unavailable.');
        }

        if ($this->cancellationRequested($fresh)) {
            throw new DriveUploadCancelled;
        }

        $artifactSize = max(0, (int) $fresh->size);
        $boundedTotal = $total > 0 ? min($artifactSize, $total) : $artifactSize;
        $boundedUploaded = min(
            $boundedTotal,
            max(0, $uploaded),
        );
        $previous = max(0, (int) $fresh->drive_upload_bytes);
        $reportingStep = max(64 * 1024, (int) ceil(max(1, $artifactSize) / 100));

        if ($boundedUploaded < $artifactSize
            && $boundedUploaded < $previous + $reportingStep) {
            return null;
        }

        $updated = BackupRecord::query()
            ->whereKey($fresh->getKey())
            ->whereNull('drive_upload_cancel_requested_at')
            ->where('drive_upload_status', BackupRecord::DRIVE_UPLOAD_UPLOADING)
            ->update([
                'drive_upload_bytes' => max($previous, $boundedUploaded),
                'drive_upload_updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $fresh = BackupRecord::query()->find($record->getKey());

            if ($fresh instanceof BackupRecord && $this->cancellationRequested($fresh)) {
                throw new DriveUploadCancelled;
            }
        }

        return null;
    }

    private function cancellationRequested(BackupRecord $record): bool
    {
        return $record->drive_upload_cancel_requested_at !== null
            || in_array($record->drive_upload_status, [
                BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
                BackupRecord::DRIVE_UPLOAD_CANCELLED,
            ], true);
    }

    private function markCompleted(BackupRecord $record): void
    {
        DB::transaction(function () use ($record): void {
            $fresh = BackupRecord::query()->lockForUpdate()->find($record->getKey());

            if (! $fresh instanceof BackupRecord || ! filled($fresh->remote_file_id)) {
                return;
            }

            $fresh->forceFill([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_COMPLETED,
                'drive_upload_bytes' => max(0, (int) $fresh->size),
                'drive_upload_failure_code' => null,
                'drive_upload_cancel_requested_at' => null,
                'drive_upload_updated_at' => now(),
            ])->save();
        }, 3);
    }

    private function markFailed(BackupRecord $record, string $reason): bool
    {
        return BackupRecord::query()
            ->whereKey($record->getKey())
            ->whereNull('remote_file_id')
            ->whereNull('drive_upload_cancel_requested_at')
            ->where(function ($query): void {
                $query->whereNull('drive_upload_status')
                    ->orWhereNotIn('drive_upload_status', [
                        BackupRecord::DRIVE_UPLOAD_COMPLETED,
                        BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
                        BackupRecord::DRIVE_UPLOAD_CANCELLED,
                    ]);
            })
            ->update([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_FAILED,
                'drive_upload_attempts' => max(
                    (int) $record->drive_upload_attempts,
                    max(1, $this->attempts()),
                ),
                'drive_upload_failure_code' => $reason,
                'drive_upload_updated_at' => now(),
            ]) === 1;
    }

    private function recordCancellation(BackupRecord $record): void
    {
        $updated = BackupRecord::query()
            ->whereKey($record->getKey())
            ->whereNull('remote_file_id')
            ->whereNotNull('drive_upload_cancel_requested_at')
            ->whereIn('drive_upload_status', [
                BackupRecord::DRIVE_UPLOAD_QUEUED,
                BackupRecord::DRIVE_UPLOAD_UPLOADING,
                BackupRecord::DRIVE_UPLOAD_RETRYING,
                BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
            ])
            ->update([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_CANCELLED,
                'drive_upload_failure_code' => null,
                'drive_upload_updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return;
        }

        try {
            AuditLog::record('backup.drive_upload_cancelled', $record, [
                'provider' => 'google_drive',
                'format' => 'msbackup',
                'format_version' => 2,
                'attempt' => max(1, $this->attempts()),
            ], $record->created_by);
            ApplicationEvent::record('BackupDriveUploadCancelled', context: [
                'backup_record_id' => $record->getKey(),
                'provider' => 'google_drive',
                'format' => 'msbackup',
                'format_version' => 2,
            ]);
        } catch (Throwable) {
            // Cancellation must not be converted back into a transfer failure.
        }
    }

    private function recordSuccessfulUpload(
        BackupRecord $record,
        string $remoteFileId,
        int $attempt,
    ): BackupRecord {
        return DB::transaction(function () use ($record, $remoteFileId, $attempt): BackupRecord {
            $fresh = BackupRecord::query()->lockForUpdate()->find($record->getKey());

            if (! $fresh instanceof BackupRecord) {
                throw new RuntimeException('The Drive upload record is unavailable.');
            }

            if (filled($fresh->remote_file_id)
                && ! hash_equals((string) $fresh->remote_file_id, $remoteFileId)) {
                throw new RuntimeException('The Drive upload result conflicts with its local record.');
            }

            $fresh->forceFill([
                'remote_file_id' => $remoteFileId,
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_COMPLETED,
                'drive_upload_bytes' => max(0, (int) $fresh->size),
                'drive_upload_attempts' => max((int) $fresh->drive_upload_attempts, $attempt),
                'drive_upload_failure_code' => null,
                'drive_upload_cancel_requested_at' => null,
                'drive_upload_updated_at' => now(),
            ])->save();

            return $fresh;
        }, 3);
    }

    /**
     * A queue worker can mark permanent local validation failures as failed
     * immediately. Direct invocations still receive the bounded exception.
     */
    private function rejectPermanently(RuntimeException $failure): void
    {
        if ($this->job !== null) {
            $this->fail($failure);

            return;
        }

        throw $failure;
    }

    private function recordFailure(?BackupRecord $record, string $reason): void
    {
        try {
            AuditLog::record('backup.drive_upload_failed', $record, [
                'provider' => 'google_drive',
                'backup_record_id' => $record?->getKey() ?? $this->backupRecordId,
                'format' => 'msbackup',
                'format_version' => 2,
                'reason' => $reason,
                'attempt' => $this->attempts(),
            ], $record?->created_by);
            ApplicationEvent::record('BackupDriveUploadFailed', 'error', context: [
                'backup_record_id' => $record?->getKey() ?? $this->backupRecordId,
                'provider' => 'google_drive',
                'format' => 'msbackup',
                'format_version' => 2,
                'reason' => $reason,
                'attempt' => $this->attempts(),
            ]);
        } catch (Throwable) {
            // Never replace the original job failure if history is unavailable.
        }
    }
}
