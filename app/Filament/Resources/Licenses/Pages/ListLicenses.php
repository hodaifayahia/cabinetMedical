<?php

namespace App\Filament\Resources\Licenses\Pages;

use App\Filament\Resources\Licenses\LicenseResource;
use App\Models\Cabinet;
use App\Models\LicenseType;
use App\Services\CabinetFulfillmentService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLicenses extends ListRecords
{
    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Créer une licence locale'),
            Action::make('generateLicense')
                ->label('Générer un code client')
                ->modalHeading('Envoyer un code d’activation au client')
                ->modalDescription('Sélectionnez le client et le type. Un code à usage unique sera généré et envoyé à son adresse e-mail.')
                ->modalSubmitActionLabel('Générer et envoyer')
                ->schema([
                    Select::make('cabinet_id')
                        ->label('Client')
                        ->options(fn (): array => Cabinet::query()->with('owner')->orderBy('name')->get()->mapWithKeys(fn (Cabinet $cabinet): array => [
                            $cabinet->getKey() => $cabinet->name.($cabinet->owner?->email ? ' — '.$cabinet->owner->email : ''),
                        ])->all())
                        ->searchable()
                        ->required(),
                    Select::make('license_type_id')
                        ->label('Type de licence')
                        ->options(fn (): array => LicenseType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $cabinet = Cabinet::query()->findOrFail((int) $data['cabinet_id']);
                    $type = LicenseType::query()->where('is_active', true)->findOrFail((int) $data['license_type_id']);
                    $issued = app(CabinetFulfillmentService::class)->issueLicenseCode($cabinet, $type);
                    $javascriptCode = json_encode($issued->code, JSON_THROW_ON_ERROR);

                    Notification::make()
                        ->title('Licence générée et envoyée')
                        ->body('Le code a été envoyé au client par e-mail : **'.$issued->code.'**')
                        ->actions([
                            Action::make('copyLicenseCode')
                                ->label('Copier le code')
                                ->button()
                                ->alpineClickHandler("navigator.clipboard.writeText({$javascriptCode})"),
                        ])
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
