<?php

namespace App\Filament\Resources\Licenses;

use App\Enums\PermissionName;
use App\Filament\Resources\Licenses\Pages\CreateLicense;
use App\Filament\Resources\Licenses\Pages\EditLicense;
use App\Filament\Resources\Licenses\Pages\ListLicenses;
use App\Filament\Resources\Licenses\Schemas\LicenseForm;
use App\Filament\Resources\Licenses\Tables\LicensesTable;
use App\Models\License;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LicenseResource extends Resource
{
    protected static ?string $model = License::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Licences & ventes';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'license_id';

    public static function getModelLabel(): string
    {
        return 'licence';
    }

    public static function getPluralModelLabel(): string
    {
        return 'licences';
    }

    public static function getNavigationLabel(): string
    {
        return 'Licences';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(PermissionName::CONFIGURATION_LICENSING_MANAGE->value);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) License::query()->where('status', 'active')->count();
    }

    public static function form(Schema $schema): Schema
    {
        return LicenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LicensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLicenses::route('/'),
            'create' => CreateLicense::route('/create'),
            'edit' => EditLicense::route('/{record}/edit'),
        ];
    }
}
