<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Compte')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nom'),
                        TextEntry::make('email')
                            ->label('Adresse e-mail')
                            ->copyable(),
                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->label('Modifié le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                    ]),
                Section::make('Sécurité')
                    ->columns(2)
                    ->schema([
                        IconEntry::make('is_platform_admin')
                            ->label('Accès plateforme')
                            ->boolean(),
                        TextEntry::make('email_verified_at')
                            ->label('E-mail vérifié le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Non vérifié'),
                        TextEntry::make('two_factor_confirmed_at')
                            ->label('2FA confirmée le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Non activée'),
                        TextEntry::make('cabinet_id')
                            ->label('Cabinet rattaché')
                            ->placeholder('Aucun — compte plateforme'),
                    ]),
            ]);
    }
}
