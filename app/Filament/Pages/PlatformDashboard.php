<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Cabinets\CabinetResource;
use App\Filament\Widgets\AdminOverview;
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
        return 'Activez les nouveaux cabinets et suivez leur état commercial depuis un espace unique.';
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            AdminOverview::class,
            PendingCabinets::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageCabinets')
                ->label('Gérer les cabinets')
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->url(CabinetResource::getUrl('index')),
        ];
    }
}
