<?php

namespace App\Services\Sync;

use App\Models\Appointment;

/**
 * What happened to one imported event.
 *
 * A sync run reports these back to the clinic, so "nothing changed" is
 * distinguishable from "we refused something", and a rejection carries the
 * reason rather than disappearing into a log file.
 */
final readonly class ImportResult
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_UPDATED = 'updated';

    public const OUTCOME_DELETED = 'deleted';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_REJECTED = 'rejected';

    private function __construct(
        public string $outcome,
        public ?string $reason = null,
        /**
         * The appointment the import touched, when it touched one.
         *
         * A relay that accepts pushed changes needs this so it can republish
         * them on its own event stream; without it, a change pushed up from a
         * desktop would never reach the mobile app.
         */
        public ?Appointment $appointment = null,
    ) {}

    public static function created(Appointment $appointment): self
    {
        return new self(self::OUTCOME_CREATED, appointment: $appointment);
    }

    public static function updated(Appointment $appointment): self
    {
        return new self(self::OUTCOME_UPDATED, appointment: $appointment);
    }

    public static function deleted(Appointment $appointment): self
    {
        return new self(self::OUTCOME_DELETED, appointment: $appointment);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::OUTCOME_SKIPPED, $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::OUTCOME_REJECTED, $reason);
    }

    /** True when the import changed local data. */
    public function changedData(): bool
    {
        return in_array(
            $this->outcome,
            [self::OUTCOME_CREATED, self::OUTCOME_UPDATED, self::OUTCOME_DELETED],
            true,
        );
    }

    public function wasRejected(): bool
    {
        return $this->outcome === self::OUTCOME_REJECTED;
    }
}
