<?php

namespace App\Filament\Widgets;

use App\Enums\AiUsagePeriod;
use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Filament\Resources\Cabinets\Tables\CabinetAiCredits;
use App\Models\AiUsage;
use App\Models\Cabinet;
use App\Services\Ai\AiUsageReport;
use App\Support\FrenchNumber;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * Every cabinet's wallet next to what it consumed over the page's period,
 * heaviest consumers first, with the recharge one click away on each row.
 */
class AiCabinetWallets extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    #[On(CabinetAiCredits::UPDATED_EVENT)]
    public function refreshAfterRecharge(): void {}

    public function table(Table $table): Table
    {
        $period = AiUsagePeriod::fromFilter($this->pageFilters['period'] ?? null);
        $report = AiUsageReport::for($period);
        $spends = fn (): Builder => $report->lines()
            ->whereColumn('ai_usages.cabinet_id', 'cabinets.id')
            ->where('ai_usages.status', AiUsage::STATUS_CHARGED);

        return $table
            ->query(
                Cabinet::query()
                    ->with('owner:id,name,email')
                    ->select('cabinets.*')
                    ->addSelect([
                        'period_credits' => $spends()->selectRaw('COALESCE(-SUM(ai_usages.credits), 0)'),
                        'period_calls' => $spends()->selectRaw('COUNT(*)'),
                        'period_tokens' => $spends()->selectRaw('COALESCE(SUM(ai_usages.prompt_tokens), 0) + COALESCE(SUM(ai_usages.completion_tokens), 0)'),
                        'last_used_at' => AiUsage::query()
                            ->whereColumn('ai_usages.cabinet_id', 'cabinets.id')
                            ->where('ai_usages.status', AiUsage::STATUS_CHARGED)
                            ->selectRaw('MAX(ai_usages.created_at)'),
                    ]),
            )
            ->heading('Portefeuilles des cabinets')
            ->description('Solde et consommation de chaque cabinet — '.mb_strtolower($period->label()).'. Rechargez depuis la ligne ou sélectionnez plusieurs cabinets.')
            ->headerActions([
                Action::make('openLedger')
                    ->label('Journal détaillé')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->url(AiUsageResource::getUrl('index')),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Cabinet')
                    ->weight(FontWeight::SemiBold)
                    ->searchable(),
                TextColumn::make('owner.name')
                    ->label('Médecin')
                    ->description(fn (Cabinet $record): ?string => $record->owner?->email)
                    ->searchable(['name', 'email'])
                    ->placeholder('—'),
                CabinetAiCredits::column()
                    ->label('Solde'),
                TextColumn::make('period_credits')
                    ->label('Consommé')
                    ->formatStateUsing(fn ($state): string => FrenchNumber::format((int) $state).' crédits')
                    ->description(fn (Cabinet $record): string => FrenchNumber::format((int) $record->getAttribute('period_calls'))
                        .((int) $record->getAttribute('period_calls') > 1 ? ' appels' : ' appel'))
                    ->sortable(),
                TextColumn::make('period_tokens')
                    ->label('Tokens')
                    ->formatStateUsing(fn ($state): string => FrenchNumber::compact((int) $state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_used_at')
                    ->label('Dernier usage')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->placeholder('Jamais')
                    ->sortable(),
            ])
            ->defaultSort('period_credits', 'desc')
            ->filters([
                Filter::make('low_balance')
                    ->label('À recharger (moins de '.CabinetAiCredits::LOW_BALANCE.' crédits)')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('cabinets.ai_enabled', true)
                        ->where('cabinets.ai_credits', '<', CabinetAiCredits::LOW_BALANCE)),
                Filter::make('used_in_period')
                    ->label('Ont utilisé l’IA sur la période')
                    ->query(fn (Builder $query): Builder => $query->whereExists($spends())),
                Filter::make('ai_disabled')
                    ->label('Assistant IA désactivé')
                    ->query(fn (Builder $query): Builder => $query->where('cabinets.ai_enabled', false)),
            ])
            ->recordActions([
                CabinetAiCredits::manageAction()
                    ->label('Recharger'),
                CabinetAiCredits::historyAction()
                    ->iconButton()
                    ->tooltip('Historique IA du cabinet'),
            ])
            ->toolbarActions([
                CabinetAiCredits::bulkRechargeAction(),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Aucun cabinet')
            ->emptyStateDescription('Les cabinets apparaissent ici dès leur inscription.')
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2);
    }
}
