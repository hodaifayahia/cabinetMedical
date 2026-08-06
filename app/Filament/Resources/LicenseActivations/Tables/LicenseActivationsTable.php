<?php

namespace App\Filament\Resources\LicenseActivations\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LicenseActivationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('license.license_id')
                    ->label('Licence')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('installation_id')
                    ->label('Installation')
                    ->searchable()
                    ->copyable()
                    ->limit(18)
                    ->fontFamily('mono'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'deactivated' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('activated_at')
                    ->label('Activée le')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Dernière activité')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('deactivated_at')
                    ->label('Désactivée le')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('activated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Active',
                        'deactivated' => 'Désactivée',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
