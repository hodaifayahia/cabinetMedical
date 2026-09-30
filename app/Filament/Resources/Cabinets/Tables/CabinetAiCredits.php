<?php

namespace App\Filament\Resources\Cabinets\Tables;

use App\Enums\AiFeature;
use App\Filament\Resources\AiUsages\AiUsageResource;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\User;
use App\Services\Ai\AiCreditLedger;
use App\Support\FrenchNumber;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The cabinet's AI wallet in the admin panel: the balance column and the
 * recharge actions support uses when a doctor asks for more credits.
 */
final class CabinetAiCredits
{
    /** Below this a cabinet is flagged for a recharge. */
    public const LOW_BALANCE = 50;

    /** Every widget showing a balance listens for this after a recharge. */
    public const UPDATED_EVENT = 'ai-credits-updated';

    public static function column(): TextColumn
    {
        return TextColumn::make('ai_credits')
            ->label('Crédits IA')
            ->badge()
            ->sortable()
            ->formatStateUsing(fn (Cabinet $record, $state): string => $record->ai_enabled ? (string) $state : $state.' · désactivé')
            ->color(fn (Cabinet $record): string => self::balanceColor($record));
    }

    public static function balanceColor(Cabinet $cabinet): string
    {
        return match (true) {
            ! $cabinet->ai_enabled => 'gray',
            $cabinet->ai_credits <= 0 => 'danger',
            $cabinet->ai_credits < self::LOW_BALANCE => 'warning',
            default => 'success',
        };
    }

