<?php

namespace App\Filament\Resources\AiUsages;

use App\Filament\Resources\AiUsages\Pages\ListAiUsages;
use App\Filament\Resources\AiUsages\Tables\AiUsagesTable;
use App\Models\AiUsage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only ledger of every AI spend and recharge, across all cabinets.
 */
class AiUsageResource extends Resource
{
    protected static ?string $model = AiUsage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Clients';

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return 'utilisation IA';
    }

    public static function getPluralModelLabel(): string
    {
        return 'utilisations IA';
    }

    public static function getNavigationLabel(): string
    {
        return 'Consommation IA';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AiUsagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiUsages::route('/'),
        ];
    }
}
