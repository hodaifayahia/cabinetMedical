<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case SCHEDULED = 'scheduled';
    case CONFIRMED = 'confirmed';
    case CHECKED_IN = 'checked_in';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';

    public function blocksScheduling(): bool
    {
        return match ($this) {
            self::SCHEDULED, self::CONFIRMED, self::CHECKED_IN, self::IN_PROGRESS => true,
            self::COMPLETED, self::CANCELLED, self::NO_SHOW => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function blockingValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(
                self::cases(),
                static fn (self $status): bool => $status->blocksScheduling(),
            ),
        );
    }

    /**
     * @return list<string>
     */
    public static function creatableValues(): array
    {
        return [
            self::SCHEDULED->value,
            self::CONFIRMED->value,
        ];
    }
}
