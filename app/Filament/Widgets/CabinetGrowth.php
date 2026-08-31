<?php

namespace App\Filament\Widgets;

use App\Models\Cabinet;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Twelve weeks of sign-ups against activations. The gap between the two
 * lines is the queue the operator is actually working through.
 */
class CabinetGrowth extends ChartWidget
{
    private const WEEKS = 12;

    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected ?string $maxHeight = '16rem';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public function getHeading(): ?string
    {
        return 'Inscriptions et activations';
    }

    public function getDescription(): ?string
    {
        return 'Douze dernières semaines. Un écart persistant signale des cabinets en attente de licence.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $weekStarts = self::weekStarts();
        $since = $weekStarts[0];

        // Bucketing in PHP keeps this free of database-specific date
        // functions; the platform holds one row per cabinet, not per event.
        $cabinets = Cabinet::query()
            ->where(fn (Builder $recent): Builder => $recent
                ->where('created_at', '>=', $since)
                ->orWhere('activated_at', '>=', $since))
            ->get(['created_at', 'activated_at']);

        $registered = array_fill(0, self::WEEKS, 0);
        $activated = array_fill(0, self::WEEKS, 0);

        foreach ($cabinets as $cabinet) {
            $registeredIndex = self::weekIndex($weekStarts, $cabinet->created_at);
            if ($registeredIndex !== null) {
                $registered[$registeredIndex]++;
            }

            $activatedIndex = self::weekIndex($weekStarts, $cabinet->activated_at);
            if ($activatedIndex !== null) {
                $activated[$activatedIndex]++;
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Inscriptions',
                    'data' => $registered,
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.14)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => 'Activations',
                    'data' => $activated,
                    'borderColor' => '#0d9488',
                    'backgroundColor' => 'rgba(13, 148, 136, 0.14)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => array_map(
                static fn (CarbonImmutable $week): string => $week->format('d/m'),
                $weekStarts,
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    /** @return list<CarbonImmutable> */
    private static function weekStarts(): array
    {
        $current = CarbonImmutable::now()->startOfWeek();

        return array_map(
            static fn (int $offset): CarbonImmutable => $current->subWeeks($offset),
            range(self::WEEKS - 1, 0),
        );
    }

    /** @param list<CarbonImmutable> $weekStarts */
    private static function weekIndex(array $weekStarts, mixed $moment): ?int
    {
        if ($moment === null) {
            return null;
        }

        $timestamp = CarbonImmutable::parse($moment)->startOfWeek();

        foreach ($weekStarts as $index => $weekStart) {
            if ($timestamp->equalTo($weekStart)) {
                return $index;
            }
        }

        return null;
    }
}
