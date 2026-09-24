<?php

namespace App\Filament\Resources\Cabinets\Tables;

use App\Enums\AiFeature;
use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Ai\AiCreditLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

/**
 * The cabinet's AI wallet in the admin panel: the balance column and the
 * recharge action support uses when a doctor asks for more credits.
 */
final class CabinetAiCredits
{
    public static function column(): TextColumn
    {
        return TextColumn::make('ai_credits')
            ->label('Crédits IA')
            ->badge()
            ->sortable()
            ->formatStateUsing(fn (Cabinet $record, $state): string => $record->ai_enabled ? (string) $state : $state.' · désactivé')
            ->color(fn (Cabinet $record): string => match (true) {
                ! $record->ai_enabled => 'gray',
                $record->ai_credits <= 0 => 'danger',
                $record->ai_credits < 50 => 'warning',
                default => 'success',
            });
    }

    public static function manageAction(): Action
    {
        return Action::make('manageAiCredits')
            ->label('Crédits IA')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('primary')
            ->modalHeading(fn (Cabinet $record): string => 'Crédits IA — '.$record->name)
            ->modalDescription(fn (Cabinet $record): string => 'Solde actuel : '.$record->ai_credits.' crédits. Coûts : '
                .collect(AiFeature::cases())->map(fn (AiFeature $feature): string => mb_strtolower($feature->label()).' '.$feature->cost())->implode(', ').'.')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (Cabinet $record): array => [
                'operation' => 'add',
                'amount' => 500,
                'ai_enabled' => $record->ai_enabled,
            ])
            ->schema([
                ToggleButtons::make('operation')
                    ->label('Opération')
                    ->options([
                        'add' => 'Recharger',
                        'remove' => 'Retirer',
                        'set' => 'Définir le solde',
                    ])
                    ->inline()
                    ->required(),
                TextInput::make('amount')
                    ->label('Nombre de crédits')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(1000000)
                    ->required(),
                TextInput::make('note')
                    ->label('Note (facultatif)')
                    ->placeholder('Ex. recharge payée le 24/09')
                    ->maxLength(255),
                Toggle::make('ai_enabled')
                    ->label('Assistant IA activé pour ce cabinet'),
            ])
            ->action(function (Cabinet $record, array $data): void {
                $admin = auth()->user();
                $balance = app(AiCreditLedger::class)->adjust(
                    $record,
                    $admin instanceof User ? $admin : null,
                    (string) $data['operation'],
                    (int) $data['amount'],
                    $data['note'] ?? null,
                );

                Cabinet::query()->whereKey($record->getKey())->update(['ai_enabled' => (bool) $data['ai_enabled']]);

                AuditLog::record('admin.cabinet_ai_credits_updated', $record, [
                    'operation' => $data['operation'],
                    'amount' => (int) $data['amount'],
                    'balance' => $balance,
                    'ai_enabled' => (bool) $data['ai_enabled'],
                ]);

                Notification::make()
                    ->title('Crédits IA mis à jour')
                    ->body('Nouveau solde : '.$balance.' crédits.')
                    ->success()
                    ->send();
            });
    }

    public static function historyAction(): Action
    {
        return Action::make('aiUsageHistory')
            ->label('Historique IA')
            ->icon(Heroicon::OutlinedListBullet)
            ->color('gray')
            ->url(fn (Cabinet $record): string => AiUsageResource::getUrl('index', [
                'filters' => ['cabinet_id' => ['value' => $record->getKey()]],
            ]));
    }
}
