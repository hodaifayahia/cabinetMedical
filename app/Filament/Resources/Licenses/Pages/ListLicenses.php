<?php

namespace App\Filament\Resources\Licenses\Pages;

use App\Filament\Resources\Licenses\LicenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ListLicenses extends ListRecords
{
    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nouvelle licence'),
            CreateAction::make('grantFree')
                ->label('Offrir une licence gratuite')
                ->icon(Heroicon::OutlinedGift)
                ->color('success')
                ->mutateDataUsing(function (array $data): array {
                    $data['edition'] = 'complimentary';
                    $data['status'] = 'active';
                    $data['product'] ??= 'ClickDZ Doctor';
                    $data['license_id'] ??= 'FREE-'.Str::upper(Str::random(10));
                    $data['issued_at'] ??= now();

                    return $data;
                }),
        ];
    }
}
