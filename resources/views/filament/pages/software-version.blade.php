<x-filament-panels::page>
    <div class="grid gap-6 md:grid-cols-2">
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Version installée</p>
            <p class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $installedVersion }}</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Canaux : {{ $channels ?: '—' }}</p>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Dernière version publiée</p>
            <p class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $publishedVersion ?? '—' }}</p>
            @if ($publishedAt)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Publiée {{ $publishedAt->diffForHumans() }}</p>
            @endif
        </div>
    </div>

    @if ($publishedNotes)
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Notes de la dernière version</p>
            <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $publishedNotes }}</p>
        </div>
    @endif

    <div @class([
        'rounded-xl p-4 text-sm ring-1',
        'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400' => $updaterConfigured,
        'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400' => ! $updaterConfigured,
    ])>
        @if ($updaterConfigured)
            Le programme de mise à jour signé est configuré : les postes clients peuvent télécharger cette version.
        @else
            Le programme de mise à jour signé n’est pas configuré sur cette instance. La publication enregistre la
            version dans le journal ; la distribution du binaire nécessite l’updater signé embarqué au build.
        @endif
    </div>
</x-filament-panels::page>
