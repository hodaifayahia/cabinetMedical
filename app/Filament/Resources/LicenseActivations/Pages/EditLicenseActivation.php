<?php

namespace App\Filament\Resources\LicenseActivations\Pages;

use App\Filament\Resources\LicenseActivations\LicenseActivationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLicenseActivation extends EditRecord
{
    protected static string $resource = LicenseActivationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
