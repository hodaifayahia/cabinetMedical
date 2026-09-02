<?php

namespace App\Filament\Resources\LandingSettings\Pages;

use App\Filament\Resources\LandingSettings\LandingSettingResource;
use App\Models\LandingSetting;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLandingSettings extends ManageRecords
{
    protected static string $resource = LandingSettingResource::class;

    /**
     * Installs created before the contact block became configurable have no
     * rows at all, so the screen would open empty and the admin would have to
     * know the key names to create them. Materialising the defaults here
     * means the phone, e-mail and opening hours are always present and ready
     * to edit. It is idempotent and never overwrites an existing value.
     */
    public function mount(): void
    {
        parent::mount();

        LandingSetting::ensureContactDefaults();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Ajouter un texte'),
        ];
    }
}
