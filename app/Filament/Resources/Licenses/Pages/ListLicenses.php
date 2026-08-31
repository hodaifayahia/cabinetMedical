<?php

namespace App\Filament\Resources\Licenses\Pages;

use App\Filament\Resources\ActivationKeys\ActivationKeyResource;
use App\Filament\Resources\Licenses\LicenseResource;
use App\Models\Cabinet;
use App\Models\LicenseType;
use App\Services\CabinetFulfillmentService;
use App\Support\ClipboardJs;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLicenses extends ListRecords
{
    protected static string $resource = LicenseResource::class;

    public function getSubheading(): ?string
    {
        return 'Licences signées installées chez un client. Les codes d’activation envoyés aux cabinets se suivent dans « Clés d’activation ».';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Créer une licence locale')
                ->icon(Heroicon::OutlinedPlus),
            Action::make('generateLicense')
                ->label('Générer un code client')
                ->icon(Heroicon::OutlinedTicket)
                ->color('gray')
                ->modalHeading('Envoyer un code d’activation au client')
                ->modalDescription('Sélectionnez le client et le type. Un code à usage unique sera généré, envoyé à son adresse e-mail et ajouté à la liste des clés d’activation.')
                ->modalSubmitActionLabel('Générer et envoyer')
                // A code creates a grant, not a licence row, so leaving the
                // operator on this table made a successful generation look
                // like nothing had happened. Land them where the new key is.
                ->successRedirectUrl(fn (): string => ActivationKeyResource::getUrl('index'))
                ->schema([
                    Select::make('cabinet_id')
                        ->label('Client')
                        ->options(fn (): array => Cabinet::query()
                            ->awaitingActivationCode()
                            ->with('owner:id,email')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Cabinet $cabinet): array => [
                                $cabinet->getKey() => $cabinet->name.($cabinet->owner?->email ? ' — '.$cabinet->owner->email : ''),
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->helperText('Seuls les cabinets pouvant recevoir un code sont proposés.'),
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
                    $cabinet = Cabinet::query()->findOrFail((int) $data['cabinet_id']);
                    $type = LicenseType::query()
                        ->where('is_active', true)
                        ->findOrFail((int) $data['license_type_id']);
                    $issued = app(CabinetFulfillmentService::class)->issueLicenseCode($cabinet, $type);

                    Notification::make()
                        ->title('Licence générée et envoyée')
                        ->body('Le code a été envoyé au client par e-mail : **'.$issued->code.'**')
                        ->actions([
                            Action::make('copyLicenseCode')
                                ->label('Copier le code')
                                ->button()
                                ->alpineClickHandler(ClipboardJs::copy($issued->code)),
                        ])
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
