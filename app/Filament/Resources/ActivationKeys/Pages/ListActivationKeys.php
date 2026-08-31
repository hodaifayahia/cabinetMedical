<?php

namespace App\Filament\Resources\ActivationKeys\Pages;

use App\Filament\Resources\ActivationKeys\ActivationKeyResource;
use App\Models\Cabinet;
use App\Models\LicenseType;
use App\Services\CabinetFulfillmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListActivationKeys extends ListRecords
{
    protected static string $resource = ActivationKeyResource::class;

    public function getSubheading(): ?string
    {
        return 'Générez les codes de plusieurs cabinets en une seule fois, puis suivez ici ceux qui restent à utiliser.';
    }

    /**
     * A key is a grant, not a record the operator fills in, so the resource
     * refuses plain creation. Issuing happens through this batch action,
     * which serves a whole selection of cabinets in one pass.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('issueBatch')
                ->label('Générer des codes')
                ->icon(Heroicon::OutlinedSparkles)
                ->modalHeading('Générer des codes d’activation')
                ->modalDescription('Sélectionnez les cabinets à servir. Un code est créé pour chacun et envoyé à son propriétaire ; régénérer annule le code précédent du cabinet concerné.')
                ->modalSubmitActionLabel('Générer')
                ->modalWidth('2xl')
                ->schema([
                    Select::make('cabinet_ids')
                        ->label('Cabinets')
                        ->multiple()
                        ->searchable()
                        ->required()
                        ->options(fn (): array => self::eligibleCabinetOptions())
                        // Servicing the whole waiting queue is the common
                        // case, so it is the default rather than an extra step.
                        ->default(fn (): array => array_keys(self::eligibleCabinetOptions()))
                        ->helperText('Seuls les cabinets en attente d’activation ou en essai renouvelable sont proposés.'),
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
                ->action(function (array $data): void {
                    $type = LicenseType::query()
                        ->where('is_active', true)
                        ->findOrFail((int) $data['license_type_id']);

                    $cabinets = Cabinet::query()
                        ->with('owner')
                        ->whereKey($data['cabinet_ids'])
                        ->get();

                    $result = app(CabinetFulfillmentService::class)->issueLicenseCodes($cabinets, $type);

                    if ($result->issuedCount() === 0) {
                        Notification::make()
                            ->title('Aucun code généré')
                            ->body('Aucun des cabinets sélectionnés ne pouvait recevoir un code.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $body = $result->issuedCount().' code(s) généré(s) et envoyé(s) par e-mail. Utilisez « Révéler la clé » pour les copier.';

                    if ($result->skippedCount() > 0) {
                        $body .= ' Ignorés : '.implode(', ', $result->skippedCabinetNames()).'.';
                    }

                    Notification::make()
                        ->title('Codes d’activation générés')
                        ->body($body)
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /** @return array<int, string> */
    private static function eligibleCabinetOptions(): array
    {
        return Cabinet::query()
            ->awaitingActivationCode()
            ->with('owner:id,email')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Cabinet $cabinet): array => [
                $cabinet->getKey() => $cabinet->name.($cabinet->owner?->email ? ' — '.$cabinet->owner->email : ''),
            ])
            ->all();
    }
}
