<?php

namespace App\Filament\Resources\DesktopDownloadLeads\Pages;

use App\Filament\Resources\DesktopDownloadLeads\DesktopDownloadLeadResource;
use Filament\Resources\Pages\ListRecords;

class ListDesktopDownloadLeads extends ListRecords
{
    protected static string $resource = DesktopDownloadLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
