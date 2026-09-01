<?php

namespace App\Filament\Pages;

use App\Enums\PermissionName;
use App\Models\ApplicationEvent;
use App\Models\User;
use App\Services\DesktopReleaseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class SoftwareVersion extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Licences & activations';

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
                ->modalDescription('Dès la publication, chaque poste connecté se verra proposer cette version lors de sa prochaine vérification.')
                ->schema([
                    TextInput::make('version')
                        ->label('Numéro de version')
                        ->required()
                        ->maxLength(50)
                        ->placeholder('1.2.0')
                        ->rule('regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/')
                        ->helperText('Doit être supérieure à la version installée, sinon les postes l’ignoreront.'),
                    FileUpload::make('installer')
                        ->label('Installateur')
                        ->required()
                        ->disk('local')
                        ->directory('desktop/incoming')
                        ->helperText('Le fichier produit par la compilation, par exemple Drclick_1.2.0_x64-setup.nsis.zip.'),
                    Textarea::make('signature')
                        ->label('Signature')
                        ->required()
                        ->rows(3)
                        ->helperText('Le contenu du fichier .sig généré à côté de l’installateur. Le serveur ne signe rien lui même.'),
                    Textarea::make('notes')
                        ->label('Notes de version')
                        ->rows(5),
                ])
                ->action(function (array $data, DesktopReleaseService $releases): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    $stored = Storage::disk('local')->path($data['installer']);

                    $release = $releases->publish(
                        new UploadedFile($stored, basename($stored), null, null, true),
                        (string) $data['signature'],
                        (string) $data['version'],
                        $data['notes'] ?? null,
                        $actor,
                    );

                    ApplicationEvent::record(
                        'updates.version_published',
                        'info',
                        'Version publiée : '.$release->version,
                        [
                            'version' => $release->version,
                            'notes' => $release->notes,
                            'sha256' => $release->installer_sha256,
                        ],
                    );

                    Notification::make()
                        ->title('Version publiée')
                        ->body('La version '.$release->version.' est maintenant proposée à tous les postes.')
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
        // Read the release row, not the log entry: the row is what the updater
        // and the download page actually serve, so this page shows the truth
        // rather than a record of someone having pressed a button.
        $current = app(DesktopReleaseService::class)->current();

        return [
            'installedVersion' => (string) config('medismart.version'),
            'updaterConfigured' => (bool) config('medismart.updates.signed_updater_configured', false),
            'channels' => implode(', ', (array) config('medismart.updates.allowed_channels', [])),
            'publishedVersion' => $current?->version,
            'publishedAt' => $current?->published_at,
            'publishedNotes' => $current?->notes,
        ];
    }
}
