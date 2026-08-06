<?php

namespace App\Backups;

final readonly class LocalBackupRetentionPreview
{
    /**
     * @param  list<array{record_id: string|null, file_ref: string|null, reason_code: string, size_bytes: int}>  $protectedEntries
     */
    public function __construct(
        public BackupRetentionPlan $plan,
        public array $protectedEntries,
        public string $inventorySha256,
        public string $managedRootSha256,
    ) {
        foreach ([$inventorySha256, $managedRootSha256] as $fingerprint) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
                throw new BackupArchiveException('The local retention inventory fingerprint is invalid.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => 'dry_run',
            'inventory_sha256' => $this->inventorySha256,
            'managed_root_sha256' => $this->managedRootSha256,
            'plan' => $this->plan->toArray(),
            'protected_entries' => $this->protectedEntries,
            'destructive_actions_performed' => false,
            'deletion_authorized' => false,
            'confirmation_token_issued' => false,
        ];
    }
}
