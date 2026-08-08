<?php

namespace App\Filament\Resources\LicenseTypes\Pages;

use App\Filament\Resources\LicenseTypes\LicenseTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLicenseType extends EditRecord
{
    protected static string $resource = LicenseTypeResource::class;
    protected function getHeaderActions(): array { return [DeleteAction::make()]; }
}
