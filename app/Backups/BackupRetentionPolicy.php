<?php

namespace App\Backups;

use InvalidArgumentException;

final readonly class BackupRetentionPolicy
{
    public const MAXIMUM_DAILY_BUCKETS = 365;

    public const MAXIMUM_WEEKLY_BUCKETS = 104;

    public const MAXIMUM_MONTHLY_BUCKETS = 120;

    public const MAXIMUM_STORAGE_BYTES = 10 * 1024 * 1024 * 1024 * 1024;

    public const MAXIMUM_RECENT_COPIES = 24;

    /**
     * @param  int  $recent  the newest archives kept whatever their day, so the
     *                       earlier backups of a day with several are not
     *                       pruned as soon as the next one is written
     */
    public function __construct(
        public int $daily,
        public int $weekly,
        public int $monthly,
        public ?int $maximumStorageBytes = null,
        public int $recent = 0,
    ) {
        if ($daily < 0 || $daily > self::MAXIMUM_DAILY_BUCKETS
            || $weekly < 0 || $weekly > self::MAXIMUM_WEEKLY_BUCKETS
            || $monthly < 0 || $monthly > self::MAXIMUM_MONTHLY_BUCKETS
            || $recent < 0 || $recent > self::MAXIMUM_RECENT_COPIES
            || ($maximumStorageBytes !== null
                && ($maximumStorageBytes < 1 || $maximumStorageBytes > self::MAXIMUM_STORAGE_BYTES))) {
            throw new InvalidArgumentException('The backup retention counts are outside the supported safety limits.');
        }
    }

    /** @return array{recent: int, daily: int, weekly: int, monthly: int, maximum_storage_bytes: int|null, timezone: 'UTC', week_starts_on: 'monday'} */
    public function toArray(): array
    {
        return [
            'recent' => $this->recent,
            'daily' => $this->daily,
            'weekly' => $this->weekly,
            'monthly' => $this->monthly,
            'maximum_storage_bytes' => $this->maximumStorageBytes,
            'timezone' => 'UTC',
            'week_starts_on' => 'monday',
        ];
    }
}
