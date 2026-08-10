<?php

namespace App\Filament\Resources\LandingSections;

use App\Filament\Resources\LandingSections\Pages\ManageLandingSections;
use App\Models\LandingSection;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LandingSectionResource extends Resource
{
    protected static ?string $model = LandingSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|\UnitEnum|null $navigationGroup = 'Site public';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'Contenu de la landing page';
    }

    public static function getModelLabel(): string
    {
        return 'section';
    }

    public static function getPluralModelLabel(): string
    {
        return 'sections';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_platform_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('locale')
                    ->label('Langue')
                    ->options([
                        'fr' => 'Français',
                        'en' => 'English',
                        'ar' => 'العربية',
                    ])
                    ->default('fr')
                    ->required(),
                Select::make('section_type')
                    ->label('Type de section')
                    ->options([
                        'content' => 'Contenu',
                        'feature' => 'Fonctionnalité',
                        'cta' => 'Appel à l’action',
                    ])
                    ->default('content')
                    ->required(),
                TextInput::make('slug')
                    ->label('Identifiant')
                    ->helperText('Utilisé comme ancre HTML. Exemple : securite.')
                    ->required()
                    ->maxLength(120),
                TextInput::make('eyebrow')
                    ->label('Sur-titre')
                    ->maxLength(160),
                TextInput::make('title')
                    ->label('Titre')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->label('Description')
                    ->rows(5)
                    ->columnSpanFull(),
                Repeater::make('items')
                    ->label('Cartes ou éléments')
                    ->schema([
                        TextInput::make('title')
                            ->label('Titre')
                            ->required(),
                        Textarea::make('body')
                            ->label('Description')
                            ->rows(3),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
                TextInput::make('cta_label')
                    ->label('Texte du bouton'),
                TextInput::make('cta_url')
                    ->label('Lien du bouton')
                    ->url(),
                TextInput::make('image_url')
                    ->label('Image (URL publique)')
                    ->url(),
                TextInput::make('sort_order')
                    ->label('Ordre')
                    ->numeric()
                    ->default(100)
                    ->required(),
                Toggle::make('is_published')
                    ->label('Publié')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Titre')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('locale')
                    ->label('Langue')
                    ->badge(),
                TextColumn::make('section_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('sort_order')
                    ->label('Ordre')
                    ->sortable(),
                IconColumn::make('is_published')
                    ->label('Publié')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Modifié')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
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
            'index' => ManageLandingSections::route('/'),
        ];
    }
}
