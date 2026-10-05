<?php

namespace Tests\Unit\Ai;

use App\Enums\AiUsagePeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reporting windows of the platform's AI consumption page.
 */
class AiUsagePeriodTest extends TestCase
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-05 14:30:00');
    }

    public function test_the_last_7_days_include_today_and_start_at_midnight(): void
    {
        [$from, $until] = AiUsagePeriod::LAST_7_DAYS->range($this->now());

        $this->assertSame('2026-09-29 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 14:30:00', $until->format('Y-m-d H:i:s'));
    }

    public function test_the_last_30_days(): void
    {
        [$from, $until] = AiUsagePeriod::LAST_30_DAYS->range($this->now());

        $this->assertSame('2026-09-06 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertTrue($until->equalTo($this->now()));
    }

    public function test_the_last_90_days(): void
    {
        [$from] = AiUsagePeriod::LAST_90_DAYS->range($this->now());

        $this->assertSame('2026-07-08 00:00:00', $from->format('Y-m-d H:i:s'));
    }

    public function test_this_month_starts_on_the_first(): void
    {
        [$from, $until] = AiUsagePeriod::THIS_MONTH->range($this->now());

        $this->assertSame('2026-10-01 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertTrue($until->equalTo($this->now()));
    }

    public function test_last_month_covers_the_whole_previous_month(): void
    {
        [$from, $until] = AiUsagePeriod::LAST_MONTH->range($this->now());

        $this->assertSame('2026-09-01 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 23:59:59', $until->format('Y-m-d H:i:s'));
    }

    public function test_last_month_does_not_overflow_from_the_31st(): void
    {
        // March 31st minus one month must be February, not March 3rd.
        [$from, $until] = AiUsagePeriod::LAST_MONTH->range(CarbonImmutable::parse('2026-03-31 10:00:00'));

        $this->assertSame('2026-02-01', $from->toDateString());
        $this->assertSame('2026-02-28', $until->toDateString());
    }

    public function test_last_month_in_january_is_the_previous_december(): void
    {
        [$from, $until] = AiUsagePeriod::LAST_MONTH->range(CarbonImmutable::parse('2027-01-15 08:00:00'));

        $this->assertSame('2026-12-01', $from->toDateString());
        $this->assertSame('2026-12-31', $until->toDateString());
    }

    public function test_every_range_starts_before_it_ends(): void
    {
        foreach (AiUsagePeriod::cases() as $period) {
            [$from, $until] = $period->range($this->now());

            $this->assertTrue($from->lessThan($until), $period->value);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: AiUsagePeriod}>
     */
    public static function filters(): array
    {
        return [
            '7d' => ['7d', AiUsagePeriod::LAST_7_DAYS],
            '30d' => ['30d', AiUsagePeriod::LAST_30_DAYS],
            'month' => ['month', AiUsagePeriod::THIS_MONTH],
            'last_month' => ['last_month', AiUsagePeriod::LAST_MONTH],
            '90d' => ['90d', AiUsagePeriod::LAST_90_DAYS],
            'unknown string' => ['1y', AiUsagePeriod::LAST_30_DAYS],
            'empty string' => ['', AiUsagePeriod::LAST_30_DAYS],
            'null' => [null, AiUsagePeriod::LAST_30_DAYS],
            'integer' => [7, AiUsagePeriod::LAST_30_DAYS],
            'array' => [['7d'], AiUsagePeriod::LAST_30_DAYS],
        ];
    }

    #[DataProvider('filters')]
    public function test_any_filter_value_resolves_to_a_period(mixed $value, AiUsagePeriod $expected): void
    {
        $this->assertSame($expected, AiUsagePeriod::fromFilter($value));
    }

    public function test_the_default_is_the_last_30_days(): void
    {
        $this->assertSame(AiUsagePeriod::LAST_30_DAYS, AiUsagePeriod::default());
    }

    public function test_options_list_every_period_with_its_label(): void
    {
        $options = AiUsagePeriod::options();

        $this->assertSame(['7d', '30d', 'month', 'last_month', '90d'], array_keys($options));

        foreach (AiUsagePeriod::cases() as $period) {
            $this->assertSame($period->label(), $options[$period->value]);
            $this->assertNotSame('', $period->label());
        }
    }
}
