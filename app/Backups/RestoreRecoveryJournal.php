<?php

namespace App\Backups;

use Illuminate\Support\Str;
use JsonException;

final class RestoreRecoveryJournal
{
    private const MAXIMUM_BYTES = 4 * 1024 * 1024;

    private const EVENTS = [
        'preparation_started',
        'archive_authenticated',
        'payload_extracted',
        'staging_validated',
        'ready_for_offline_apply',
        'preparation_failed',
        'apply_started',
        'safety_backup_verified',
        'target_swap_started',
        'target_backed_up',
        'target_installed',
        'apply_validation_passed',
        'rollback_started',
        'target_rolled_back',
        'rollback_completed',
        'manual_recovery_required',
        'applied_pending_restart',
    ];

    private const SENSITIVE_KEY_PATTERN = '/pass|secret|token|credential|app[_-]?key|oauth|private[_-]?key/i';

    private function __construct(
        public readonly string $operationId,
        public readonly string $path,
    ) {}

    public static function create(string $operationId): self
    {
        self::assertOperationId($operationId);
        $directory = storage_path('app/private/restore-journals');
        self::ensurePrivateDirectory($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$operationId.'.jsonl';
        $journal = new self($operationId, $path);

        if (file_exists($path) || is_link($path)) {
            throw new BackupArchiveException('A recovery journal already exists for this restore operation.');
        }

        $handle = @fopen($path, 'xb');

        if (! is_resource($handle)) {
            throw new BackupArchiveException('The restore recovery journal could not be created.');
        }

        try {
            self::secureFile($path);
            $journal->writeRecord($handle, 1, 'preparation_started', []);
            $journal->flush($handle);
        } finally {
            fclose($handle);
        }

        return $journal;
    }

    public static function open(string $operationId): self
    {
        self::assertOperationId($operationId);
        $path = storage_path('app/private/restore-journals/'.$operationId.'.jsonl');

        if (! is_file($path) || is_link($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new BackupArchiveException('The restore recovery journal is unavailable.');
        }

        $journal = new self($operationId, $path);
        $journal->records();

        return $journal;
    }

    /** @param array<string, bool|int|string|null> $context */
    public function append(string $event, array $context = []): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new BackupArchiveException('The restore recovery event is not supported.');
        }

        $this->assertSafeContext($context);
        $handle = @fopen($this->path, 'c+b');

        if (! is_resource($handle) || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new BackupArchiveException('The restore recovery journal could not be locked.');
        }

        try {
            $records = $this->recordsFromLockedHandle($handle);
            $sequence = count($records) + 1;

            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new BackupArchiveException('The restore recovery journal could not be updated.');
            }

            $this->writeRecord($handle, $sequence, $event, $context);
            $this->flush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<array{
     *     sequence: int,
     *     operation_id: string,
     *     event: string,
     *     occurred_at: string,
     *     context: array<string, bool|int|string|null>,
     *     sha256: string
     * }>
     */
    public function records(): array
    {
        $handle = @fopen($this->path, 'rb');

        if (! is_resource($handle) || ! flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new BackupArchiveException('The restore recovery journal could not be read.');
        }

        try {
            return $this->recordsFromLockedHandle($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<array{
     *     sequence: int,
     *     operation_id: string,
     *     event: string,
     *     occurred_at: string,
     *     context: array<string, bool|int|string|null>,
     *     sha256: string
     * }>
     */
    private function recordsFromLockedHandle(mixed $handle): array
    {
        $stat = fstat($handle);

        if ($stat === false || $stat['size'] < 1 || $stat['size'] > self::MAXIMUM_BYTES
            || fseek($handle, 0, SEEK_SET) !== 0) {
            throw new BackupArchiveException('The restore recovery journal is malformed or exceeds its safety limit.');
        }

        $contents = stream_get_contents($handle, self::MAXIMUM_BYTES + 1);

        if (! is_string($contents) || strlen($contents) !== $stat['size'] || ! str_ends_with($contents, "\n")) {
            throw new BackupArchiveException('The restore recovery journal has an incomplete record; manual recovery is required.');
        }

        $lines = explode("\n", rtrim($contents, "\n"));
        $records = [];

        foreach ($lines as $index => $line) {
            try {
                $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new BackupArchiveException('The restore recovery journal is malformed; manual recovery is required.');
            }

            if (! is_array($record) || array_is_list($record)) {
                throw new BackupArchiveException('The restore recovery journal is malformed; manual recovery is required.');
            }

            $checksum = $record['sha256'] ?? null;
            unset($record['sha256']);
            $context = $record['context'] ?? null;

            if (($record['sequence'] ?? null) !== $index + 1
                || ($record['operation_id'] ?? null) !== $this->operationId
                || ! is_string($record['event'] ?? null)
                || ! in_array($record['event'], self::EVENTS, true)
                || ! is_string($record['occurred_at'] ?? null)
                || ! is_array($context) || ($context !== [] && array_is_list($context))
                || ! is_string($checksum)
                || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
                || ! hash_equals($checksum, hash('sha256', $this->encode($record)))) {
                throw new BackupArchiveException('The restore recovery journal failed integrity validation; manual recovery is required.');
            }

            $this->assertSafeContext($context);
            $record['sha256'] = $checksum;
            /** @var array{sequence: int, operation_id: string, event: string, occurred_at: string, context: array<string, bool|int|string|null>, sha256: string} $record */
            $records[] = $record;
        }

        return $records;
    }

    /** @param array<mixed, mixed> $context */
    private function assertSafeContext(array $context): void
    {
        if (count($context) > 24) {
            throw new BackupArchiveException('The restore recovery context exceeds its safety limit.');
        }

        foreach ($context as $key => $value) {
            if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1
                || preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1
                || (! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_string($value))
                || (is_string($value) && (strlen($value) > 512 || preg_match('//u', $value) !== 1))) {
                throw new BackupArchiveException('The restore recovery context contains unsupported or sensitive data.');
            }
        }
    }

    /** @param array<string, bool|int|string|null> $context */
    private function writeRecord(mixed $handle, int $sequence, string $event, array $context): void
    {
        $record = [
            'sequence' => $sequence,
            'operation_id' => $this->operationId,
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'context' => $context,
        ];
        $record['sha256'] = hash('sha256', $this->encode($record));
        $line = $this->encode($record)."\n";
        $written = fwrite($handle, $line);

        if (! is_int($written) || $written !== strlen($line)) {
            throw new BackupArchiveException('The restore recovery journal could not be updated.');
        }
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new BackupArchiveException('The restore recovery journal could not be encoded.');
        }
    }

    private function flush(mixed $handle): void
    {
        if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
            throw new BackupArchiveException('The restore recovery journal could not be persisted durably.');
        }
    }

    private static function assertOperationId(string $operationId): void
    {
        if (! Str::isUuid($operationId)) {
            throw new BackupArchiveException('The restore operation identifier is invalid.');
        }
    }

    private static function ensurePrivateDirectory(string $directory): void
    {
        if (is_link($directory)
            || (! is_dir($directory) && (! @mkdir($directory, 0700, true) && ! is_dir($directory)))
            || ! is_writable($directory)) {
            throw new BackupArchiveException('The restore recovery directory is unavailable.');
        }

        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($directory, 0700)) {
            throw new BackupArchiveException('The restore recovery directory could not be secured.');
        }
    }

    private static function secureFile(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($path, 0600)) {
            throw new BackupArchiveException('The restore recovery journal could not be secured.');
        }
    }
}
