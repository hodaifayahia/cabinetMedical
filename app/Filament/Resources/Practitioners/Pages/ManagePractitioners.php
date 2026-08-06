<?php

namespace App\Filament\Resources\Practitioners\Pages;

use App\Filament\Resources\Practitioners\PractitionerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePractitioners extends ManageRecords
{
    protected static string $resource = PractitionerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
