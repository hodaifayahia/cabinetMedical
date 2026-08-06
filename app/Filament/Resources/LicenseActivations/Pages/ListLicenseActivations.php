<?php

namespace App\Filament\Resources\LicenseActivations\Pages;

use App\Filament\Resources\LicenseActivations\LicenseActivationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLicenseActivations extends ListRecords
{
    protected static string $resource = LicenseActivationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
