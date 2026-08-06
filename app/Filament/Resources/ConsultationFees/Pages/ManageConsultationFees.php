<?php

namespace App\Filament\Resources\ConsultationFees\Pages;

use App\Filament\Resources\ConsultationFees\ConsultationFeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageConsultationFees extends ManageRecords
{
    protected static string $resource = ConsultationFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
