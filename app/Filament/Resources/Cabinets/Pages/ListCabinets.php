<?php

namespace App\Filament\Resources\Cabinets\Pages;

use App\Enums\LicensePlan;
use App\Filament\Resources\ActivationKeys\ActivationKeyResource;
use App\Filament\Resources\Cabinets\CabinetResource;
use App\Filament\Resources\Cabinets\Tables\CabinetSeats;
use App\Filament\Resources\Cabinets\Tables\CabinetsTable;
use App\Services\Cabinet\CabinetLeadRegistrar;
use App\Services\CabinetFulfillmentService;
use App\Support\ClipboardJs;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ListCabinets extends ListRecords
{
    protected static string $resource = CabinetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCabinet')
                ->label('Nouveau cabinet')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Créer un cabinet et son code d’activation')
                ->modalDescription('Le code est envoyé à l’e-mail du propriétaire. Il le saisit dans Drclick sur son PC (ou en ligne) et son compte est rattaché à ce cabinet par cette adresse.')
                ->modalSubmitActionLabel('Créer et générer le code')
                ->schema([
                    TextInput::make('name')
                        ->label('Nom du médecin propriétaire')
                        ->required()
                        ->minLength(2)
                        ->maxLength(120),
                    TextInput::make('email')
                        ->label('E-mail du propriétaire')
                        ->email()
                        ->required()
                        ->maxLength(190),
                    TextInput::make('phone')
                        ->label('Téléphone')
                        ->tel()
                        ->required()
                        ->minLength(6)
                        ->maxLength(32)
                        ->regex('/^[0-9+().\s-]+$/'),
                    TextInput::make('cabinet_name')
                        ->label('Nom du cabinet')
                        ->required()
                        ->minLength(2)
                        ->maxLength(160),
                    TextInput::make('specialization')
                        ->label('Spécialité')
                        ->required()
                        ->minLength(2)
                        ->maxLength(160),
                    Select::make('plan')
                        ->label('Type de licence')
                        ->options(LicensePlan::options())
                        ->default(LicensePlan::TRIAL->value)
                        ->helperText('L’essai commence lors de la saisie du code et expire exactement 7 jours plus tard.')
                        ->required()
                        ->native(false),
                    ...CabinetSeats::fields(),
                ])
                ->action(function (array $data): void {
                    $lead = app(CabinetLeadRegistrar::class)->register([
                        'name' => trim((string) $data['name']),
                        'email' => Str::lower(trim((string) $data['email'])),
                        'phone' => trim((string) $data['phone']),
                        'cabinet_name' => trim((string) $data['cabinet_name']),
                        'specialization' => trim((string) $data['specialization']),
                    ]);
                    $cabinet = $lead->cabinet()->firstOrFail();

                    // The e-mail may already belong to a cabinet that holds a
                    // licence: it is not given a second code here.
                    if (! CabinetsTable::canIssueLicenseCode($cabinet)) {
                        Notification::make()
                            ->title('Ce propriétaire a déjà un cabinet')
                            ->body("« {$cabinet->name} » est déjà rattaché à {$lead->email}. Gérez sa licence depuis sa ligne dans la liste.")
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $issued = app(CabinetFulfillmentService::class)
                        ->issueLicenseCode($cabinet, LicensePlan::from($data['plan']));
                    $seats = CabinetSeats::apply($cabinet, $data)->seatLimit();

                    Notification::make()
                        ->title('Cabinet créé')
                        ->body("Code d’activation de « {$cabinet->name} » : **{$issued->code}**. Il a été envoyé à {$lead->email}. Sièges accordés : **{$seats}**.")
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
        ];
    }
}
