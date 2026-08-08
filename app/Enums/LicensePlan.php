<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum LicensePlan: string
{
    case TRIAL = 'trial';
    case LIFETIME = 'lifetime';

    public const TRIAL_DAYS = 7;

    public function label(): string
    {
        return match ($this) {
            self::TRIAL => 'Essai de 7 jours',
            self::LIFETIME => 'À vie',
        };
    }

    public function expiresAt(CarbonImmutable $startsAt): ?CarbonImmutable
    {
        return match ($this) {
            self::TRIAL => $startsAt->addDays(self::TRIAL_DAYS),
            self::LIFETIME => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::TRIAL->value => self::TRIAL->label(),
            self::LIFETIME->value => self::LIFETIME->label(),
        ];
    }
}
