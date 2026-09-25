<?php

namespace App\Filament\Resources\MedicalSpecialties\Pages;

use App\Filament\Resources\MedicalSpecialties\MedicalSpecialtyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMedicalSpecialties extends ListRecords
{
    protected static string $resource = MedicalSpecialtyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvelle spécialité')];
    }
}
