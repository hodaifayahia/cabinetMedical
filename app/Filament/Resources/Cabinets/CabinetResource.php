<?php

namespace App\Filament\Resources\Cabinets;

use App\Enums\CabinetStatus;
use App\Filament\Resources\Cabinets\Pages\ListCabinets;
use App\Filament\Resources\Cabinets\Tables\CabinetsTable;
use App\Models\Cabinet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CabinetResource extends Resource
{
    protected static ?string $model = Cabinet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Plateforme';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return 'cabinet';
    }

    public static function getPluralModelLabel(): string
    {
        return 'cabinets';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cabinets';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    /**
     * Badge shows the number of cabinets awaiting activation.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = Cabinet::query()->where('status', CabinetStatus::PENDING->value)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return CabinetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCabinets::route('/'),
        ];
    }
}
