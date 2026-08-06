<?php

namespace App\Backups;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class OfflineRestoreExecutor
{
    public function __construct(
        private readonly MsBackupArchiveVerifier $archiveVerifier,
        private readonly StagedSqliteValidator $databaseValidator,
    ) {}

    /**
     * The native Tauri restore bridge supplies both capabilities only after it
     * has stopped and joined every database-writing process. Web routes can
     * prepare a signed plan, but they cannot invoke this offline apply step.
     */
    public function apply(
        PreparedRestore $restore,
        RestoreTargetSet $targets,
        OfflineRestoreGuard $guard,
        RestoreSafetyBackupProvider $safetyBackups,
    ): void {
        if (PHP_SAPI !== 'cli') {
            throw new BackupArchiveException('Restore apply is restricted to the supervised offline CLI process.');
        }

        $journal = RestoreRecoveryJournal::open($restore->operationId);
        $records = $journal->records();
        $last = end($records);

        if (! is_array($last) || $last['event'] !== 'ready_for_offline_apply'
            || ($last['context']['plan_sha256'] ?? null) !== $restore->planSha256) {
            throw new BackupArchiveException('The restore plan is not in a verified ready state.');
        }

        $this->assertExclusiveOwnership($guard, true);
        $items = $targets->items($restore);

        try {
            $receipt = $safetyBackups->createSafetyBackup($restore);
        } catch (Throwable) {
            throw new BackupArchiveException('The required pre-restore safety backup could not be created; no active data was changed.');
        }

        $this->assertSafetyBackup($receipt, $restore, $items);
        $journal->append('safety_backup_verified', [
            'sha256' => $receipt->sha256,
            'format' => 'msbackup-v1',
        ]);
        $this->assertExclusiveOwnership($guard);
        $this->verifyStagedInventory($restore);
        $this->assertExclusiveOwnership($guard);
        $this->purgeDatabaseConnections();
        $journal->append('apply_started', [
            'target_count' => count($items),
            'rollback_retained' => true,
        ]);

        /** @var array<string, array{backed_up: bool, installed: bool}> $states */
        $states = [];

        try {
            foreach ($items as $item) {
                $this->assertExclusiveOwnership($guard);
                $originalExists = file_exists($item['active']);
                $states[$item['key']] = ['backed_up' => false, 'installed' => false];
                $journal->append('target_swap_started', [
                    'target' => $item['key'],
                    'original_exists' => $originalExists,
                ]);

                if (! $this->matchesType($item['staged'], $item['type']) || is_link($item['staged'])) {
                    throw new BackupArchiveException('A staged restore target changed before it could be installed.');
                }

                if ($originalExists) {
                    if (is_link($item['active']) || ! $this->matchesType($item['active'], $item['type'])
                        || file_exists($item['rollback']) || is_link($item['rollback'])
                        || ! @rename($item['active'], $item['rollback'])) {
                        throw new BackupArchiveException('An active restore target could not be moved to its rollback path.');
                    }

                    $states[$item['key']]['backed_up'] = true;
                    $journal->append('target_backed_up', ['target' => $item['key']]);
                }

                if (file_exists($item['active']) || is_link($item['active'])
                    || ! @rename($item['staged'], $item['active'])) {
                    throw new BackupArchiveException('A staged restore target could not be installed atomically.');
                }

                $states[$item['key']]['installed'] = true;
                $journal->append('target_installed', ['target' => $item['key']]);
            }

            $this->assertExclusiveOwnership($guard);
            $this->databaseValidator->validate($targets->database, $restore->manifest);
            $journal->append('apply_validation_passed', [
                'target_count' => count($items),
            ]);
            $journal->append('applied_pending_restart', [
                'rollback_retained' => true,
                'requires_supervisor_confirmation' => true,
            ]);
        } catch (Throwable) {
            $rolledBack = $this->rollback($items, $states, $guard, $journal);
            $this->purgeDatabaseConnections();

            if (! $rolledBack) {
                throw new BackupArchiveException(
                    'Restore apply failed and deterministic rollback was incomplete; manual recovery is required.',
                );
            }

            throw new BackupArchiveException('Restore apply failed and the active installation was rolled back.');
        }
    }

    /**
     * @param  list<array{key: string, type: string, active: string, staged: string, rollback: string}>  $items
     * @param  array<string, array{backed_up: bool, installed: bool}>  $states
     */
    private function rollback(
        array $items,
        array $states,
        OfflineRestoreGuard $guard,
        RestoreRecoveryJournal $journal,
    ): bool {
        $this->safeJournalAppend($journal, 'rollback_started');
        $successful = true;

        foreach (array_reverse($items) as $item) {
            $state = $states[$item['key']] ?? null;

            if (! is_array($state)) {
                continue;
            }

            try {
                $this->assertExclusiveOwnership($guard);

                if ($state['installed']) {
                    if (file_exists($item['staged']) || is_link($item['staged'])
                        || ! $this->matchesType($item['active'], $item['type'])
                        || ! @rename($item['active'], $item['staged'])) {
                        throw new BackupArchiveException('The newly installed restore target could not be returned to staging.');
                    }
                }

                if ($state['backed_up']) {
                    if (file_exists($item['active']) || is_link($item['active'])
                        || ! $this->matchesType($item['rollback'], $item['type'])
                        || ! @rename($item['rollback'], $item['active'])) {
                        throw new BackupArchiveException('An original restore target could not be returned from rollback storage.');
                    }
                }

                $this->safeJournalAppend($journal, 'target_rolled_back', ['target' => $item['key']]);
            } catch (Throwable) {
                $successful = false;
            }
        }

        $this->safeJournalAppend(
            $journal,
            $successful ? 'rollback_completed' : 'manual_recovery_required',
            $successful ? [] : ['reason_code' => 'rollback_incomplete'],
        );

        return $successful;
    }

