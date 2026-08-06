<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label('Nom'),
                TextEntry::make('email')
                    ->label('Adresse e-mail'),
                TextEntry::make('roles.name')
                    ->label('Rôles')
                    ->badge()
                    ->placeholder('—'),
                TextEntry::make('email_verified_at')
                    ->label('E-mail vérifié le')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('two_factor_confirmed_at')
                    ->label('2FA confirmée le')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->label('Créé le')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }
}
