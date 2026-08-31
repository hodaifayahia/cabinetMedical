<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): ?string
    {
        return 'Comptes autorisés à ouvrir ce tableau de bord. Le personnel d’un cabinet reste géré dans l’installation locale de ce cabinet.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Créer un utilisateur')
                ->icon(Heroicon::OutlinedUserPlus),
        ];
    }
}
