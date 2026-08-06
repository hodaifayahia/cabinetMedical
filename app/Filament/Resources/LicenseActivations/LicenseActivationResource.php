<?php

namespace App\Filament\Resources\LicenseActivations;

use App\Enums\PermissionName;
use App\Filament\Resources\LicenseActivations\Pages\ListLicenseActivations;
use App\Filament\Resources\LicenseActivations\Schemas\LicenseActivationForm;
use App\Filament\Resources\LicenseActivations\Tables\LicenseActivationsTable;
use App\Models\LicenseActivation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LicenseActivationResource extends Resource
{
    protected static ?string $model = LicenseActivation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Licences & ventes';

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return 'activation';
    }

    public static function getPluralModelLabel(): string
    {
        return 'activations';
    }

    public static function getNavigationLabel(): string
    {
        return 'Activations';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(PermissionName::CONFIGURATION_LICENSING_MANAGE->value);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return LicenseActivationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LicenseActivationsTable::configure($table);
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
            'index' => ListLicenseActivations::route('/'),
        ];
    }
}
