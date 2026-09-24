<?php

namespace App\Services\Billing;

use App\Enums\ExpenseCategory;
use App\Models\Consultation;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Revenue analytics for one cabinet (the cabinet global scope applies to
 * every query here).
 *
 * Two bases are reported side by side and never mixed:
 *  - "collected" is cash basis: ledger entries by `received_at`, refunds
 *    included as negative amounts, in the period the money moved;
 *  - "billed" is accrual basis: consultation charges by `consulted_at`.
 *
 * Paid consultations that predate the ledger (no payment rows) count as a
 * collection on their consultation date, matching the payments screen.
 *
 * Aggregation happens in PHP so the same code runs on the desktop SQLite
 * database and on the hosted MySQL one without dialect-specific date SQL.
 * All amounts stay in minor units until the final payload.
 *
 * @phpstan-type CashRow array{at: CarbonImmutable, amount: int, mode: string, user_id: int, patient_id: int}
 * @phpstan-type ChargeRow array{at: CarbonImmutable, charge: int, adjustment: int, collected: int, outstanding: int, service: string, patient_id: int}
 * @phpstan-type ExpenseRow array{at: CarbonImmutable, amount: int, category: string}
 * @phpstan-type Kpis array{collected: int, gross_collected: int, refunds: int, billed: int, discounts: int, outstanding: int, collection_rate: float|null, consultations: int, average_ticket: int, patients: int, transactions: int}
 */
final class FinanceReport
{
    private const MONTH_LABELS = [
        1 => 'Janv.', 2 => 'Févr.', 3 => 'Mars', 4 => 'Avr.', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juil.', 8 => 'Août', 9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Déc.',
    ];

    private const MONTH_NAMES = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    private const WEEKDAY_LABELS = [
        1 => 'Lun.', 2 => 'Mar.', 3 => 'Mer.', 4 => 'Jeu.', 5 => 'Ven.', 6 => 'Sam.', 7 => 'Dim.',
    ];

    private const UNSPECIFIED_METHOD = 'Non renseigné';

    /**
     * Years that hold at least one charge or collection, plus the current
     * year, newest first.
     *
     * @return list<int>
     */
    public function availableYears(CarbonImmutable $now): array
    {
        $firstPayment = Payment::query()->min('received_at');
        $firstCharge = Consultation::query()
            ->whereNotNull('payment_amount_minor')
            ->min('consulted_at');

        $firstYear = (int) $now->year;

        foreach ([$firstPayment, $firstCharge] as $value) {
            if (is_string($value) && $value !== '') {
                $firstYear = min($firstYear, (int) CarbonImmutable::parse($value)->year);
            }
        }

        return array_values(array_reverse(range($firstYear, (int) $now->year)));
    }

