(() => {
    const title = document.getElementById('startup-title');
    const message = document.getElementById('startup-message');
    const progress = document.getElementById('startup-progress');
    const recovery = document.getElementById('startup-recovery');
    const code = document.getElementById('startup-code');

    const safeCodes = new Set([
        'configuration_invalid',
        'health_timeout',
        'health_unavailable',
        'laravel_exited',
        'missing_database_seed',
        'missing_laravel_resources',
        'missing_php_runtime',
        'migration_configuration_invalid',
        'migration_database_invalid',
        'migration_database_newer_than_release',
        'migration_disk_space_insufficient',
        'migration_failed_rolled_back',
        'migration_filesystem_unsafe',
        'migration_helper_contract_invalid',
        'migration_helper_failed',
        'migration_journal_invalid',
        'migration_lock_contended',
        'migration_lock_unavailable',
        'migration_postflight_failed',
        'migration_process_failed',
        'migration_process_spawn_failed',
        'migration_process_timeout',
        'migration_recovery_required',
        'migration_recovery_snapshot_invalid',
        'migration_resources_invalid',
        'migration_runtime_io_failed',
        'migration_runtime_mismatch',
        'migration_snapshot_invalid',
        'port_unavailable',
        'process_retries_exhausted',
        'process_spawn_failed',
        'runtime_io_failed',
        'startup_failed',
    ]);

    const recoveryMessages = Object.freeze({
        migration_database_newer_than_release:
            'Cette base provient d’une version plus récente. Réinstallez la version MediSmart correspondante.',
        migration_disk_space_insufficient:
            'Libérez de l’espace disque, puis relancez MediSmart. Aucune migration n’a été lancée.',
        migration_failed_rolled_back:
            'La mise à niveau a échoué, mais la base précédente vérifiée a été rétablie. Contactez l’assistance avant de réessayer.',
        migration_lock_contended:
            'Une autre opération sécurisée utilise la base locale. Fermez l’autre instance, puis réessayez.',
        migration_recovery_required:
            'Une mise à niveau interrompue nécessite une récupération supervisée. Gardez l’application hors ligne et contactez l’assistance.',
        migration_recovery_snapshot_invalid:
            'La copie de récupération ne peut pas être vérifiée. Gardez l’application hors ligne et contactez l’assistance.',
        migration_resources_invalid:
            'Les ressources de mise à niveau sont invalides. Réinstallez cette version de MediSmart.',
        migration_runtime_mismatch:
            'Les ressources installées ne correspondent pas à cette version. Réinstallez MediSmart.',
    });

    window.__medismartSetStartupState = (state) => {
        if (!state || typeof state !== 'object') {
            return;
        }

        if (state.phase === 'retrying') {
            title.textContent = 'Redémarrage du service local…';
            message.textContent =
                'MediSmart effectue une tentative de récupération limitée.';
            progress.hidden = false;
            recovery.hidden = true;

            return;
        }

        if (state.phase === 'failed') {
            const safeCode = safeCodes.has(state.code)
                ? state.code
                : 'startup_failed';
            title.textContent = 'MediSmart n’a pas pu démarrer';
            message.textContent =
                recoveryMessages[safeCode] ??
                'Vos données existantes n’ont pas été supprimées ni remplacées.';
            progress.hidden = true;
            code.textContent = safeCode;
            recovery.hidden = false;

            return;
        }

        title.textContent = 'Démarrage sécurisé…';
        message.textContent =
            'Le serveur médical local est en cours de vérification.';
        progress.hidden = false;
        recovery.hidden = true;
    };
})();
