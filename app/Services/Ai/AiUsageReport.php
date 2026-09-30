<?php

namespace App\Services\Ai;

use App\Enums\AiUsagePeriod;
use App\Models\AiUsage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the platform's AI consumed over a period, read from the credit ledger.
 *
 * Spends are the `charged` lines. A failed provider call is refunded before
 * any line is written, so those lines are already net and can be summed as is.
 * Tokens are what the provider bills; credits are what the cabinets pay.
 */
final class AiUsageReport
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
    ) {}

    public static function for(AiUsagePeriod $period, ?CarbonImmutable $now = null): self
    {
        [$from, $until] = $period->range($now ?? CarbonImmutable::now());

        return new self($from, $until);
    }

    /**
     * @return array{credits_spent: int, calls: int, prompt_tokens: int, completion_tokens: int, cabinets: int, credits_added: int, recharges: int}
     */
    public function totals(): array
    {
        $spent = $this->lines()
            ->where('status', AiUsage::STATUS_CHARGED)
            ->selectRaw('COALESCE(SUM(credits), 0) AS credits')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens')
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) AS completion_tokens')
            ->selectRaw('COUNT(DISTINCT cabinet_id) AS cabinets')
            ->toBase()
            ->first();

        $added = $this->lines()
            ->where('status', AiUsage::STATUS_ADJUSTED)
            ->where('credits', '>', 0)
            ->selectRaw('COALESCE(SUM(credits), 0) AS credits')
            ->selectRaw('COUNT(*) AS recharges')
            ->toBase()
            ->first();

        return [
            'credits_spent' => -(int) ($spent->credits ?? 0),
            'calls' => (int) ($spent->calls ?? 0),
            'prompt_tokens' => (int) ($spent->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($spent->completion_tokens ?? 0),
            'cabinets' => (int) ($spent->cabinets ?? 0),
            'credits_added' => (int) ($added->credits ?? 0),
            'recharges' => (int) ($added->recharges ?? 0),
        ];
    }

    /**
     * Credits spent per calendar day, every day of the period present so the
     * chart shows the quiet days too.
     *
     * @return array<string, int> keyed Y-m-d
     */
    public function daily(): array
    {
        $days = [];

        for ($day = $this->from->startOfDay(); $day->lessThanOrEqualTo($this->until); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = 0;
        }

        // DATE() reads the same in SQLite and MySQL, and created_at is stored
        // in the application timezone, so a bucket is the operator's day.
        $rows = $this->lines()
            ->where('status', AiUsage::STATUS_CHARGED)
            ->selectRaw('DATE(created_at) AS day')
            ->selectRaw('SUM(credits) AS credits')
            ->groupByRaw('DATE(created_at)')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $key = substr((string) $row->day, 0, 10);

            if (array_key_exists($key, $days)) {
                $days[$key] = -(int) $row->credits;
            }
        }

        return $days;
    }

    /**
     * @return array<string, array{credits: int, calls: int, tokens: int}> keyed by feature, most credits first
     */
    public function byFeature(): array
    {
        $rows = $this->lines()
            ->where('status', AiUsage::STATUS_CHARGED)
            ->select('feature')
            ->selectRaw('SUM(credits) AS credits')
            ->selectRaw('COUNT(*) AS calls')
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) + COALESCE(SUM(completion_tokens), 0) AS tokens')
            ->groupBy('feature')
            ->toBase()
            ->get();

        $features = [];

        foreach ($rows as $row) {
            $features[(string) $row->feature] = [
                'credits' => -(int) $row->credits,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
            ];
        }

        uasort($features, static fn (array $a, array $b): int => [$b['credits'], $b['calls']] <=> [$a['credits'], $a['calls']]);

        return $features;
    }

    /**
     * @return Builder<AiUsage>
     */
    public function lines(): Builder
    {
        return AiUsage::query()
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<=', $this->until);
    }
}
