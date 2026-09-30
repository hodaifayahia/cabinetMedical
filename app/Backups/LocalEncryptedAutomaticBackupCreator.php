<?php

namespace App\Backups;

use App\Models\BackupRecord;
use App\Services\BackupService;
use RuntimeException;
use SensitiveParameter;

final class LocalEncryptedAutomaticBackupCreator implements EncryptedAutomaticBackupCreator
{
    public function __construct(private readonly BackupService $backups) {}

    /**
     * A subdirectory of the managed backup root: the upload job still finds
     * the copy there, while the local retention treats it as protected rather
     * than as a restore point. Otherwise the copy, written seconds after the
     * plain scheduled archive, would win that day's retention bucket and the
     * plain archive would be deleted the next day.
     */
    public static function outboxDirectory(): string
    {
        return rtrim((string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        ), '\\/').DIRECTORY_SEPARATOR.'drive-outbox';
    }

    public function create(#[SensitiveParameter] string $passphrase): BackupRecord
    {
        $record = $this->backups->createEncryptedArchive(
            $passphrase,
            destinationDirectory: self::outboxDirectory(),
        )['record'];

        if ($record->status !== 'completed'
            || $record->completed_at === null) {
            throw new RuntimeException('The automatic Drive archive was not completed and verified.');
        }

        return $record;
    }
}
