<?php

namespace App\Filament\Resources\ActivationKeys\Tables;

use App\Models\AuditLog;
use App\Models\HostedLicenseGrant;
use App\Services\CabinetFulfillmentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivationKeysTable
{
    /** @var array<string, string> */
    private const STATE_LABELS = [
        'outstanding' => 'En attente',
        'redeemed' => 'Utilisée',
        'revoked' => 'Révoquée',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cabinet.name')
                    ->label('Cabinet')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (HostedLicenseGrant $record): ?string => $record->cabinet?->owner?->email)
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('type_name')
                    ->label('Type')
                    ->state(fn (HostedLicenseGrant $record): string => $record->typeLabel())
                    ->badge()
                    ->color('info'),
                TextColumn::make('state')
                    ->label('État')
                    ->state(fn (HostedLicenseGrant $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (HostedLicenseGrant $record): string => match ($record->status()) {
                        'outstanding' => 'warning',
                        'redeemed' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn (HostedLicenseGrant $record): Heroicon => match ($record->status()) {
                        'outstanding' => Heroicon::OutlinedClock,
                        'redeemed' => Heroicon::OutlinedCheckCircle,
                        default => Heroicon::OutlinedNoSymbol,
                    }),
                TextColumn::make('code_suffix')
                    ->label('Clé')
                    ->state(fn (HostedLicenseGrant $record): string => $record->maskedCode())
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('issuer.name')
                    ->label('Émise par')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Émise le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('redeemed_at')
                    ->label('Utilisée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedTicket)
            ->emptyStateHeading('Aucune clé d’activation')
            ->emptyStateDescription('Générez un lot depuis « Générer des codes » : chaque clé émise apparaîtra immédiatement dans cette liste.')
            ->filters([
                SelectFilter::make('state')
                    ->label('État')
                    ->options(self::STATE_LABELS)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'outstanding' => $query->whereNull('redeemed_at')->whereNull('revoked_at'),
                        'redeemed' => $query->whereNotNull('redeemed_at'),
                        'revoked' => $query->whereNotNull('revoked_at')->whereNull('redeemed_at'),
                        default => $query,
                    }),
                SelectFilter::make('license_type_id')
                    ->label('Type de licence')
                    ->relationship('licenseType', 'name')
                    ->preload(),
                SelectFilter::make('cabinet_id')
                    ->label('Cabinet')
                    ->relationship('cabinet', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('reveal')
                        ->label('Révéler la clé')
                        ->icon(Heroicon::OutlinedEye)
                        ->color('primary')
                        ->modalHeading('Clé d’activation')
                        ->modalDescription(fn (HostedLicenseGrant $record): string => 'Remettez cette clé au propriétaire de '.($record->cabinet?->name ?? 'ce cabinet').'.')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Fermer')
                        ->modalContent(fn (HostedLicenseGrant $record) => view('filament.activation-keys.reveal', [
                            'code' => self::revealCode($record),
                            'grant' => $record,
                        ])),
                    Action::make('resend')
                        ->label('Renvoyer par e-mail')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->color('gray')
                        ->visible(fn (HostedLicenseGrant $record): bool => $record->isOutstanding()
                            && $record->plainCode() !== null
                            && filled($record->cabinet?->owner?->email))
                        ->requiresConfirmation()
                        ->modalDescription(fn (HostedLicenseGrant $record): string => 'La clé sera renvoyée à '.($record->cabinet?->owner?->email ?? '').'.')
                        ->action(function (HostedLicenseGrant $record): void {
                            $code = $record->plainCode();

                            if ($code === null) {
                                return;
                            }

                            app(CabinetFulfillmentService::class)->resendLicenseCode($record, $code);

                            Notification::make()
                                ->title('Clé renvoyée')
                                ->body('Le propriétaire a reçu la clé par e-mail.')
                                ->success()
                                ->send();
                        }),
                    Action::make('revoke')
                        ->label('Révoquer')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (HostedLicenseGrant $record): bool => $record->isOutstanding())
                        ->requiresConfirmation()
                        ->modalDescription('La clé cessera immédiatement de fonctionner. Le cabinet devra en recevoir une nouvelle.')
                        ->action(function (HostedLicenseGrant $record): void {
                            app(CabinetFulfillmentService::class)->revokeLicenseCode($record);

                            Notification::make()
                                ->title('Clé révoquée')
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('exportCsv')
                        ->label('Exporter la sélection (CSV)')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->color('gray')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records): StreamedResponse => self::exportCsv($records)),
                ]),
            ]);
    }

    /**
     * Reading a code back is a deliberate disclosure, so every reveal is
     * written to the audit trail with the acting administrator.
     */
    private static function revealCode(HostedLicenseGrant $grant): ?string
    {
        $code = $grant->plainCode();

        if ($code !== null) {
            AuditLog::record('cabinet.license_code_revealed', $grant, [
                'grant_id' => $grant->getKey(),
                'cabinet_id' => $grant->cabinet_id,
                'grant_suffix' => $grant->code_suffix,
            ]);
        }

        return $code;
    }

    /** @param Collection<int, HostedLicenseGrant> $records */
    private static function exportCsv(Collection $records): StreamedResponse
    {
        $rows = $records->map(static fn (HostedLicenseGrant $grant): array => [
            $grant->cabinet?->name ?? '',
            $grant->cabinet?->owner?->email ?? '',
            $grant->typeLabel(),
            $grant->statusLabel(),
            $grant->plainCode() ?? $grant->maskedCode(),
            $grant->created_at?->format('d/m/Y H:i') ?? '',
        ])->all();

        foreach ($records as $grant) {
            AuditLog::record('cabinet.license_code_revealed', $grant, [
                'grant_id' => $grant->getKey(),
                'cabinet_id' => $grant->cabinet_id,
                'grant_suffix' => $grant->code_suffix,
                'channel' => 'csv_export',
            ]);
        }

        return Response::streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            // Excel on Windows needs the BOM to read the accented headers.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Cabinet', 'E-mail', 'Type', 'État', 'Clé', 'Émise le']);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'cles-activation-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