    /**
     * @param  list<array{key: string, type: string, active: string, staged: string, rollback: string}>  $items
     */
    private function assertSafetyBackup(
        RestoreSafetyBackupReceipt $receipt,
        PreparedRestore $restore,
        array $items,
    ): void {
        $path = realpath($receipt->path);

        if (! is_string($path) || ! is_file($path) || is_link($receipt->path) || ! is_readable($path)) {
            throw new BackupArchiveException('The required pre-restore safety backup is unavailable.');
        }

        foreach ($items as $item) {
            if ($this->isWithin($path, $item['active'])) {
                throw new BackupArchiveException('The safety backup cannot be stored inside an active restore target.');
            }
        }

        $verified = $this->archiveVerifier->verify($path);

        if (! hash_equals($receipt->sha256, $verified['archive_sha256'])
            || ($verified['manifest']['installation_id'] ?? null) !== ($restore->manifest['installation_id'] ?? null)) {
            throw new BackupArchiveException('The required pre-restore safety backup failed verification.');
        }
    }

    private function purgeDatabaseConnections(): void
    {
        DB::disconnect();
        DB::purge();
    }

    private function assertExclusiveOwnership(OfflineRestoreGuard $guard, bool $initial = false): void
    {
        try {
            if ($initial) {
                $guard->assertExclusiveProcessOwnership();
            } else {
                $guard->assertStillExclusive();
            }
        } catch (Throwable) {
            throw new BackupArchiveException('Exclusive offline restore process ownership could not be verified.');
        }
    }

    private function verifyStagedInventory(PreparedRestore $restore): void
    {
        $root = realpath($restore->workspace.DIRECTORY_SEPARATOR.'staged');

        if (! is_string($root) || ! is_dir($root) || is_link($root)) {
            throw new BackupArchiveException('The prepared restore staging root is unavailable.');
        }

        $expected = [];

        foreach ($restore->inventory as $item) {
            $this->assertInventoryPath($item['path']);
            $expected[$item['path']] = $item;
        }

        $actualPaths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new BackupArchiveException('The prepared restore staging tree contains a symbolic link.');
            }

            if (! $entry->isFile()) {
                continue;
            }

            $path = $entry->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
            $actualPaths[] = $relative;
            $metadata = $expected[$relative] ?? null;

            if (! is_array($metadata)) {
                throw new BackupArchiveException('The prepared restore staging tree contains an unexpected file.');
            }

            $size = $entry->getSize();
            $sha256 = hash_file('sha256', $path);

            if ($size !== $metadata['size'] || ! is_string($sha256)
                || ! hash_equals($metadata['sha256'], $sha256)) {
                throw new BackupArchiveException('A prepared restore staging file failed checksum verification.');
            }
        }

        $expectedPaths = array_keys($expected);
        sort($actualPaths, SORT_STRING);
        sort($expectedPaths, SORT_STRING);

        if ($actualPaths !== $expectedPaths) {
            throw new BackupArchiveException('The prepared restore staging inventory is incomplete.');
        }
    }

    private function assertInventoryPath(string $path): void
    {
        BackupArchivePath::assertSafe($path);

        if ($path !== 'database.sqlite3'
            && ! str_starts_with($path, 'private/clinical-documents/')
            && ! str_starts_with($path, 'private/patient-documents/')
            && ! str_starts_with($path, 'private/medical-models/')
            && ! str_starts_with($path, 'public/cabinet/')) {
            throw new BackupArchiveException('The prepared restore inventory contains a path outside managed roots.');
        }
    }

    private function matchesType(string $path, string $type): bool
    {
        return $type === 'file' ? is_file($path) : is_dir($path);
    }

    private function isWithin(string $path, string $root): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedPath = strtolower($normalizedPath);
            $normalizedRoot = strtolower($normalizedRoot);
        }

        return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot.'/');
    }

    /** @param array<string, bool|int|string|null> $context */
    private function safeJournalAppend(
        RestoreRecoveryJournal $journal,
        string $event,
        array $context = [],
    ): void {
        try {
            $journal->append($event, $context);
        } catch (Throwable) {
            // Filesystem rollback must continue even if the diagnostic journal
            // itself is damaged or unavailable.
        }
    }
}
