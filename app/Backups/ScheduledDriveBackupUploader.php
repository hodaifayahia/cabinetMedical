<?php

namespace App\Backups;

use App\Jobs\UploadBackupToGoogleDrive;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\DriveBackupConnection;
use App\Services\Backups\DriveBackupAuthority;
use App\Services\Backups\DriveBackupEntitlement;
use SensitiveParameter;
use Throwable;

/**
 * Sends a copy of each scheduled backup to the cabinet's Google Drive.
 *
 * The local archive of a scheduled run is never touched here: a separate,
 * encrypted v2 archive is created in the Drive outbox and handed to the same
 * queued, verified upload job the manual "send now" button uses. Every failure
 * is contained so the clinic's local backup always stands on its own.
 */
final class ScheduledDriveBackupUploader
{
    private const DEFAULT_FOLDER = 'Drclick Backups';

    /** The upload job still owns a copy in one of these states. */
    private const PENDING_UPLOAD_STATUSES = [
        BackupRecord::DRIVE_UPLOAD_QUEUED,
        BackupRecord::DRIVE_UPLOAD_UPLOADING,
        BackupRecord::DRIVE_UPLOAD_RETRYING,
        BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
    ];

    public function __construct(
        private readonly AutomaticDriveUploadPolicy $policy,
        private readonly EncryptedAutomaticBackupCreator $creator,
        private readonly DriveBackupEntitlement $entitlement,
        private readonly DriveBackupAuthority $driveAuthority,
    ) {}

    /**
     * $passphrase is the doctor's one-off choice for a manual backup; without
     * it the automatic-copy passphrase is used, when that copy is on.
     *
     * @return array{status: 'queued'|'skipped'|'failed', reason: string|null, backup_record_id: string|null}
     */
    public function queueCopyOf(
        BackupRecord $scheduledBackup,
        #[SensitiveParameter] ?string $passphrase = null,
    ): array {
        $passphrase = $passphrase !== null && strlen($passphrase) >= AutomaticDriveUploadPolicy::MINIMUM_PASSPHRASE_LENGTH
            ? $passphrase
            : $this->policy->passphrase();

        if ($passphrase === null) {
            return ['status' => 'skipped', 'reason' => 'automatic_upload_disabled', 'backup_record_id' => null];
        }

        $record = null;

        try {
            $connections = DriveBackupConnection::query()
                ->whereNotNull('refresh_token')
                ->orderBy('cabinet_setting_id')
                ->limit(2)
                ->get();

            // The archive holds the whole installation, so it may only ever go
            // to one clinic's Drive. Several grants on one desktop, or a second
            // cabinet's records on the machine, fail closed.
            $reason = match (true) {
                ! $this->googleClientConfigured() => 'google_oauth_unconfigured',
                ! $this->encryptionAvailable() => 'encryption_unavailable',
                $connections->isEmpty() => 'drive_not_connected',
                $connections->count() > 1 => 'ambiguous_drive_connection',
                ! $this->installationMayUploadTo($connections->first()) => 'drive_cabinet_mismatch',
                ! $this->entitlement->granted() => 'drive_backup_unlicensed',
                default => null,
            };

            if ($reason !== null) {
                $this->recordSkipped($scheduledBackup, $reason);

                return ['status' => 'skipped', 'reason' => $reason, 'backup_record_id' => null];
            }

            /** @var DriveBackupConnection $connection */
            $connection = $connections->first();
            $folderName = trim((string) $connection->folder_name);
            $record = $this->creator->create($passphrase);
            $record->forceFill([
                'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_QUEUED,
                'drive_upload_bytes' => 0,
                'drive_upload_attempts' => 0,
                'drive_upload_failure_code' => null,
                'drive_upload_cancel_requested_at' => null,
                'drive_upload_updated_at' => now(),
            ])->save();

            UploadBackupToGoogleDrive::dispatch(
                (int) $connection->cabinet_setting_id,
                (string) $record->getKey(),
                $folderName !== '' ? $folderName : self::DEFAULT_FOLDER,
            );
        } catch (Throwable) {
            if ($record instanceof BackupRecord) {
                // Only a copy still waiting in the queue is ours to fail; the
                // job owns every later state (uploading, retrying, completed).
                BackupRecord::query()
                    ->whereKey($record->getKey())
                    ->whereNull('remote_file_id')
                    ->where('drive_upload_status', BackupRecord::DRIVE_UPLOAD_QUEUED)
                    ->update([
                        'drive_upload_status' => BackupRecord::DRIVE_UPLOAD_FAILED,
                        'drive_upload_failure_code' => 'queue_dispatch_failed',
                        'drive_upload_updated_at' => now(),
                    ]);
            }

            $this->recordFailure($scheduledBackup, $record);

            return [
                'status' => 'failed',
                'reason' => 'drive_copy_failed',
                'backup_record_id' => $record instanceof BackupRecord ? (string) $record->getKey() : null,
            ];
        }

        try {
            AuditLog::record('backup.scheduled_drive_queued', $record, [
                'provider' => 'google_drive',
                'trigger_backup_record_id' => $scheduledBackup->getKey(),
                'format' => 'msbackup',
                'format_version' => 2,
                'size' => $record->size,
                'sha256' => $record->sha256,
            ]);
            ApplicationEvent::record('ScheduledDriveBackupQueued', context: [
                'backup_record_id' => $record->getKey(),
                'trigger_backup_record_id' => $scheduledBackup->getKey(),
                'provider' => 'google_drive',
            ]);
        } catch (Throwable) {
            // The copy is already queued; missing history must not undo it.
        }

        return ['status' => 'queued', 'reason' => null, 'backup_record_id' => (string) $record->getKey()];
    }

