<?php

namespace App\Filament\Resources\Licenses\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LicenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('license_id')
                    ->label('Identifiant de licence')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('customer_id')
                    ->label('Client / cabinet')
                    ->maxLength(255)
                    ->helperText('Nom ou identifiant du client à qui la licence est attribuée.'),
                TextInput::make('product')
                    ->label('Produit')
                    ->required()
                    ->default('ClickDZ Doctor')
                    ->maxLength(255),
                Select::make('edition')
                    ->label('Édition')
                    ->required()
                    ->options([
                        'complimentary' => 'Gratuite (offerte)',
                        'standard' => 'Standard',
                        'professional' => 'Professionnelle',
                        'enterprise' => 'Entreprise',
                    ])
                    ->default('standard'),
                Select::make('status')
                    ->label('Statut')
                    ->required()
                    ->options([
                        'not_activated' => 'Non activée',
                        'active' => 'Active',
                        'expired' => 'Expirée',
                        'suspended' => 'Suspendue',
                        'revoked' => 'Révoquée',
                        'device_limit_reached' => 'Limite d’appareils atteinte',
                    ])
                    ->default('not_activated'),
                DateTimePicker::make('issued_at')
                    ->label('Émise le')
                    ->required()
                    ->default(now()),
                DateTimePicker::make('expires_at')
                    ->label('Expire le')
                    ->helperText('Laisser vide pour une licence sans expiration.'),
                DateTimePicker::make('offline_grace_until')
                    ->label('Grâce hors-ligne jusqu’au'),
                DateTimePicker::make('last_verified_at')
                    ->label('Dernière vérification')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
