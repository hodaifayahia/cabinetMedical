<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ActivationKeys\ActivationKeyResource;
use App\Filament\Resources\Cabinets\CabinetResource;
use App\Filament\Widgets\AdminOverview;
use App\Filament\Widgets\CabinetGrowth;
use App\Filament\Widgets\LicenceExpiryRadar;
use App\Filament\Widgets\PendingCabinets;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class PlatformDashboard extends Dashboard
{
    protected static ?string $title = 'Pilotage de la plateforme';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public function getSubheading(): ?string
    {
        return 'Activez les nouveaux cabinets, suivez les clés d’activation en circulation et anticipez les renouvellements.';
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    /**
     * Ordered as the day is worked: the numbers, then the trend, then the
     * two lists that carry an action.
     */
    public function getWidgets(): array
    {
        return [
            AdminOverview::class,
            CabinetGrowth::class,
            PendingCabinets::class,
            LicenceExpiryRadar::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageCabinets')
                ->label('Gérer les cabinets')
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->url(CabinetResource::getUrl('index')),
            Action::make('activationKeys')
                ->label('Clés d’activation')
                ->icon(Heroicon::OutlinedTicket)
                ->color('gray')
                ->url(ActivationKeyResource::getUrl('index')),
            Action::make('aiConsumption')
                ->label('Consommation IA')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->url(AiConsumption::getUrl()),
        ];
    }
}
