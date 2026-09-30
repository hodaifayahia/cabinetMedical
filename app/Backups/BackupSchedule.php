<?php

namespace App\Backups;

use Carbon\CarbonImmutable;

/**
 * The three daily times at which a supervised desktop saves a local backup.
 *
 * A slot counts as due from its time until the next slot starts. A PC that was
 * switched off over one or more slots therefore makes a single catch-up copy
 * when it comes back, never one per missed slot.
 */
final readonly class BackupSchedule
{
    public const SLOTS = 3;

    /** @var list<string> */
    public const DEFAULT_TIMES = ['10:00', '14:00', '18:00'];

    /** @param list<string> $times three distinct H:i values, ascending */
    private function __construct(private array $times) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULT_TIMES);
    }

    /** A stored or submitted value; anything unusable falls back to the defaults. */
    public static function fromSetting(mixed $value): self
    {
        $times = self::normalize($value);

        return new self($times ?? self::DEFAULT_TIMES);
    }

    /**
     * Exactly three distinct 24-hour times, sorted, or null.
     *
     * @return list<string>|null
     */
    public static function normalize(mixed $value): ?array
    {
        if (! is_array($value) || count($value) !== self::SLOTS || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $time) {
            if (! is_string($time) || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time) !== 1) {
                return null;
            }
        }

        if (count(array_unique($value)) !== self::SLOTS) {
            return null;
        }

        sort($value);

        return $value;
    }

    /** @return list<string> */
    public function times(): array
    {
        return $this->times;
    }

    /** The most recent slot at or before $now: one of today's, or yesterday's last. */
    public function latestDueSlot(CarbonImmutable $now): CarbonImmutable
    {
        foreach (array_reverse($this->times) as $time) {
            $slot = $this->at($now->startOfDay(), $time);

            if ($slot->lessThanOrEqualTo($now)) {
                return $slot;
            }
        }

        return $this->at($now->startOfDay()->subDay(), $this->times[self::SLOTS - 1]);
    }

    /** The first slot strictly after $now. */
    public function nextSlot(CarbonImmutable $now): CarbonImmutable
    {
        foreach ($this->times as $time) {
            $slot = $this->at($now->startOfDay(), $time);

            if ($slot->greaterThan($now)) {
                return $slot;
            }
        }

        return $this->at($now->startOfDay()->addDay(), $this->times[0]);
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        return $day->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));
    }
}
