<?php

namespace App\Services\Sync;

/**
 * What one sync run did, in terms a clinician can read.
 */
final class SyncReport
{
    public int $pulled = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $deleted = 0;

    public int $skipped = 0;

    /**
     * Appointments both sides changed independently at the same version. Local
     * state was kept; a person has to reconcile them.
     */
    public int $conflicts = 0;

    public int $pushed = 0;

    /** @var list<string> */
    public array $rejections = [];

    public bool $offline = false;

    public ?string $error = null;

    public function record(ImportResult $result): void
    {
        match ($result->outcome) {
            ImportResult::OUTCOME_CREATED => $this->created++,
            ImportResult::OUTCOME_UPDATED => $this->updated++,
            ImportResult::OUTCOME_DELETED => $this->deleted++,
            ImportResult::OUTCOME_SKIPPED => $this->recordSkip($result),
            ImportResult::OUTCOME_REJECTED => $this->rejections[] = (string) $result->reason,
            default => null,
        };
    }

    private function recordSkip(ImportResult $result): void
    {
        $this->skipped++;

        if ($result->reason === 'version_conflict') {
            $this->conflicts++;
        }
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /** True when the run completed without changing anything. */
    public function wasQuiet(): bool
    {
        return ! $this->failed()
            && $this->created === 0
            && $this->updated === 0
            && $this->deleted === 0
            && $this->pushed === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pulled' => $this->pulled,
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'skipped' => $this->skipped,
            'conflicts' => $this->conflicts,
            'pushed' => $this->pushed,
            'rejections' => $this->rejections,
            'offline' => $this->offline,
            'error' => $this->error,
        ];
    }
}
