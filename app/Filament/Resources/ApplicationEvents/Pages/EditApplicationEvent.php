<?php

namespace App\Filament\Resources\ApplicationEvents\Pages;

use App\Filament\Resources\ApplicationEvents\ApplicationEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditApplicationEvent extends EditRecord
{
    protected static string $resource = ApplicationEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
