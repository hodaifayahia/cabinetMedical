<?php

namespace App\Filament\Resources\LicenseActivations\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LicenseActivationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('license_id')
                    ->label('Licence')
                    ->relationship('license', 'license_id'),
                TextInput::make('installation_id')
                    ->label('Identifiant d’installation'),
                TextInput::make('status')
                    ->label('Statut'),
                DateTimePicker::make('activated_at')
                    ->label('Activée le'),
                DateTimePicker::make('last_seen_at')
                    ->label('Dernière activité'),
                DateTimePicker::make('deactivated_at')
                    ->label('Désactivée le'),
            ]);
    }
}
