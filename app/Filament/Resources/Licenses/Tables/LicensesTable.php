<?php

namespace App\Filament\Resources\Licenses\Tables;

use App\Models\AuditLog;
use App\Models\License;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LicensesTable
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'not_activated' => 'Non activée',
        'active' => 'Active',
        'expired' => 'Expirée',
        'suspended' => 'Suspendue',
        'revoked' => 'Révoquée',
        'device_limit_reached' => 'Limite d’appareils atteinte',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('license_id')
                    ->label('Licence')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('customer_id')
                    ->label('Client')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('edition')
                    ->label('Édition')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'complimentary' => 'Gratuite',
                        'standard' => 'Standard',
                        'professional' => 'Professionnelle',
                        'enterprise' => 'Entreprise',
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === 'complimentary' ? 'info' : 'gray'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'not_activated' => 'gray',
                        'suspended', 'device_limit_reached' => 'warning',
                        'expired', 'revoked' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('activations_count')
                    ->label('Activations')
                    ->counts('activations')
                    ->badge()
                    ->alignCenter(),
                TextColumn::make('issued_at')
                    ->label('Émise le')
                    ->date()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expire le')
                    ->date()
                    ->placeholder('Illimitée')
                    ->sortable(),
                TextColumn::make('last_verified_at')
                    ->label('Vérifiée le')
                    ->dateTime()
                    ->since()
                    ->placeholder('Jamais')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(self::STATUS_LABELS),
                SelectFilter::make('edition')
                    ->label('Édition')
                    ->options([
                        'complimentary' => 'Gratuite',
                        'standard' => 'Standard',
                        'professional' => 'Professionnelle',
                        'enterprise' => 'Entreprise',
                    ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('activate')
                        ->label('Activer')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn (License $record): bool => $record->status !== 'active')
                        ->requiresConfirmation()
                        ->action(fn (License $record) => self::transition($record, 'active', 'license.marked_active', 'Licence activée')),
                    Action::make('suspend')
                        ->label('Suspendre')
                        ->icon(Heroicon::OutlinedPauseCircle)
                        ->color('warning')
                        ->visible(fn (License $record): bool => $record->status === 'active')
                        ->requiresConfirmation()
                        ->action(fn (License $record) => self::transition($record, 'suspended', 'license.marked_suspended', 'Licence suspendue')),
                    Action::make('revoke')
                        ->label('Révoquer')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (License $record): bool => $record->status !== 'revoked')
                        ->requiresConfirmation()
                        ->action(fn (License $record) => self::transition($record, 'revoked', 'license.marked_revoked', 'Licence révoquée')),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function transition(License $record, string $status, string $auditAction, string $message): void
    {
        $record->update(['status' => $status]);
        AuditLog::record($auditAction, $record, ['license_id' => $record->license_id]);

        Notification::make()
            ->title($message)
            ->success()
            ->send();
    }
}
