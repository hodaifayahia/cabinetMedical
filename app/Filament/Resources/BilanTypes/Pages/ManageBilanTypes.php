<?php

namespace App\Filament\Resources\BilanTypes\Pages;

use App\Filament\Resources\BilanTypes\BilanTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBilanTypes extends ManageRecords
{
    protected static string $resource = BilanTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
