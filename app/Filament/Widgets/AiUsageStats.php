<?php

namespace App\Filament\Widgets;

use App\Enums\AiUsagePeriod;
use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Filament\Resources\Cabinets\Tables\CabinetAiCredits;
use App\Models\Cabinet;
use App\Services\Ai\AiUsageReport;
use App\Support\FrenchNumber;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * The headline numbers of the AI page: what the provider consumed (tokens),
 * what the cabinets paid (credits), and which wallets need topping up.
 */
class AiUsageStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    // Filament polls every 5 s by default, re-running the ledger aggregates
    // for as long as the page is open. A recharge already refreshes these
    // figures through its event, and the period filter re-renders them.
    protected ?string $pollingInterval = null;

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'xl' => 3,
    ];

    public static function canView(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    #[On(CabinetAiCredits::UPDATED_EVENT)]
    public function refreshAfterRecharge(): void {}

    protected function getStats(): array
    {
        $period = AiUsagePeriod::fromFilter($this->pageFilters['period'] ?? null);
        $report = AiUsageReport::for($period);
        $totals = $report->totals();
        $tokens = $totals['prompt_tokens'] + $totals['completion_tokens'];

        $wallets = Cabinet::query()
            ->where('ai_enabled', true)
            ->selectRaw('COALESCE(SUM(ai_credits), 0) AS outstanding')
            ->selectRaw('COUNT(*) AS enabled')
            ->selectRaw('SUM(CASE WHEN ai_credits < ? THEN 1 ELSE 0 END) AS low', [CabinetAiCredits::LOW_BALANCE])
            ->selectRaw('SUM(CASE WHEN ai_credits <= 0 THEN 1 ELSE 0 END) AS empty')
            ->toBase()
            ->first();

        $enabled = (int) ($wallets->enabled ?? 0);
        $low = (int) ($wallets->low ?? 0);
        $empty = (int) ($wallets->empty ?? 0);
        $ledgerUrl = AiUsageResource::getUrl('index');

        return [
            Stat::make('Crédits consommés', FrenchNumber::format($totals['credits_spent']))
                ->description(FrenchNumber::format($totals['calls']).($totals['calls'] > 1 ? ' appels IA · ' : ' appel IA · ').mb_strtolower($period->label()))
                ->descriptionIcon(Heroicon::OutlinedSparkles)
                ->chart(array_map(floatval(...), array_values($report->daily())))
                ->color('primary')
                ->url($ledgerUrl),
            Stat::make('Tokens consommés', FrenchNumber::compact($tokens))
                ->description('Entrée '.FrenchNumber::compact($totals['prompt_tokens']).' · sortie '.FrenchNumber::compact($totals['completion_tokens']))
                ->descriptionIcon(Heroicon::OutlinedCpuChip)
                ->color('info'),
            Stat::make('Crédits ajoutés', '+'.FrenchNumber::format($totals['credits_added']))
                ->description($totals['recharges'].($totals['recharges'] > 1 ? ' recharges' : ' recharge').' sur la période')
                ->descriptionIcon(Heroicon::OutlinedArrowUpCircle)
                ->color($totals['credits_added'] > 0 ? 'success' : 'gray'),
            Stat::make('Cabinets utilisateurs', (string) $totals['cabinets'])
                ->description('sur '.$enabled.' avec l’assistant activé')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice2)
                ->color('gray'),
            Stat::make('Crédits en circulation', FrenchNumber::format((int) ($wallets->outstanding ?? 0)))
                ->description('Soldes restants des cabinets')
                ->descriptionIcon(Heroicon::OutlinedWallet)
                ->color('gray'),
            Stat::make('Cabinets à recharger', (string) $low)
                ->description($empty > 0 ? "dont {$empty} à zéro" : 'Moins de '.CabinetAiCredits::LOW_BALANCE.' crédits')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color(match (true) {
                    $empty > 0 => 'danger',
                    $low > 0 => 'warning',
                    default => 'gray',
                }),
        ];
    }
}
