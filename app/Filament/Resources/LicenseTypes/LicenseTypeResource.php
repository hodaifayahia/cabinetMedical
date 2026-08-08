<?php

namespace App\Filament\Resources\LicenseTypes;

use App\Filament\Resources\LicenseTypes\Pages\CreateLicenseType;
use App\Filament\Resources\LicenseTypes\Pages\EditLicenseType;
use App\Filament\Resources\LicenseTypes\Pages\ListLicenseTypes;
use App\Models\LicenseType;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LicenseTypeResource extends Resource
{
    protected static ?string $model = LicenseType::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;
    protected static string|UnitEnum|null $navigationGroup = 'Licences & ventes';
    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string { return 'Types de licences'; }
    public static function getModelLabel(): string { return 'type de licence'; }
    public static function getPluralModelLabel(): string { return 'types de licences'; }
    public static function canAccess(): bool { return auth()->user()?->is_platform_admin === true; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(120),
            TextInput::make('slug')->label('Identifiant')->required()->alphaDash()->unique(ignoreRecord: true),
            TextInput::make('duration_days')->label('Durée (jours)')->numeric()->minValue(1)->helperText('Laisser vide pour une licence à vie.'),
            Toggle::make('is_active')->label('Disponible')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Type')->searchable()->sortable()->weight('bold'),
            TextColumn::make('duration_days')->label('Durée')->formatStateUsing(fn (?int $state): string => $state === null ? 'À vie' : $state.' jours'),
            IconColumn::make('is_active')->label('Disponible')->boolean(),
            TextColumn::make('created_at')->label('Créé le')->dateTime()->sortable(),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLicenseTypes::route('/'),
            'create' => CreateLicenseType::route('/create'),
            'edit' => EditLicenseType::route('/{record}/edit'),
        ];
    }
}
