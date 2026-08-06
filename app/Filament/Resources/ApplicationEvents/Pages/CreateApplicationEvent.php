<?php

namespace App\Filament\Resources\ApplicationEvents\Pages;

use App\Filament\Resources\ApplicationEvents\ApplicationEventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApplicationEvent extends CreateRecord
{
    protected static string $resource = ApplicationEventResource::class;
}
