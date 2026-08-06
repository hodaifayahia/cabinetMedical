<?php

namespace App\Filament\Resources\Devices\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Appareil')
                    ->searchable()
                    ->placeholder('—')
                    ->weight('bold'),
                TextColumn::make('installation_id')
                    ->label('Installation')
                    ->searchable()
                    ->copyable()
                    ->limit(18)
                    ->fontFamily('mono'),
                TextColumn::make('platform')
                    ->label('Plateforme')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('activations_count')
                    ->label('Activations')
                    ->counts('activations')
                    ->badge()
                    ->alignCenter(),
                TextColumn::make('first_seen_at')
                    ->label('Première connexion')
                    ->date()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Dernière activité')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'revoked' => 'Révoqué',
                        'inactive' => 'Inactif',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
