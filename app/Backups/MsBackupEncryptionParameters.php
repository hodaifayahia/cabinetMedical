<?php

namespace App\Backups;

final readonly class MsBackupEncryptionParameters
{
    public const MINIMUM_OPERATIONS_LIMIT = 2;

    public const MAXIMUM_OPERATIONS_LIMIT = 4;

    public const MINIMUM_MEMORY_LIMIT_BYTES = 64 * 1024 * 1024;

    public const MAXIMUM_MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    public const MINIMUM_CHUNK_BYTES = 64 * 1024;

    public const MAXIMUM_CHUNK_BYTES = 8 * 1024 * 1024;

    public const DEFAULT_CHUNK_BYTES = 1024 * 1024;

    public function __construct(
        public int $operationsLimit,
        public int $memoryLimitBytes,
        public int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
    ) {
        if ($operationsLimit < self::MINIMUM_OPERATIONS_LIMIT
            || $operationsLimit > self::MAXIMUM_OPERATIONS_LIMIT
            || $memoryLimitBytes < self::MINIMUM_MEMORY_LIMIT_BYTES
            || $memoryLimitBytes > self::MAXIMUM_MEMORY_LIMIT_BYTES
            || $chunkBytes < self::MINIMUM_CHUNK_BYTES
            || $chunkBytes > self::MAXIMUM_CHUNK_BYTES) {
            throw new BackupArchiveException('The backup encryption parameters are outside the supported safety policy.');
        }
    }

    /**
     * The production profile intentionally favors offline password resistance.
     * Callers may explicitly select interactive() on memory-constrained hosts.
     */
    public static function production(): self
    {
        return new self(
            operationsLimit: 3,
            memoryLimitBytes: 256 * 1024 * 1024,
        );
    }

    public static function interactive(): self
    {
        return new self(
            operationsLimit: self::MINIMUM_OPERATIONS_LIMIT,
            memoryLimitBytes: self::MINIMUM_MEMORY_LIMIT_BYTES,
        );
    }
}
