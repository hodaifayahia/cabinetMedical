<?php

namespace App\Filament\Resources\AiUsages\Pages;

use App\Filament\Resources\AiUsages\AiUsageResource;
use Filament\Resources\Pages\ListRecords;

class ListAiUsages extends ListRecords
{
    protected static string $resource = AiUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
