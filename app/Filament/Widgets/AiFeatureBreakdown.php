<?php

namespace App\Filament\Widgets;

use App\Enums\AiFeature;
use App\Enums\AiUsagePeriod;
use App\Services\Ai\AiUsageReport;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Which assistant actions the credits went to. The features are named on the
 * axis, so the bars share one colour instead of a legend.
 */
class AiFeatureBreakdown extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    // No 5 s polling (Filament's default): the ledger aggregates would re-run
    // for as long as the page is open.
    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '16rem';

    public static function canView(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public function getHeading(): ?string
    {
        return 'Par fonctionnalité';
    }

    public function getDescription(): ?string
    {
        return 'Crédits consommés par type d’action.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $features = AiUsageReport::for(AiUsagePeriod::fromFilter($this->pageFilters['period'] ?? null))->byFeature();

        return [
            'datasets' => [
                [
                    'label' => 'Crédits',
                    'data' => array_values(array_map(static fn (array $row): int => $row['credits'], $features)),
                    'backgroundColor' => '#0d9488',
                    'hoverBackgroundColor' => '#0f766e',
                    'borderRadius' => 4,
                    'maxBarThickness' => 22,
                ],
            ],
            'labels' => array_map(
                static fn (string $feature): string => AiFeature::tryFrom($feature)?->shortLabel() ?? $feature,
                array_keys($features),
            ),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            // Square, so the narrow card comes out as tall as the daily chart
            // beside it once both hit the shared max height.
            'aspectRatio' => 1,
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
                'y' => [
                    'grid' => ['display' => false],
                    'ticks' => ['autoSkip' => false],
                ],
            ],
        ];
    }
}
