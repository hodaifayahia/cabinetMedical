<?php

namespace App\Backups;

final readonly class AbandonedRestorePruneReport
{
    public function __construct(
        public int $retentionHours,
        public bool $lockAcquired,
        public int $workspaceEntries = 0,
        public int $journalEntries = 0,
        public int $matchedPairs = 0,
        public int $eligiblePairs = 0,
        public int $prunedPairs = 0,
        public int $protectedRecent = 0,
        public int $protectedState = 0,
        public int $protectedMismatch = 0,
        public int $protectedUnsafe = 0,
        public int $raceChanged = 0,
        public int $failures = 0,
    ) {}

    /** @return array<string, bool|int> */
    public function auditMetadata(): array
    {
        return [
            'retention_hours' => $this->retentionHours,
            'lock_acquired' => $this->lockAcquired,
            'workspace_entries' => $this->workspaceEntries,
            'journal_entries' => $this->journalEntries,
            'matched_pairs' => $this->matchedPairs,
            'eligible_pairs' => $this->eligiblePairs,
            'pruned_pairs' => $this->prunedPairs,
            'protected_recent' => $this->protectedRecent,
            'protected_state' => $this->protectedState,
            'protected_mismatch' => $this->protectedMismatch,
            'protected_unsafe' => $this->protectedUnsafe,
            'race_changed' => $this->raceChanged,
            'failures' => $this->failures,
            'destructive_actions_performed' => $this->prunedPairs > 0,
        ];
    }
}
