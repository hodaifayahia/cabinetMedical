<?php

namespace App\Filament\Pages;

use App\Enums\PermissionName;
use App\Models\ApplicationEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SoftwareVersion extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Licences & ventes';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.software-version';

    public static function getNavigationLabel(): string
    {
        return 'Version & mises à jour';
    }

    public function getTitle(): string
    {
        return 'Version & mises à jour';
    }

    public function getSubheading(): ?string
    {
        return 'Publiez la dernière version et suivez son déploiement.';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(PermissionName::CONFIGURATION_LICENSING_MANAGE->value);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publier une nouvelle version')
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->modalHeading('Publier une nouvelle version')
                ->modalSubmitActionLabel('Publier')
                ->schema([
                    TextInput::make('version')
                        ->label('Numéro de version')
                        ->required()
                        ->maxLength(50)
                        ->placeholder('1.2.0'),
                    Textarea::make('notes')
                        ->label('Notes de version')
                        ->rows(5),
                ])
                ->action(function (array $data): void {
                    ApplicationEvent::record(
                        'updates.version_published',
                        'info',
                        'Version publiée : '.$data['version'],
                        [
                            'version' => $data['version'],
                            'notes' => $data['notes'] ?? null,
                        ],
                    );

                    Notification::make()
                        ->title('Version publiée')
                        ->body('La version '.$data['version'].' a été enregistrée.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $latest = ApplicationEvent::query()
            ->where('event', 'updates.version_published')
            ->latest('occurred_at')
            ->first();

        return [
            'installedVersion' => (string) config('medismart.version'),
            'updaterConfigured' => (bool) config('medismart.updates.signed_updater_configured', false),
            'channels' => implode(', ', (array) config('medismart.updates.allowed_channels', [])),
            'publishedVersion' => $latest?->context['version'] ?? null,
            'publishedAt' => $latest?->occurred_at,
            'publishedNotes' => $latest?->context['notes'] ?? null,
        ];
    }
}