    public static function manageAction(): Action
    {
        return Action::make('manageAiCredits')
            ->label('Crédits IA')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('primary')
            ->modalHeading(fn (Cabinet $record): string => 'Crédits IA — '.$record->name)
            ->modalDescription(fn (Cabinet $record): string => 'Solde actuel : '.$record->ai_credits.' crédits. '.self::costsSentence())
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (Cabinet $record): array => [
                'operation' => 'add',
                'amount' => 500,
                'ai_enabled' => $record->ai_enabled,
            ])
            ->schema([
                ...self::adjustmentFields(),
                Toggle::make('ai_enabled')
                    ->label('Assistant IA activé pour ce cabinet'),
            ])
            ->action(function (Cabinet $record, array $data, Component $livewire): void {
                self::apply($record, $data, $livewire);
            });
    }

    /**
     * The same recharge, started from the AI page without first finding the
     * cabinet's row: pick the cabinet (or its doctor) in the form.
     */
    public static function rechargeAction(): Action
    {
        return Action::make('rechargeAiCredits')
            ->label('Recharger un cabinet')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('primary')
            ->modalHeading('Ajouter des crédits IA')
            ->modalDescription(self::costsSentence())
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm([
                'operation' => 'add',
                'amount' => 500,
            ])
            ->schema([
                Select::make('cabinet_id')
                    ->label('Cabinet ou médecin')
                    ->placeholder('Rechercher par cabinet, médecin ou e-mail')
                    ->options(fn (): array => self::cabinetOptions())
                    ->searchable()
                    ->live()
                    ->helperText(function (Get $get): ?string {
                        $id = $get('cabinet_id');
                        $cabinet = is_numeric($id) ? Cabinet::query()->find((int) $id) : null;

                        return $cabinet === null
                            ? null
                            : 'Solde actuel : '.FrenchNumber::format($cabinet->ai_credits).' crédits'.($cabinet->ai_enabled ? '.' : ' · assistant IA désactivé.');
                    })
                    ->required(),
                ...self::adjustmentFields(),
            ])
            ->action(function (array $data, Component $livewire): void {
                self::apply(Cabinet::query()->findOrFail((int) $data['cabinet_id']), $data, $livewire);
            });
    }

    /**
     * Top up every selected cabinet by the same amount, e.g. a monthly gift.
     */
    public static function bulkRechargeAction(): BulkAction
    {
        return BulkAction::make('bulkRechargeAiCredits')
            ->label('Recharger la sélection')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('primary')
            ->modalHeading('Recharger les cabinets sélectionnés')
            ->modalDescription('Le même nombre de crédits est ajouté au solde de chaque cabinet sélectionné.')
            ->modalSubmitActionLabel('Recharger')
            ->fillForm(['amount' => 100])
            ->schema([
                self::amountField(),
                self::noteField(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, Component $livewire): void {
                $admin = auth()->user();
                $amount = (int) $data['amount'];

                /** @var Cabinet $cabinet */
                foreach ($records as $cabinet) {
                    $balance = app(AiCreditLedger::class)->adjust(
                        $cabinet,
                        $admin instanceof User ? $admin : null,
                        'add',
                        $amount,
                        $data['note'] ?? null,
                    );

                    AuditLog::record('admin.cabinet_ai_credits_updated', $cabinet, [
                        'operation' => 'add',
                        'amount' => $amount,
                        'balance' => $balance,
                        'ai_enabled' => (bool) $cabinet->ai_enabled,
                    ]);
                }

                $livewire->dispatch(self::UPDATED_EVENT);

                Notification::make()
                    ->title('Crédits IA ajoutés')
                    ->body('+'.FrenchNumber::format($amount).' crédits pour '.$records->count().' cabinet(s).')
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

    public static function costsSentence(): string
    {
        return 'Coûts : '.collect(AiFeature::cases())
            // Lower-case the first letter only, so "ECG" keeps its capitals.
            ->map(fn (AiFeature $feature): string => mb_strtolower(mb_substr($feature->label(), 0, 1)).mb_substr($feature->label(), 1).' '.$feature->cost())
            ->implode(', ').'.';
    }

    /**
     * @return list<ToggleButtons|TextInput>
     */
    private static function adjustmentFields(): array
    {
        return [
            ToggleButtons::make('operation')
                ->label('Opération')
                ->options([
                    'add' => 'Recharger',
                    'remove' => 'Retirer',
                    'set' => 'Définir le solde',
                ])
                ->inline()
                ->required(),
            self::amountField(),
            self::noteField(),
        ];
    }

    private static function amountField(): TextInput
    {
        return TextInput::make('amount')
            ->label('Nombre de crédits')
            ->numeric()
            ->integer()
            ->minValue(0)
            ->maxValue(1000000)
            ->required();
    }

    private static function noteField(): TextInput
    {
        return TextInput::make('note')
            ->label('Note (facultatif)')
            ->placeholder('Ex. recharge payée le 24/09')
            ->maxLength(255);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function apply(Cabinet $cabinet, array $data, Component $livewire): void
    {
        $admin = auth()->user();
        $balance = app(AiCreditLedger::class)->adjust(
            $cabinet,
            $admin instanceof User ? $admin : null,
            (string) $data['operation'],
            (int) $data['amount'],
            $data['note'] ?? null,
        );

        $enabled = array_key_exists('ai_enabled', $data) ? (bool) $data['ai_enabled'] : (bool) $cabinet->ai_enabled;
        Cabinet::query()->whereKey($cabinet->getKey())->update(['ai_enabled' => $enabled]);

        AuditLog::record('admin.cabinet_ai_credits_updated', $cabinet, [
            'operation' => $data['operation'],
            'amount' => (int) $data['amount'],
            'balance' => $balance,
            'ai_enabled' => $enabled,
        ]);

        $livewire->dispatch(self::UPDATED_EVENT);

        Notification::make()
            ->title('Crédits IA mis à jour')
            ->body($cabinet->name.' — nouveau solde : '.FrenchNumber::format($balance).' crédits.')
            ->success()
            ->send();
    }

    /**
     * Every cabinet, labelled so the operator can find it by the doctor's
     * name or e-mail as well as by the cabinet's.
     *
     * @return array<int, string>
     */
    private static function cabinetOptions(): array
    {
        return Cabinet::query()
            ->with('owner:id,name,email')
            ->orderBy('name')
            ->get(['id', 'name', 'owner_user_id', 'ai_credits'])
            ->mapWithKeys(fn (Cabinet $cabinet): array => [
                $cabinet->getKey() => collect([
                    $cabinet->name,
                    $cabinet->owner?->name,
                    $cabinet->owner?->email,
                ])->filter()->implode(' — ').' · '.FrenchNumber::format($cabinet->ai_credits).' crédits',
            ])
            ->all();
    }
}
