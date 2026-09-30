<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The windows the AI consumption page can report on. Kept to ranges short
 * enough for a per-day chart; the full history stays in the ledger.
 */
enum AiUsagePeriod: string
{
    case LAST_7_DAYS = '7d';
    case LAST_30_DAYS = '30d';
    case THIS_MONTH = 'month';
    case LAST_MONTH = 'last_month';
    case LAST_90_DAYS = '90d';

    public function label(): string
    {
        return match ($this) {
            self::LAST_7_DAYS => '7 derniers jours',
            self::LAST_30_DAYS => '30 derniers jours',
            self::THIS_MONTH => 'Ce mois-ci',
            self::LAST_MONTH => 'Mois dernier',
            self::LAST_90_DAYS => '90 derniers jours',
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(CarbonImmutable $now): array
    {
        return match ($this) {
            self::LAST_7_DAYS => [$now->subDays(6)->startOfDay(), $now],
            self::LAST_30_DAYS => [$now->subDays(29)->startOfDay(), $now],
            self::THIS_MONTH => [$now->startOfMonth(), $now],
            self::LAST_MONTH => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            self::LAST_90_DAYS => [$now->subDays(89)->startOfDay(), $now],
        };
    }

    /**
     * The page filter arrives from the query string or the session, so any
     * unknown value falls back to the default rather than failing the page.
     */
    public static function fromFilter(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    public static function default(): self
    {
        return self::LAST_30_DAYS;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $period) {
            $options[$period->value] = $period->label();
        }

        return $options;
    }
}
