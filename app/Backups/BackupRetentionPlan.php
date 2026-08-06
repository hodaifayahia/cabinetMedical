<?php

namespace App\Backups;

final readonly class BackupRetentionPlan
{
    /**
     * @param  list<array<string, mixed>>  $keep
     * @param  list<array<string, mixed>>  $deletionCandidates
     * @param  list<array{input_index: int, reason_code: string}>  $protected
     * @param  array<string, bool|int|null>  $summary
     */
    public function __construct(
        public BackupRetentionPolicy $policy,
        public array $keep,
        public array $deletionCandidates,
        public array $protected,
        public array $summary,
        public string $planSha256,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $planSha256) !== 1) {
            throw new BackupArchiveException('The backup retention plan fingerprint is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy' => $this->policy->toArray(),
            'summary' => $this->summary,
            'keep' => $this->keep,
            'deletion_candidates' => $this->deletionCandidates,
            'protected' => $this->protected,
            'plan_sha256' => $this->planSha256,
            'destructive_actions_performed' => false,
            'deletion_authorized' => false,
            'requires_exact_managed_file_revalidation' => true,
            'requires_explicit_confirmation' => true,
        ];
    }
}
