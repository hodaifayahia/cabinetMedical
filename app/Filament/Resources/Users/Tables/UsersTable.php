<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (User $record): string => $record->getKey() === auth()->id()
                        ? 'Votre compte'
                        : 'Administrateur plateforme'),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable()
                    ->icon(Heroicon::OutlinedEnvelope),
                IconColumn::make('email_verified_at')
                    ->label('Vérifié')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->email_verified_at !== null),
                IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->two_factor_confirmed_at !== null),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('Aucun compte plateforme')
            ->emptyStateDescription('Créez le premier compte autorisé à ouvrir ce tableau de bord.')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make()
                        ->modalDescription('Ce compte perdra immédiatement l’accès au tableau de bord Drclick.'),
                ]),
            ]);
    }
}
