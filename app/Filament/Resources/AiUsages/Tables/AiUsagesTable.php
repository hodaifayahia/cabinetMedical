<?php

namespace App\Filament\Resources\AiUsages\Tables;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Cabinet;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiUsagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('cabinet.name')
                    ->label('Cabinet')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->placeholder('—'),
                TextColumn::make('feature')
                    ->label('Action')
                    ->formatStateUsing(fn (string $state): string => AiFeature::ledgerLabels()[$state] ?? $state),
                TextColumn::make('credits')
                    ->label('Crédits')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.$state : (string) $state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('balance_after')
                    ->label('Solde après'),
                TextColumn::make('tokens')
                    ->label('Tokens (entrée / sortie)')
                    ->state(fn (AiUsage $record): string => $record->prompt_tokens === null
                        ? '—'
                        : $record->prompt_tokens.' / '.$record->completion_tokens)
                    ->toggleable(),
                TextColumn::make('model')
                    ->label('Modèle')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['cabinet:id,name', 'user:id,name']))
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('cabinet_id')
                    ->label('Cabinet')
                    ->options(fn (): array => Cabinet::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('feature')
                    ->label('Action')
                    ->options(AiFeature::ledgerLabels()),
            ]);
    }
}
