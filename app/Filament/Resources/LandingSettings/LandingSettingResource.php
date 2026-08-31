<?php

namespace App\Filament\Resources\LandingSettings;

use App\Filament\Resources\LandingSettings\Pages\ManageLandingSettings;
use App\Models\LandingSetting;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class LandingSettingResource extends Resource
{
    protected static ?string $model = LandingSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Site public';

    protected static ?int $navigationSort = 11;

    /**
     * The landing texts that can be overridden without redeploying the
     * frontend. The public page falls back to its built-in copy when a key
     * has no stored value for the visitor's language.
     */
    public const KEY_OPTIONS = [
        'requirements_title' => 'Prérequis — titre',
        'requirements_subtitle' => 'Prérequis — sous-titre',
        'contact_phone' => 'Contact — téléphone',
        'contact_email' => 'Contact — e-mail',
        'contact_hours' => 'Contact — horaires',
    ];

    public const LOCALE_OPTIONS = [
        LandingSetting::ALL_LOCALES => 'Toutes les langues',
        'fr' => 'Français',
        'en' => 'English',
        'ar' => 'العربية',
    ];

    public static function getNavigationLabel(): string
    {
        return 'Textes de la landing page';
    }

    public static function getModelLabel(): string
    {
        return 'texte';
    }

    public static function getPluralModelLabel(): string
    {
        return 'textes';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_platform_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('key')
                    ->label('Texte')
                    ->options(self::KEY_OPTIONS)
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('locale', (string) ($get('locale') ?? LandingSetting::ALL_LOCALES)),
                    ),
                Select::make('locale')
                    ->label('Langue')
                    ->options(self::LOCALE_OPTIONS)
                    ->default(LandingSetting::ALL_LOCALES)
                    ->helperText('« Toutes les langues » convient au téléphone ou à l’e-mail ; les textes traduits se saisissent langue par langue.')
                    ->required(),
                Textarea::make('value')
                    ->label('Valeur affichée')
                    ->rows(3)
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Texte')
                    ->formatStateUsing(static fn (string $state): string => self::KEY_OPTIONS[$state] ?? $state)
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('locale')
                    ->label('Langue')
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::LOCALE_OPTIONS[$state] ?? $state),
                TextColumn::make('value')
                    ->label('Valeur')
                    ->limit(60),
                TextColumn::make('updated_at')
                    ->label('Modifié')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('key')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLandingSettings::route('/'),
        ];
    }
}
