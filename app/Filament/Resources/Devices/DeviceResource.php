<?php

namespace App\Filament\Resources\Devices;

use App\Enums\PermissionName;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Resources\Devices\Schemas\DeviceForm;
use App\Filament\Resources\Devices\Tables\DevicesTable;
use App\Models\Device;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Licences & ventes';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getModelLabel(): string
    {
        return 'appareil';
    }

    public static function getPluralModelLabel(): string
    {
        return 'appareils';
    }

    public static function getNavigationLabel(): string
    {
        return 'Appareils';
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
        return DeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevicesTable::configure($table);
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
            'index' => ListDevices::route('/'),
        ];
    }
}
