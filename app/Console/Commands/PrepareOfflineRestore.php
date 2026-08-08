<?php

namespace App\Console\Commands;

use App\Backups\BackupArchiveException;
use App\Backups\OfflineRestorePreparer;
use Illuminate\Console\Command;
use JsonException;

final class PrepareOfflineRestore extends Command
{
    protected $signature = 'medismart:restore:prepare
                            {archive : Absolute path to an encrypted v2 .msbackup}';

    protected $description = 'Authenticate and stage an encrypted Drclick backup without applying it';

    public function handle(OfflineRestorePreparer $preparer): int
    {
        $archive = $this->argument('archive');
        $passphrase = (string) $this->secret('Recovery passphrase (input is hidden)');

        if ($archive === '' || $passphrase === '') {
            $this->error('A backup path and hidden recovery passphrase are required.');

            return self::FAILURE;
        }

        try {
            $prepared = $preparer->prepare($archive, $passphrase);
        } catch (BackupArchiveException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($passphrase);
            }
        }

        try {
            $authorization = json_encode(
                $prepared->nativeAuthorizationArtifact(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            $this->error('The native restore authorization could not be encoded.');

            return self::FAILURE;
        }

        $this->info('Backup authenticated and staged for supervised offline restore.');
        $this->line('Operation: '.$prepared->operationId);
        $this->line('Authorization: '.$authorization);
        $this->warn('No active data was changed. Web apply remains disabled.');
        $this->warn('Only the Tauri-owned offline apply step may stop writers and apply this preparation.');

        return self::SUCCESS;
    }
}
