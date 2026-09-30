<?php

namespace App\Filament\Widgets;

use App\Enums\AiUsagePeriod;
use App\Services\Ai\AiUsageReport;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Credits spent per day across every cabinet. One series, so no legend: the
 * heading names it and the tooltip carries the value.
 */
class AiDailyConsumption extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    // No 5 s polling (Filament's default): the ledger aggregates would re-run
    // for as long as the page is open.
    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '16rem';

    // Two of the page's three columns on wide screens; alone on a row below.
    protected int|string|array $columnSpan = [
        'default' => 1,
        'xl' => 2,
    ];

    public static function canView(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public function getHeading(): ?string
    {
        return 'Crédits consommés par jour';
    }

    public function getDescription(): ?string
    {
        return AiUsagePeriod::fromFilter($this->pageFilters['period'] ?? null)->label().', tous cabinets confondus.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $daily = AiUsageReport::for(AiUsagePeriod::fromFilter($this->pageFilters['period'] ?? null))->daily();

        return [
            'datasets' => [
                [
                    'label' => 'Crédits',
                    'data' => array_values($daily),
                    'backgroundColor' => '#0d9488',
                    'hoverBackgroundColor' => '#0f766e',
                    'borderRadius' => 4,
                    'maxBarThickness' => 28,
                ],
            ],
            'labels' => array_map(
                static fn (string $day): string => CarbonImmutable::parse($day)->format('d/m'),
                array_keys($daily),
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => ['maxRotation' => 0, 'autoSkipPadding' => 12],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }
}
