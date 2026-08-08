<?php

namespace App\Filament\Resources\LicenseTypes\Pages;

use App\Filament\Resources\LicenseTypes\LicenseTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLicenseTypes extends ListRecords
{
    protected static string $resource = LicenseTypeResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Nouveau type')]; }
}