    /**
     * Full analytics payload for one year, or one month of that year.
     *
     * @return array<string, mixed>
     */
    public function report(int $year, ?int $month, CarbonImmutable $now): array
    {
        ['start' => $start, 'end' => $end, 'isCurrent' => $isCurrent, 'previousStart' => $previousStart, 'previousEnd' => $previousEnd, 'loadFrom' => $loadFrom]
            = $this->window($year, $month, $now);
        $cash = $this->cash($loadFrom, $end);
        $charges = $this->charges($loadFrom, $end);

        $periodCash = $this->cashWithin($cash, $start, $end);
        $periodCharges = $this->chargesWithin($charges, $start, $end);

        $kpis = $this->kpis($periodCash, $periodCharges);
        $previousKpis = $this->kpis(
            $this->cashWithin($cash, $previousStart, $previousEnd),
            $this->chargesWithin($charges, $previousStart, $previousEnd),
        );

        $totalDays = (int) $start->diffInDays($end->startOfDay()) + 1;
        $elapsedDays = $isCurrent ? (int) $start->diffInDays($now->startOfDay()) + 1 : $totalDays;

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $month !== null ? ucfirst(self::MONTH_NAMES[$month]).' '.$year : 'Année '.$year,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'is_current' => $isCurrent,
                'elapsed_days' => $elapsedDays,
                'total_days' => $totalDays,
            ],
            'comparison' => [
                'label' => $month !== null
                    ? ucfirst(self::MONTH_NAMES[(int) $previousStart->month]).' '.$previousStart->year
                    : 'Année '.$previousStart->year,
                'partial' => $isCurrent,
                'from' => $previousStart->toDateString(),
                'to' => $previousEnd->toDateString(),
            ],
            'kpis' => $this->major($kpis),
            'previous' => $this->major($previousKpis),
            'changes' => [
                'collected' => $this->change($kpis['collected'], $previousKpis['collected']),
                'billed' => $this->change($kpis['billed'], $previousKpis['billed']),
                'consultations' => $this->change($kpis['consultations'], $previousKpis['consultations']),
                'average_ticket' => $this->change($kpis['average_ticket'], $previousKpis['average_ticket']),
            ],
            'projection' => $isCurrent && $elapsedDays < $totalDays
                ? round($kpis['collected'] / $elapsedDays * $totalDays / 100)
                : null,
            'timeline' => $month !== null
                ? $this->dailySeries($start, $end, $periodCash, $periodCharges, $isCurrent ? $now : null)
                : $this->monthlySeries($year, $cash, $charges, $isCurrent ? $now : null),
            'weekdays' => $this->weekdaySeries($periodCash),
            'methods' => $this->byMethod($periodCash),
            'services' => $this->byService($periodCharges),
            'practitioners' => $this->byPractitioner($periodCash),
            'topPatients' => $this->topPatients($periodCash),
        ];
    }

    /**
     * Operating costs and net profit for the same period as report().
     * Kept separate so callers only load it for users allowed to see costs.
     *
     * @return array<string, mixed>
     */
    public function profit(int $year, ?int $month, CarbonImmutable $now): array
    {
        ['start' => $start, 'end' => $end, 'previousStart' => $previousStart, 'previousEnd' => $previousEnd, 'loadFrom' => $loadFrom]
            = $this->window($year, $month, $now);

        $cash = $this->cash($loadFrom, $end);
        $expenses = $this->expenses($loadFrom, $end);

        $sumCash = fn (CarbonInterface $from, CarbonInterface $to): int => (int) $this->cashWithin($cash, $from, $to)->sum('amount');
        $sumExpenses = fn (CarbonInterface $from, CarbonInterface $to): int => (int) $this->expensesWithin($expenses, $from, $to)->sum('amount');

        $collected = $sumCash($start, $end);
        $spent = $sumExpenses($start, $end);
        $previousCollected = $sumCash($previousStart, $previousEnd);
        $previousSpent = $sumExpenses($previousStart, $previousEnd);
        $net = $collected - $spent;
        $previousNet = $previousCollected - $previousSpent;

        // Timeline keys match report()['timeline'] so the page can merge them.
        $bucketKey = static fn (CarbonImmutable $at): string => $month !== null ? $at->toDateString() : $at->format('Y-m');
        /** @var array<string, array{expenses: int, collected: int}> $buckets */
        $buckets = [];

        foreach ($this->cashWithin($cash, $start, $end) as $row) {
            $key = $bucketKey($row['at']);
            $buckets[$key] ??= ['expenses' => 0, 'collected' => 0];
            $buckets[$key]['collected'] += $row['amount'];
        }

        $periodExpenses = $this->expensesWithin($expenses, $start, $end);

        foreach ($periodExpenses as $row) {
            $key = $bucketKey($row['at']);
            $buckets[$key] ??= ['expenses' => 0, 'collected' => 0];
            $buckets[$key]['expenses'] += $row['amount'];
        }

        /** @var array<string, int> $byCategory */
        $byCategory = [];

        foreach ($periodExpenses as $row) {
            $byCategory[$row['category']] = ($byCategory[$row['category']] ?? 0) + $row['amount'];
        }

        arsort($byCategory);

        return [
            'expenses' => $spent / 100,
            'previous_expenses' => $previousSpent / 100,
            'net' => $net / 100,
            'previous_net' => $previousNet / 100,
            'margin' => $collected > 0 ? round($net / $collected * 100, 1) : null,
            'changes' => [
                'expenses' => $this->change($spent, $previousSpent),
                'net' => $previousNet > 0 ? round(($net - $previousNet) / $previousNet * 100, 1) : null,
            ],
            'categories' => array_values(array_map(
                static fn (string $category, int $amount): array => [
                    'category' => $category,
                    'label' => ExpenseCategory::tryFrom($category)?->label() ?? $category,
                    'value' => $amount / 100,
                    'share' => $spent > 0 ? round($amount / $spent * 100, 1) : 0.0,
                ],
                array_keys($byCategory),
                $byCategory,
            )),
            'timeline' => array_map(static fn (array $bucket): array => [
                'expenses' => $bucket['expenses'] / 100,
                'net' => ($bucket['collected'] - $bucket['expenses']) / 100,
            ], $buckets),
        ];
    }

    /**
     * Compact figures for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function snapshot(CarbonImmutable $now): array
    {
        $yearStart = $now->startOfYear();
        $cash = $this->cash($yearStart->subYear(), $now->endOfDay());
        $charges = $this->charges($yearStart->subYear(), $now->endOfDay());
        $window = fn (CarbonInterface $from, CarbonInterface $to): array => $this->kpis(
            $this->cashWithin($cash, $from, $to),
            $this->chargesWithin($charges, $from, $to),
        );

        $today = $window($now->startOfDay(), $now->endOfDay());
        $month = $window($now->startOfMonth(), $now);
        $monthBefore = $now->subMonthNoOverflow();
        $previousMonth = $window($monthBefore->startOfMonth(), $monthBefore);
        $year = $window($yearStart, $now);
        $previousYear = $window($yearStart->subYear(), $now->subYear());

        $daysInMonth = (int) $now->daysInMonth;
        $elapsed = max(1, (int) $now->day);

        return [
            'today' => $this->major($today),
            'month' => $this->major($month),
            'year' => $this->major($year),
            'changes' => [
                'month_collected' => $this->change($month['collected'], $previousMonth['collected']),
                'year_collected' => $this->change($year['collected'], $previousYear['collected']),
                'month_average_ticket' => $this->change($month['average_ticket'], $previousMonth['average_ticket']),
            ],
            'month_projection' => $elapsed < $daysInMonth
                ? round($month['collected'] / $elapsed * $daysInMonth / 100)
                : null,
            'year_label' => (string) $now->year,
            'month_label' => ucfirst(self::MONTH_NAMES[(int) $now->month]),
            'timeline' => $this->monthlySeries((int) $now->year, $cash, $charges, $now),
            'methods' => $this->byMethod($this->cashWithin($cash, $now->startOfMonth(), $now)),
        ];
    }

    /**
     * Everything still owed, as of today, grouped by age and by patient.
     *
     * @return array<string, mixed>
     */
    public function receivables(CarbonImmutable $now, int $debtorLimit = 8): array
    {
        $open = Consultation::query()
            ->whereNotNull('payment_amount_minor')
            ->where('payment_amount_minor', '>', 0)
            ->where('is_paid', false)
            ->with('patient:id,first_name,last_name,patient_number,phone')
            ->withSum('payments', 'amount_minor')
            ->get(['id', 'patient_id', 'consulted_at', 'payment_amount_minor', 'payment_adjustment_minor', 'is_paid'])
            ->filter(static fn (Consultation $consultation): bool => $consultation->outstandingMinor() > 0);

        /** @var array<string, array{key: string, label: string, amount: int, count: int}> $buckets */
        $buckets = [
            'current' => ['key' => 'current', 'label' => '0 – 30 jours', 'amount' => 0, 'count' => 0],
            'd60' => ['key' => 'd60', 'label' => '31 – 60 jours', 'amount' => 0, 'count' => 0],
            'd90' => ['key' => 'd90', 'label' => '61 – 90 jours', 'amount' => 0, 'count' => 0],
            'older' => ['key' => 'older', 'label' => 'Plus de 90 jours', 'amount' => 0, 'count' => 0],
        ];
        /** @var array<int, array{patient_id: int, patient_name: string, patient_number: string|null, phone: string|null, amount: int, count: int, oldest: CarbonImmutable}> $debtors */
        $debtors = [];
        $today = $now->startOfDay();

        foreach ($open as $consultation) {
            $outstanding = $consultation->outstandingMinor();
            $consultedAt = $consultation->consulted_at ?? $now;
            $age = max(0, (int) $consultedAt->startOfDay()->diffInDays($today));
            $bucket = match (true) {
                $age <= 30 => 'current',
                $age <= 60 => 'd60',
                $age <= 90 => 'd90',
                default => 'older',
            };
            $aged = $buckets[$bucket];
            $aged['amount'] += $outstanding;
            $aged['count']++;
            $buckets[$bucket] = $aged;

            $key = $consultation->patient_id;

            if (! isset($debtors[$key])) {
                $patient = $consultation->patient;
                $phone = $patient->getAttribute('phone');
                $debtors[$key] = [
                    'patient_id' => $key,
                    'patient_name' => $patient->full_name,
                    'patient_number' => $patient->patient_number,
                    'phone' => is_string($phone) && $phone !== '' ? $phone : null,
                    'amount' => 0,
                    'count' => 0,
                    'oldest' => $consultedAt,
                ];
            }

            $debtors[$key]['amount'] += $outstanding;
            $debtors[$key]['count']++;

            if ($consultedAt->lessThan($debtors[$key]['oldest'])) {
                $debtors[$key]['oldest'] = $consultedAt;
            }
        }

        usort($debtors, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return [
            'total' => array_sum(array_column($buckets, 'amount')) / 100,
            'count' => $open->count(),
            'patients' => count($debtors),
            'buckets' => array_map(static fn (array $bucket): array => [
                ...$bucket,
                'amount' => $bucket['amount'] / 100,
            ], array_values($buckets)),
            'debtors' => array_map(static fn (array $row): array => [
                ...$row,
                'amount' => $row['amount'] / 100,
                'oldest' => $row['oldest']->toDateString(),
                'age_days' => max(0, (int) $row['oldest']->startOfDay()->diffInDays($today)),
            ], array_slice($debtors, 0, $debtorLimit)),
        ];
    }

    /**
     * Cash movements in a window: ledger entries plus pre-ledger paid
     * consultations.
     *
     * @return Collection<int, CashRow>
     */
    private function cash(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        // '' = no payment method recorded, 0 = no user recorded.
        $toInt = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $toString = static fn (mixed $value): string => is_string($value) ? trim($value) : '';

        /** @var Collection<int, CashRow> $ledger */
        $ledger = Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->get(['amount_minor', 'received_at', 'method', 'received_by', 'patient_id'])
            ->toBase()
            ->map(static fn (Payment $payment): array => [
                'at' => $payment->received_at ?? $from,
                'amount' => $payment->amount_minor,
                'mode' => $toString($payment->getAttribute('method')),
                'user_id' => $toInt($payment->getAttribute('received_by')),
                'patient_id' => $toInt($payment->getAttribute('patient_id')),
            ]);

        /** @var Collection<int, CashRow> $legacy */
        $legacy = Consultation::query()
            ->where('is_paid', true)
            ->where('payment_amount_minor', '>', 0)
            ->whereDoesntHave('payments')
            ->whereBetween('consulted_at', [$from, $to])
            ->get(['id', 'payment_amount_minor', 'payment_adjustment_minor', 'consulted_at', 'payment_method', 'created_by', 'patient_id'])
            ->toBase()
            ->map(static fn (Consultation $consultation): array => [
                'at' => $consultation->consulted_at ?? $from,
                'amount' => max(0, (int) $consultation->payment_amount_minor - $consultation->payment_adjustment_minor),
                'mode' => $toString($consultation->getAttribute('payment_method')),
                'user_id' => $toInt($consultation->getAttribute('created_by')),
                'patient_id' => $consultation->patient_id,
            ]);

        /** @var Collection<int, CashRow> $rows */
        $rows = $ledger->concat($legacy)->values();

        return $rows;
    }

    /**
     * Charges raised in a window, with what each has collected so far.
     *
     * @return Collection<int, ChargeRow>
     */
    private function charges(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        /** @var Collection<int, ChargeRow> */
        return Consultation::query()
            ->whereNotNull('payment_amount_minor')
            ->whereBetween('consulted_at', [$from, $to])
            ->withSum('payments', 'amount_minor')
            ->get(['id', 'patient_id', 'consulted_at', 'motif', 'payment_service', 'payment_amount_minor', 'payment_adjustment_minor', 'is_paid'])
            ->toBase()
            ->map(static function (Consultation $consultation) use ($from): array {
                $service = $consultation->getAttribute('payment_service') ?: $consultation->getAttribute('motif');

                return [
                    'at' => $consultation->consulted_at ?? $from,
                    'charge' => (int) $consultation->payment_amount_minor,
                    'adjustment' => $consultation->payment_adjustment_minor,
                    'collected' => $consultation->collectedMinor(),
                    'outstanding' => $consultation->outstandingMinor(),
                    'service' => is_string($service) && trim($service) !== '' ? trim($service) : 'Consultation',
                    'patient_id' => $consultation->patient_id,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, CashRow>  $rows
     * @return Collection<int, CashRow>
     */
    private function cashWithin(Collection $rows, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $rows->filter(static fn (array $row): bool => $row['at']->betweenIncluded($from, $to))->values();
    }

    /**
     * The period, the comparison period and how far back to load data.
     * A period still running is compared with the same elapsed span of the
     * previous period, never with a complete one.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, isCurrent: bool, previousStart: CarbonImmutable, previousEnd: CarbonImmutable, loadFrom: CarbonImmutable}
     */
    private function window(int $year, ?int $month, CarbonImmutable $now): array
    {
        $start = $now->setDate($year, $month ?? 1, 1)->startOfDay();
        $end = $month !== null ? $start->endOfMonth() : $start->endOfYear();
        $isCurrent = $now->betweenIncluded($start, $end);
        $previousStart = $month !== null ? $start->subMonthNoOverflow() : $start->subYear();
        $previousEnd = match (true) {
            $month !== null && $isCurrent => $now->subMonthNoOverflow(),
            $month !== null => $previousStart->endOfMonth(),
            $isCurrent => $now->subYear(),
            default => $previousStart->endOfYear(),
        };

        return [
            'start' => $start,
            'end' => $end,
            'isCurrent' => $isCurrent,
            'previousStart' => $previousStart,
            'previousEnd' => $previousEnd,
            // One load also covers the chart's previous-year overlay.
            'loadFrom' => $month !== null ? $previousStart : $start->subYear(),
        ];
    }

    /**
     * @return Collection<int, ExpenseRow>
     */
    private function expenses(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        /** @var Collection<int, ExpenseRow> $rows */
        $rows = Expense::query()
            ->spentBetween($from->toDateString(), $to->toDateString())
            ->get(['category', 'amount_minor', 'spent_on'])
            ->toBase()
            ->map(static fn (Expense $expense): array => [
                // Midday, so a date-only expense falls inside any window
                // that includes that day.
                'at' => $expense->spent_on->setTime(12, 0),
                'amount' => $expense->amount_minor,
                'category' => $expense->category->value,
            ])
            ->values();

        return $rows;
    }

    /**
     * @param  Collection<int, ExpenseRow>  $rows
     * @return Collection<int, ExpenseRow>
     */
    private function expensesWithin(Collection $rows, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $rows->filter(static fn (array $row): bool => $row['at']->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay()))->values();
    }

    /**
     * @param  Collection<int, ChargeRow>  $rows
     * @return Collection<int, ChargeRow>
     */
    private function chargesWithin(Collection $rows, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $rows->filter(static fn (array $row): bool => $row['at']->betweenIncluded($from, $to))->values();
    }

    /**
     * @param  Collection<int, CashRow>  $cash
     * @param  Collection<int, ChargeRow>  $charges
     * @return Kpis
     */
    private function kpis(Collection $cash, Collection $charges): array
    {
        $gross = 0;
        $refunds = 0;
        $transactions = 0;

        foreach ($cash as $row) {
            if ($row['amount'] > 0) {
                $gross += $row['amount'];
                $transactions++;
            } else {
                $refunds -= $row['amount'];
            }
        }

        $billed = (int) $charges->sum('charge');
        $discounts = (int) $charges->sum('adjustment');
        $collectable = $billed - $discounts;
        $consultations = $charges->filter(static fn (array $row): bool => $row['charge'] > 0)->count();

        return [
            'collected' => $gross - $refunds,
            'gross_collected' => $gross,
            'refunds' => $refunds,
            'billed' => $billed,
            'discounts' => $discounts,
            'outstanding' => (int) $charges->sum('outstanding'),
            // Share of this period's charges that has been paid (whenever
            // the money arrived), after documented discounts.
            'collection_rate' => $collectable > 0
                ? round(min(100, (int) $charges->sum('collected') / $collectable * 100), 1)
                : null,
            'consultations' => $consultations,
            'average_ticket' => $consultations > 0 ? intdiv($billed, $consultations) : 0,
            'patients' => $charges->pluck('patient_id')->unique()->count(),
            'transactions' => $transactions,
        ];
    }

    /**
     * Money figures in major units for the page.
     *
     * @param  Kpis  $kpis
     * @return array<string, int|float|null>
     */
    private function major(array $kpis): array
    {
        $result = $kpis;

        foreach (['collected', 'gross_collected', 'refunds', 'billed', 'discounts', 'outstanding', 'average_ticket'] as $key) {
            $result[$key] = $kpis[$key] / 100;
        }

        return $result;
    }

    private function change(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Twelve months of the year with the previous year's collections for
     * comparison. Months after `$now` are flagged so the chart can leave
     * them empty rather than drawing a false drop to zero.
     *
     * @param  Collection<int, CashRow>  $cash
     * @param  Collection<int, ChargeRow>  $charges
     * @return list<array<string, mixed>>
     */
    private function monthlySeries(int $year, Collection $cash, Collection $charges, ?CarbonImmutable $now): array
    {
        /** @var array<int, array{key: string, month: int, label: string, collected: int, refunds: int, billed: int, discounts: int, consultations: int, previous_collected: int, future: bool}> $rows */
        $rows = [];

        foreach (self::MONTH_LABELS as $month => $label) {
            $rows[$month] = [
                'key' => sprintf('%d-%02d', $year, $month),
                'month' => $month,
                'label' => $label,
                'collected' => 0,
                'refunds' => 0,
                'billed' => 0,
                'discounts' => 0,
                'consultations' => 0,
                'previous_collected' => 0,
                'future' => $now !== null && $now->lessThan(CarbonImmutable::create($year, $month, 1)),
            ];
        }

        foreach ($cash as $row) {
            $month = (int) $row['at']->month;

            if (! isset($rows[$month])) {
                continue;
            }

            if ((int) $row['at']->year === $year) {
                $rows[$month]['collected'] += $row['amount'];
                $rows[$month]['refunds'] += max(0, -$row['amount']);
            } elseif ((int) $row['at']->year === $year - 1) {
                $rows[$month]['previous_collected'] += $row['amount'];
            }
        }

        foreach ($charges as $row) {
            $month = (int) $row['at']->month;

            if ((int) $row['at']->year === $year && isset($rows[$month])) {
                $rows[$month]['billed'] += $row['charge'];
                $rows[$month]['discounts'] += $row['adjustment'];
                $rows[$month]['consultations'] += $row['charge'] > 0 ? 1 : 0;
            }
        }

        return array_values(array_map(static fn (array $row): array => [
            ...$row,
            'collected' => $row['collected'] / 100,
            'refunds' => $row['refunds'] / 100,
            'billed' => $row['billed'] / 100,
            'discounts' => $row['discounts'] / 100,
            'previous_collected' => $row['previous_collected'] / 100,
        ], $rows));
    }

    /**
     * @param  Collection<int, CashRow>  $cash
     * @param  Collection<int, ChargeRow>  $charges
     * @return list<array<string, mixed>>
     */
    private function dailySeries(CarbonImmutable $start, CarbonImmutable $end, Collection $cash, Collection $charges, ?CarbonImmutable $now): array
    {
        /** @var array<string, array{key: string, day: int, label: string, weekday: string, collected: int, refunds: int, billed: int, consultations: int, future: bool}> $rows */
        $rows = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $rows[$day->toDateString()] = [
                'key' => $day->toDateString(),
                'day' => (int) $day->day,
                'label' => (string) $day->day,
                'weekday' => self::WEEKDAY_LABELS[(int) $day->isoWeekday()] ?? '',
                'collected' => 0,
                'refunds' => 0,
                'billed' => 0,
                'consultations' => 0,
                'future' => $now !== null && $day->greaterThan($now),
            ];
        }

        foreach ($cash as $row) {
            $key = $row['at']->toDateString();

            if (isset($rows[$key])) {
                $rows[$key]['collected'] += $row['amount'];
                $rows[$key]['refunds'] += max(0, -$row['amount']);
            }
        }

        foreach ($charges as $row) {
            $key = $row['at']->toDateString();

            if (isset($rows[$key])) {
                $rows[$key]['billed'] += $row['charge'];
                $rows[$key]['consultations'] += $row['charge'] > 0 ? 1 : 0;
            }
        }

        return array_values(array_map(static fn (array $row): array => [
            ...$row,
            'collected' => $row['collected'] / 100,
            'refunds' => $row['refunds'] / 100,
            'billed' => $row['billed'] / 100,
        ], $rows));
    }

    /**
     * @param  Collection<int, CashRow>  $cash
     * @return list<array{label: string, value: float|int, count: int}>
     */
    private function weekdaySeries(Collection $cash): array
    {
        /** @var array<int, array{label: string, value: int, count: int}> $rows */
        $rows = [];

        foreach (self::WEEKDAY_LABELS as $index => $label) {
            $rows[$index] = ['label' => $label, 'value' => 0, 'count' => 0];
        }

        foreach ($cash as $row) {
            $index = (int) $row['at']->isoWeekday();

            if (isset($rows[$index])) {
                $rows[$index]['value'] += $row['amount'];
                $rows[$index]['count'] += $row['amount'] > 0 ? 1 : 0;
            }
        }

        return array_values(array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'value' => $row['value'] / 100,
            'count' => $row['count'],
        ], $rows));
    }

    /**
     * @param  Collection<int, CashRow>  $cash
     * @return list<array{label: string, value: float|int, count: int, share: float}>
     */
    private function byMethod(Collection $cash): array
    {
        $total = max(1, (int) $cash->sum('amount'));
        /** @var array<string, array{label: string, value: int, count: int}> $groups */
        $groups = [];

        foreach ($cash as $row) {
            $label = $row['mode'] !== '' ? $row['mode'] : self::UNSPECIFIED_METHOD;
            $groups[$label] ??= ['label' => $label, 'value' => 0, 'count' => 0];
            $groups[$label]['value'] += $row['amount'];
            $groups[$label]['count'] += $row['amount'] > 0 ? 1 : 0;
        }

        $groups = array_filter($groups, static fn (array $group): bool => $group['value'] !== 0);
        usort($groups, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return array_map(static fn (array $group): array => [
            'label' => $group['label'],
            'value' => $group['value'] / 100,
            'count' => $group['count'],
            'share' => round($group['value'] / $total * 100, 1),
        ], $groups);
    }

    /**
     * @param  Collection<int, ChargeRow>  $charges
     * @return list<array{label: string, value: float|int, collected: float|int, count: int}>
     */
    private function byService(Collection $charges): array
    {
        /** @var array<string, array{label: string, value: int, collected: int, count: int}> $groups */
        $groups = [];

        foreach ($charges as $row) {
            if ($row['charge'] <= 0) {
                continue;
            }

            $key = mb_strtolower($row['service']);
            $groups[$key] ??= ['label' => $row['service'], 'value' => 0, 'collected' => 0, 'count' => 0];
            $groups[$key]['value'] += $row['charge'];
            $groups[$key]['collected'] += $row['collected'];
            $groups[$key]['count']++;
        }

        usort($groups, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return array_map(static fn (array $group): array => [
            'label' => $group['label'],
            'value' => $group['value'] / 100,
            'collected' => $group['collected'] / 100,
            'count' => $group['count'],
        ], array_slice($groups, 0, 8));
    }

    /**
     * @param  Collection<int, CashRow>  $cash
     * @return list<array{label: string, value: float|int, count: int}>
     */
    private function byPractitioner(Collection $cash): array
    {
        /** @var array<int, array{value: int, count: int}> $groups */
        $groups = [];

        foreach ($cash as $row) {
            $userId = $row['user_id'];
            $groups[$userId] ??= ['value' => 0, 'count' => 0];
            $groups[$userId]['value'] += $row['amount'];
            $groups[$userId]['count'] += $row['amount'] > 0 ? 1 : 0;
        }

        $names = User::query()
            ->whereIn('id', array_keys($groups))
            ->pluck('name', 'id');
        $rows = [];

        foreach ($groups as $userId => $group) {
            if ($group['value'] === 0) {
                continue;
            }

            $name = $names->get($userId);
            $rows[] = [
                'label' => is_string($name) ? $name : 'Non attribué',
                'value' => $group['value'],
                'count' => $group['count'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return array_map(static fn (array $row): array => [
            ...$row,
            'value' => $row['value'] / 100,
        ], $rows);
    }

    /**
     * @param  Collection<int, CashRow>  $cash
     * @return list<array{patient_id: int, patient_name: string, patient_number: string|null, value: float|int, count: int}>
     */
    private function topPatients(Collection $cash): array
    {
        /** @var array<int, array{value: int, count: int}> $groups */
        $groups = [];

        foreach ($cash as $row) {
            $groups[$row['patient_id']] ??= ['value' => 0, 'count' => 0];
            $groups[$row['patient_id']]['value'] += $row['amount'];
            $groups[$row['patient_id']]['count'] += $row['amount'] > 0 ? 1 : 0;
        }

        $groups = array_filter($groups, static fn (array $group): bool => $group['value'] > 0);
        uasort($groups, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $groups = array_slice($groups, 0, 5, true);

        $patients = Patient::query()
            ->withTrashed()
            ->whereIn('id', array_keys($groups))
            ->get(['id', 'first_name', 'last_name', 'patient_number'])
            ->keyBy('id');
        $rows = [];

        foreach ($groups as $patientId => $group) {
            $patient = $patients->get($patientId);
            $rows[] = [
                'patient_id' => $patientId,
                'patient_name' => $patient instanceof Patient ? $patient->full_name : 'Patient supprimé',
                'patient_number' => $patient instanceof Patient ? $patient->patient_number : null,
                'value' => $group['value'] / 100,
                'count' => $group['count'],
            ];
        }

        return $rows;
    }
}
