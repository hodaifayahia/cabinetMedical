<?php

namespace App\Filament\Resources\Devices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('installation_id')
                    ->label('Identifiant d’installation'),
                TextInput::make('label')
                    ->label('Nom de l’appareil'),
                TextInput::make('platform')
                    ->label('Plateforme'),
                TextInput::make('status')
                    ->label('Statut'),
                DateTimePicker::make('first_seen_at')
                    ->label('Première connexion'),
                DateTimePicker::make('last_seen_at')
                    ->label('Dernière activité'),
            ]);
    }
}
