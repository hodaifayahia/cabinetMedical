<?php

namespace App\Filament\Resources\ApplicationEvents;

use App\Enums\PermissionName;
use App\Filament\Resources\ApplicationEvents\Pages\ListApplicationEvents;
use App\Filament\Resources\ApplicationEvents\Schemas\ApplicationEventForm;
use App\Filament\Resources\ApplicationEvents\Tables\ApplicationEventsTable;
use App\Models\ApplicationEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ApplicationEventResource extends Resource
{
    protected static ?string $model = ApplicationEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Journal & activité';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return 'événement';
    }

    public static function getPluralModelLabel(): string
    {
        return 'événements';
    }

    public static function getNavigationLabel(): string
    {
        return 'Événements système';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(PermissionName::CONFIGURATION_DIAGNOSTICS_VIEW->value);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ApplicationEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApplicationEventsTable::configure($table);
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
            'index' => ListApplicationEvents::route('/'),
        ];
    }
}
