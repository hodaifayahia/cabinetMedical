<?php

namespace App\Filament\Pages;

use App\Enums\AiUsagePeriod;
use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Filament\Resources\Cabinets\Tables\CabinetAiCredits;
use App\Filament\Widgets\AiCabinetWallets;
use App\Filament\Widgets\AiDailyConsumption;
use App\Filament\Widgets\AiFeatureBreakdown;
use App\Filament\Widgets\AiUsageStats;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The AI back office: how much the platform consumed, where it went, and
 * each cabinet's wallet with the recharge at hand.
 */
class AiConsumption extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'ia';

    protected static ?string $title = 'Consommation IA';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence artificielle';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public function getSubheading(): ?string
    {
        return 'Suivez les crédits et les tokens consommés par l’assistant, et rechargez les cabinets qui en ont besoin.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Période')
                ->options(AiUsagePeriod::options())
                ->default(AiUsagePeriod::default()->value)
                ->selectablePlaceholder(false)
                ->native(false),
        ]);
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'xl' => 3,
        ];
    }

    public function getWidgets(): array
    {
        return [
            AiUsageStats::class,
            AiDailyConsumption::class,
            AiFeatureBreakdown::class,
            AiCabinetWallets::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CabinetAiCredits::rechargeAction(),
            Action::make('openLedger')
                ->label('Journal des crédits')
                ->icon(Heroicon::OutlinedListBullet)
                ->color('gray')
                ->url(AiUsageResource::getUrl('index')),
        ];
    }
}
