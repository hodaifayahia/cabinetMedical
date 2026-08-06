<?php

namespace App\Filament\Resources\ApplicationEvents\Pages;

use App\Filament\Resources\ApplicationEvents\ApplicationEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApplicationEvents extends ListRecords
{
    protected static string $resource = ApplicationEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
