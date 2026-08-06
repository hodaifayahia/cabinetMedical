<?php

namespace App\Backups;

/**
 * Internal inventory state. Paths deliberately never appear in the public
 * preview or confirmation token.
 */
final readonly class LocalBackupRetentionSnapshot
{
    /**
     * @param  array<string, array{
     *     record_id: string,
     *     path: string,
     *     record_fingerprint: string,
     *     metadata: array{format_version: 1|2, created_at: string, size_bytes: int, sha256: string}
     * }>  $candidates
     */
    public function __construct(
        public LocalBackupRetentionPreview $preview,
        public string $managedRoot,
        public array $candidates,
    ) {}
}
