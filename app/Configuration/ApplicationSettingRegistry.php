<?php

namespace App\Configuration;

use Closure;
use InvalidArgumentException;

final class ApplicationSettingRegistry
{
    public const UPLOAD_DEFAULT_MODE = 'uploads.default_mode';

    public const UPLOAD_SESSION_TTL_MINUTES = 'uploads.session_ttl_minutes';

    public const UPLOAD_MAXIMUM_FILES = 'uploads.maximum_files';

    public const UPLOAD_MAXIMUM_INDIVIDUAL_BYTES = 'uploads.maximum_individual_bytes';

    public const UPLOAD_MAXIMUM_TOTAL_BYTES = 'uploads.maximum_total_bytes';

    public const CONNECTIVITY_LAN_ENABLED = 'connectivity.lan_enabled';

    public const CONNECTIVITY_SELECTED_ADAPTER_ID = 'connectivity.selected_adapter_id';

    public const CONNECTIVITY_MANUAL_IPV4 = 'connectivity.manual_ipv4';

    public const CONNECTIVITY_PREFERRED_PORT = 'connectivity.preferred_port';

    public const CONNECTIVITY_FIREWALL_DIAGNOSTICS_ENABLED = 'connectivity.firewall_diagnostics_enabled';

    public const BACKUP_AUTOMATIC_ENABLED = 'backups.automatic_enabled';

    public const BACKUP_SCHEDULE_TIME = 'backups.schedule_time';

    public const BACKUP_VERIFY_AFTER_CREATE = 'backups.verify_after_create';

    public const BACKUP_ENCRYPTION_ENABLED = 'backups.encryption_enabled';

    public const BACKUP_RETENTION_DAILY = 'backups.retention_daily';

    public const BACKUP_RETENTION_WEEKLY = 'backups.retention_weekly';

    public const BACKUP_RETENTION_MONTHLY = 'backups.retention_monthly';

    public const BACKUP_MAXIMUM_STORAGE_BYTES = 'backups.maximum_storage_bytes';

    public const BACKUP_DRIVE_AUTO_UPLOAD = 'backups.drive_auto_upload';

    public const UPDATE_AUTO_CHECK = 'updates.auto_check';

    public const UPDATE_CHANNEL = 'updates.channel';

    public const UPDATE_CHECK_INTERVAL_HOURS = 'updates.check_interval_hours';

    public const UPDATE_AUTO_DOWNLOAD = 'updates.auto_download';

    public const UPDATE_BACKUP_BEFORE_INSTALL = 'updates.backup_before_install';

    public const DESKTOP_CLOSE_TO_TRAY = 'desktop.close_to_tray';

    public const DESKTOP_LAUNCH_AT_LOGIN = 'desktop.launch_at_login';

    public const DESKTOP_NOTIFICATIONS_ENABLED = 'desktop.notifications_enabled';

    public const SECURITY_IDLE_LOCK_MINUTES = 'security.idle_lock_minutes';

    public const SECURITY_LOG_RETENTION_DAYS = 'security.log_retention_days';

    public const DESKTOP_INSTALLATION_ID = 'desktop.installation_id';

    public const DESKTOP_MACHINE_SEED = 'desktop.machine_seed';

    public const LICENSING_TRUSTED_TIME = 'licensing.trusted_time';

