<?php

namespace App\Backups;

use App\Models\BackupRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The backups actually present on this PC, newest first, for the "Dernières
 * sauvegardes" list: what each one is (manual, scheduled, safety copy taken
 * before a restore, downloaded from Drive), whether it was verified, and
 * whether a copy also sits in the doctor's folder or on Google Drive.
 *
 * An archive is addressed by its path relative to the managed directory, so
 * an archive without a record (left by a restore, for example) can still be
 * downloaded or restored. resolve() is the only way back to a real path.
 */
final class LocalBackupCatalog
{
    private const ARCHIVE_NAME = '[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.msbackup';

    private const ENCRYPTED_MAGIC = "MEDISMART-MSBAK\x02";

    public function __construct(private readonly BackupCopyDestination $folderCopy) {}

    /**
     * @return list<array{
     *     key: string,
     *     record_id: string|null,
     *     filename: string,
     *     created_at: string|null,
     *     size_bytes: int,
     *     kind: 'manual'|'scheduled'|'safety'|'drive_download'|'unknown',
     *     verified: bool,
     *     encrypted: bool,
     *     in_copy_folder: bool,
     *     drive_status: string|null
     * }>
     */
    public function entries(int $limit = 30): array
    {
        $root = $this->root();

        if ($root === null) {
            return [];
        }

        $files = [];

        foreach (['', InAppBackupRestorer::SAFETY_DIRECTORY.'/'] as $prefix) {
            $directory = $prefix === '' ? $root : $root.DIRECTORY_SEPARATOR.rtrim($prefix, '/');

            if (! is_dir($directory) || is_link($directory)) {
                continue;
            }

            foreach (scandir($directory) ?: [] as $entry) {
                $path = $directory.DIRECTORY_SEPARATOR.$entry;

                if (preg_match('/\A'.self::ARCHIVE_NAME.'\z/', $entry) === 1 && is_file($path) && ! is_link($path)) {
                    $files[$this->normalize($path)] = ['key' => $prefix.$entry, 'path' => $path];
                }
            }
        }

        if ($files === []) {
            return [];
        }

        $records = $this->recordsFor(array_keys($files));
        $recordIds = array_values(array_map(
            static fn (BackupRecord $record): string => (string) $record->getKey(),
            $records,
        ));
        $kinds = $this->triggerKinds($recordIds);
        $driveCopies = $this->driveCopyStatuses($recordIds);
        $entries = [];

        foreach ($files as $normalized => $file) {
            $record = $records[$normalized] ?? null;
            $recordId = $record === null ? null : (string) $record->getKey();
            $createdAt = $record === null ? null : ($record->completed_at ?? $record->started_at);
            $modified = @filemtime($file['path']);
            $kind = match (true) {
                str_starts_with($file['key'], InAppBackupRestorer::SAFETY_DIRECTORY.'/') => 'safety',
                $recordId !== null && isset($kinds[$recordId]) => $kinds[$recordId],
                filled($record?->remote_file_id) => 'drive_download',
                default => 'unknown',
            };

            $entries[] = [
                'key' => $file['key'],
                'record_id' => $recordId,
                'filename' => basename($file['path']),
                'created_at' => $createdAt?->toIso8601String()
                    ?? (is_int($modified) ? date(DATE_ATOM, $modified) : null),
                'size_bytes' => (int) (@filesize($file['path']) ?: 0),
                'kind' => $kind,
                'verified' => $record?->status === 'completed',
                'encrypted' => $this->isEncrypted($file['path']),
                'in_copy_folder' => $this->folderCopy->holdsCopyOf(basename($file['path'])),
                'drive_status' => $recordId === null
                    ? null
                    : ($driveCopies[$recordId] ?? (filled($record?->remote_file_id) ? BackupRecord::DRIVE_UPLOAD_COMPLETED : null)),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($entries, 0, max(1, $limit));
    }

    /** The absolute path of a listed archive, or null when $key names none. */
    public function resolve(string $key): ?string
    {
        $root = $this->root();
        $safety = preg_quote(InAppBackupRestorer::SAFETY_DIRECTORY, '/');

        if ($root === null || preg_match('/\A(?:'.$safety.'\/)?'.self::ARCHIVE_NAME.'\z/', $key) !== 1) {
            return null;
        }

        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $key);
        $real = realpath($path);

        if (! is_string($real) || is_link($path) || ! is_file($real)
            || ! str_starts_with($this->normalize($real), $this->normalize($root).'/')) {
            return null;
        }

        return $real;
    }

    private function root(): ?string
    {
        $root = realpath((string) config(
            'medismart.backups.managed_directory',
            storage_path('app/private/backups'),
        ));

        return is_string($root) && is_dir($root) ? $root : null;
    }

    /**
     * @param  list<string>  $paths  normalized paths
     * @return array<string, BackupRecord> keyed by normalized path
     */
    private function recordsFor(array $paths): array
    {
        if (! Schema::hasTable('backup_records')) {
            return [];
        }

        $records = [];

        BackupRecord::query()
            ->whereNotNull('local_path')
            ->latest('started_at')
            ->limit(500)
            ->get()
            ->each(function (BackupRecord $record) use ($paths, &$records): void {
                $real = realpath((string) $record->local_path);
                $normalized = $this->normalize(is_string($real) ? $real : (string) $record->local_path);

                if (in_array($normalized, $paths, true) && ! isset($records[$normalized])) {
                    $records[$normalized] = $record;
                }
            });

        return $records;
    }

    /**
     * @param  list<string>  $recordIds
     * @return array<string, 'manual'|'scheduled'>
     */
    private function triggerKinds(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        try {
            $kinds = [];

            foreach (DB::table('audit_logs')
                ->whereIn('action', ['backup.manual_completed', 'backup.scheduled_completed'])
                ->whereIn('subject_id', $recordIds)
                ->get(['action', 'subject_id']) as $row) {
                $kinds[(string) $row->subject_id] = $row->action === 'backup.manual_completed' ? 'manual' : 'scheduled';
            }

            return $kinds;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The state of the encrypted Drive copy queued after each local backup.
     *
     * @param  list<string>  $recordIds
     * @return array<string, string>
     */
    private function driveCopyStatuses(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        try {
            $copies = [];

            foreach (DB::table('audit_logs')
                ->where('action', 'backup.scheduled_drive_queued')
                ->orderBy('id')
                ->limit(2000)
                ->get(['subject_id', 'metadata']) as $row) {
                $metadata = json_decode((string) $row->metadata, true);
                $trigger = is_array($metadata) ? ($metadata['trigger_backup_record_id'] ?? null) : null;

                if (is_string($trigger) && in_array($trigger, $recordIds, true)) {
                    $copies[(string) $row->subject_id] = $trigger;
                }
            }

            $statuses = [];

            foreach (BackupRecord::query()->whereKey(array_keys($copies))->get() as $copy) {
                $statuses[$copies[(string) $copy->getKey()]] = filled($copy->remote_file_id)
                    ? BackupRecord::DRIVE_UPLOAD_COMPLETED
                    : ($copy->drive_upload_status ?? BackupRecord::DRIVE_UPLOAD_QUEUED);
            }

            return $statuses;
        } catch (Throwable) {
            return [];
        }
    }

    private function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if (! is_resource($handle)) {
            return false;
        }

        try {
            return fread($handle, strlen(self::ENCRYPTED_MAGIC)) === self::ENCRYPTED_MAGIC;
        } finally {
            fclose($handle);
        }
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
