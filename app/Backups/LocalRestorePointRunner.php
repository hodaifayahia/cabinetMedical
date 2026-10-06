<?php

namespace App\Backups;

use App\Models\ApplicationEvent;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use SensitiveParameter;
use Throwable;

/**
 * Creates one verified local backup on this PC, from the schedule or from the
 * doctor's "save now" button, and then does what follows every local backup:
 * housekeeping of finished Drive copies, local retention, the copy to the
 * folder the doctor chose and, when the doctor turned it on (or asked for it
 * on a manual backup), the encrypted copy for Google Drive.
 *
 * The local archive is the backup that is required. Retention, the folder copy
 * and the Drive copy are reported, but never turn a verified local archive
 * into a failure.
 */
final class LocalRestorePointRunner
{
    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    private const LOCK = 'medismart:scheduled-backup';

    public function __construct(
        private readonly AutomaticBackupCreator $creator,
        private readonly LocalBackupRetentionManager $retention,
        private readonly ScheduledDriveBackupUploader $driveCopies,
        private readonly BackupCopyDestination $folderCopy,
    ) {}

    /**
     * Null when another backup is already being written.
     *
     * $drivePassphrase sends this one backup to Drive even when the automatic
     * copy is off (the doctor ticked "also send to Google Drive").
     *
     * @param  self::TRIGGER_*  $trigger
     * @return array{record: BackupRecord, drive: 'queued'|'skipped'|'failed', drive_reason: string|null, retention: bool, copy: array{status: 'copied'|'skipped'|'failed', path: string|null, message: string|null}}|null
     *
     * @throws Throwable when the local archive could not be created and verified
     */
    public function run(
        string $trigger,
        ?CarbonImmutable $scheduledFor = null,
        ?User $actor = null,
        #[SensitiveParameter] ?string $drivePassphrase = null,
    ): ?array {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            return null;
        }

        try {
            try {
                $record = $this->creator->create();
            } catch (Throwable $exception) {
                ApplicationEvent::record($this->event($trigger, 'Failed'), 'error', context: [
                    'scheduled_for' => $scheduledFor?->toIso8601String(),
                    'error' => $trigger.'_backup_failed',
                ]);

                throw $exception;
            }

            AuditLog::record('backup.'.$trigger.'_completed', $record, [
                'scheduled_for' => $scheduledFor?->toIso8601String(),
                'size' => $record->size,
                'sha256' => $record->sha256,
            ], $actor?->getKey());
            ApplicationEvent::record($this->event($trigger, 'Completed'), context: [
                'backup_record_id' => $record->getKey(),
                'scheduled_for' => $scheduledFor?->toIso8601String(),
            ]);

            // Before retention, so a copy Drive already holds is not counted
            // against the local storage limit.
            $this->driveCopies->pruneFinishedCopies();
            $retained = $this->applyRetention($record);
            $copy = $this->folderCopy->copy($record);
            $drive = $this->queueDriveCopy($record, $drivePassphrase);

            return [
                'record' => $record,
                'drive' => $drive['status'],
                'drive_reason' => $drive['reason'],
                'retention' => $retained,
                'copy' => $copy,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a local backup that still exists on this PC was started at or
     * after $since. Drive outbox copies and records carried over from another
     * machine do not count: neither is a restore point here.
     */
    public function hasRestorePointSince(CarbonImmutable $since): bool
    {
        return $this->latestRestorePoint($since) instanceof BackupRecord;
    }

    /** The newest local backup still present on this PC, optionally not older than $since. */
    public function latestRestorePoint(?CarbonImmutable $since = null): ?BackupRecord
    {
        $outbox = rtrim(LocalEncryptedAutomaticBackupCreator::outboxDirectory(), '\\/');
        // The copy taken just before a restore holds the data that was
        // replaced: it is kept, but it is not a restore point of the data
        // now in use.
        $safety = rtrim((string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        ), '\\/').DIRECTORY_SEPARATOR.InAppBackupRestorer::SAFETY_DIRECTORY;
        $excluded = array_values(array_filter(
            [$outbox, $safety, realpath($outbox), realpath($safety)],
            'is_string',
        ));
        $candidates = BackupRecord::query()
            ->where('status', 'completed')
            ->whereNull('drive_upload_status')
            ->whereNotNull('local_path')
            ->when($since !== null, fn ($query) => $query->where('started_at', '>=', $since))
            ->latest('started_at')
            ->limit(20)
            ->get();

        foreach ($candidates as $record) {
            $path = (string) $record->local_path;

            if (! in_array(rtrim(dirname($path), '\\/'), $excluded, true) && is_file($path)) {
                return $record;
            }
        }

        return null;
    }

    private function event(string $trigger, string $outcome): string
    {
        return ($trigger === self::TRIGGER_MANUAL ? 'ManualBackup' : 'ScheduledBackup').$outcome;
    }

    private function applyRetention(BackupRecord $newestBackup): bool
    {
        try {
            $preview = $this->retention->preview();
            $result = $this->retention->apply(
                confirmationToken: $this->retention->issueConfirmation($preview),
                internalConfirmation: true,
            );
            ApplicationEvent::record('BackupRetentionCompleted', context: [
                'trigger_backup_record_id' => $newestBackup->getKey(),
                'plan_sha256' => $result['plan_sha256'] ?? null,
                'deleted_count' => $result['deleted_count'] ?? 0,
            ]);

            return true;
        } catch (Throwable) {
            ApplicationEvent::record('BackupRetentionFailed', 'warning', context: [
                'trigger_backup_record_id' => $newestBackup->getKey(),
                'error' => 'local_retention_failed_closed',
            ]);

            return false;
        }
    }

    /** @return array{status: 'queued'|'skipped'|'failed', reason: string|null} */
    private function queueDriveCopy(BackupRecord $backup, #[SensitiveParameter] ?string $passphrase): array
    {
        try {
            $result = $this->driveCopies->queueCopyOf($backup, $passphrase);

            return ['status' => $result['status'], 'reason' => $result['reason']];
        } catch (Throwable) {
            return ['status' => 'failed', 'reason' => 'drive_copy_failed'];
        }
    }
}