    /**
     * @return array<string, ApplicationSettingDefinition>
     */
    public function all(): array
    {
        $maximumFiles = max(1, min(50, (int) config('medismart.uploads.maximum_files', 10)));
        $maximumIndividualBytes = max(1, (int) config(
            'medismart.uploads.maximum_individual_bytes',
            20 * 1024 * 1024,
        ));
        $maximumTotalBytes = max(1, (int) config(
            'medismart.uploads.maximum_total_bytes',
            100 * 1024 * 1024,
        ));
        $sessionTtl = max(1, min(30, (int) config('medismart.uploads.expires_after_minutes', 15)));
        $updateChannels = $this->updateChannels();
        $maximumIdleLock = max(1, (int) config('medismart.security.maximum_idle_lock_minutes', 60));
        $defaultIdleLock = max(1, min(
            $maximumIdleLock,
            (int) config('medismart.security.default_idle_lock_minutes', 15),
        ));

        $definitions = [
            new ApplicationSettingDefinition(
                key: self::UPLOAD_DEFAULT_MODE,
                group: 'uploads',
                permission: 'configuration.connectivity.manage',
                label: 'Mode d\'envoi par défaut',
                helpText: 'Mode proposé lors de la création d\'un nouveau QR temporaire.',
                type: ApplicationSettingType::STRING,
                defaultValue: 'local',
                options: ['local', 'remote', 'relay'],
            ),
            new ApplicationSettingDefinition(
                key: self::UPLOAD_SESSION_TTL_MINUTES,
                group: 'uploads',
                permission: 'configuration.connectivity.manage',
                label: 'Durée de validité du QR',
                helpText: 'Durée en minutes, sans jamais dépasser la limite de sécurité du serveur.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: $sessionTtl,
                minimum: 1,
                maximum: 30,
            ),
            new ApplicationSettingDefinition(
                key: self::UPLOAD_MAXIMUM_FILES,
                group: 'uploads',
                permission: 'configuration.connectivity.manage',
                label: 'Nombre maximal de fichiers',
                helpText: 'Valeur par défaut bornée par la limite immuable de la version installée.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: $maximumFiles,
                minimum: 1,
                maximum: $maximumFiles,
            ),
            new ApplicationSettingDefinition(
                key: self::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES,
                group: 'uploads',
                permission: 'configuration.connectivity.manage',
                label: 'Taille maximale par fichier',
                helpText: 'Taille en octets, bornée par la configuration de sécurité du serveur.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: min($maximumIndividualBytes, $maximumTotalBytes),
                minimum: 1,
                maximum: min($maximumIndividualBytes, $maximumTotalBytes),
            ),
            new ApplicationSettingDefinition(
                key: self::UPLOAD_MAXIMUM_TOTAL_BYTES,
                group: 'uploads',
                permission: 'configuration.connectivity.manage',
                label: 'Taille totale maximale',
                helpText: 'Taille totale en octets, bornée par la configuration de sécurité du serveur.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: $maximumTotalBytes,
                minimum: 1,
                maximum: $maximumTotalBytes,
            ),
            new ApplicationSettingDefinition(
                key: self::CONNECTIVITY_LAN_ENABLED,
                group: 'connectivity',
                permission: 'configuration.connectivity.manage',
                label: 'Envoi sur le réseau local',
                helpText: 'Autorise le mode Wi-Fi local lorsque le listener dédié est disponible.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: false,
                scope: 'installation',
                restart: 'listener',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::CONNECTIVITY_SELECTED_ADAPTER_ID,
                group: 'connectivity',
                permission: 'configuration.connectivity.manage',
                label: 'Carte réseau sélectionnée',
                helpText: 'Identifiant stable de la carte réseau choisie, jamais son adresse IP courante.',
                type: ApplicationSettingType::STRING,
                defaultValue: null,
                nullable: true,
                maximumLength: 255,
                scope: 'installation',
                restart: 'listener',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::CONNECTIVITY_MANUAL_IPV4,
                group: 'connectivity',
                permission: 'configuration.connectivity.manage',
                label: 'Adresse IPv4 locale manuelle',
                helpText: 'Adresse privée de secours lorsque la détection automatique ne suffit pas.',
                type: ApplicationSettingType::STRING,
                defaultValue: null,
                nullable: true,
                rules: ['ipv4', $this->privateIpv4Rule()],
                maximumLength: 15,
                scope: 'installation',
                restart: 'listener',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::CONNECTIVITY_PREFERRED_PORT,
                group: 'connectivity',
                permission: 'configuration.connectivity.manage',
                label: 'Port local préféré',
                helpText: 'Indice facultatif pour le launcher; le port actif reste choisi et vérifié dynamiquement.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: null,
                nullable: true,
                minimum: 1024,
                maximum: 65535,
                scope: 'installation',
                restart: 'listener',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::CONNECTIVITY_FIREWALL_DIAGNOSTICS_ENABLED,
                group: 'connectivity',
                permission: 'configuration.connectivity.manage',
                label: 'Diagnostic du pare-feu',
                helpText: 'Autorise uniquement les contrôles de diagnostic; Drclick ne désactive jamais le pare-feu.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: true,
                scope: 'installation',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_AUTOMATIC_ENABLED,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Sauvegardes automatiques',
                helpText: 'Active la planification uniquement lorsque le scheduler supervisé est disponible.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: false,
                requiresRecentConfirmation: true,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_SCHEDULE_TIME,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Heure de sauvegarde',
                helpText: 'Heure locale du cabinet au format 24 heures.',
                type: ApplicationSettingType::STRING,
                defaultValue: '02:00',
                rules: ['date_format:H:i'],
                maximumLength: 5,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_VERIFY_AFTER_CREATE,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Vérification obligatoire',
                helpText: 'Le manifeste et toutes les sommes de contrôle sont vérifiés avant de publier une archive.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: true,
                editable: false,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_ENCRYPTION_ENABLED,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Chiffrement portable obligatoire',
                helpText: 'Les archives exportées et cloud utilisent le format authentifié v2 avec une phrase secrète non stockée.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: true,
                editable: false,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_RETENTION_DAILY,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Rétention quotidienne',
                helpText: 'Nombre de sauvegardes quotidiennes vérifiées à conserver.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: 7,
                minimum: 1,
                maximum: 365,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_RETENTION_WEEKLY,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Rétention hebdomadaire',
                helpText: 'Nombre de sauvegardes hebdomadaires vérifiées à conserver.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: 4,
                minimum: 1,
                maximum: 104,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_RETENTION_MONTHLY,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Rétention mensuelle',
                helpText: 'Nombre de sauvegardes mensuelles vérifiées à conserver.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: 12,
                minimum: 1,
                maximum: 120,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_MAXIMUM_STORAGE_BYTES,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Plafond de stockage',
                helpText: 'Plafond facultatif en octets; la sauvegarde la plus récente n\'est jamais supprimée.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: null,
                nullable: true,
                minimum: 100 * 1024 * 1024,
                maximum: 10 * 1024 * 1024 * 1024 * 1024,
            ),
            new ApplicationSettingDefinition(
                key: self::BACKUP_DRIVE_AUTO_UPLOAD,
                group: 'backups',
                permission: 'configuration.backups.manage',
                label: 'Envoyer automatiquement vers Drive',
                helpText: 'Clé réservée à une future politique dotée d\'un secret supervisé; aucun contrôle inactif n\'est affiché.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: false,
                backupPolicy: 'reconnect',
                editable: false,
            ),
            new ApplicationSettingDefinition(
                key: self::UPDATE_AUTO_CHECK,
                group: 'updates',
                permission: 'configuration.connectivity.manage',
                label: 'Rechercher automatiquement les mises à jour',
                helpText: 'Vérifie périodiquement le flux de mises à jour signé.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: true,
                scope: 'installation',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::UPDATE_CHANNEL,
                group: 'updates',
                permission: 'configuration.connectivity.manage',
                label: 'Canal de mise à jour',
                helpText: 'Le canal bêta est accepté seulement par une version qui le déclare disponible.',
                type: ApplicationSettingType::STRING,
                defaultValue: 'stable',
                options: $updateChannels,
                scope: 'installation',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::UPDATE_CHECK_INTERVAL_HOURS,
                group: 'updates',
                permission: 'configuration.connectivity.manage',
                label: 'Intervalle de vérification',
                helpText: 'Nombre d\'heures entre deux vérifications du flux signé.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: 24,
                minimum: 1,
                maximum: 168,
                scope: 'installation',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::UPDATE_AUTO_DOWNLOAD,
                group: 'updates',
                permission: 'configuration.connectivity.manage',
                label: 'Télécharger automatiquement',
                helpText: 'Réservé à une future file native persistante; la version actuelle exige une action explicite.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: false,
                scope: 'installation',
                backupPolicy: 'machine_bound',
                editable: false,
            ),
            new ApplicationSettingDefinition(
                key: self::UPDATE_BACKUP_BEFORE_INSTALL,
                group: 'updates',
                permission: 'configuration.connectivity.manage',
                label: 'Sauvegarder avant installation',
                helpText: 'Une mise à jour de schéma impose toujours cette protection, quelle que soit la préférence.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: true,
                scope: 'installation',
                backupPolicy: 'machine_bound',
                editable: false,
            ),
            new ApplicationSettingDefinition(
                key: self::DESKTOP_CLOSE_TO_TRAY,
                group: 'desktop',
                label: 'Réduire dans la zone de notification',
                helpText: 'Ferme la fenêtre sans arrêter les services locaux supervisés.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: true,
                scope: 'installation',
                restart: 'desktop',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::DESKTOP_LAUNCH_AT_LOGIN,
                group: 'desktop',
                label: 'Démarrer à l\'ouverture de session',
                helpText: 'Demande au shell desktop de démarrer Drclick avec Windows.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: false,
                scope: 'installation',
                restart: 'desktop',
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::DESKTOP_NOTIFICATIONS_ENABLED,
                group: 'desktop',
                label: 'Notifications desktop',
                helpText: 'Affiche les notifications opérationnelles sans données médicales sensibles.',
                type: ApplicationSettingType::BOOLEAN,
                defaultValue: true,
                scope: 'user',
                backupPolicy: 'excluded',
            ),
            new ApplicationSettingDefinition(
                key: self::SECURITY_IDLE_LOCK_MINUTES,
                group: 'security',
                label: 'Verrouillage après inactivité',
                helpText: 'Durée en minutes, bornée par la politique de sécurité de la version installée.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: $defaultIdleLock,
                minimum: 1,
                maximum: $maximumIdleLock,
                scope: 'installation',
                requiresRecentConfirmation: true,
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::SECURITY_LOG_RETENTION_DAYS,
                group: 'security',
                label: 'Rétention des journaux techniques',
                helpText: 'Durée en jours, hors journal d\'audit médical soumis à sa propre politique.',
                type: ApplicationSettingType::INTEGER,
                defaultValue: 30,
                minimum: 7,
                maximum: 365,
                scope: 'installation',
                requiresRecentConfirmation: true,
                backupPolicy: 'machine_bound',
            ),
            new ApplicationSettingDefinition(
                key: self::DESKTOP_INSTALLATION_ID,
                group: 'desktop',
                label: 'Identifiant de l\'installation',
                helpText: 'Identifiant interne généré une seule fois par installation.',
                type: ApplicationSettingType::STRING,
                defaultValue: null,
                nullable: true,
                rules: ['uuid'],
                maximumLength: 36,
                scope: 'installation',
                permission: 'internal',
                backupPolicy: 'machine_bound',
                editable: false,
            ),
            new ApplicationSettingDefinition(
                key: self::DESKTOP_MACHINE_SEED,
                group: 'desktop',
                label: 'Secret machine',
                helpText: 'Secret interne utilisé pour dériver une empreinte locale non réversible.',
                type: ApplicationSettingType::STRING,
                defaultValue: null,
                nullable: true,
                rules: ['min:64'],
                maximumLength: 256,
                scope: 'installation',
                sensitive: true,
                redaction: 'full',
                permission: 'internal',
                requiresRecentConfirmation: true,
                backupPolicy: 'excluded',
                editable: false,
            ),
            new ApplicationSettingDefinition(
                key: self::LICENSING_TRUSTED_TIME,
                group: 'licensing',
                label: 'Ancre temporelle de licence',
                helpText: 'Dernière heure de confiance utilisée pour détecter un recul important de l’horloge.',
                type: ApplicationSettingType::STRING,
                defaultValue: null,
                nullable: true,
                rules: ['date'],
                maximumLength: 64,
                scope: 'installation',
                sensitive: true,
                redaction: 'full',
                permission: 'internal',
                backupPolicy: 'excluded',
                editable: false,
            ),
        ];

        $indexed = [];

        foreach ($definitions as $definition) {
            $indexed[$definition->key] = $definition;
        }

        return $indexed;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ApplicationSettingDefinition
    {
        return $this->all()[$key]
            ?? throw new InvalidArgumentException("Unknown application setting [{$key}].");
    }

    /**
     * @return array<string, ApplicationSettingDefinition>
     */
    public function editable(?string $group = null): array
    {
        return array_filter(
            $this->all(),
            static fn (ApplicationSettingDefinition $definition): bool => $definition->editable
                && ($group === null || $definition->group === $group),
        );
    }

    /**
     * @return list<string>
     */
    private function updateChannels(): array
    {
        $configured = config('medismart.updates.allowed_channels', ['stable']);
        $configured = is_array($configured) ? $configured : ['stable'];
        $channels = array_values(array_intersect(['stable', 'beta'], $configured));

        return in_array('stable', $channels, true) ? $channels : ['stable'];
    }

    private function privateIpv4Rule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isPrivateIpv4($value)) {
                $fail('L\'adresse doit être une IPv4 privée utilisable sur le réseau local.');
            }
        };
    }

    private static function isPrivateIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $octets = array_map('intval', explode('.', $address));

        return $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168);
    }
}
