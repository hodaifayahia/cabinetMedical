<?php

namespace App\Filament\Pages;

use App\Enums\ServerBackupDriveStatus;
use App\Models\ApplicationEvent;
use App\Models\ServerBackupRun;
use App\Models\User;
use App\Services\Backups\ServerBackupDrive;
use App\Services\Backups\ServerBackupStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use UnitEnum;

/**
 * The online service's own backups: every clinic's data sits in the server
 * database, so a nightly encrypted copy must leave the hosting. This page
 * connects the Google Drive that receives it and shows whether the Drive and
 * the operator's PC actually hold a recent copy.
 *
 * Google sends the admin back to this page with `?code=&state=`; mount()
 * finishes the connection, so the flow needs no route of its own.
 */
class ServerBackups extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Paramètres';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'server-backups';

    protected string $view = 'filament.pages.server-backups';

    public static function getNavigationLabel(): string
    {
        return 'Sauvegardes serveur';
    }

    public function getTitle(): string
    {
        return 'Sauvegardes du serveur';
    }

    public function getSubheading(): ?string
    {
        return 'Suivez la dernière archive chiffrée, sa copie sur le PC Windows et l’envoi vers Google Drive.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_platform_admin === true;
    }

    public static function getNavigationBadge(): ?string
    {
        $status = app(ServerBackupStatus::class);

        if ($status->needsAttention()) {
            return 'Requis';
        }

        return $status->problems() === [] ? null : 'À compléter';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return app(ServerBackupStatus::class)->needsAttention() ? 'danger' : 'warning';
    }

    public function mount(ServerBackupDrive $drive): void
    {
        $error = request()->query('error');
        $code = request()->query('code');
        $state = request()->query('state');

        if ($error === null && ($code === null || $state === null)) {
            return;
        }

        $actor = auth()->user();

        if ($error === null && is_string($code) && is_string($state) && $actor instanceof User) {
            try {
                $connection = $drive->completeAuthorization($code, $state, $actor);

                ApplicationEvent::record(
                    'backups.server_drive_connected',
                    'info',
                    'Google Drive connecté pour les sauvegardes serveur : '.$connection->email,
                    ['email' => $connection->email, 'user_id' => $actor->getKey()],
                );

                Notification::make()
                    ->title('Google Drive connecté')
                    ->body('Les prochaines sauvegardes seront envoyées sur '.$connection->email.'.')
                    ->success()
                    ->send();
            } catch (RuntimeException $exception) {
                Notification::make()->title('Connexion Google impossible')->body($exception->getMessage())->danger()->send();
            }
        } else {
            Notification::make()->title('Connexion Google annulée')->warning()->send();
        }

        // Drop the one-time code from the address bar.
        $this->redirect(static::getUrl());
    }

    protected function getHeaderActions(): array
    {
        $drive = app(ServerBackupDrive::class);

        return [
            Action::make('connectDrive')
                ->label(fn (): string => $drive->isConnected() ? 'Changer de compte Google' : 'Connecter Google Drive')
                ->icon(Heroicon::OutlinedLink)
                ->color(fn (): string => $drive->isConnected() ? 'gray' : 'primary')
                ->visible(fn (): bool => $drive->isConfigured())
                ->action(function () use ($drive): void {
                    $this->redirect($drive->authorizationUrl(static::getUrl()));
                }),
            Action::make('sendLatest')
                ->label('Envoyer la dernière sauvegarde')
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->color('gray')
                ->visible(function () use ($drive): bool {
                    $run = ServerBackupRun::query()->latest('id')->first();

                    return $drive->isConnected()
                        && $run !== null
                        && $run->drive_status !== ServerBackupDriveStatus::UPLOADED
                        && is_file($run->path);
                })
                ->action(function () use ($drive): void {
                    $run = ServerBackupRun::query()->latest('id')->firstOrFail();

                    try {
                        $drive->upload($run, $run->path);
                        Notification::make()->title('Sauvegarde envoyée sur Google Drive')->success()->send();
                    } catch (RuntimeException $exception) {
                        Notification::make()->title('Envoi impossible')->body($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('disconnectDrive')
                ->label('Déconnecter')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn (): bool => $drive->isConnected())
                ->requiresConfirmation()
                ->modalHeading('Déconnecter Google Drive ?')
                ->modalDescription('Les sauvegardes resteront sur le serveur et sur le PC, mais plus aucune copie ne partira sur Google Drive. Les copies déjà envoyées ne sont pas supprimées.')
                ->modalSubmitActionLabel('Déconnecter')
                ->action(function () use ($drive): void {
                    $email = $drive->connection()?->email;
                    $drive->disconnect();

                    ApplicationEvent::record(
                        'backups.server_drive_disconnected',
                        'warning',
                        'Google Drive déconnecté des sauvegardes serveur.',
                        ['email' => $email, 'user_id' => auth()->id()],
                    );

                    Notification::make()->title('Google Drive déconnecté')->warning()->send();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $drive = app(ServerBackupDrive::class);
        $status = app(ServerBackupStatus::class);

        return [
            'problems' => $status->problems(),
            'driveConfigured' => $drive->isConfigured(),
            'connection' => $drive->connection(),
            'lastRun' => $status->lastRun(),
            'lastDriveUploadAt' => $status->lastDriveUploadAt(),
            'lastPcCopyAt' => $status->lastPcCopyAt(),
            'runs' => ServerBackupRun::query()->latest('id')->limit(14)->get(),
            'redirectUri' => static::getUrl(),
            'keepOnDrive' => ServerBackupDrive::KEEP_ON_DRIVE,
        ];
    }
}
