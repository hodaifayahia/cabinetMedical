<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ApplicationSettingRegistry as Setting;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\CabinetSetting;
use App\Models\Patient;
use App\Models\UploadedDocument;
use App\Models\UploadSession;
use App\Services\ApplicationHealthService;
use App\Services\ApplicationSettingService;
use App\Services\GoogleDriveService;
use App\Services\LicenseActivationService;
use App\Services\LicenseService;
use App\Services\NetworkService;
use App\Services\QrUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class ConnectivityAndBackupController extends Controller
{
    private const ACTIVE_UPLOAD_COOKIE = 'medismart_active_upload';

    public function edit(
        Request $request,
        ApplicationSettingService $settings,
        ApplicationHealthService $health,
        NetworkService $network,
        GoogleDriveService $drive,
        LicenseService $licenses,
        LicenseActivationService $licenseActivation,
        QrUploadService $qrUploads,
    ): Response {
        $actor = $request->user();
        $canManageConnectivity = $actor?->can(PermissionName::CONFIGURATION_CONNECTIVITY_MANAGE->value) ?? false;
        $canManageBackups = $actor?->can(PermissionName::CONFIGURATION_BACKUPS_MANAGE->value) ?? false;
        $canManageRestore = $actor?->can(PermissionName::CONFIGURATION_RESTORE_MANAGE->value) ?? false;
        $canManageDrive = $actor?->can(PermissionName::CONFIGURATION_DRIVE_MANAGE->value) ?? false;
        $canManageLicense = $actor?->can(PermissionName::CONFIGURATION_LICENSING_MANAGE->value) ?? false;
        $canViewDiagnostics = $actor?->can(PermissionName::CONFIGURATION_DIAGNOSTICS_VIEW->value) ?? false;
        $foundationReady = $this->foundationReady();
        $status = $health->status();
        $candidates = $foundationReady ? $network->ipv4Candidates() : [];
        $selectedAdapterId = $foundationReady ? $network->selectedAdapterId() : null;
        $selectedAdapter = collect($candidates)->first(
            static fn (array $candidate): bool => $candidate['id'] === $selectedAdapterId,
        );
        $localUrl = $foundationReady ? $network->localUploadBaseUrl() : null;
        $lanEnabled = (bool) $this->value(
            $settings,
            Setting::CONNECTIVITY_LAN_ENABLED,
            false,
            $foundationReady,
        );
        $license = $status['license'];
        $remoteLicensed = $foundationReady && $licenses->featureEnabled('remote_upload');
        $relayLicensed = $foundationReady && $licenses->featureEnabled('remote_relay');
        $tunnelReady = ($status['tunnel']['configured'] ?? false) === true
            && ($status['tunnel']['runtime_state'] ?? null) === 'active';
        $tunnelRuntimeState = match ($status['tunnel']['runtime_state'] ?? null) {
            'active' => 'ready',
            'starting' => 'starting',
            'failed', 'error' => 'degraded',
            'stopped' => 'stopped',
            default => 'unknown',
        };
        $lanListenerActive = ($status['lan_listener']['status'] ?? null) === 'active';
        $encryptedBackupsReady = $foundationReady
            && extension_loaded('sodium')
            && function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push');
        $lastBackup = $foundationReady && Schema::hasTable('backup_records')
            ? BackupRecord::query()->latest('started_at')->first()
            : null;
        $backupHistory = ($canManageBackups || $canManageDrive)
            && $foundationReady
            && Schema::hasTable('backup_records')
            ? BackupRecord::query()
                ->latest('started_at')
                ->limit(20)
                ->get([
                    'id',
                    'filename',
                    'size',
                    'sha256',
                    'status',
                    'started_at',
                    'completed_at',
                    'remote_file_id',
                    'drive_upload_status',
                    'drive_upload_bytes',
                    'drive_upload_attempts',
                    'drive_upload_cancel_requested_at',
                ])
                ->map(function (BackupRecord $record) use ($canManageDrive): array {
                    $driveStatus = $this->driveUploadStatus($record);
                    $size = max(0, (int) $record->size);
                    $uploaded = min($size, max(0, (int) $record->drive_upload_bytes));

                    if ($driveStatus === BackupRecord::DRIVE_UPLOAD_COMPLETED) {
                        $uploaded = $size;
                    }

                    return [
                        'id' => (string) $record->getKey(),
                        'filename' => $record->filename,
                        'size_bytes' => $record->size,
                        'sha256_hint' => is_string($record->sha256)
                            ? substr($record->sha256, 0, 12)
                            : null,
                        'status' => $record->status,
                        'started_at' => $record->started_at?->toIso8601String(),
                        'completed_at' => $record->completed_at?->toIso8601String(),
                        'drive_uploaded' => filled($record->remote_file_id),
                        'drive_upload_status' => $driveStatus,
                        'drive_upload_bytes' => $driveStatus === null ? null : $uploaded,
                        'drive_upload_progress_percent' => $driveStatus === null
                            ? null
                            : ($driveStatus === BackupRecord::DRIVE_UPLOAD_COMPLETED
                                ? 100
                                : ($size > 0
                                    ? min(100, max(0, (int) floor(($uploaded / $size) * 100)))
                                    : 0)),
                        'drive_upload_attempts' => $driveStatus === null
                            ? 0
                            : min(3, max(0, (int) $record->drive_upload_attempts)),
                        'drive_cancel_available' => $canManageDrive
                            && blank($record->remote_file_id)
                            && $record->drive_upload_cancel_requested_at === null
                            && in_array($driveStatus, [
                                BackupRecord::DRIVE_UPLOAD_QUEUED,
                                BackupRecord::DRIVE_UPLOAD_UPLOADING,
                                BackupRecord::DRIVE_UPLOAD_RETRYING,
                            ], true),
                    ];
                })->all()
            : [];

        $driveStatus = $canManageDrive
            ? $drive->status(CabinetSetting::current())
            : $this->hiddenDriveStatus();
        $driveConfigured = ($driveStatus['google_drive_configured'] ?? false) === true;
        $driveConnected = ($driveStatus['google_drive_connected'] ?? false) === true;
        $driveLicensed = $foundationReady && $licenses->featureEnabled('google_drive_backup');
        $queueWorkerActive = $this->queueWorkerActive($status);
        $schedulerActive = $this->schedulerActive($status);
        $signedUpdaterAvailable = (bool) config('medismart.runtime.desktop_supervised', false)
            && (bool) config('medismart.updates.signed_updater_configured', false);
        $automaticUpdatesLicensed = $foundationReady && $licenses->featureEnabled('automatic_updates');
        $driveAvailable = $encryptedBackupsReady && $driveConfigured && $driveLicensed;
        $uploadTablesReady = $foundationReady
            && Schema::hasTable('upload_sessions')
            && Schema::hasTable('uploaded_documents');
        $activeSessions = $uploadTablesReady
            ? UploadSession::query()->whereIn('status', [
                UploadSession::STATUS_PENDING,
                UploadSession::STATUS_UPLOADING,
            ])->where('expires_at', '>', now())->count()
            : 0;
        $pendingFiles = $uploadTablesReady
            ? UploadedDocument::query()->where('status', UploadedDocument::STATUS_PENDING_REVIEW)->count()
            : 0;
        $lastReceivedAt = $uploadTablesReady
            ? UploadedDocument::query()->whereNotNull('uploaded_at')->max('uploaded_at')
            : null;

        return Inertia::render('configuration/ConnectivityAndBackup', [
            'settings' => $this->settingsPayload($settings, $foundationReady),
            'runtime' => [
                'connectivity' => [
                    'state' => ! $foundationReady
                        ? 'degraded'
                        : ($lanListenerActive ? 'ready' : 'stopped'),
                    'message' => ! $foundationReady
                        ? 'Les migrations desktop doivent être appliquées.'
                        : (! $lanListenerActive
                            ? 'Le listener LAN dédié n’est pas démarré ou vérifié par le shell desktop.'
                            : 'Le listener LAN dédié est actif et vérifié.'),
                    'adapter_label' => is_array($selectedAdapter) ? $selectedAdapter['label'] : null,
                    'ip_address' => $status['lan_listener']['address'] ?? null,
                    'active_port' => $this->portFromUrl($localUrl),
                    'local_url' => $localUrl,
                    'remote_url' => $status['urls']['remote'] ?? null,
                    'last_checked_at' => $status['checked_at'] ?? null,
                ],
                'uploads' => [
                    'state' => ! $uploadTablesReady
                        ? 'unavailable'
                        : ($lanEnabled && $lanListenerActive && $localUrl !== null ? 'ready' : 'stopped'),
                    'message' => ! $uploadTablesReady
                        ? 'Le stockage des sessions QR n’est pas prêt.'
                        : (! $lanEnabled
                            ? 'Activez le mode LAN pour préparer les envois locaux.'
                            : (! $lanListenerActive
                                ? 'Le shell desktop doit démarrer et vérifier le listener LAN avant de créer un QR.'
                                : 'Les liens restent temporaires et chaque fichier attend une validation.')),
                    'active_sessions' => $activeSessions,
                    'pending_files' => $pendingFiles,
                    'last_received_at' => is_string($lastReceivedAt) ? $lastReceivedAt : null,
                ],
                'tunnel' => [
                    'state' => $tunnelRuntimeState,
                    'message' => ($status['tunnel']['configured'] ?? false) === true
                        ? ($tunnelReady
                            ? 'Le tunnel nommé est actif et son origine correspond à l’hôte configuré.'
                            : 'Le tunnel nommé est configuré, mais il n’est pas encore vérifié actif.')
                        : 'Aucun tunnel Cloudflare nommé n’est configuré.',
                    'configured' => ($status['tunnel']['configured'] ?? false) === true,
                    'hostname' => is_string($status['tunnel']['hostname'] ?? null)
                        ? $status['tunnel']['hostname']
                        : null,
                    'service_installed' => ($status['tunnel']['service_installed'] ?? false) === true,
                    'cloudflared_version' => is_string($status['tunnel']['cloudflared_version'] ?? null)
                        ? $status['tunnel']['cloudflared_version']
                        : null,
                    'retry_count' => is_string($status['tunnel']['retry_count'] ?? null)
                        ? $status['tunnel']['retry_count']
                        : null,
                    'desired_state' => is_string($status['tunnel']['desired_state'] ?? null)
                        ? $status['tunnel']['desired_state']
                        : 'stopped',
                    'last_checked_at' => is_string($status['tunnel']['last_health_check_at'] ?? null)
                        ? $status['tunnel']['last_health_check_at']
                        : null,
                    'last_error' => is_string($status['tunnel']['last_error'] ?? null)
                        ? $status['tunnel']['last_error']
                        : null,
                ],
                'backups' => [
                    'state' => $lastBackup?->status === 'failed'
                        ? 'degraded'
                        : ($foundationReady ? 'ready' : 'unavailable'),
                    'message' => $foundationReady
                        ? 'Les archives .msbackup locales incluent une copie SQLite cohérente et les fichiers gérés, puis sont vérifiées avant publication.'
                        : 'Les tables de suivi des sauvegardes ne sont pas disponibles.',
                    'last_completed_at' => $lastBackup?->completed_at?->toIso8601String(),
                    'last_filename' => $lastBackup?->filename,
                    'last_verified_at' => null,
                ],
                'updates' => [
                    'state' => $signedUpdaterAvailable ? 'ready' : 'unavailable',
                    'message' => $signedUpdaterAvailable
                        ? 'Le shell desktop utilise un endpoint HTTPS intégré et vérifie obligatoirement la signature de chaque mise à jour.'
                        : 'Cette exécution ne contient pas la configuration de publication signée du shell desktop.',
                    'current_version' => (string) config('medismart.version', 'unknown'),
                    'available_version' => null,
                    'last_checked_at' => null,
                ],
            ],
            'capabilities' => [
                'local_upload' => $this->capability(
                    $uploadTablesReady && $lanEnabled && $lanListenerActive && $localUrl !== null,
                    ! $uploadTablesReady
                        ? 'Appliquez les migrations desktop.'
                        : (! $lanEnabled
                            ? 'Activez puis enregistrez le mode LAN.'
                            : (! $lanListenerActive
                                ? 'Le listener LAN desktop n’est pas actif et vérifié.'
                                : ($localUrl === null ? 'Aucune adresse locale utilisable.' : null))),
                ),
                'lan' => $this->capability(
                    $foundationReady && $candidates !== [],
                    $foundationReady
                        ? ($candidates === [] ? 'Aucune carte réseau privée détectée.' : null)
                        : 'Appliquez les migrations desktop.',
                ),
                'remote_upload' => $this->capability(
                    $remoteLicensed && $tunnelReady,
                    ! $remoteLicensed
                        ? 'La licence active n’autorise pas l’envoi distant.'
                        : (! $tunnelReady ? 'Le tunnel nommé n’est pas configuré et vérifié actif.' : null),
                ),
                'relay_upload' => $this->capability(
                    false,
                    ! $foundationReady
                        ? 'Appliquez les migrations desktop avant d’utiliser le relais sécurisé.'
                        : (! $relayLicensed
                            ? 'La licence active n’autorise pas l’envoi par relais sécurisé.'
                            : 'Aucun service relais central n’est configuré.'),
                ),
                'automatic_backups' => $this->capability(
                    $foundationReady && $schedulerActive,
                    ! $foundationReady
                        ? 'Appliquez les migrations desktop avant de planifier une sauvegarde.'
                        : (! $schedulerActive
                            ? 'Le scheduler desktop supervisé n’est pas actif.'
                            : null),
                ),
                'local_backups' => $this->capability(
                    $foundationReady
                        && ($status['database']['driver'] ?? null) === 'sqlite'
                        && ($status['storage']['writable'] ?? false) === true,
                    ! $foundationReady
                        ? 'Appliquez les migrations desktop avant de créer une sauvegarde.'
                        : 'La base SQLite ou le stockage privé n’est pas disponible.',
                ),
                'encrypted_backups' => $this->capability(
                    $encryptedBackupsReady,
                    'L’extension cryptographique Sodium requise n’est pas disponible.',
                ),
                'offline_restore' => $this->capability(
                    $foundationReady
                        && $encryptedBackupsReady
                        && (bool) config('medismart.runtime.desktop_supervised', false),
                    ! $foundationReady
                        ? 'Appliquez les migrations desktop avant toute restauration.'
                        : (! $encryptedBackupsReady
                            ? 'L’extension cryptographique Sodium requise n’est pas disponible.'
                            : 'La restauration complète est disponible uniquement depuis l’application Windows supervisée.'),
                ),
                'google_drive' => $this->capability(
                    $driveAvailable,
                    ! $encryptedBackupsReady
                        ? 'Le chiffrement .msbackup requis n’est pas disponible.'
                        : (! $driveConfigured
                            ? 'Configurez le client OAuth Google de cette installation.'
                            : (! $driveLicensed
                                ? 'La licence active n’autorise pas la sauvegarde Google Drive.'
                                : null)),
                ),
                'drive_upload' => $this->capability(
                    $driveAvailable && $driveConnected && $queueWorkerActive,
                    ! $driveAvailable
                        ? 'La connexion Google Drive n’est pas disponible.'
                        : (! $driveConnected
                            ? 'Connectez d’abord le compte Google Drive.'
                            : (! $queueWorkerActive
                                ? 'Le worker de sauvegarde supervisé n’est pas actif.'
                                : null)),
                ),
                'updates' => $this->capability(
                    $signedUpdaterAvailable,
                    'Construisez la version Windows avec l’endpoint HTTPS et la clé publique Tauri approuvés.',
                ),
                'automatic_updates' => $this->capability(
                    $signedUpdaterAvailable && $automaticUpdatesLicensed,
                    ! $signedUpdaterAvailable
                        ? 'Le programme de mise à jour signé n’est pas disponible dans cette exécution.'
                        : (! $automaticUpdatesLicensed
                            ? 'La licence active n’autorise pas la recherche automatique des mises à jour.'
                            : null),
                ),
                'beta_updates' => $this->capability(false, 'Seul le canal stable est autorisé dans cette version.'),
            ],
            'adapters' => array_map(static fn (array $candidate): array => [
                'id' => $candidate['id'],
                'label' => $candidate['label'],
                'address' => $candidate['address'],
                'connected' => true,
            ], $canManageConnectivity || $canViewDiagnostics ? $candidates : []),
            'backup' => $driveStatus,
            'backupHistory' => array_values($backupHistory),
            'permissions' => [
                'manage_settings' => $canManageConnectivity,
                'manage_backups' => $canManageBackups,
                'manage_restore' => $canManageRestore,
                'manage_drive' => $canManageDrive,
                'manage_license' => $canManageLicense,
                'view_diagnostics' => $canViewDiagnostics,
                'manage_upload_sessions' => $canManageConnectivity,
                'sensitive_actions_confirmed' => $this->recentPasswordConfirmation($request),
            ],
            // Raw SQLite replacement remains deliberately hidden until the
            // validated, atomic .msbackup restore workflow is implemented.
            'legacyRestoreEnabled' => false,
            'activeUpload' => $canManageConnectivity ? $this->activeUpload($request, $qrUploads) : null,
            'activeUploadSessions' => $canManageConnectivity ? $this->activeUploadSessions($uploadTablesReady) : [],
            'pendingUploads' => $canManageConnectivity ? $this->pendingUploads($uploadTablesReady) : [],
            'patients' => $canManageConnectivity ? Patient::query()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->limit(500)
                ->get(['id', 'patient_number', 'first_name', 'last_name'])
                ->map(static fn (Patient $patient): array => [
                    'id' => $patient->getKey(),
                    'label' => trim($patient->patient_number.' · '.$patient->full_name, ' ·'),
                ])->all() : [],
            'qrDataUrl' => null,
            'license' => $license,
            'licenseActivation' => $canManageLicense
                ? $licenseActivation->status()
                : $this->hiddenLicenseActivationStatus(),
        ]);
    }

    public function update(
        Request $request,
        ApplicationSettingService $settings,
        ApplicationHealthService $health,
        NetworkService $network,
        LicenseService $licenses,
    ): RedirectResponse {
        abort_unless($this->foundationReady(), 503, 'Desktop settings migrations are not applied.');

        $actor = $request->user();
        $canManageConnectivity = $actor?->can(PermissionName::CONFIGURATION_CONNECTIVITY_MANAGE->value) ?? false;
        $canManageBackups = $actor?->can(PermissionName::CONFIGURATION_BACKUPS_MANAGE->value) ?? false;
        abort_unless($canManageConnectivity || $canManageBackups, 403);

        $candidateIds = array_column($network->ipv4Candidates(), 'id');
        $desktopSupervised = (bool) config('medismart.runtime.desktop_supervised', false);
        $automaticUpdatesLicensed = $licenses->featureEnabled('automatic_updates');
        $data = $request->validate([
            'uploads.default_mode' => ['required', Rule::in(['local', 'remote', 'relay'])],
            'uploads.session_ttl_minutes' => ['required', 'integer', 'min:1', 'max:30'],
            'uploads.maximum_files' => ['required', 'integer', 'min:1', 'max:50'],
            'uploads.maximum_individual_bytes' => ['required', 'integer', 'min:1'],
            'uploads.maximum_total_bytes' => ['required', 'integer', 'min:1'],
            'connectivity.lan_enabled' => ['required', 'boolean'],
            'connectivity.selected_adapter_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf($request->boolean('connectivity.lan_enabled')),
                static function (string $attribute, mixed $value, \Closure $fail) use ($candidateIds, $desktopSupervised, $request): void {
                    if ($desktopSupervised
                        && is_string($value)
                        && preg_match('/\Aadapter-v1:[a-f0-9]{64}\z/D', $value) !== 1) {
                        $fail('L’identifiant de carte réseau natif est invalide.');

                        return;
                    }
                    if ($request->boolean('connectivity.lan_enabled')
                        && (! is_string($value) || ! in_array($value, $candidateIds, true))) {
                        $fail('Sélectionnez une carte réseau privée actuellement disponible.');
                    }
                },
            ],
            'connectivity.preferred_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'connectivity.firewall_diagnostics_enabled' => ['required', 'boolean'],
            'backups.automatic_enabled' => ['required', 'boolean'],
            'backups.schedule_time' => ['required', 'date_format:H:i'],
            'backups.retention_daily' => ['required', 'integer', 'min:1', 'max:365'],
            'backups.retention_weekly' => ['required', 'integer', 'min:1', 'max:104'],
            'backups.retention_monthly' => ['required', 'integer', 'min:1', 'max:120'],
            'backups.maximum_storage_bytes' => [
                'nullable',
                'integer',
                'min:104857600',
                'max:10995116277760',
            ],
            'updates.auto_check' => ['required', 'boolean'],
            'updates.channel' => ['required', Rule::in(['stable'])],
            'updates.check_interval_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'updates.auto_download' => ['required', 'boolean'],
        ]);

        if ($canManageBackups
            && $data['backups']['automatic_enabled']
            && ! $this->schedulerActive($health->status())) {
            return back()->withErrors([
                'backups.automatic_enabled' => 'Le scheduler desktop supervisé doit être actif avant d’activer cette option.',
            ]);
        }

        $values = [];

        if ($canManageConnectivity) {
            $values += [
                Setting::UPLOAD_DEFAULT_MODE => $data['uploads']['default_mode'],
                Setting::UPLOAD_SESSION_TTL_MINUTES => $data['uploads']['session_ttl_minutes'],
                Setting::UPLOAD_MAXIMUM_FILES => $data['uploads']['maximum_files'],
                Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES => $data['uploads']['maximum_individual_bytes'],
                Setting::UPLOAD_MAXIMUM_TOTAL_BYTES => $data['uploads']['maximum_total_bytes'],
                Setting::CONNECTIVITY_LAN_ENABLED => $data['connectivity']['lan_enabled'],
                Setting::CONNECTIVITY_SELECTED_ADAPTER_ID => $data['connectivity']['selected_adapter_id'],
                Setting::CONNECTIVITY_PREFERRED_PORT => $data['connectivity']['preferred_port'],
                Setting::CONNECTIVITY_FIREWALL_DIAGNOSTICS_ENABLED => $data['connectivity']['firewall_diagnostics_enabled'],
                Setting::UPDATE_AUTO_CHECK => $automaticUpdatesLicensed && $data['updates']['auto_check'],
                Setting::UPDATE_CHANNEL => $data['updates']['channel'],
                Setting::UPDATE_CHECK_INTERVAL_HOURS => $data['updates']['check_interval_hours'],
            ];
        }

        if ($canManageBackups) {
            $values += [
                Setting::BACKUP_AUTOMATIC_ENABLED => $data['backups']['automatic_enabled'],
                Setting::BACKUP_SCHEDULE_TIME => $data['backups']['schedule_time'],
                Setting::BACKUP_RETENTION_DAILY => $data['backups']['retention_daily'],
                Setting::BACKUP_RETENTION_WEEKLY => $data['backups']['retention_weekly'],
                Setting::BACKUP_RETENTION_MONTHLY => $data['backups']['retention_monthly'],
                Setting::BACKUP_MAXIMUM_STORAGE_BYTES => $data['backups']['maximum_storage_bytes'],
            ];
        }

        $settings->setMany($values);

        if ($canManageConnectivity) {
            // Downloads stay coupled to an explicit, backup-authorized
            // installation until a durable native download queue exists.
            $settings->setInternal(Setting::UPDATE_AUTO_DOWNLOAD, false);
        }
        AuditLog::record('settings.connectivity_backup_updated', metadata: [
            'keys' => array_keys($values),
        ], userId: $request->user()?->getKey());
        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Préférences enregistrées. Le runtime natif vérifie ensuite l’état demandé.',
        ]);

        return back();
    }

    public function confirmSensitiveActions(Request $request): RedirectResponse
    {
        $request->session()->put(
            'url.intended',
            route('app.configuration.connectivity-backup.edit'),
        );

        return to_route('password.confirm');
    }

    /** @return array<string, mixed> */
    private function settingsPayload(ApplicationSettingService $settings, bool $ready): array
    {
        return [
            'uploads' => [
                'default_mode' => $this->value($settings, Setting::UPLOAD_DEFAULT_MODE, 'local', $ready),
                'session_ttl_minutes' => $this->value($settings, Setting::UPLOAD_SESSION_TTL_MINUTES, 15, $ready),
                'maximum_files' => $this->value($settings, Setting::UPLOAD_MAXIMUM_FILES, 10, $ready),
                'maximum_individual_bytes' => $this->value($settings, Setting::UPLOAD_MAXIMUM_INDIVIDUAL_BYTES, 20 * 1024 * 1024, $ready),
                'maximum_total_bytes' => $this->value($settings, Setting::UPLOAD_MAXIMUM_TOTAL_BYTES, 100 * 1024 * 1024, $ready),
            ],
            'connectivity' => [
                'lan_enabled' => $this->value($settings, Setting::CONNECTIVITY_LAN_ENABLED, false, $ready),
                'selected_adapter_id' => $this->value($settings, Setting::CONNECTIVITY_SELECTED_ADAPTER_ID, null, $ready),
                'preferred_port' => $this->value($settings, Setting::CONNECTIVITY_PREFERRED_PORT, null, $ready),
                'firewall_diagnostics_enabled' => $this->value($settings, Setting::CONNECTIVITY_FIREWALL_DIAGNOSTICS_ENABLED, true, $ready),
            ],
            'backups' => [
                'automatic_enabled' => $this->value($settings, Setting::BACKUP_AUTOMATIC_ENABLED, false, $ready),
                'schedule_time' => $this->value($settings, Setting::BACKUP_SCHEDULE_TIME, '02:00', $ready),
                'retention_daily' => $this->value($settings, Setting::BACKUP_RETENTION_DAILY, 7, $ready),
                'retention_weekly' => $this->value($settings, Setting::BACKUP_RETENTION_WEEKLY, 4, $ready),
                'retention_monthly' => $this->value($settings, Setting::BACKUP_RETENTION_MONTHLY, 12, $ready),
                'maximum_storage_bytes' => $this->value($settings, Setting::BACKUP_MAXIMUM_STORAGE_BYTES, null, $ready),
            ],
            'updates' => [
                'auto_check' => $this->value($settings, Setting::UPDATE_AUTO_CHECK, true, $ready),
                'channel' => $this->value($settings, Setting::UPDATE_CHANNEL, 'stable', $ready),
                'check_interval_hours' => $this->value($settings, Setting::UPDATE_CHECK_INTERVAL_HOURS, 24, $ready),
                'auto_download' => $this->value($settings, Setting::UPDATE_AUTO_DOWNLOAD, false, $ready),
            ],
        ];
    }

    private function value(
        ApplicationSettingService $settings,
        string $key,
        mixed $fallback,
        bool $ready,
    ): mixed {
        if (! $ready) {
            return $fallback;
        }

        try {
            return $settings->get($key);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function recentPasswordConfirmation(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = max(1, (int) config('auth.password_timeout', 10800));

        return $confirmedAt >= time() - $timeout;
    }

    /** @return array{available: bool, reason: string|null} */
    private function capability(bool $available, ?string $reason): array
    {
        return ['available' => $available, 'reason' => $available ? null : $reason];
    }

    /**
     * Do not disclose the connected account or remote backup metadata to a
     * user who can open another section of this shared page but cannot manage
     * the Drive integration.
     *
     * @return array<string, bool|string|null>
     */
    private function hiddenDriveStatus(): array
    {
        return [
            'google_drive_configured' => false,
            'google_drive_email' => null,
            'google_drive_connected' => false,
            'google_drive_folder' => 'MediSmart Backups',
            'last_backup_at' => null,
            'last_backup_name' => null,
            'verification_state' => 'not_tested',
            'verification_checked_at' => null,
        ];
    }

    /** @return array<string, bool|string|null> */
    private function hiddenLicenseActivationStatus(): array
    {
        return [
            'configured' => false,
            'refresh_configured' => false,
            'deactivation_configured' => false,
            'installation_id_hint' => '—',
            'reason' => 'Autorisation de gestion de licence requise.',
        ];
    }

    private function driveUploadStatus(BackupRecord $record): ?string
    {
        if (filled($record->remote_file_id)) {
            return BackupRecord::DRIVE_UPLOAD_COMPLETED;
        }

        if ($record->drive_upload_status === BackupRecord::DRIVE_UPLOAD_COMPLETED
            || (in_array($record->drive_upload_status, [
                BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
                BackupRecord::DRIVE_UPLOAD_CANCELLED,
            ], true) && $record->drive_upload_cancel_requested_at === null)) {
            return null;
        }

        return in_array($record->drive_upload_status, [
            BackupRecord::DRIVE_UPLOAD_QUEUED,
            BackupRecord::DRIVE_UPLOAD_UPLOADING,
            BackupRecord::DRIVE_UPLOAD_RETRYING,
            BackupRecord::DRIVE_UPLOAD_CANCEL_REQUESTED,
            BackupRecord::DRIVE_UPLOAD_CANCELLED,
            BackupRecord::DRIVE_UPLOAD_COMPLETED,
            BackupRecord::DRIVE_UPLOAD_FAILED,
        ], true) ? $record->drive_upload_status : null;
    }

    /** @param array<string, mixed> $status */
    private function queueWorkerActive(array $status): bool
    {
        return ($status['queue']['worker_status'] ?? null) === 'active'
            && ($status['queue']['operational'] ?? false) === true
            && ($status['queue']['observation_source'] ?? null)
                === 'native_supervisor_process_contract';
    }

    /** @param array<string, mixed> $status */
    private function schedulerActive(array $status): bool
    {
        return ($status['scheduler']['status'] ?? null) === 'active'
            && ($status['scheduler']['process_bound'] ?? false) === true
            && ($status['scheduler']['observation_source'] ?? null)
                === 'native_supervisor_process_contract';
    }

    /** @return list<array<string, mixed>> */
    private function pendingUploads(bool $ready): array
    {
        if (! $ready) {
            return [];
        }

        $uploads = UploadedDocument::query()
            ->with('uploadSession.patient:id,patient_number,first_name,last_name')
            ->where('status', UploadedDocument::STATUS_PENDING_REVIEW)
            ->latest('uploaded_at')
            ->limit(25)
            ->get(['id', 'upload_session_id', 'patient_id', 'original_name', 'size', 'uploaded_at'])
            ->map(static fn (UploadedDocument $document): array => [
                'id' => (string) $document->getKey(),
                'name' => $document->original_name,
                'size_bytes' => $document->size,
                'received_at' => $document->uploaded_at?->toIso8601String() ?? '',
                'status' => 'pending',
                'patient_id' => $document->patient_id ?? $document->uploadSession?->patient_id,
                'patient_name' => $document->uploadSession?->patient?->full_name,
            ])->all();

        return array_values($uploads);
    }

    private function foundationReady(): bool
    {
        return Schema::hasTable('application_settings')
            && Schema::hasTable('upload_sessions')
            && Schema::hasTable('uploaded_documents')
            && Schema::hasTable('backup_records');
    }

    private function portFromUrl(?string $url): ?int
    {
        if ($url === null) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? $port : null;
    }

    /**
     * Return only revocation metadata. The public verifier is intentionally
     * absent so a refreshed configuration page can manage sessions without
     * recovering or persisting the one-time QR secret.
     *
     * @return list<array{
     *     id: string,
     *     mode: string,
     *     status: string,
     *     patient_name: string|null,
     *     expires_at: string,
     *     remaining_seconds: int
     * }>
     */
    private function activeUploadSessions(bool $uploadTablesReady): array
    {
        if (! $uploadTablesReady) {
            return [];
        }

        $sessions = UploadSession::query()
            ->with('patient:id,first_name,last_name')
            ->whereIn('status', [
                UploadSession::STATUS_PENDING,
                UploadSession::STATUS_UPLOADING,
            ])
            ->where('expires_at', '>', now())
            ->latest()
            ->limit(50)
            ->get()
            ->map(static fn (UploadSession $session): array => [
                'id' => (string) $session->getKey(),
                'mode' => $session->mode,
                'status' => $session->status,
                'patient_name' => $session->patient?->full_name,
                'expires_at' => $session->expires_at->toIso8601String(),
                'remaining_seconds' => (int) max(0, now()->diffInSeconds($session->expires_at, false)),
            ])->all();

        return array_values($sessions);
    }

    /** @return array{id: string, mode: string, url: string, expires_at: string, remaining_seconds: int, reachability: array{state: string, checked_at: string|null, message: string|null}}|null */
    private function activeUpload(Request $request, QrUploadService $uploads): ?array
    {
        $encoded = $request->cookie(self::ACTIVE_UPLOAD_COOKIE);

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        Cookie::queue(Cookie::forget(
            self::ACTIVE_UPLOAD_COOKIE,
            '/app/configuration/connectivity-backup',
        ));
        $payload = json_decode($encoded, true);

        if (! is_array($payload)
            || ! is_string($payload['id'] ?? null)
            || ! is_string($payload['url'] ?? null)
            || ! is_int($payload['issued_at'] ?? null)
            || abs(now()->getTimestamp() - $payload['issued_at']) > 180) {
            return null;
        }

        $path = parse_url($payload['url'], PHP_URL_PATH);
        $fragment = parse_url($payload['url'], PHP_URL_FRAGMENT);

        if (! is_string($path) || ! is_string($fragment)) {
            return null;
        }

        parse_str($fragment, $fragmentData);
        $selector = basename($path);
        $verifier = $fragmentData['v'] ?? null;

        if (! is_string($verifier)) {
            return null;
        }

        $session = $uploads->findByToken($selector, $verifier);

        if (! $session instanceof UploadSession
            || $session->getKey() !== $payload['id']
            || ! $session->isUsable()) {
            return null;
        }

        return [
            'id' => (string) $session->getKey(),
            'mode' => $session->mode,
            'url' => $payload['url'],
            'expires_at' => $session->expires_at->toIso8601String(),
            'remaining_seconds' => (int) max(0, now()->diffInSeconds($session->expires_at, false)),
            'reachability' => $this->activeUploadReachability($payload),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{state: string, checked_at: string|null, message: string|null}
     */
    private function activeUploadReachability(array $payload): array
    {
        $reachability = $payload['reachability'] ?? null;

        if (! is_array($reachability)
            || ! in_array($reachability['state'] ?? null, ['not_tested', 'verified', 'failed'], true)) {
            return [
                'state' => 'not_tested',
                'checked_at' => null,
                'message' => null,
            ];
        }

        return [
            'state' => $reachability['state'],
            'checked_at' => is_string($reachability['checked_at'] ?? null)
                ? $reachability['checked_at']
                : null,
            'message' => is_string($reachability['message'] ?? null)
                ? $reachability['message']
                : null,
        ];
    }
}
