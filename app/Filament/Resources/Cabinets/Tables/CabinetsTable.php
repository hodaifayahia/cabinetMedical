<?php

namespace App\Filament\Resources\Cabinets\Tables;

use App\Enums\CabinetStatus;
use App\Enums\LicensePlan;
use App\Filament\Resources\ActivationKeys\ActivationKeyResource;
use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\LicenseType;
use App\Services\Cabinet\CabinetDirectoryListing;
use App\Services\CabinetFulfillmentService;
use App\Support\ClipboardJs;
use App\Support\Wilayas;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CabinetsTable
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'pending' => 'En attente',
        'active' => 'Actif',
        'suspended' => 'Suspendu',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Cabinet')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('owner.email')
                    ->label('Propriétaire')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('specialization')
                    ->label('Spécialité')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('wilaya_code')
                    ->label('Wilaya')
                    ->formatStateUsing(fn (?int $state): string => Wilayas::label($state) ?? '—'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::STATUS_LABELS[self::statusValue($state)] ?? self::statusValue($state))
                    ->color(fn ($state): string => match (self::statusValue($state)) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('license_plan')
                    ->label('Licence')
                    ->state(fn (Cabinet $record): string => $record->license?->typeLabel() ?? 'Non attribuée')
                    ->badge()
                    ->color(fn (Cabinet $record): string => match ($record->license?->plan) {
                        LicensePlan::LIFETIME => 'success',
                        LicensePlan::TRIAL => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('license_effective_status')
                    ->label('État licence')
                    ->state(fn (Cabinet $record): string => self::licenseStatusLabel($record))
                    ->badge()
                    ->color(fn (Cabinet $record): string => match ($record->license?->effectiveStatus()) {
                        'active' => 'success',
                        'expired', 'revoked' => 'danger',
                        'suspended' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('license_validity')
                    ->label('Validité')
                    ->state(fn (Cabinet $record): string => self::licenseValidityLabel($record)),
                TextColumn::make('pending_license_code')
                    ->label('Code à remettre')
                    ->state(fn (Cabinet $record): string => self::pendingGrantLabel($record))
                    ->badge()
                    ->color(fn (Cabinet $record): string => self::pendingGrantLabel($record) === '—' ? 'gray' : 'warning')
                    ->toggleable(),
                IconColumn::make('publicProfile.is_listed')
                    ->label('App mobile')
                    ->boolean()
                    ->default(false)
                    ->tooltip(fn (Cabinet $record): string => match (true) {
                        ! (bool) $record->publicProfile?->is_listed => 'Masqué dans l’application patient',
                        $record->status !== CabinetStatus::ACTIVE => 'Visible dès l’activation du cabinet',
                        default => 'Visible dans l’application patient',
                    }),
                CabinetAiCredits::column(),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('activated_at')
                    ->label('Activé le')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('publicProfile'))
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(self::STATUS_LABELS),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('issueLicenseCode')
                        ->label(fn (Cabinet $record): string => $record->isPending()
                            ? 'Générer le code d’activation'
                            : 'Générer un code de renouvellement')
                        ->icon(Heroicon::OutlinedKey)
                        ->color('primary')
                        ->visible(fn (Cabinet $record): bool => self::canIssueLicenseCode($record))
                        ->modalHeading('Préparer une licence à utiliser par le propriétaire')
                        ->modalDescription('Le cabinet restera bloqué jusqu’à la saisie de ce code. Une régénération annulera immédiatement le code précédent.')
                        ->modalSubmitActionLabel('Générer le code')
                        ->schema([
                            Select::make('plan')
                                ->label('Type de licence')
                                ->options(LicensePlan::options())
                                ->default(fn (Cabinet $record): string => $record->isPending()
                                    ? LicensePlan::TRIAL->value
                                    : LicensePlan::LIFETIME->value)
                                ->helperText('L’essai commence lors de la saisie du code et expire exactement 7 jours plus tard.')
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Cabinet $record, array $data): void {
                            $plan = LicensePlan::from($data['plan']);
                            $issued = app(CabinetFulfillmentService::class)->issueLicenseCode($record, $plan);
                            Notification::make()
                                ->title('Code de licence généré')
                                ->body("Copiez et remettez ce code au propriétaire : **{$issued->code}**. Il lui a également été envoyé par e-mail.")
                                ->actions([
                                    Action::make('copyLicenseCode')
                                        ->label('Copier le code')
                                        ->button()
                                        ->alpineClickHandler(ClipboardJs::copy($issued->code)),
                                    Action::make('viewActivationKeys')
                                        ->label('Voir dans les clés')
                                        ->link()
                                        ->url(ActivationKeyResource::getUrl('index')),
                                ])
                                ->success()
                                ->persistent()
                                ->send();
                        }),
                    CabinetAiCredits::manageAction(),
                    CabinetAiCredits::historyAction(),
                    Action::make('toggleMobileListing')
                        ->label(fn (Cabinet $record): string => $record->publicProfile?->is_listed
                            ? 'Masquer dans l’app mobile'
                            : 'Afficher dans l’app mobile')
                        ->icon(fn (Cabinet $record) => $record->publicProfile?->is_listed
                            ? Heroicon::OutlinedEyeSlash
                            : Heroicon::OutlinedEye)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (Cabinet $record): void {
                            $listed = ! (bool) $record->publicProfile?->is_listed;

                            app(CabinetDirectoryListing::class)->setListed($record, $listed);

                            AuditLog::record('admin.cabinet_listing_updated', $record, [
                                'is_listed' => $listed,
                            ]);

                            Notification::make()
                                ->title($listed ? 'Cabinet visible dans l’app mobile' : 'Cabinet masqué dans l’app mobile')
                                ->success()
                                ->send();
                        }),
                    Action::make('suspend')
                        ->label('Suspendre')
                        ->icon(Heroicon::OutlinedPauseCircle)
                        ->color('warning')
                        ->visible(fn (Cabinet $record): bool => $record->status === CabinetStatus::ACTIVE)
                        ->requiresConfirmation()
                        ->action(function (Cabinet $record): void {
                            app(CabinetFulfillmentService::class)->suspend($record);

                            Notification::make()
                                ->title('Cabinet suspendu')
                                ->warning()
                                ->send();
                        }),
                    Action::make('reactivate')
                        ->label('Réactiver')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('success')
                        ->visible(fn (Cabinet $record): bool => $record->status === CabinetStatus::SUSPENDED)
                        ->requiresConfirmation()
                        ->action(function (Cabinet $record): void {
                            app(CabinetFulfillmentService::class)->reactivate($record);

                            Notification::make()
                                ->title('Cabinet réactivé')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('issueLicenseCodes')
                        ->label('Générer les codes d’activation')
                        ->icon(Heroicon::OutlinedKey)
                        ->color('primary')
                        ->modalHeading('Générer un code pour chaque cabinet sélectionné')
                        ->modalDescription('Un code est créé et envoyé par cabinet éligible. Les cabinets déjà licenciés ou suspendus sont ignorés sans interrompre le lot.')
                        ->modalSubmitActionLabel('Générer les codes')
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('license_type_id')
                                ->label('Type de licence')
                                ->options(fn (): array => LicenseType::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $type = LicenseType::query()
                                ->where('is_active', true)
                                ->findOrFail((int) $data['license_type_id']);

                            /** @var Collection<int, Cabinet> $selected */
                            $selected = $records->load('owner');

                            $result = app(CabinetFulfillmentService::class)
                                ->issueLicenseCodes($selected, $type);

                            if ($result->issuedCount() === 0) {
                                Notification::make()
                                    ->title('Aucun code généré')
                                    ->body('Aucun cabinet sélectionné ne pouvait recevoir un code.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $body = $result->issuedCount().' code(s) généré(s) et envoyé(s) par e-mail.';

                            if ($result->skippedCount() > 0) {
                                $body .= ' Ignorés : '.implode(', ', $result->skippedCabinetNames()).'.';
                            }

                            Notification::make()
                                ->title('Codes d’activation générés')
                                ->body($body)
                                ->actions([
                                    Action::make('viewActivationKeys')
                                        ->label('Ouvrir les clés d’activation')
                                        ->button()
                                        ->url(ActivationKeyResource::getUrl('index')),
                                ])
                                ->success()
                                ->persistent()
                                ->send();
                        }),
                ]),
            ]);
    }

    private static function statusValue(mixed $state): string
    {
        return $state instanceof CabinetStatus ? $state->value : (string) $state;
    }

    private static function licenseStatusLabel(Cabinet $cabinet): string
    {
        return $cabinet->license?->effectiveStatusLabel() ?? 'Non attribuée';
    }

    private static function licenseValidityLabel(Cabinet $cabinet): string
    {
        $license = $cabinet->license;

        if ($license === null) {
            return '—';
        }

        if ($license->expires_at === null) {
            return 'À vie';
        }

        return $license->expires_at
            ->timezone(config('app.timezone'))
            ->translatedFormat('d/m/Y H:i');
    }

    private static function canIssueLicenseCode(Cabinet $cabinet): bool
    {
        if ($cabinet->isPending()) {
            return $cabinet->license_id === null;
        }

        $license = $cabinet->license;

        return $cabinet->isActive()
            && $license !== null
            && $license->plan === LicensePlan::TRIAL
            && $license->status !== 'revoked';
    }

    private static function pendingGrantLabel(Cabinet $cabinet): string
    {
        $grant = $cabinet->hostedLicenseGrants()->outstanding()->latest()->first();

        return $grant === null
            ? '—'
            : $grant->typeLabel().' · ••••'.$grant->code_suffix;
    }
}
