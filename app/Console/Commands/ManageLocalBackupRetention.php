<?php

namespace App\Console\Commands;

use App\Backups\BackupArchiveException;
use App\Backups\LocalBackupRetentionManager;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

final class ManageLocalBackupRetention extends Command
{
    protected $signature = 'medismart:backup:retention
        {--dry-run : Produce a non-destructive fresh retention preview (the default)}
        {--issue-confirmation : Issue a short-lived token bound to this exact preview}
        {--apply : Apply the freshly revalidated plan}
        {--internal-confirm : Explicit internal acknowledgement required with --apply}
        {--confirm= : Short-lived confirmation token issued for the unchanged inventory}';

    protected $description = 'Preview or internally apply fail-closed local .msbackup retention';

    protected $hidden = true;

    public function handle(LocalBackupRetentionManager $retention): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run');
        $issueConfirmation = (bool) $this->option('issue-confirmation');

        if (($apply && ($dryRun || $issueConfirmation))
            || (! $apply && (bool) $this->option('internal-confirm'))) {
            $this->components->error('The requested retention command modes conflict.');

            return self::FAILURE;
        }

        try {
            if ($apply) {
                $token = $this->option('confirm');

                if (! is_string($token)) {
                    throw new BackupArchiveException('A retention confirmation token is required.');
                }

                $result = $retention->apply(
                    confirmationToken: $token,
                    internalConfirmation: (bool) $this->option('internal-confirm'),
                );
            } else {
                $preview = $retention->preview();
                $result = $preview->toArray();

                if ($issueConfirmation) {
                    $result['confirmation_token'] = $retention->issueConfirmation($preview);
                    $result['confirmation_token_issued'] = true;
                }
            }

            $this->line(json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (BackupArchiveException|JsonException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error('Local backup retention failed closed.');

            return self::FAILURE;
        }
    }
}
