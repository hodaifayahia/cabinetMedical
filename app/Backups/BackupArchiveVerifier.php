<?php

namespace App\Backups;

interface BackupArchiveVerifier
{
    /**
     * @return array{
     *     manifest: array<string, mixed>,
     *     archive_size: int,
     *     archive_sha256: string,
     *     entry_count: int
     * }
     */
    public function verify(string $path): array;
}
