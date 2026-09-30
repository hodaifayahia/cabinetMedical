<?php

namespace App\Filament\Resources\AiUsages\Pages;

use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Filament\Resources\Cabinets\Tables\CabinetAiCredits;
use Filament\Resources\Pages\ListRecords;

class ListAiUsages extends ListRecords
{
    protected static string $resource = AiUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CabinetAiCredits::rechargeAction(),
        ];
    }
}
