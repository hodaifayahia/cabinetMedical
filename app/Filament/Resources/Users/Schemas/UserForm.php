<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identité')
                    ->description('Ce compte accède au tableau de bord Drclick, jamais aux données d’un cabinet.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom complet')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Administrateur Drclick'),
                        TextInput::make('email')
                            ->label('Adresse e-mail')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Uniqueness must hold across every account, not
                            // just the platform ones this resource lists.
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (string $state): string => Str::lower(trim($state)))
                            ->placeholder('admin@admin.com'),
                    ]),
                Section::make('Accès')
                    ->description('Mot de passe d’au moins 12 caractères, avec majuscules, chiffres et symboles.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('password')
                            ->label('Mot de passe')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->rule(Password::min(12)->letters()->mixedCase()->numbers()->symbols())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? 'Transmettez-le au titulaire par un canal sécurisé.'
                                : 'Laisser vide pour conserver le mot de passe actuel.'),
                        TextInput::make('password_confirmation')
                            ->label('Confirmer le mot de passe')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->same('password')
                            ->dehydrated(false)
                            ->required(fn (string $operation): bool => $operation === 'create'),
                    ]),
            ]);
    }
}