    /**
     * An outbox copy only exists to be sent: once the upload job is done with
     * it (sent, failed for good, cancelled or never queued), its file goes.
     * The record stays for the upload history. The clinic's own restore
     * points are the plain scheduled archives, which the local retention
     * manages on its own. Never throws.
     */
    public function pruneFinishedCopies(): void
    {
        try {
            $outbox = rtrim(LocalEncryptedAutomaticBackupCreator::outboxDirectory(), '\\/');
            $canonicalOutbox = realpath($outbox);

            if (! is_string($canonicalOutbox) || is_link($outbox)) {
                return;
            }

            $finished = BackupRecord::query()
                ->whereNotNull('local_path')
                ->where(fn ($query) => $query
                    ->whereNull('drive_upload_status')
                    ->orWhereNotIn('drive_upload_status', self::PENDING_UPLOAD_STATUSES))
                ->get();

            foreach ($finished as $record) {
                $path = (string) $record->local_path;

                if (! in_array(dirname($path), [$outbox, $canonicalOutbox], true)) {
                    continue;
                }

                if (is_file($path) && ! is_link($path) && ! @unlink($path) && is_file($path)) {
                    continue;
                }

                $record->forceFill(['local_path' => null])->save();
            }
        } catch (Throwable) {
            // Housekeeping only: a copy left behind is retried next run.
        }
    }

    private function installationMayUploadTo(?DriveBackupConnection $connection): bool
    {
        $settings = $connection?->cabinet;

        return $settings instanceof CabinetSetting
            && $this->driveAuthority->installationMayUploadTo($settings);
    }

    private function googleClientConfigured(): bool
    {
        $clientId = config('services.google.client_id');

        return is_string($clientId) && trim($clientId) !== '';
    }

    private function encryptionAvailable(): bool
    {
        return extension_loaded('sodium')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push');
    }

    private function recordSkipped(BackupRecord $scheduledBackup, string $reason): void
    {
        try {
            ApplicationEvent::record('ScheduledDriveBackupSkipped', 'warning', context: [
                'trigger_backup_record_id' => $scheduledBackup->getKey(),
                'provider' => 'google_drive',
                'reason' => $reason,
            ]);
        } catch (Throwable) {
            // Diagnostics only.
        }
    }

    private function recordFailure(BackupRecord $scheduledBackup, ?BackupRecord $record): void
    {
        try {
            ApplicationEvent::record('ScheduledDriveBackupFailed', 'error', context: [
                'trigger_backup_record_id' => $scheduledBackup->getKey(),
                'backup_record_id' => $record?->getKey(),
                'provider' => 'google_drive',
                'reason' => 'drive_copy_failed',
            ]);
        } catch (Throwable) {
            // Diagnostics only; the local backup already succeeded.
        }
    }
}
