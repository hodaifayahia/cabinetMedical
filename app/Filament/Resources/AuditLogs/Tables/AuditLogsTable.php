<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->searchable()
                    ->placeholder('Système'),
                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'failed') => 'danger',
                        str_contains($state, 'login'), str_contains($state, 'unlock') => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Objet')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label('Adresse IP')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
