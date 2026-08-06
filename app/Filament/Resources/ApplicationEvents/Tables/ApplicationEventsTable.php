<?php

namespace App\Filament\Resources\ApplicationEvents\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ApplicationEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event')
                    ->label('Événement')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('severity')
                    ->label('Gravité')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'info' => 'info',
                        'warning' => 'warning',
                        'error', 'critical' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('message')
                    ->label('Message')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),
                TextColumn::make('occurred_at')
                    ->label('Survenu le')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('severity')
                    ->label('Gravité')
                    ->options([
                        'info' => 'Information',
                        'warning' => 'Avertissement',
                        'error' => 'Erreur',
                        'critical' => 'Critique',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
