<?php

namespace App\Filament\Resources\Cabinets\Pages;

use App\Filament\Resources\Cabinets\CabinetResource;
use Filament\Resources\Pages\ListRecords;

class ListCabinets extends ListRecords
{
    protected static string $resource = CabinetResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
