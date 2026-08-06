<?php

namespace App\Backups;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\CabinetSetting;
use App\Services\ApplicationSettingService;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class RemoteBackupRetentionManager
{
    public function __construct(
        private readonly BackupRetentionPlanner $planner,
        private readonly GoogleDriveService $drive,
        private readonly ApplicationSettingService $settings,
    ) {}

    public function configuredEnabled(): bool
    {
        $value = config('backup.remote_retention.enabled', true);

        return match (true) {
            is_bool($value) => $value,
            $value === 1 || $value === '1' || $value === 'true' => true,
            $value === 0 || $value === '0' || $value === 'false' => false,
            default => throw new BackupArchiveException(
                'The Google Drive retention enabled flag is invalid.',
            ),
        };
    }

    public function configuredPolicy(): BackupRetentionPolicy
    {
        try {
            $maximumStorage = $this->settings->get(Setting::BACKUP_MAXIMUM_STORAGE_BYTES);

            if ($maximumStorage !== null && ! is_int($maximumStorage)) {
                throw new BackupArchiveException(
                    'The configured Google Drive storage limit is invalid.',
                );
            }

            return new BackupRetentionPolicy(
                daily: $this->integerSetting(Setting::BACKUP_RETENTION_DAILY),
                weekly: $this->integerSetting(Setting::BACKUP_RETENTION_WEEKLY),
                monthly: $this->integerSetting(Setting::BACKUP_RETENTION_MONTHLY),
                maximumStorageBytes: $maximumStorage,
            );
        } catch (Throwable $exception) {
            throw new BackupArchiveException(
                'The configured Google Drive retention policy is invalid.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{
     *     plan_sha256: string,
     *     deleted_count: int,
     *     deleted_backup_record_ids: list<string>,
     *     destructive_actions_performed: bool
     * }
     */
    public function run(
        CabinetSetting $cabinet,
        ?BackupRetentionPolicy $policy = null,
    ): array {
        $lock = Cache::lock(
            'medismart:google-drive-retention:'.$cabinet->getKey(),
            1800,
        );
        $deletedBackupRecordIds = [];

        if (! $lock->get()) {
            $this->recordFailure($cabinet, 0, 'operation_locked');

            throw new BackupArchiveException(
                'Another Google Drive retention operation is already active.',
            );
        }

        try {
            $effectivePolicy = $policy ?? $this->configuredPolicy();
            $authorizedPlan = $this->freshPlan($cabinet, $effectivePolicy);

            foreach ($authorizedPlan->deletionCandidates as $authorizedDecision) {
                $managedFileId = $authorizedDecision['managed_file_id'] ?? null;
                $authorizedFingerprint = $authorizedDecision['metadata_fingerprint'] ?? null;

                if (! is_string($managedFileId)
                    || ! is_string($authorizedFingerprint)
                    || preg_match('/\A[a-f0-9]{64}\z/', $authorizedFingerprint) !== 1) {
                    throw new BackupArchiveException(
                        'The Google Drive retention plan contains an invalid candidate.',
                    );
                }

                // Every deletion gets a complete new Drive listing and plan.
                // The adapter then re-fetches the exact target and proves that
                // a strictly newer remote artifact still matches a completed
                // local BackupRecord before sending DELETE.
                $freshPlan = $this->freshPlan($cabinet, $effectivePolicy);
                $freshDecision = $this->freshDecision($freshPlan, $managedFileId);
                $freshFingerprint = $freshDecision['metadata_fingerprint'] ?? null;

                if (! is_string($freshFingerprint)
                    || ! hash_equals($authorizedFingerprint, $freshFingerprint)) {
                    throw new BackupArchiveException(
                        'A Google Drive retention candidate changed during revalidation.',
                    );
                }

                $deleted = $this->drive->deleteManagedBackup($cabinet, $managedFileId);
                $targetBackupRecordId = $freshDecision['backup_record_id'] ?? null;
                $deletedFileId = data_get($deleted, 'id');
                $deletedBackupRecordId = data_get($deleted, 'backup_record_id');
                $newerBackupRecordId = data_get($deleted, 'newer_backup_record_id');

                if (! is_string($targetBackupRecordId)
                    || ! is_string($deletedFileId)
                    || ! is_string($deletedBackupRecordId)
                    || ! is_string($newerBackupRecordId)
                    || ! Str::isUuid($targetBackupRecordId)
                    || ! Str::isUuid($newerBackupRecordId)
                    || hash_equals($targetBackupRecordId, $newerBackupRecordId)
                    || ! hash_equals($managedFileId, $deletedFileId)
                    || ! hash_equals($targetBackupRecordId, $deletedBackupRecordId)) {
                    throw new BackupArchiveException(
                        'Google Drive returned an invalid retention deletion receipt.',
                    );
                }

                $deletedBackupRecordIds[] = $targetBackupRecordId;
                AuditLog::record('backup.drive_retention_deleted', metadata: [
                    'cabinet_setting_id' => $cabinet->getKey(),
                    'backup_record_id' => $targetBackupRecordId,
                    'newer_backup_record_id' => $newerBackupRecordId,
                    'reason_code' => $freshDecision['reason_code'] ?? 'retention_policy',
                ]);
                ApplicationEvent::record('BackupDriveRetentionDeleted', context: [
                    'cabinet_setting_id' => $cabinet->getKey(),
                    'backup_record_id' => $targetBackupRecordId,
                    'newer_backup_record_id' => $newerBackupRecordId,
                ]);
            }

            $result = [
                'plan_sha256' => $authorizedPlan->planSha256,
                'deleted_count' => count($deletedBackupRecordIds),
                'deleted_backup_record_ids' => $deletedBackupRecordIds,
                'destructive_actions_performed' => $deletedBackupRecordIds !== [],
            ];
            AuditLog::record('backup.drive_retention_completed', metadata: [
                'cabinet_setting_id' => $cabinet->getKey(),
                'plan_sha256' => $authorizedPlan->planSha256,
                'deleted_count' => $result['deleted_count'],
                'policy' => $effectivePolicy->toArray(),
            ]);
            ApplicationEvent::record('BackupDriveRetentionCompleted', context: [
                'cabinet_setting_id' => $cabinet->getKey(),
                'plan_sha256' => $authorizedPlan->planSha256,
                'deleted_count' => $result['deleted_count'],
            ]);

            return $result;
        } catch (Throwable $exception) {
            $this->recordFailure(
                $cabinet,
                count($deletedBackupRecordIds),
                'remote_retention_failed_closed',
            );

            throw new BackupArchiveException(
                'Google Drive backup retention failed closed.',
                previous: $exception,
            );
        } finally {
            $lock->release();
        }
    }

    private function freshPlan(
        CabinetSetting $cabinet,
        BackupRetentionPolicy $policy,
    ): BackupRetentionPlan {
        $plan = $this->planner->plan(
            $this->drive->retentionInventory($cabinet),
            $policy,
        );

        if ($plan->protected !== []) {
            throw new BackupArchiveException(
                'The Google Drive retention inventory contains protected metadata.',
            );
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    private function freshDecision(
        BackupRetentionPlan $plan,
        string $managedFileId,
    ): array {
        foreach ($plan->deletionCandidates as $decision) {
            if (($decision['managed_file_id'] ?? null) === $managedFileId) {
                return $decision;
            }
        }

        throw new BackupArchiveException(
            'A Google Drive backup is no longer a fresh retention candidate.',
        );
    }

    private function integerSetting(string $key): int
    {
        $value = $this->settings->get($key);

        if (! is_int($value)) {
            throw new BackupArchiveException(
                'A configured Google Drive retention count is invalid.',
            );
        }

        return $value;
    }

    private function recordFailure(
        CabinetSetting $cabinet,
        int $deletedCount,
        string $reasonCode,
    ): void {
        try {
            AuditLog::record('backup.drive_retention_failed', metadata: [
                'cabinet_setting_id' => $cabinet->getKey(),
                'deleted_count' => $deletedCount,
                'reason_code' => $reasonCode,
            ]);
            ApplicationEvent::record('BackupDriveRetentionFailed', 'warning', context: [
                'cabinet_setting_id' => $cabinet->getKey(),
                'deleted_count' => $deletedCount,
                'reason_code' => $reasonCode,
            ]);
        } catch (Throwable) {
            // Preserve the fail-closed retention outcome if diagnostics storage
            // is unavailable.
        }
    }
}
