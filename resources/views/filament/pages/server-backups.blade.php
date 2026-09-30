{{-- Filament's compiled stylesheet carries no Tailwind utilities for custom
     views, so layout here uses Filament components plus inline styles (the
     panel CSP allows them), like the rest of the console's visual layer. --}}
<x-filament-panels::page>
    @foreach ($problems as $problem)
        <x-filament::callout
            :color="$problem['level']"
            :icon="$problem['level'] === 'danger' ? \Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle : \Filament\Support\Icons\Heroicon::OutlinedInformationCircle"
            :heading="$problem['level'] === 'danger' ? 'Requis' : 'À vérifier'"
            :description="$problem['message']"
        />
    @endforeach

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));">
        <x-filament::section heading="Dernière sauvegarde" :description="$lastRun ? \Illuminate\Support\Number::fileSize($lastRun->size_bytes, 1).' · '.$lastRun->database_driver : 'Aucune pour l’instant'">
            <p style="font-size: 1.5rem; font-weight: 700;">{{ $lastRun?->created_at?->format('d/m/Y H:i') ?? '—' }}</p>
        </x-filament::section>

        <x-filament::section
            heading="Google Drive"
            :description="$connection ? $connection->email.' · dossier « '.\App\Services\Backups\ServerBackupDrive::FOLDER_NAME.' » · '.$keepOnDrive.' dernières copies' : 'Non connecté'"
        >
            <p style="font-size: 1.5rem; font-weight: 700;">{{ $lastDriveUploadAt?->format('d/m/Y H:i') ?? '—' }}</p>
        </x-filament::section>

        <x-filament::section heading="Copie sur le PC Windows" description="Récupérée chaque jour par la tâche planifiée du PC.">
            <p style="font-size: 1.5rem; font-weight: 700;">{{ $lastPcCopyAt?->format('d/m/Y H:i') ?? '—' }}</p>
        </x-filament::section>
    </div>

    @unless ($driveConfigured)
        <x-filament::section heading="Activer Google Drive sur ce serveur">
            <ol style="list-style: decimal; padding-inline-start: 1.25rem; display: grid; gap: 0.35rem;">
                <li>Dans Google Cloud Console, créez un identifiant OAuth de type « Application Web ».</li>
                <li>Ajoutez cette URI de redirection autorisée : <code>{{ $redirectUri }}</code></li>
                <li>Renseignez <code>GOOGLE_CLIENT_ID</code> et <code>GOOGLE_CLIENT_SECRET</code> dans le fichier .env du serveur, puis revenez ici.</li>
            </ol>
        </x-filament::section>
    @endunless

    <x-filament::section heading="Dernières sauvegardes" description="Les fichiers restent 14 jours sur le serveur.">
        @if ($runs->isEmpty())
            <p style="opacity: 0.7;">Aucune sauvegarde pour l’instant.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr style="text-align: start;">
                            <th style="padding: 0.5rem 0.75rem; text-align: start;">Sauvegarde</th>
                            <th style="padding: 0.5rem 0.75rem; text-align: start;">Taille</th>
                            <th style="padding: 0.5rem 0.75rem; text-align: start;">Google Drive</th>
                            <th style="padding: 0.5rem 0.75rem; text-align: start;">PC Windows</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr style="border-top: 1px solid color-mix(in oklab, currentColor 12%, transparent);">
                                <td style="padding: 0.5rem 0.75rem;">
                                    {{ $run->created_at?->format('d/m/Y H:i') }}
                                    <span style="display: block; font-size: 0.75rem; opacity: 0.65;">{{ $run->filename }}</span>
                                </td>
                                <td style="padding: 0.5rem 0.75rem;">{{ \Illuminate\Support\Number::fileSize($run->size_bytes, 1) }}</td>
                                <td style="padding: 0.5rem 0.75rem;">
                                    <x-filament::badge :color="$run->drive_status->color()" style="display: inline-flex;">{{ $run->drive_status->label() }}</x-filament::badge>
                                    @if ($run->drive_error)
                                        <span style="display: block; font-size: 0.75rem; opacity: 0.8;">{{ $run->drive_error }}</span>
                                    @endif
                                </td>
                                <td style="padding: 0.5rem 0.75rem;">{{ $run->pc_copied_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
