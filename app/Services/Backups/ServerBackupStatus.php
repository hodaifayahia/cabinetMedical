<?php

namespace App\Services\Backups;

use App\Enums\ServerBackupDriveStatus;
use App\Models\ServerBackupRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What the back office says about the online service's own backups: the
 * last nightly dump, where its copies went, and what needs fixing.
 */
final class ServerBackupStatus
{
    // The cron runs nightly; past this the night's run was missed.
    public const BACKUP_STALE_AFTER_HOURS = 30;

    // An upload still PENDING after this was cut off (process killed) and will
    // never record its outcome.
    public const DRIVE_UPLOAD_GRACE_MINUTES = 60;

    public const PC_STALE_AFTER_HOURS = 72;

    // The PC copy is the only one a compromised server cannot delete (the
    // server holds the Drive grant), so a week without one is required work.
    public const PC_MISSING_AFTER_HOURS = 168;

    public function __construct(private readonly ServerBackupDrive $drive) {}

    /**
     * @return list<array{level: 'danger'|'warning', message: string}>
     */
    public function problems(): array
    {
        $problems = [];
        $lastRun = $this->lastRun();

        if (! $this->drive->isConfigured()) {
            $problems[] = ['level' => 'danger', 'message' => 'Google Drive est requis, mais la configuration Google (GOOGLE_CLIENT_ID) manque sur ce serveur.'];
        } elseif (! $this->drive->isConnected()) {
            $problems[] = ['level' => 'danger', 'message' => 'Google Drive est requis : connectez le compte Google qui recevra les sauvegardes.'];
        } elseif ($lastRun?->drive_status === ServerBackupDriveStatus::FAILED) {
            $problems[] = ['level' => 'danger', 'message' => 'La dernière sauvegarde n’a pas été envoyée sur Google Drive : '.$lastRun->drive_error];
        } elseif ($lastRun !== null && $this->neverReachedDrive($lastRun)) {
            $problems[] = ['level' => 'danger', 'message' => 'La dernière sauvegarde n’est pas sur Google Drive. Utilisez « Envoyer la dernière sauvegarde ».'];
        }

        if ($lastRun === null) {
            $problems[] = ['level' => 'danger', 'message' => 'Aucune sauvegarde du serveur n’a encore été faite. Vérifiez la tâche cron de sauvegarde dans hPanel.'];
        } elseif ($lastRun->created_at?->lt(now()->subHours(self::BACKUP_STALE_AFTER_HOURS))) {
            $problems[] = ['level' => 'danger', 'message' => 'La sauvegarde de la nuit n’a pas eu lieu (dernière : '.$lastRun->created_at->diffForHumans().'). Vérifiez la tâche cron dans hPanel.'];
        }

        $lastPcCopy = $this->lastPcCopyAt();

        if ($lastRun !== null && ($lastPcCopy === null || $lastPcCopy->lt(now()->subHours(self::PC_STALE_AFTER_HOURS)))) {
            // A PC that never took a copy counts from the first backup.
            $since = $lastPcCopy ?? $this->firstRunAt();

            $problems[] = [
                'level' => $since?->lt(now()->subHours(self::PC_MISSING_AFTER_HOURS)) ? 'danger' : 'warning',
                'message' => $lastPcCopy === null
                    ? 'Aucune copie n’a encore été récupérée sur le PC Windows.'
                    : 'Le PC Windows n’a pas récupéré de copie depuis '.$lastPcCopy->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE).'.',
            ];
        }

        return $problems;
    }

    public function needsAttention(): bool
    {
        foreach ($this->problems() as $problem) {
            if ($problem['level'] === 'danger') {
                return true;
            }
        }

        return false;
    }

    public function lastRun(): ?ServerBackupRun
    {
        return ServerBackupRun::query()->latest('id')->first();
    }

    public function lastDriveUploadAt(): ?Carbon
    {
        $value = ServerBackupRun::query()->max('drive_uploaded_at');

        return $value === null ? null : Carbon::parse($value);
    }

    public function lastPcCopyAt(): ?Carbon
    {
        $value = ServerBackupRun::query()->max('pc_copied_at');

        return $value === null ? null : Carbon::parse($value);
    }

    private function firstRunAt(): ?Carbon
    {
        $value = ServerBackupRun::query()->min('created_at');

        return $value === null ? null : Carbon::parse($value);
    }

    /**
     * Made while no account was connected, or cut off mid-upload: only a
     * failed upload records its own outcome.
     */
    private function neverReachedDrive(ServerBackupRun $run): bool
    {
        return match ($run->drive_status) {
            ServerBackupDriveStatus::SKIPPED => true,
            ServerBackupDriveStatus::PENDING => $run->updated_at?->lt(now()->subMinutes(self::DRIVE_UPLOAD_GRACE_MINUTES)) ?? false,
            default => false,
        };
    }
}
