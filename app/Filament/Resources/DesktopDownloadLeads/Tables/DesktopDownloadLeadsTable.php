<?php

namespace App\Filament\Resources\DesktopDownloadLeads\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DesktopDownloadLeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('cabinet_name')
                    ->label('Cabinet')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('specialization')
                    ->label('Spécialité')
                    ->searchable()
                    ->wrap(),
                IconColumn::make('downloaded_at')
                    ->label('Téléchargé')
                    ->boolean()
                    ->state(fn ($record): bool => $record->downloaded_at !== null),
                TextColumn::make('created_at')
                    ->label('Demandé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('downloaded_at')
                    ->label('Téléchargé le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Pas encore')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('downloaded_at')
                    ->label('Téléchargement effectué')
                    ->nullable(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
