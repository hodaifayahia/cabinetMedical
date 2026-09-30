<?php

namespace App\Services\Backups;

use App\Backups\AutomaticDriveUploadPolicy;
use App\Backups\LocalRestorePointRunner;
use App\Models\BackupRecord;
use App\Models\DriveBackupConnection;
use App\Models\User;
use Throwable;

/**
 * What, if anything, the doctor must hear about this desktop's backups.
 *
 * The local backups on the PC are required: the app keeps saying so while
 * none exists or the newest is more than a day old. Google Drive is optional,
 * so it only comes up once the doctor turned the copy on and it stopped
 * reaching Drive. Nothing here ever blocks clinical work.
 */
final class BackupReminder
{
    /** No local backup exists on this PC yet. */
    public const LOCAL_MISSING = 'local_missing';

    /** The newest local backup on this PC is more than a day old. */
    public const LOCAL_OVERDUE = 'local_overdue';

    /** The optional Drive copy is on, but copies are not reaching Drive. */
    public const DRIVE_FAILING = 'drive_failing';

    /** Three backups a day: a full day without one means they stopped. */
    private const OVERDUE_AFTER_HOURS = 24;

    /** A copy still waiting after this long means the queue is not moving. */
    private const STALLED_AFTER_HOURS = 24;

    public function __construct(
        private readonly LocalBackupAuthority $localBackups,
        private readonly LocalRestorePointRunner $restorePoints,
        private readonly AutomaticDriveUploadPolicy $automaticUpload,
    ) {}

    /**
     * Shared with every Inertia page, only with someone who can act on it:
     * the clinic's doctor on their desktop, or an installation maintainer.
     *
     * @return array{state: string}|null
     */
    public function sharedProps(?User $user): ?array
    {
        if (! $this->applies() || ! $user instanceof User) {
            return null;
        }

        try {
            if (! $this->localBackups->mayManage($user)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $state = $this->state();

        return $state === null ? null : ['state' => $state];
    }

    /**
     * Null while everything is in place. A reminder must never break the page
     * it is shown on, so any unexpected failure also yields null.
     */
    public function state(): ?string
    {
        if (! $this->applies()) {
            return null;
        }

        try {
            $latest = $this->restorePoints->latestRestorePoint();

            if (! $latest instanceof BackupRecord) {
                return self::LOCAL_MISSING;
            }

            if ($latest->started_at === null
                || $latest->started_at->isBefore(now()->subHours(self::OVERDUE_AFTER_HOURS))) {
                return self::LOCAL_OVERDUE;
            }

            return $this->automaticUpload->enabled() && $this->driveCopiesAreFailing()
                ? self::DRIVE_FAILING
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function applies(): bool
    {
        return (bool) config('medismart.runtime.desktop_supervised', false);
    }

    /**
     * Configured is not the same as working: an expired or revoked grant, a
     * machine that stayed offline, a queue that never runs or two grants on
     * the machine all leave the copy on this PC alone.
     */
    private function driveCopiesAreFailing(): bool
    {
        // The scheduled copy refuses to choose between two grants.
        if (DriveBackupConnection::query()->whereNotNull('refresh_token')->count() !== 1) {
            return true;
        }

        $latest = BackupRecord::query()
            ->whereNotNull('drive_upload_status')
            ->latest('started_at')
            ->first();

        if (! $latest instanceof BackupRecord || filled($latest->remote_file_id)) {
            return false;
        }

        $lastMovedAt = $latest->drive_upload_updated_at ?? $latest->started_at;

        return match ($latest->drive_upload_status) {
            BackupRecord::DRIVE_UPLOAD_FAILED => true,
            BackupRecord::DRIVE_UPLOAD_QUEUED,
            BackupRecord::DRIVE_UPLOAD_UPLOADING,
            BackupRecord::DRIVE_UPLOAD_RETRYING => $lastMovedAt === null
                || $lastMovedAt->isBefore(now()->subHours(self::STALLED_AFTER_HOURS)),
            default => false,
        };
    }
}
