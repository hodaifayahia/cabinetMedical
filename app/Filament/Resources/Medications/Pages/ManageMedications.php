<?php

namespace App\Filament\Resources\Medications\Pages;

use App\Filament\Resources\Medications\MedicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMedications extends ManageRecords
{
    protected static string $resource = MedicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
