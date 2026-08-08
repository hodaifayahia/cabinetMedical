<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUpCircle,
    CheckCircle2,
    Cloud,
    CloudOff,
    Copy,
    DatabaseBackup,
    Download,
    ExternalLink,
    FileClock,
    HardDrive,
    LoaderCircle,
    Network,
    QrCode,
    RefreshCw,
    Save,
    ShieldCheck,
    Trash2,
    Upload,
    Wifi,
    WifiOff,
} from '@lucide/vue';
import { invoke, isTauri } from '@tauri-apps/api/core';
import QRCodeGenerator from 'qrcode';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { postFormData, postJson } from '@/lib/http';
import {
    boundedDriveUploadAttempts,
    boundedDriveUploadBytes,
    boundedDriveUploadProgress,
    driveUploadStatusLabel,
} from '@/pages/configuration/driveUploadPresentation';
import {
    googleOAuthErrorMessage,
    isGoogleAuthorizationUrl,
    openGoogleAuthorization,
} from '@/pages/configuration/googleOAuthContract';
import {
    normalizeOfflineRestorePreparation,
    offlineRestoreComponentLabel,
    offlineRestorePreparationErrorMessage,
} from '@/pages/configuration/restoreContract';
import {
    normalizeNativeUpdateCheck,
    normalizeNativeUpdateInstallResponse,
    normalizeNativeUpdaterStatus,
    normalizeUpdateInstallPreparation,
    signedUpdaterErrorMessage,
} from '@/pages/configuration/updateContract';
import type { NativeUpdaterStatus } from '@/pages/configuration/updateContract';
import type {
    BackupHistoryEntry,
    ConfigurationCapability,
    ConnectivityBackupPageProps,
    ConnectivityBackupSettings,
    OfflineRestoreApplyResult,
    OfflineRestorePreparation,
    OfflineRestoreRuntimeState,
    RemoteDriveBackup,
    RuntimeState,
    UploadMode,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Configuration', href: '/app/configuration' },
            {
                title: 'Connexion & sauvegardes',
                href: '/app/configuration/connectivity-backup',
            },
        ],
    },
});

const props = withDefaults(defineProps<ConnectivityBackupPageProps>(), {
    activeUpload: null,
    activeUploadSessions: () => [],
    pendingUploads: () => [],
    backupHistory: () => [],
    patients: () => [],
    qrDataUrl: null,
});
const page = usePage();

const settingsUrl = '/app/configuration/connectivity-backup';
const uploadSessionsUrl = settingsUrl + '/upload-sessions';
const mebibyte = 1024 * 1024;

const form = useForm<ConnectivityBackupSettings>({
    uploads: { ...props.settings.uploads },
    connectivity: { ...props.settings.connectivity },
    backups: { ...props.settings.backups },
    updates: { ...props.settings.updates },
});
const uploadSessionForm = useForm<{
    mode: UploadMode;
    patient_id: number | null;
}>({
    mode: props.settings.uploads.default_mode,
    patient_id: null,
});
const driveForm = useForm({
    folder_name: props.backup.google_drive_folder,
    passphrase: '',
    passphrase_confirmation: '',
});
const licenseForm = useForm({ serial: '' });
const restoreForm = useForm<{ backup: File | null }>({ backup: null });
const offlineRestoreFileInput = ref<HTMLInputElement | null>(null);
const offlineRestoreArchive = ref<File | null>(null);
const offlineRestoreArchiveName = ref<string | null>(null);
const offlineRestorePassphrase = ref('');
const offlineRestorePreparation = ref<OfflineRestorePreparation | null>(null);
const offlineRestorePreparing = ref(false);
const offlineRestoreApplying = ref(false);
const offlineRestoreConfirmed = ref(false);
const offlineRestoreError = ref<string | null>(null);
const offlineRestoreApplyMessage = ref<string | null>(null);
const offlineRestoreApplyStatus = ref<
    OfflineRestoreApplyResult['status'] | null
>(null);
const offlineRestoreRuntimeState = ref<OfflineRestoreRuntimeState>('unchanged');

const selectedBackupName = ref<string | null>(null);
const revokingUploadId = ref<string | null>(null);
const testingUploadId = ref<string | null>(null);
const reviewingUploadId = ref<string | null>(null);
const generatedQrDataUrl = ref<string | null>(null);
const remoteDriveBackups = ref<RemoteDriveBackup[]>([]);
const remoteDriveBackupsLoaded = ref(false);
const remoteDriveBackupsLoading = ref(false);
const remoteDriveBackupsError = ref<string | null>(null);
const deletingRemoteDriveId = ref<string | null>(null);
const cancellingDriveUploadId = ref<string | null>(null);
const driveConnectionTesting = ref(false);
const driveConnectProcessing = ref(false);
const driveConnectError = ref<string | null>(null);
const driveConnectNotice = ref<string | null>(null);
const licenseRefreshProcessing = ref(false);
const licenseDeactivationProcessing = ref(false);
const copiedUrl = ref(false);
const copiedTechnicalInfo = ref(false);
const browserOnline = ref(true);
const runtimeRefreshInFlight = ref(false);
const settingsNotice = ref<string | null>(null);
const canManageConfiguration = computed(
    () =>
        props.permissions.manage_settings ||
        props.permissions.manage_backups ||
        props.permissions.manage_restore ||
        props.permissions.manage_drive ||
        props.permissions.manage_license,
);
const needsSensitiveConfirmation = computed(
    () =>
        (props.permissions.manage_backups ||
            props.permissions.manage_settings ||
            props.permissions.manage_restore ||
            props.permissions.manage_drive ||
            (props.permissions.manage_license &&
                props.hostedEntitlement === null)) &&
        !props.permissions.sensitive_actions_confirmed,
);
const desktopShell = isTauri();
const nativeUpdaterStatus = ref<NativeUpdaterStatus | null>(null);
const updateChecking = ref(false);
const updateInstalling = ref(false);
const updateError = ref<string | null>(null);
const updateNotice = ref<string | null>(null);
const remainingSeconds = ref<number | null>(
    props.activeUpload?.remaining_seconds ?? null,
);
let countdownTimer: number | null = null;
let runtimePollingTimer: number | null = null;

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;
const isRemoteDriveBackup = (value: unknown): value is RemoteDriveBackup =>
    isRecord(value) &&
    typeof value.id === 'string' &&
    typeof value.name === 'string' &&
    typeof value.size_bytes === 'number' &&
    typeof value.created_at === 'string' &&
    typeof value.sha256_hint === 'string' &&
    typeof value.backup_record_id === 'string';

const uploadModeLabels: Record<UploadMode, string> = {
    local: 'Réseau local',
    remote: 'Tunnel distant',
    relay: 'Relais sécurisé',
};
const stateLabels: Record<RuntimeState, string> = {
    ready: 'Opérationnel',
    starting: 'Démarrage',
    degraded: 'Dégradé',
    stopped: 'Arrêté',
    offline: 'Hors ligne',
    unavailable: 'Indisponible',
    unknown: 'Inconnu',
};
const licenseStateLabels: Record<
    ConnectivityBackupPageProps['license']['state'],
    string
> = {
    not_activated: 'Non activée',
    active: 'Active',
    offline_grace: 'Période de grâce hors ligne',
    expired: 'Expirée',
    suspended: 'Suspendue',
    revoked: 'Révoquée',
    device_limit_reached: 'Limite d’appareils atteinte',
    clock_rollback: 'Horloge à vérifier',
    server_unavailable: 'Serveur indisponible',
    invalid: 'Signature invalide',
};

const signedUpdaterCapability = computed<ConfigurationCapability>(() => {
    if (desktopShell && nativeUpdaterStatus.value?.configured) {
        return { available: true, reason: null };
    }

    return props.capabilities.updates;
});

const nativeUpdateLastCheckedAt = computed(() => {
    const timestamp = nativeUpdaterStatus.value?.last_checked_at;

    return timestamp ? new Date(timestamp * 1000).toISOString() : null;
});

const nativeUpdateState = computed<RuntimeState>(() => {
    if (updateChecking.value || updateInstalling.value) {
        return 'starting';
    }

    if (desktopShell && nativeUpdaterStatus.value?.configured) {
        return 'ready';
    }

    return props.runtime.updates.state;
});

const statusCards = computed(() => [
    {
        key: 'network',
        title: 'Écoute locale',
        icon: Network,
        state: props.runtime.connectivity.state,
        message:
            props.runtime.connectivity.message ??
            'Aucun détail fourni par le service.',
        lines: [
            {
                label: 'Adaptateur',
                value: props.runtime.connectivity.adapter_label ?? '—',
            },
            {
                label: 'Adresse',
                value: props.runtime.connectivity.ip_address
                    ? props.runtime.connectivity.ip_address +
                      (props.runtime.connectivity.active_port
                          ? ':' + props.runtime.connectivity.active_port
                          : '')
                    : '—',
            },
            {
                label: 'URL locale',
                value: props.runtime.connectivity.local_url ?? '—',
            },
            {
                label: 'URL distante',
                value: props.runtime.connectivity.remote_url ?? '—',
            },
        ],
    },
    {
        key: 'uploads',
        title: 'Téléversements',
        icon: QrCode,
        state: props.runtime.uploads.state,
        message:
            props.runtime.uploads.message ??
            'Aucun détail fourni par le service.',
        lines: [
            {
                label: 'Sessions actives',
                value: String(props.runtime.uploads.active_sessions),
            },
            {
                label: 'Fichiers à contrôler',
                value: String(props.runtime.uploads.pending_files),
            },
            {
                label: 'Dernier fichier',
                value: formatDate(props.runtime.uploads.last_received_at),
            },
        ],
    },
    {
        key: 'tunnel',
        title: 'Tunnel distant',
        icon: Cloud,
        state: props.runtime.tunnel.state,
        message:
            props.runtime.tunnel.message ??
            'Aucun détail fourni par le service.',
        lines: [
            {
                label: 'Hôte',
                value: props.runtime.tunnel.hostname ?? '—',
            },
            {
                label: 'Service Windows',
                value: props.runtime.tunnel.service_installed
                    ? 'Installé'
                    : 'Non installé',
            },
            {
                label: 'État demandé',
                value:
                    props.runtime.tunnel.desired_state === 'running'
                        ? 'Démarré'
                        : 'Arrêté',
            },
            {
                label: 'Dernière vérification',
                value: formatDate(props.runtime.tunnel.last_checked_at),
            },
        ],
    },
    {
        key: 'backups',
        title: 'Sauvegardes',
        icon: DatabaseBackup,
        state: props.runtime.backups.state,
        message:
            props.runtime.backups.message ??
            'Aucun détail fourni par le service.',
        lines: [
            {
                label: 'Dernière réussite',
                value: formatDate(props.runtime.backups.last_completed_at),
            },
            {
                label: 'Dernière vérification',
                value: formatDate(props.runtime.backups.last_verified_at),
            },
            {
                label: 'Archive',
                value: props.runtime.backups.last_filename ?? '—',
            },
        ],
    },
    {
        key: 'updates',
        title: 'Application',
        icon: ArrowUpCircle,
        state: nativeUpdateState.value,
        message:
            updateError.value ??
            updateNotice.value ??
            props.runtime.updates.message ??
            'Aucun détail fourni par le service.',
        lines: [
            {
                label: 'Version installée',
                value:
                    nativeUpdaterStatus.value?.current_version ??
                    props.runtime.updates.current_version,
            },
            {
                label: 'Version disponible',
                value:
                    nativeUpdaterStatus.value?.pending_update?.version ??
                    props.runtime.updates.available_version ??
                    '—',
            },
            {
                label: 'Dernière recherche',
                value: formatDate(
                    nativeUpdateLastCheckedAt.value ??
                        props.runtime.updates.last_checked_at,
                ),
            },
        ],
    },
]);

const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];
const driveError = (key: string): string | undefined =>
    (driveForm.errors as Record<string, string | undefined>)[key];
const csrfToken = computed(() =>
    typeof document === 'undefined'
        ? ''
        : (document
              .querySelector('meta[name="csrf-token"]')
              ?.getAttribute('content') ?? ''),
);
const encryptedBackupError = computed(() => {
    const errors = (
        page.props as { errors?: Record<string, string | undefined> }
    ).errors;

    return (
        errors?.encrypted_backup ??
        errors?.passphrase ??
        errors?.passphrase_confirmation
    );
});
const licenseError = computed(
    () =>
        (licenseForm.errors as Record<string, string | undefined>).license ??
        licenseForm.errors.serial,
);
const licenseActionError = computed(() => {
    const errors = (
        page.props as { errors?: Record<string, string | undefined> }
    ).errors;

    return errors?.license_refresh ?? errors?.license_deactivation;
});
const driveRemoteActionError = computed(() => {
    const errors = (
        page.props as { errors?: Record<string, string | undefined> }
    ).errors;

    return (
        errors?.drive_connection ??
        errors?.drive_download ??
        errors?.drive_delete
    );
});
const driveCancelError = computed(() => {
    const errors = (
        page.props as { errors?: Record<string, string | undefined> }
    ).errors;

    return errors?.drive_cancel;
});

const individualLimitMiB = computed<string | number>({
    get: () => form.uploads.maximum_individual_bytes / mebibyte,
    set: (value) => {
        form.uploads.maximum_individual_bytes =
            Math.max(0, Number(value) || 0) * mebibyte;
    },
});
const totalLimitMiB = computed<string | number>({
    get: () => form.uploads.maximum_total_bytes / mebibyte,
    set: (value) => {
        form.uploads.maximum_total_bytes =
            Math.max(0, Number(value) || 0) * mebibyte;
    },
});
const preferredPort = computed<string | number>({
    get: () => form.connectivity.preferred_port ?? '',
    set: (value) => {
        form.connectivity.preferred_port = value === '' ? null : Number(value);
    },
});
const adapterOptions = computed(() => props.adapters);
const maximumStorageMiB = computed<string | number>({
    get: () =>
        form.backups.maximum_storage_bytes === null
            ? ''
            : form.backups.maximum_storage_bytes / mebibyte,
    set: (value) => {
        form.backups.maximum_storage_bytes =
            value === '' ? null : Math.max(0, Number(value) || 0) * mebibyte;
    },
});

const modeCapability = (mode: UploadMode): ConfigurationCapability => {
    if (mode === 'remote') {
        return props.capabilities.remote_upload;
    }

    if (mode === 'relay') {
        return props.capabilities.relay_upload;
    }

    return props.capabilities.local_upload;
};
const selectedSessionCapability = computed(() =>
    modeCapability(uploadSessionForm.mode),
);
const canCreateUpload = computed(
    () =>
        props.permissions.manage_upload_sessions &&
        selectedSessionCapability.value.available &&
        props.activeUpload === null &&
        uploadSessionForm.patient_id !== null &&
        (uploadSessionForm.mode === 'local' || browserOnline.value),
);
const effectiveQrDataUrl = computed(
    () => props.qrDataUrl ?? generatedQrDataUrl.value,
);
const canSaveDrive = computed(
    () =>
        props.permissions.manage_drive &&
        props.permissions.sensitive_actions_confirmed &&
        props.capabilities.local_backups.available &&
        props.capabilities.drive_upload.available &&
        props.backup.google_drive_configured &&
        props.backup.google_drive_connected &&
        driveForm.folder_name.trim().length > 0 &&
        driveForm.passphrase.length >= 12 &&
        driveForm.passphrase === driveForm.passphrase_confirmation &&
        browserOnline.value,
);
const canConnectDrive = computed(
    () =>
        props.permissions.manage_drive &&
        props.permissions.sensitive_actions_confirmed &&
        props.capabilities.google_drive.available &&
        props.backup.google_drive_configured &&
        !props.backup.google_drive_connected &&
        browserOnline.value &&
        !driveConnectProcessing.value,
);
const offlineRestoreRecoveryRequired = computed(
    () => offlineRestoreRuntimeState.value === 'offline_recovery_required',
);
const canPrepareOfflineRestore = computed(
    () =>
        !desktopShell &&
        props.permissions.manage_restore &&
        props.permissions.sensitive_actions_confirmed &&
        props.capabilities.offline_restore.available &&
        offlineRestoreArchive.value !== null &&
        offlineRestorePassphrase.value.length >= 12 &&
        offlineRestorePassphrase.value.length <= 1024 &&
        !offlineRestorePreparing.value &&
        !offlineRestoreApplying.value &&
        !offlineRestoreRecoveryRequired.value,
);
const canApplyOfflineRestore = computed(
    () =>
        !desktopShell &&
        props.permissions.manage_restore &&
        props.permissions.sensitive_actions_confirmed &&
        props.capabilities.offline_restore.available &&
        offlineRestorePreparation.value !== null &&
        offlineRestoreConfirmed.value &&
        !offlineRestorePreparing.value &&
        !offlineRestoreApplying.value &&
        !offlineRestoreRecoveryRequired.value,
);
const activeUploadExpired = computed(
    () => remainingSeconds.value !== null && remainingSeconds.value <= 0,
);
const activeUploadReachabilityLabel = computed(() => {
    if (!props.activeUpload) {
        return '';
    }

    if (activeUploadExpired.value) {
        return 'Expiré';
    }

    if (props.activeUpload.reachability.state === 'verified') {
        return 'Testé joignable';
    }

    if (props.activeUpload.reachability.state === 'failed') {
        return 'Test échoué';
    }

    return 'Non testé';
});
const licenseIsHealthy = computed(() =>
    ['active', 'offline_grace'].includes(props.license.state),
);

const redactedActiveUploadUrl = computed(() => {
    const value = props.activeUpload?.url;

    if (!value) {
        return '—';
    }

    try {
        const url = new URL(value);
        url.hash = url.hash ? '#v=[masqué]' : '';

        return url.toString();
    } catch {
        return '[lien temporaire masqué]';
    }
});

const technicalDetails = computed(() => [
    {
        label: 'Version de l’application',
        value: props.runtime.updates.current_version,
    },
    {
        label: 'Indice navigateur',
        value: browserOnline.value
            ? 'Navigateur signale en ligne'
            : 'Navigateur signale hors ligne',
    },
    {
        label: 'Mode QR sélectionné',
        value: uploadModeLabels[form.uploads.default_mode],
    },
    {
        label: 'État du listener local',
        value: stateLabels[props.runtime.connectivity.state],
    },
    {
        label: 'Carte réseau',
        value: props.runtime.connectivity.adapter_label ?? '—',
    },
    {
        label: 'Adresse IPv4',
        value: props.runtime.connectivity.ip_address ?? '—',
    },
    {
        label: 'Port local',
        value: props.runtime.connectivity.active_port?.toString() ?? '—',
    },
    {
        label: 'URL locale',
        value: props.runtime.connectivity.local_url ?? '—',
    },
    {
        label: 'URL distante',
        value: props.runtime.connectivity.remote_url ?? '—',
    },
    {
        label: 'Tunnel configuré',
        value: props.runtime.tunnel.configured ? 'Oui' : 'Non',
    },
    {
        label: 'Hôte du tunnel',
        value: props.runtime.tunnel.hostname ?? '—',
    },
    {
        label: 'Exécutable tunnel vérifié',
        value: props.runtime.tunnel.service_installed ? 'Oui' : 'Non',
    },
    {
        label: 'Version cloudflared',
        value: props.runtime.tunnel.cloudflared_version ?? '—',
    },
    {
        label: 'Relances tunnel',
        value: props.runtime.tunnel.retry_count ?? '—',
    },
    {
        label: 'Dernière erreur du tunnel',
        value: props.runtime.tunnel.last_error ?? 'Aucune',
    },
    {
        label: 'Lien QR actif',
        value: redactedActiveUploadUrl.value,
    },
    {
        label: 'Dernière vérification réseau',
        value: formatDate(props.runtime.connectivity.last_checked_at),
    },
    {
        label: 'État des téléversements',
        value: stateLabels[props.runtime.uploads.state],
    },
    {
        label: 'Fichiers en attente',
        value: props.runtime.uploads.pending_files.toString(),
    },
    {
        label: 'État des sauvegardes',
        value: stateLabels[props.runtime.backups.state],
    },
    {
        label: 'État de la licence',
        value: licenseStateLabels[props.license.state],
    },
]);

function formatDate(value: string | null): string {
    if (!value) {
        return 'Jamais';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('fr-DZ', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

const formatBytes = (bytes: number): string => {
    if (bytes < 1024) {
        return bytes + ' o';
    }

    if (bytes < mebibyte) {
        return (
            new Intl.NumberFormat('fr-DZ', {
                maximumFractionDigits: 1,
            }).format(bytes / 1024) + ' Kio'
        );
    }

    return (
        new Intl.NumberFormat('fr-DZ', {
            maximumFractionDigits: 1,
        }).format(bytes / mebibyte) + ' Mio'
    );
};
const formatDuration = (seconds: number | null): string => {
    if (seconds === null) {
        return '—';
    }

    if (seconds <= 0) {
        return 'Expirée';
    }

    const minutes = Math.floor(seconds / 60);

    return minutes + ' min ' + String(seconds % 60).padStart(2, '0') + ' s';
};
const stateClass = (state: RuntimeState): string => {
    if (state === 'ready') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300';
    }

    if (state === 'starting' || state === 'degraded') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300';
    }

    if (state === 'unavailable') {
        return 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300';
    }

    return 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
};
const capabilityClass = (capability: ConfigurationCapability): string =>
    capability.available
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
        : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300';
const licenseStateClass = computed(() =>
    licenseIsHealthy.value
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
        : props.license.state === 'not_activated' ||
            props.license.state === 'server_unavailable'
          ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300'
          : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
);

const refreshRuntimeState = () => {
    if (
        runtimeRefreshInFlight.value ||
        document.visibilityState !== 'visible' ||
        form.processing ||
        uploadSessionForm.processing ||
        driveConnectProcessing.value ||
        offlineRestorePreparing.value ||
        offlineRestoreApplying.value ||
        reviewingUploadId.value !== null
    ) {
        return;
    }

    runtimeRefreshInFlight.value = true;
    router.reload({
        only: [
            'runtime',
            'activeUploadSessions',
            'pendingUploads',
            'backup',
            'backupHistory',
        ],
        onFinish: () => {
            runtimeRefreshInFlight.value = false;
            void refreshSignedUpdaterStatus();
        },
    });
};

const updateBrowserState = () => {
    browserOnline.value = navigator.onLine;
    refreshRuntimeState();
};
const refreshSignedUpdaterStatus =
    async (): Promise<NativeUpdaterStatus | null> => {
        if (!desktopShell) {
            return null;
        }

        try {
            const rawStatus = await invoke<unknown>('signed_updater_status');
            const status = normalizeNativeUpdaterStatus(rawStatus);

            if (!status) {
                throw new Error('invalid_signed_updater_status');
            }

            nativeUpdaterStatus.value = status;

            if (status.configured) {
                updateError.value = null;
            }

            return status;
        } catch (error) {
            nativeUpdaterStatus.value = null;
            updateError.value = signedUpdaterErrorMessage(error);

            return null;
        }
    };

const shouldAutomaticallyCheckForUpdates = (
    status: NativeUpdaterStatus,
): boolean => {
    if (
        !props.permissions.manage_settings ||
        !props.capabilities.automatic_updates.available ||
        !form.updates.auto_check ||
        !browserOnline.value ||
        !status.configured ||
        status.checking ||
        status.installing
    ) {
        return false;
    }

    if (status.last_checked_at === null) {
        return true;
    }

    return (
        Date.now() / 1000 - status.last_checked_at >=
        form.updates.check_interval_hours * 60 * 60
    );
};

const checkForSignedUpdate = async (manual = true) => {
    if (
        updateChecking.value ||
        updateInstalling.value ||
        !props.permissions.manage_settings ||
        !browserOnline.value ||
        !nativeUpdaterStatus.value?.configured
    ) {
        return;
    }

    updateChecking.value = true;
    updateError.value = null;

    if (manual) {
        updateNotice.value = 'Recherche sur le canal HTTPS approuvé…';
    }

    try {
        const rawResult = await invoke<unknown>('check_for_signed_update');
        const result = normalizeNativeUpdateCheck(rawResult);

        if (!result) {
            throw new Error('invalid_signed_update_check');
        }

        updateNotice.value = result.update
            ? `La version ${result.update.version} est disponible sur le canal approuvé.`
            : 'DrClickDz est à jour.';
        await refreshSignedUpdaterStatus();
    } catch (error) {
        updateError.value = signedUpdaterErrorMessage(error);
        updateNotice.value = null;
    } finally {
        updateChecking.value = false;
    }
};

const installPendingSignedUpdate = async () => {
    const pending = nativeUpdaterStatus.value?.pending_update;

    if (
        !pending ||
        updateChecking.value ||
        updateInstalling.value ||
        !props.permissions.manage_settings ||
        !nativeUpdaterStatus.value?.configured
    ) {
        return;
    }

    if (!props.permissions.sensitive_actions_confirmed) {
        updateError.value =
            'Confirmez votre mot de passe avant de préparer la sauvegarde de sécurité.';

        return;
    }

    if (
        !window.confirm(
            `Installer DrClickDz ${pending.version} ? Une sauvegarde locale vérifiée sera créée avant le téléchargement. L’application redémarrera pendant l’installation.`,
        )
    ) {
        return;
    }

    updateInstalling.value = true;
    updateError.value = null;
    updateNotice.value =
        'Création et vérification de la sauvegarde de sécurité…';

    try {
        const rawPreparation = await postJson<unknown>(
            '/app/configuration/updates/prepare-install',
            { target_version: pending.version },
        );
        const preparation = normalizeUpdateInstallPreparation(rawPreparation);

        if (
            !preparation ||
            preparation.authorization.target_version !== pending.version
        ) {
            throw new Error('invalid_update_install_preparation');
        }

        updateNotice.value = `Sauvegarde ${preparation.backup.filename} vérifiée. Téléchargement et vérification de la mise à jour…`;
        const rawResult = await invoke<unknown>('install_signed_update', {
            authorization: preparation.authorization,
        });
        const result = normalizeNativeUpdateInstallResponse(rawResult);

        if (!result || result.target_version !== pending.version) {
            throw new Error('invalid_update_install_result');
        }

        updateNotice.value = result.message_fr;
    } catch (error) {
        updateError.value = signedUpdaterErrorMessage(error);
        updateNotice.value = null;
        await refreshSignedUpdaterStatus();
    } finally {
        updateInstalling.value = false;
    }
};

onMounted(() => {
    browserOnline.value = navigator.onLine;
    window.addEventListener('online', updateBrowserState);
    window.addEventListener('offline', updateBrowserState);
    window.addEventListener('focus', refreshRuntimeState);
    countdownTimer = window.setInterval(() => {
        if (remainingSeconds.value !== null && remainingSeconds.value > 0) {
            remainingSeconds.value -= 1;
        }
    }, 1000);
    runtimePollingTimer = window.setInterval(refreshRuntimeState, 10_000);
    void refreshSignedUpdaterStatus().then((status) => {
        if (status && shouldAutomaticallyCheckForUpdates(status)) {
            void checkForSignedUpdate(false);
        }
    });
    void generateQrCode(props.activeUpload?.url ?? null);
});
onBeforeUnmount(() => {
    window.removeEventListener('online', updateBrowserState);
    window.removeEventListener('offline', updateBrowserState);
    window.removeEventListener('focus', refreshRuntimeState);

    if (countdownTimer !== null) {
        window.clearInterval(countdownTimer);
    }

    if (runtimePollingTimer !== null) {
        window.clearInterval(runtimePollingTimer);
    }
});
watch(
    () => [props.activeUpload?.id, props.activeUpload?.remaining_seconds],
    () => {
        remainingSeconds.value = props.activeUpload?.remaining_seconds ?? null;
    },
);
watch(
    () => props.activeUpload?.url,
    (url) => void generateQrCode(url ?? null),
);

const generateQrCode = async (url: string | null) => {
    generatedQrDataUrl.value = null;

    if (!url || typeof window === 'undefined') {
        return;
    }

    try {
        const svg = await QRCodeGenerator.toString(url, {
            type: 'svg',
            errorCorrectionLevel: 'M',
            margin: 2,
            width: 512,
        });
        generatedQrDataUrl.value =
            'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    } catch {
        generatedQrDataUrl.value = null;
    }
};

const copyActiveUrl = async () => {
    if (!props.activeUpload?.url || !navigator.clipboard) {
        return;
    }

    await navigator.clipboard.writeText(props.activeUpload.url);
    copiedUrl.value = true;
    window.setTimeout(() => (copiedUrl.value = false), 2000);
};

const copyTechnicalInfo = async () => {
    if (!navigator.clipboard) {
        return;
    }

    const text = technicalDetails.value
        .map(({ label, value }) => label + ' : ' + value)
        .join('\n');

    await navigator.clipboard.writeText(text);
    copiedTechnicalInfo.value = true;
    window.setTimeout(() => (copiedTechnicalInfo.value = false), 2000);
};

const acceptPendingUpload = (id: string, patientId: number | null) => {
    if (!patientId || reviewingUploadId.value !== null) {
        return;
    }

    reviewingUploadId.value = id;
    router.post(
        `${settingsUrl}/uploaded-documents/${encodeURIComponent(id)}/accept`,
        { patient_id: patientId },
        {
            preserveScroll: true,
            onFinish: () => (reviewingUploadId.value = null),
        },
    );
};

const rejectPendingUpload = (id: string) => {
    if (
        reviewingUploadId.value !== null ||
        !window.confirm('Refuser et supprimer définitivement ce fichier ?')
    ) {
        return;
    }

    reviewingUploadId.value = id;
    router.post(
        `${settingsUrl}/uploaded-documents/${encodeURIComponent(id)}/reject`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (reviewingUploadId.value = null),
        },
    );
};

const submitSettings = () => {
    settingsNotice.value = null;
    form.put(settingsUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            settingsNotice.value =
                'Préférences enregistrées sur le serveur DrClickDz.';
        },
    });
};
const activateLicense = () => {
    if (
        !props.permissions.manage_license ||
        !props.permissions.sensitive_actions_confirmed ||
        !props.licenseActivation.configured ||
        licenseForm.processing
    ) {
        return;
    }

    licenseForm.post('/app/configuration/license/activate', {
        preserveScroll: true,
        onSuccess: () => licenseForm.reset('serial'),
    });
};
const refreshLicense = () => {
    if (
        licenseRefreshProcessing.value ||
        !props.permissions.manage_license ||
        !props.permissions.sensitive_actions_confirmed ||
        !props.licenseActivation.refresh_configured ||
        props.license.state === 'not_activated' ||
        !browserOnline.value
    ) {
        return;
    }

    router.post(
        '/app/configuration/license/refresh',
        {},
        {
            preserveScroll: true,
            onStart: () => (licenseRefreshProcessing.value = true),
            onFinish: () => (licenseRefreshProcessing.value = false),
        },
    );
};
const deactivateLicense = () => {
    if (
        licenseDeactivationProcessing.value ||
        !props.permissions.manage_license ||
        !props.permissions.sensitive_actions_confirmed ||
        !props.licenseActivation.deactivation_configured ||
        props.license.state === 'not_activated' ||
        !browserOnline.value ||
        !window.confirm(
            'Désactiver cette installation ? Faites-le uniquement avant de déplacer la licence vers un autre ordinateur.',
        )
    ) {
        return;
    }

    router.delete('/app/configuration/license', {
        preserveScroll: true,
        onStart: () => (licenseDeactivationProcessing.value = true),
        onFinish: () => (licenseDeactivationProcessing.value = false),
    });
};
const createUploadSession = () => {
    if (!canCreateUpload.value) {
        return;
    }

    uploadSessionForm.post(uploadSessionsUrl, { preserveScroll: true });
};
const revokeUploadSession = (id: string) => {
    if (
        !window.confirm(
            'Révoquer ce lien ? Les téléversements en cours seront refusés.',
        )
    ) {
        return;
    }

    revokingUploadId.value = id;
    router.delete(uploadSessionsUrl + '/' + encodeURIComponent(id), {
        preserveScroll: true,
        onFinish: () => {
            revokingUploadId.value = null;
        },
    });
};
const testUploadSession = (id: string) => {
    if (testingUploadId.value !== null) {
        return;
    }

    testingUploadId.value = id;
    router.post(
        uploadSessionsUrl + '/' + encodeURIComponent(id) + '/test',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testingUploadId.value = null;
            },
        },
    );
};
const chooseOfflineRestoreArchive = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    offlineRestorePreparation.value = null;
    offlineRestoreConfirmed.value = false;
    offlineRestoreApplyMessage.value = null;
    offlineRestoreApplyStatus.value = null;
    offlineRestoreRuntimeState.value = 'unchanged';
    offlineRestoreError.value = null;
    offlineRestoreArchive.value = null;
    offlineRestoreArchiveName.value = file?.name ?? null;

    if (file === null) {
        return;
    }

    if (
        file.name.length > 255 ||
        !/\.msbackup$/i.test(file.name) ||
        /[/\\<>:"|?*\u0000-\u001f\u007f]/.test(file.name)
    ) {
        offlineRestoreError.value =
            'Choisissez une archive DrClickDz portant l’extension .msbackup.';

        return;
    }

    offlineRestoreArchive.value = file;
};
const resetOfflineRestorePreparation = () => {
    if (offlineRestoreApplying.value || offlineRestoreRecoveryRequired.value) {
        return;
    }

    offlineRestoreArchive.value = null;
    offlineRestoreArchiveName.value = null;
    offlineRestorePassphrase.value = '';
    offlineRestorePreparation.value = null;
    offlineRestoreConfirmed.value = false;
    offlineRestoreError.value = null;
    offlineRestoreApplyMessage.value = null;
    offlineRestoreApplyStatus.value = null;
    offlineRestoreRuntimeState.value = 'unchanged';

    if (offlineRestoreFileInput.value !== null) {
        offlineRestoreFileInput.value.value = '';
    }
};
const prepareOfflineRestore = async () => {
    if (
        !canPrepareOfflineRestore.value ||
        offlineRestoreArchive.value === null
    ) {
        return;
    }

    const formData = new FormData();
    formData.append(
        'backup',
        offlineRestoreArchive.value,
        offlineRestoreArchive.value.name,
    );
    formData.append('passphrase', offlineRestorePassphrase.value);
    offlineRestorePassphrase.value = '';
    offlineRestorePreparing.value = true;
    offlineRestorePreparation.value = null;
    offlineRestoreConfirmed.value = false;
    offlineRestoreError.value = null;
    offlineRestoreApplyMessage.value = null;
    offlineRestoreApplyStatus.value = null;
    offlineRestoreRuntimeState.value = 'unchanged';

    try {
        const response = await postFormData<unknown>(
            '/app/configuration/backup/restore/prepare',
            formData,
        );
        const normalized = normalizeOfflineRestorePreparation(response);

        if (normalized === null) {
            throw new Error('invalid_offline_restore_preparation');
        }

        offlineRestorePreparation.value = normalized;
        offlineRestoreArchive.value = null;
    } catch (error) {
        offlineRestoreError.value =
            offlineRestorePreparationErrorMessage(error);
    } finally {
        formData.delete('passphrase');
        formData.delete('backup');
        offlineRestorePreparing.value = false;
    }
};
const applyOfflineRestore = () => {
    if (
        !canApplyOfflineRestore.value ||
        offlineRestorePreparation.value === null
    ) {
        return;
    }

    offlineRestoreError.value =
        'L’application locale d’une archive n’est pas disponible dans le client hébergé. Contactez le support DrClickDz pour une restauration gérée côté serveur.';
};
const chooseLocalBackup = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    restoreForm.backup = file;
    selectedBackupName.value = file?.name ?? null;
    restoreForm.clearErrors();
};
const restoreLocalBackup = () => {
    if (
        !props.permissions.sensitive_actions_confirmed ||
        !restoreForm.backup ||
        !window.confirm(
            'Restaurer cette sauvegarde ? Une copie de la base actuelle sera créée avant son remplacement.',
        )
    ) {
        return;
    }

    restoreForm.post('/app/configuration/backup/restore', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            restoreForm.reset();
            selectedBackupName.value = null;
        },
    });
};
const saveToDrive = () => {
    if (!canSaveDrive.value) {
        return;
    }

    driveForm.post('/app/configuration/backup/drive', {
        preserveScroll: true,
        onSuccess: () =>
            driveForm.reset('passphrase', 'passphrase_confirmation'),
    });
};
const connectDrive = async () => {
    if (!canConnectDrive.value) {
        return;
    }

    driveConnectError.value = null;
    driveConnectNotice.value = null;
    const desktopRuntime = isTauri();
    let browserAuthorizationWindow: Window | null = null;

    if (!desktopRuntime) {
        browserAuthorizationWindow = window.open(
            'about:blank',
            'medismart-google-drive-oauth',
            'popup,width=640,height=760',
        );

        if (browserAuthorizationWindow === null) {
            driveConnectError.value =
                'Le navigateur a bloqué la fenêtre d’autorisation Google. Autorisez les fenêtres contextuelles puis réessayez.';

            return;
        }

        browserAuthorizationWindow.opener = null;
    }

    driveConnectProcessing.value = true;

    try {
        const prepared = await postJson<{ authorization_url: string }>(
            '/app/configuration/backup/google/prepare',
            {},
        );

        if (!isGoogleAuthorizationUrl(prepared.authorization_url)) {
            throw new Error('invalid_google_authorization_url');
        }

        openGoogleAuthorization(
            prepared.authorization_url,
            desktopRuntime,
            browserAuthorizationWindow,
        );

        driveConnectNotice.value =
            'Google a été ouvert dans le navigateur système. Terminez l’autorisation ; cet écran détectera ensuite la connexion automatiquement.';
    } catch (error) {
        browserAuthorizationWindow?.close();
        driveConnectError.value = googleOAuthErrorMessage(error);
    } finally {
        driveConnectProcessing.value = false;
    }
};
const disconnectDrive = () => {
    if (
        !props.permissions.manage_drive ||
        !props.permissions.sensitive_actions_confirmed ||
        !window.confirm(
            'Déconnecter Google Drive ? Les identifiants OAuth locaux seront supprimés et les sauvegardes déjà présentes sur Drive seront conservées.',
        )
    ) {
        return;
    }

    router.delete('/app/configuration/backup/google', {
        preserveScroll: true,
    });
};
const refreshDriveBackups = async () => {
    if (
        !props.backup.google_drive_connected ||
        !props.capabilities.google_drive.available ||
        !browserOnline.value ||
        remoteDriveBackupsLoading.value
    ) {
        return;
    }

    remoteDriveBackupsLoading.value = true;
    remoteDriveBackupsError.value = null;

    try {
        const response = await fetch('/app/configuration/backup/google/files', {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload: unknown = await response.json();

        if (
            !response.ok ||
            !isRecord(payload) ||
            !Array.isArray(payload.backups) ||
            !payload.backups.every(isRemoteDriveBackup)
        ) {
            throw new Error('invalid_drive_backup_list');
        }

        remoteDriveBackups.value = payload.backups;
        remoteDriveBackupsLoaded.value = true;
    } catch {
        remoteDriveBackups.value = [];
        remoteDriveBackupsLoaded.value = false;
        remoteDriveBackupsError.value =
            'La liste Drive n’a pas pu être chargée. Vérifiez la connexion puis réessayez.';
    } finally {
        remoteDriveBackupsLoading.value = false;
    }
};
const deleteRemoteDriveBackup = (backup: RemoteDriveBackup) => {
    if (
        !props.permissions.sensitive_actions_confirmed ||
        deletingRemoteDriveId.value !== null ||
        !browserOnline.value ||
        !window.confirm(
            `Supprimer définitivement « ${backup.name} » de Google Drive ? La copie locale éventuelle ne sera pas supprimée.`,
        )
    ) {
        return;
    }

    deletingRemoteDriveId.value = backup.id;
    router.delete(
        `/app/configuration/backup/google/files/${encodeURIComponent(backup.id)}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                remoteDriveBackups.value = remoteDriveBackups.value.filter(
                    (item) => item.id !== backup.id,
                );
            },
            onFinish: () => (deletingRemoteDriveId.value = null),
        },
    );
};
const cancelDriveUpload = (backup: BackupHistoryEntry) => {
    if (
        !props.permissions.manage_drive ||
        !backup.drive_cancel_available ||
        cancellingDriveUploadId.value !== null ||
        !window.confirm(
            `Annuler l’envoi de « ${backup.filename} » vers Google Drive ? L’archive locale chiffrée sera conservée.`,
        )
    ) {
        return;
    }

    router.delete(
        `/app/configuration/backup/drive/${encodeURIComponent(backup.id)}/upload`,
        {
            preserveScroll: true,
            onStart: () => (cancellingDriveUploadId.value = backup.id),
            onFinish: () => (cancellingDriveUploadId.value = null),
        },
    );
};
const testDriveConnection = () => {
    if (
        driveConnectionTesting.value ||
        !browserOnline.value ||
        !props.backup.google_drive_connected
    ) {
        return;
    }

    router.post(
        '/app/configuration/backup/google/test',
        {},
        {
            preserveScroll: true,
            onStart: () => (driveConnectionTesting.value = true),
            onFinish: () => (driveConnectionTesting.value = false),
        },
    );
};
</script>

<template>
    <Head title="Connexion, téléversements et sauvegardes" />

    <div class="med-page">
        <ConfigurationTabs />

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Connexion & sauvegardes"
                description="Configurez les transferts par QR code, le réseau local, les sauvegardes et les mises à jour depuis un seul écran."
            />
            <Button
                v-if="permissions.manage_settings || permissions.manage_backups"
                type="submit"
                form="connectivity-settings-form"
                :disabled="form.processing || !form.isDirty"
            >
                <LoaderCircle
                    v-if="form.processing"
                    class="size-4 animate-spin"
                />
                <Save v-else class="size-4" />
                {{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}
            </Button>
        </div>

        <div
            v-if="!canManageConfiguration"
            class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <ShieldCheck class="mt-0.5 size-4 shrink-0" />
            Ces réglages sont en lecture seule. Une autorisation
            d’administration est nécessaire pour les modifier.
        </div>

        <div
            v-else-if="needsSensitiveConfirmation"
            class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <div class="flex items-start gap-3">
                <ShieldCheck class="mt-0.5 size-5 shrink-0" />
                <div>
                    <p class="font-semibold">
                        Confirmation requise pour les actions sensibles
                    </p>
                    <p class="mt-1 text-xs">
                        Confirmez votre mot de passe avant un export, une
                        restauration, une connexion Drive ou une modification de
                        licence. La confirmation reste valable pendant la durée
                        de sécurité configurée.
                    </p>
                </div>
            </div>
            <Button as-child class="shrink-0">
                <a
                    href="/app/configuration/connectivity-backup/confirm-sensitive-actions"
                >
                    Confirmer mon mot de passe
                </a>
            </Button>
        </div>

        <section class="med-panel p-6">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <h2
                        class="text-lg font-bold text-slate-900 dark:text-white"
                    >
                        État réel de l’installation
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        État observé par les services locaux, distinct des
                        préférences enregistrées.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex w-fit items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"
                    >
                        <RefreshCw
                            class="size-3.5"
                            :class="runtimeRefreshInFlight && 'animate-spin'"
                        />
                        Actualisation automatique
                    </span>
                    <span
                        class="inline-flex w-fit items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold"
                        :class="
                            browserOnline
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                : 'border-amber-200 bg-amber-50 text-amber-700'
                        "
                    >
                        <Wifi v-if="browserOnline" class="size-3.5" />
                        <WifiOff v-else class="size-3.5" />
                        {{
                            browserOnline
                                ? 'Navigateur connecté'
                                : 'Navigateur hors ligne'
                        }}
                    </span>
                </div>
            </div>

            <div
                class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5"
            >
                <article
                    v-for="card in statusCards"
                    :key="card.key"
                    class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"
                        >
                            <component :is="card.icon" class="size-4" />
                        </span>
                        <span
                            class="rounded-full border px-2.5 py-1 text-[11px] font-bold"
                            :class="stateClass(card.state)"
                        >
                            {{ stateLabels[card.state] }}
                        </span>
                    </div>
                    <h3 class="mt-3 font-semibold">{{ card.title }}</h3>
                    <p class="mt-1 min-h-10 text-xs text-muted-foreground">
                        {{ card.message }}
                    </p>
                    <dl class="mt-3 space-y-1 text-xs">
                        <div
                            v-for="line in card.lines"
                            :key="line.label"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-muted-foreground">
                                {{ line.label }}
                            </dt>
                            <dd class="truncate font-medium">
                                {{ line.value }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>

            <details
                v-if="permissions.view_diagnostics"
                class="mt-5 rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-900/60"
            >
                <summary
                    class="cursor-pointer text-sm font-semibold text-foreground"
                >
                    Informations techniques
                </summary>
                <div
                    class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                >
                    <dl class="grid flex-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        <div
                            v-for="detail in technicalDetails"
                            :key="detail.label"
                            class="rounded-lg border border-slate-200 bg-background px-3 py-2 dark:border-slate-700"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ detail.label }}
                            </dt>
                            <dd class="mt-1 text-sm font-medium break-all">
                                {{ detail.value }}
                            </dd>
                        </div>
                    </dl>
                    <Button
                        type="button"
                        variant="outline"
                        class="shrink-0"
                        @click="copyTechnicalInfo"
                    >
                        <CheckCircle2
                            v-if="copiedTechnicalInfo"
                            class="size-4 text-emerald-600"
                        />
                        <Copy v-else class="size-4" />
                        {{
                            copiedTechnicalInfo
                                ? 'Informations copiées'
                                : 'Copier les informations'
                        }}
                    </Button>
                </div>
                <p class="mt-3 text-xs text-muted-foreground">
                    Les jetons, identifiants OAuth et secrets de tunnel ne sont
                    jamais inclus. Le vérificateur du lien QR est masqué.
                </p>
            </details>
        </section>

        <form
            v-if="permissions.manage_settings || permissions.manage_backups"
            id="connectivity-settings-form"
            class="space-y-6"
            @submit.prevent="submitSettings"
        >
            <section v-if="permissions.manage_settings" class="med-panel p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2
                            class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            <QrCode class="size-5 text-violet-600" />
                            Politique de téléversement
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Limites appliquées à chaque lien temporaire.
                        </p>
                    </div>
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="capabilityClass(capabilities.local_upload)"
                    >
                        {{
                            capabilities.local_upload.available
                                ? 'Service disponible'
                                : 'Service indisponible'
                        }}
                    </span>
                </div>

                <p
                    v-if="
                        !capabilities.local_upload.available &&
                        capabilities.local_upload.reason
                    "
                    class="mt-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ capabilities.local_upload.reason }}
                </p>

                <div class="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-5">
                    <div class="grid gap-2 sm:col-span-2 xl:col-span-1">
                        <Label for="upload-mode">Mode par défaut</Label>
                        <select
                            id="upload-mode"
                            v-model="form.uploads.default_mode"
                            class="med-native-control w-full"
                            :disabled="!permissions.manage_settings"
                        >
                            <option
                                value="local"
                                :disabled="!capabilities.local_upload.available"
                            >
                                Réseau local
                            </option>
                            <option
                                value="remote"
                                :disabled="
                                    !capabilities.remote_upload.available
                                "
                            >
                                Tunnel distant
                            </option>
                            <option
                                value="relay"
                                :disabled="!capabilities.relay_upload.available"
                            >
                                Relais sécurisé
                            </option>
                        </select>
                        <InputError
                            :message="fieldError('uploads.default_mode')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="upload-ttl">Validité (min)</Label>
                        <Input
                            id="upload-ttl"
                            v-model="form.uploads.session_ttl_minutes"
                            type="number"
                            min="1"
                            max="30"
                            :disabled="!permissions.manage_settings"
                        />
                        <InputError
                            :message="fieldError('uploads.session_ttl_minutes')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="upload-files">Nombre de fichiers</Label>
                        <Input
                            id="upload-files"
                            v-model="form.uploads.maximum_files"
                            type="number"
                            min="1"
                            max="50"
                            :disabled="!permissions.manage_settings"
                        />
                        <InputError
                            :message="fieldError('uploads.maximum_files')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="upload-file-size">Par fichier (Mio)</Label>
                        <Input
                            id="upload-file-size"
                            v-model="individualLimitMiB"
                            type="number"
                            min="1"
                            :disabled="!permissions.manage_settings"
                        />
                        <InputError
                            :message="
                                fieldError('uploads.maximum_individual_bytes')
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="upload-total-size">Total (Mio)</Label>
                        <Input
                            id="upload-total-size"
                            v-model="totalLimitMiB"
                            type="number"
                            min="1"
                            :disabled="!permissions.manage_settings"
                        />
                        <InputError
                            :message="fieldError('uploads.maximum_total_bytes')"
                        />
                    </div>
                </div>

                <div
                    class="mt-5 flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900 dark:bg-emerald-950/25"
                >
                    <ShieldCheck
                        class="mt-0.5 size-5 shrink-0 text-emerald-600"
                    />
                    <div>
                        <p class="text-sm font-semibold">
                            Contrôle médical obligatoire
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            PDF, JPEG et PNG restent en quarantaine jusqu’à leur
                            validation sur cet ordinateur. Cette protection ne
                            peut pas être désactivée.
                        </p>
                    </div>
                </div>
            </section>

            <section v-if="permissions.manage_settings" class="med-panel p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2
                            class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            <Network class="size-5 text-blue-600" />
                            Réseau local
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Ces préférences sont enregistrées et appliquées par
                            le serveur DrClickDz.
                        </p>
                    </div>
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="capabilityClass(capabilities.lan)"
                    >
                        {{
                            capabilities.lan.available
                                ? 'Pris en charge'
                                : 'Non pris en charge'
                        }}
                    </span>
                </div>

                <p
                    v-if="
                        !capabilities.lan.available && capabilities.lan.reason
                    "
                    class="mt-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ capabilities.lan.reason }}
                </p>

                <div class="mt-6 grid gap-5 lg:grid-cols-3">
                    <label
                        class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"
                        :class="
                            permissions.manage_settings &&
                            capabilities.lan.available
                                ? 'cursor-pointer hover:border-blue-300'
                                : 'opacity-60'
                        "
                    >
                        <Checkbox
                            :model-value="form.connectivity.lan_enabled"
                            :disabled="
                                !permissions.manage_settings ||
                                !capabilities.lan.available
                            "
                            @update:model-value="
                                (value) =>
                                    (form.connectivity.lan_enabled =
                                        value === true)
                            "
                        />
                        <span>
                            <span class="block text-sm font-semibold">
                                Activer la réception locale
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                N’ouvre jamais l’administration complète.
                            </span>
                        </span>
                    </label>
                    <div class="grid gap-2">
                        <Label for="network-adapter">Carte réseau</Label>
                        <select
                            id="network-adapter"
                            v-model="form.connectivity.selected_adapter_id"
                            class="med-native-control w-full"
                            :disabled="
                                !permissions.manage_settings ||
                                !capabilities.lan.available
                            "
                        >
                            <option :value="null">
                                Sélectionnez une carte
                            </option>
                            <option
                                v-for="adapter in adapterOptions"
                                :key="adapter.id"
                                :value="adapter.id"
                                :disabled="!adapter.connected"
                            >
                                {{ adapter.label
                                }}{{
                                    adapter.address
                                        ? ' · ' + adapter.address
                                        : ''
                                }}
                            </option>
                        </select>
                        <InputError
                            :message="
                                fieldError('connectivity.selected_adapter_id')
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="preferred-port">Port préféré</Label>
                        <Input
                            id="preferred-port"
                            v-model="preferredPort"
                            type="number"
                            min="1024"
                            max="65535"
                            placeholder="Choix dynamique"
                            :disabled="
                                !permissions.manage_settings ||
                                !capabilities.lan.available
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            Le serveur choisit un port sûr et disponible si
                            aucun port n’est imposé.
                        </p>
                        <InputError
                            :message="fieldError('connectivity.preferred_port')"
                        />
                    </div>
                </div>

                <div
                    v-if="desktopShell"
                    class="mt-5 flex gap-3 rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/25 dark:text-blue-100"
                >
                    <Cloud class="mt-0.5 size-5 shrink-0" />
                    <div>
                        <p class="font-semibold">Configuration hébergée</p>
                        <p class="mt-1">
                            Le client de bureau enregistre ces préférences sur
                            le serveur DrClickDz. Il n’exécute aucun listener
                            LAN et ne modifie pas le pare-feu Windows.
                        </p>
                    </div>
                </div>

                <label
                    class="mt-5 flex max-w-xl items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"
                    :class="
                        permissions.manage_settings
                            ? 'cursor-pointer hover:border-blue-300'
                            : 'opacity-60'
                    "
                >
                    <Checkbox
                        :model-value="
                            form.connectivity.firewall_diagnostics_enabled
                        "
                        :disabled="!permissions.manage_settings"
                        @update:model-value="
                            (value) =>
                                (form.connectivity.firewall_diagnostics_enabled =
                                    value === true)
                        "
                    />
                    <span>
                        <span class="block text-sm font-semibold">
                            Vérifier le pare-feu Windows
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">
                            Exécute uniquement un test local borné. Il ne prouve
                            pas l’accès depuis un téléphone et ne modifie jamais
                            les règles Windows.
                        </span>
                    </span>
                </label>
            </section>

            <section v-if="permissions.manage_backups" class="med-panel p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2
                            class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            <DatabaseBackup class="size-5 text-emerald-600" />
                            Politique de sauvegarde
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Planification, vérification et rétention des
                            archives gérées.
                        </p>
                    </div>
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="capabilityClass(capabilities.automatic_backups)"
                    >
                        {{
                            capabilities.automatic_backups.available
                                ? 'Planificateur disponible'
                                : 'Planificateur indisponible'
                        }}
                    </span>
                </div>

                <p
                    v-if="
                        !capabilities.automatic_backups.available &&
                        capabilities.automatic_backups.reason
                    "
                    class="mt-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ capabilities.automatic_backups.reason }}
                </p>

                <div class="mt-6 grid gap-5 lg:grid-cols-2">
                    <label
                        class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"
                        :class="
                            permissions.manage_backups &&
                            capabilities.automatic_backups.available
                                ? 'cursor-pointer hover:border-emerald-300'
                                : 'opacity-60'
                        "
                    >
                        <Checkbox
                            :model-value="form.backups.automatic_enabled"
                            :disabled="
                                !permissions.manage_backups ||
                                !capabilities.automatic_backups.available
                            "
                            @update:model-value="
                                (value) =>
                                    (form.backups.automatic_enabled =
                                        value === true)
                            "
                        />
                        <span>
                            <span class="block text-sm font-semibold">
                                Sauvegarde automatique
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                Crée une archive à l’heure définie.
                            </span>
                        </span>
                    </label>
                    <div class="grid gap-2">
                        <Label for="backup-time">Heure quotidienne</Label>
                        <Input
                            id="backup-time"
                            v-model="form.backups.schedule_time"
                            type="time"
                            :disabled="
                                !permissions.manage_backups ||
                                !capabilities.automatic_backups.available ||
                                !form.backups.automatic_enabled
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            Heure locale du cabinet.
                        </p>
                        <InputError
                            :message="fieldError('backups.schedule_time')"
                        />
                    </div>
                    <article
                        class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-900 dark:bg-emerald-950/20"
                    >
                        <CheckCircle2
                            class="mt-0.5 size-5 shrink-0 text-emerald-600"
                        />
                        <span>
                            <span class="block text-sm font-semibold">
                                Vérification obligatoire
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                Le manifeste et chaque somme SHA-256 sont
                                contrôlés avant publication.
                            </span>
                        </span>
                    </article>
                    <article
                        class="flex items-start gap-3 rounded-xl border p-4"
                        :class="
                            capabilities.encrypted_backups.available
                                ? 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20'
                                : 'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/20'
                        "
                    >
                        <ShieldCheck
                            class="mt-0.5 size-5 shrink-0"
                            :class="
                                capabilities.encrypted_backups.available
                                    ? 'text-emerald-600'
                                    : 'text-amber-600'
                            "
                        />
                        <span>
                            <span class="block text-sm font-semibold">
                                Chiffrement portable obligatoire
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                {{
                                    capabilities.encrypted_backups.available
                                        ? 'Les exports et les copies Drive utilisent le format authentifié v2. La phrase secrète n’est jamais stockée.'
                                        : capabilities.encrypted_backups.reason
                                }}
                            </span>
                        </span>
                    </article>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="grid gap-2">
                        <Label for="retention-daily">Copies quotidiennes</Label>
                        <Input
                            id="retention-daily"
                            v-model="form.backups.retention_daily"
                            type="number"
                            min="1"
                            max="365"
                            :disabled="
                                !permissions.manage_backups ||
                                !capabilities.automatic_backups.available
                            "
                        />
                        <InputError
                            :message="fieldError('backups.retention_daily')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="retention-weekly"
                            >Copies hebdomadaires</Label
                        >
                        <Input
                            id="retention-weekly"
                            v-model="form.backups.retention_weekly"
                            type="number"
                            min="1"
                            max="104"
                            :disabled="
                                !permissions.manage_backups ||
                                !capabilities.automatic_backups.available
                            "
                        />
                        <InputError
                            :message="fieldError('backups.retention_weekly')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="retention-monthly">Copies mensuelles</Label>
                        <Input
                            id="retention-monthly"
                            v-model="form.backups.retention_monthly"
                            type="number"
                            min="1"
                            max="120"
                            :disabled="
                                !permissions.manage_backups ||
                                !capabilities.automatic_backups.available
                            "
                        />
                        <InputError
                            :message="fieldError('backups.retention_monthly')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="maximum-storage">
                            Plafond local (Mio)
                        </Label>
                        <Input
                            id="maximum-storage"
                            v-model="maximumStorageMiB"
                            type="number"
                            min="100"
                            max="10485760"
                            step="100"
                            placeholder="Sans plafond"
                            :disabled="
                                !permissions.manage_backups ||
                                !capabilities.automatic_backups.available
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            Facultatif. La copie valide la plus récente reste
                            toujours protégée.
                        </p>
                        <InputError
                            :message="
                                fieldError('backups.maximum_storage_bytes')
                            "
                        />
                    </div>
                </div>
            </section>

            <section v-if="permissions.manage_settings" class="med-panel p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2
                            class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            <ArrowUpCircle class="size-5 text-amber-600" />
                            Mises à jour signées
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Préférences du programme de mise à jour du bureau.
                        </p>
                    </div>
                    <span
                        class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="capabilityClass(signedUpdaterCapability)"
                    >
                        {{
                            signedUpdaterCapability.available
                                ? 'Programme disponible'
                                : 'Programme indisponible'
                        }}
                    </span>
                </div>

                <p
                    v-if="
                        !signedUpdaterCapability.available &&
                        signedUpdaterCapability.reason
                    "
                    class="mt-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ signedUpdaterCapability.reason }}
                </p>

                <div class="mt-6 grid gap-5 lg:grid-cols-2">
                    <label
                        class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"
                        :class="
                            permissions.manage_settings &&
                            capabilities.automatic_updates.available
                                ? 'cursor-pointer hover:border-amber-300'
                                : 'opacity-60'
                        "
                    >
                        <Checkbox
                            :model-value="form.updates.auto_check"
                            :disabled="
                                !permissions.manage_settings ||
                                !capabilities.automatic_updates.available
                            "
                            @update:model-value="
                                (value) =>
                                    (form.updates.auto_check = value === true)
                            "
                        />
                        <span>
                            <span class="block text-sm font-semibold">
                                Rechercher automatiquement
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                Vérifie uniquement le canal signé sélectionné.
                            </span>
                            <span
                                v-if="
                                    !capabilities.automatic_updates.available &&
                                    capabilities.automatic_updates.reason
                                "
                                class="mt-2 block text-xs text-amber-700 dark:text-amber-300"
                            >
                                {{ capabilities.automatic_updates.reason }}
                            </span>
                        </span>
                    </label>
                    <article
                        class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700"
                    >
                        <ShieldCheck
                            class="mt-0.5 size-5 shrink-0 text-emerald-600"
                        />
                        <span>
                            <span class="block text-sm font-semibold">
                                Installation contrôlée
                            </span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                Aucun téléchargement silencieux. Chaque
                                installation exige une signature valide, une
                                confirmation et une sauvegarde locale vérifiée.
                            </span>
                        </span>
                    </article>
                    <div class="grid gap-2">
                        <Label for="update-channel">Canal</Label>
                        <select
                            id="update-channel"
                            v-model="form.updates.channel"
                            class="med-native-control w-full"
                            :disabled="
                                !permissions.manage_settings ||
                                !signedUpdaterCapability.available
                            "
                        >
                            <option value="stable">Stable</option>
                            <option
                                value="beta"
                                :disabled="!capabilities.beta_updates.available"
                            >
                                Bêta
                            </option>
                        </select>
                        <InputError :message="fieldError('updates.channel')" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="update-interval">Intervalle (heures)</Label>
                        <Input
                            id="update-interval"
                            v-model="form.updates.check_interval_hours"
                            type="number"
                            min="1"
                            max="168"
                            :disabled="
                                !permissions.manage_settings ||
                                !capabilities.automatic_updates.available ||
                                !form.updates.auto_check
                            "
                        />
                        <InputError
                            :message="
                                fieldError('updates.check_interval_hours')
                            "
                        />
                    </div>
                </div>

                <div
                    v-if="nativeUpdaterStatus"
                    class="mt-5 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:grid-cols-3 dark:border-slate-700 dark:bg-slate-900/50"
                >
                    <div>
                        <p class="text-xs font-semibold text-muted-foreground">
                            Version installée
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ nativeUpdaterStatus.current_version }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-muted-foreground">
                            Mise à jour détectée
                        </p>
                        <p class="mt-1 font-semibold">
                            {{
                                nativeUpdaterStatus.pending_update?.version ??
                                'Aucune'
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-muted-foreground">
                            Dernière recherche
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ formatDate(nativeUpdateLastCheckedAt) }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="updateError"
                    class="mt-4 flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ updateError }}
                </p>
                <p
                    v-if="updateNotice"
                    class="mt-4 flex gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200"
                >
                    <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
                    {{ updateNotice }}
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="
                            updateChecking ||
                            updateInstalling ||
                            !browserOnline ||
                            !signedUpdaterCapability.available ||
                            !nativeUpdaterStatus?.configured
                        "
                        @click="checkForSignedUpdate()"
                    >
                        <LoaderCircle
                            v-if="updateChecking"
                            class="size-4 animate-spin"
                        />
                        <RefreshCw v-else class="size-4" />
                        {{
                            updateChecking
                                ? 'Recherche…'
                                : 'Rechercher maintenant'
                        }}
                    </Button>
                    <Button
                        v-if="nativeUpdaterStatus?.pending_update"
                        type="button"
                        :disabled="
                            updateChecking ||
                            updateInstalling ||
                            !browserOnline ||
                            !permissions.sensitive_actions_confirmed
                        "
                        @click="installPendingSignedUpdate"
                    >
                        <LoaderCircle
                            v-if="updateInstalling"
                            class="size-4 animate-spin"
                        />
                        <ArrowUpCircle v-else class="size-4" />
                        {{
                            updateInstalling
                                ? 'Préparation…'
                                : `Sauvegarder et installer ${nativeUpdaterStatus.pending_update.version}`
                        }}
                    </Button>
                    <Button
                        v-if="
                            nativeUpdaterStatus?.pending_update &&
                            !permissions.sensitive_actions_confirmed
                        "
                        type="button"
                        variant="outline"
                        @click="
                            router.visit(
                                '/app/configuration/connectivity-backup/confirm-sensitive-actions',
                            )
                        "
                    >
                        <ShieldCheck class="size-4" />
                        Confirmer le mot de passe
                    </Button>
                    <span
                        v-if="!browserOnline"
                        class="text-xs text-amber-700 dark:text-amber-300"
                    >
                        Reconnectez Internet pour rechercher ou installer une
                        mise à jour.
                    </span>
                </div>
            </section>

            <div
                v-if="permissions.manage_settings || permissions.manage_backups"
                class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end"
            >
                <p
                    v-if="settingsNotice && !form.isDirty"
                    class="text-sm text-emerald-700 dark:text-emerald-300"
                    role="status"
                >
                    {{ settingsNotice }}
                </p>
                <p
                    v-if="form.isDirty"
                    class="text-sm text-amber-700 dark:text-amber-300"
                >
                    Modifications non enregistrées
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    <Save v-else class="size-4" />
                    {{
                        form.processing
                            ? 'Enregistrement…'
                            : 'Enregistrer les réglages'
                    }}
                </Button>
            </div>
        </form>

        <section
            v-if="permissions.manage_upload_sessions"
            class="med-panel p-6"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                    >
                        <QrCode class="size-5 text-violet-600" />
                        Lien de téléversement temporaire
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Les fichiers reçus restent à contrôler avant leur
                        classement.
                    </p>
                </div>
                <span
                    v-if="activeUpload"
                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                    :class="
                        activeUploadExpired
                            ? 'border-red-200 bg-red-50 text-red-700'
                            : activeUpload.reachability.state === 'verified'
                              ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                              : activeUpload.reachability.state === 'failed'
                                ? 'border-amber-200 bg-amber-50 text-amber-700'
                                : 'border-slate-200 bg-slate-50 text-slate-700'
                    "
                >
                    {{ activeUploadReachabilityLabel }}
                </span>
            </div>

            <div
                v-if="activeUpload"
                class="mt-6 grid gap-6 lg:grid-cols-[180px_minmax(0,1fr)]"
            >
                <div
                    class="flex aspect-square max-w-[180px] items-center justify-center rounded-2xl border border-violet-200 bg-white p-3 dark:border-violet-900 dark:bg-slate-900"
                >
                    <img
                        v-if="effectiveQrDataUrl"
                        :src="effectiveQrDataUrl"
                        alt="QR code du lien de téléversement"
                        class="h-full w-full object-contain"
                    />
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 text-center text-xs text-muted-foreground"
                    >
                        <QrCode class="size-12 text-violet-400" />
                        URL disponible, QR graphique non fourni
                    </div>
                </div>
                <div class="min-w-0">
                    <div
                        class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50"
                    >
                        <p class="text-xs text-muted-foreground">
                            Adresse à ouvrir sur le téléphone
                        </p>
                        <p
                            class="mt-2 font-mono text-sm font-semibold break-all"
                        >
                            {{ activeUpload.url }}
                        </p>
                    </div>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">Mode</dt>
                            <dd class="mt-1 font-semibold">
                                {{ uploadModeLabels[activeUpload.mode] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Expiration
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{ formatDate(activeUpload.expires_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Temps restant
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{ formatDuration(remainingSeconds) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Test de joignabilité
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{ activeUploadReachabilityLabel }}
                            </dd>
                            <p
                                v-if="activeUpload.reachability.checked_at"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{
                                    formatDate(
                                        activeUpload.reachability.checked_at,
                                    )
                                }}
                            </p>
                        </div>
                    </dl>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <a
                            :href="activeUpload.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-input bg-white px-4 text-sm font-semibold shadow-sm hover:bg-slate-50 dark:bg-slate-900"
                        >
                            <ExternalLink class="size-4" /> Ouvrir
                        </a>
                        <Button
                            type="button"
                            variant="outline"
                            @click="copyActiveUrl"
                        >
                            <CheckCircle2 v-if="copiedUrl" class="size-4" />
                            <Copy v-else class="size-4" />
                            {{ copiedUrl ? 'Copié' : 'Copier le lien' }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="
                                activeUploadExpired || testingUploadId !== null
                            "
                            @click="testUploadSession(activeUpload.id)"
                        >
                            <LoaderCircle
                                v-if="testingUploadId === activeUpload.id"
                                class="size-4 animate-spin"
                            />
                            <RefreshCw v-else class="size-4" />
                            Tester
                        </Button>
                        <Button
                            v-if="permissions.manage_upload_sessions"
                            type="button"
                            variant="outline"
                            class="border-red-200 text-red-600 hover:bg-red-50"
                            :disabled="revokingUploadId !== null"
                            @click="revokeUploadSession(activeUpload.id)"
                        >
                            <LoaderCircle
                                v-if="revokingUploadId === activeUpload.id"
                                class="size-4 animate-spin"
                            />
                            <Trash2 v-else class="size-4" />
                            Révoquer
                        </Button>
                    </div>
                </div>
            </div>

            <form
                v-else-if="permissions.manage_upload_sessions"
                class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50"
                @submit.prevent="createUploadSession"
            >
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div class="grid w-full max-w-sm gap-2">
                        <Label for="session-patient">Patient concerné</Label>
                        <select
                            id="session-patient"
                            v-model="uploadSessionForm.patient_id"
                            class="med-native-control w-full"
                            required
                        >
                            <option :value="null" disabled>
                                Sélectionner un patient
                            </option>
                            <option
                                v-for="patient in patients"
                                :key="patient.id"
                                :value="patient.id"
                            >
                                {{ patient.label }}
                            </option>
                        </select>
                        <InputError
                            :message="uploadSessionForm.errors.patient_id"
                        />
                    </div>
                    <div class="grid w-full max-w-sm gap-2">
                        <Label for="session-mode">Mode du nouveau lien</Label>
                        <select
                            id="session-mode"
                            v-model="uploadSessionForm.mode"
                            class="med-native-control w-full"
                        >
                            <option
                                value="local"
                                :disabled="!capabilities.local_upload.available"
                            >
                                Réseau local
                            </option>
                            <option
                                value="remote"
                                :disabled="
                                    !capabilities.remote_upload.available
                                "
                            >
                                Tunnel distant
                            </option>
                            <option
                                value="relay"
                                :disabled="!capabilities.relay_upload.available"
                            >
                                Relais sécurisé
                            </option>
                        </select>
                        <p
                            v-if="selectedSessionCapability.reason"
                            class="text-xs text-muted-foreground"
                        >
                            {{ selectedSessionCapability.reason }}
                        </p>
                        <InputError :message="uploadSessionForm.errors.mode" />
                    </div>
                    <Button
                        type="submit"
                        :disabled="
                            uploadSessionForm.processing || !canCreateUpload
                        "
                    >
                        <LoaderCircle
                            v-if="uploadSessionForm.processing"
                            class="size-4 animate-spin"
                        />
                        <QrCode v-else class="size-4" />
                        {{
                            uploadSessionForm.processing
                                ? 'Création…'
                                : 'Créer le lien QR'
                        }}
                    </Button>
                </div>
            </form>

            <div v-if="activeUploadSessions.length > 0" class="mt-6">
                <h3 class="mb-3 flex items-center gap-2 font-semibold">
                    <QrCode class="size-4 text-violet-600" />
                    Sessions encore actives ({{ activeUploadSessions.length }})
                </h3>
                <p class="mb-3 text-sm text-muted-foreground">
                    Les liens secrets ne sont jamais réaffichés après leur
                    création. Vous pouvez néanmoins révoquer chaque session
                    depuis cette liste.
                </p>
                <div class="med-table-wrap">
                    <table class="med-table">
                        <thead
                            class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Patient</th>
                                <th class="px-4 py-3 font-medium">Mode</th>
                                <th class="px-4 py-3 font-medium">État</th>
                                <th class="px-4 py-3 font-medium">
                                    Expiration
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr
                                v-for="session in activeUploadSessions"
                                :key="session.id"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{
                                        session.patient_name ??
                                        'Patient non attribué'
                                    }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ uploadModeLabels[session.mode] }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{
                                        session.status === 'uploading'
                                            ? 'Réception en cours'
                                            : 'En attente'
                                    }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ formatDate(session.expires_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        v-if="
                                            permissions.manage_upload_sessions
                                        "
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        class="border-red-200 text-red-600 hover:bg-red-50"
                                        :disabled="revokingUploadId !== null"
                                        @click="revokeUploadSession(session.id)"
                                    >
                                        <LoaderCircle
                                            v-if="
                                                revokingUploadId === session.id
                                            "
                                            class="size-4 animate-spin"
                                        />
                                        <Trash2 v-else class="size-4" />
                                        Révoquer
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="pendingUploads.length > 0" class="mt-6">
                <h3 class="mb-3 flex items-center gap-2 font-semibold">
                    <FileClock class="size-4 text-amber-600" />
                    Fichiers reçus à contrôler ({{ pendingUploads.length }})
                </h3>
                <div class="med-table-wrap">
                    <table class="med-table">
                        <thead
                            class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Fichier</th>
                                <th class="px-4 py-3 font-medium">Taille</th>
                                <th class="px-4 py-3 font-medium">Reçu</th>
                                <th class="px-4 py-3 font-medium">Patient</th>
                                <th class="px-4 py-3 font-medium">État</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr
                                v-for="upload in pendingUploads"
                                :key="upload.id"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ upload.name }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ formatBytes(upload.size_bytes) }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ formatDate(upload.received_at) }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ upload.patient_name ?? 'Non attribué' }}
                                </td>
                                <td class="px-4 py-3">
                                    {{
                                        upload.status === 'accepted'
                                            ? 'Accepté'
                                            : upload.status === 'rejected'
                                              ? 'Refusé'
                                              : 'En attente'
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a
                                            :href="`${settingsUrl}/uploaded-documents/${encodeURIComponent(upload.id)}/preview`"
                                            target="_blank"
                                            rel="noreferrer"
                                            class="inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
                                        >
                                            <ExternalLink class="size-4" />
                                            Examiner
                                        </a>
                                        <Button
                                            type="button"
                                            size="sm"
                                            :disabled="
                                                reviewingUploadId !== null ||
                                                upload.patient_id === null
                                            "
                                            @click="
                                                acceptPendingUpload(
                                                    upload.id,
                                                    upload.patient_id,
                                                )
                                            "
                                        >
                                            <CheckCircle2 class="size-4" />
                                            Accepter
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            class="border-red-200 text-red-600"
                                            :disabled="
                                                reviewingUploadId !== null
                                            "
                                            @click="
                                                rejectPendingUpload(upload.id)
                                            "
                                        >
                                            <Trash2 class="size-4" />
                                            Refuser
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section v-if="hostedEntitlement" class="med-panel p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                    >
                        <ShieldCheck class="size-5 text-emerald-600" />
                        Licence du cabinet
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Cette licence est attribuée et gérée par la plateforme
                        DrClickDz.
                    </p>
                </div>
                <span
                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                    :class="
                        hostedEntitlement.status === 'active'
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200'
                            : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200'
                    "
                >
                    {{ hostedEntitlement.status_label }}
                </span>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Type de licence
                    </p>
                    <p
                        class="mt-1 text-lg font-bold text-slate-900 dark:text-white"
                    >
                        {{ hostedEntitlement.plan_label ?? '—' }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Validité
                    </p>
                    <p
                        class="mt-1 text-lg font-bold text-slate-900 dark:text-white"
                    >
                        {{
                            hostedEntitlement.expires_at
                                ? formatDate(hostedEntitlement.expires_at)
                                : 'À vie'
                        }}
                    </p>
                </div>
            </div>

            <p
                class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200"
            >
                Aucun numéro de série ni certificat machine n’est requis. Pour
                renouveler un essai ou passer à une licence à vie, contactez
                l’administration DrClickDz.
            </p>
        </section>

        <section v-else-if="permissions.manage_license" class="med-panel p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                    >
                        <ShieldCheck class="size-5 text-emerald-600" />
                        Licence DrClickDz
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        La licence signe les fonctions commerciales sans jamais
                        supprimer ni rendre illisibles les dossiers locaux.
                    </p>
                </div>
                <span
                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                    :class="licenseStateClass"
                >
                    {{ licenseStateLabels[license.state] }}
                </span>
            </div>

            <div class="mt-6 grid gap-5 lg:grid-cols-2">
                <div
                    class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50"
                >
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Édition
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{ license.edition ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Installation
                            </dt>
                            <dd class="mt-1 font-mono font-semibold">
                                {{ licenseActivation.installation_id_hint }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Expiration
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{ formatDate(license.expires_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Grâce hors ligne
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{ formatDate(license.offline_grace_until) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Dernière vérification signée
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{ formatDate(license.last_verified_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Horloge locale
                            </dt>
                            <dd class="mt-1 font-semibold">
                                {{
                                    license.clock_warning
                                        ? 'Correction requise'
                                        : 'Cohérente'
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="license.clock_warning"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                    >
                        Un recul important de l’horloge a été détecté. Corrigez
                        l’heure Windows puis actualisez la licence en ligne. Les
                        dossiers et sauvegardes locales restent accessibles.
                    </p>
                    <p
                        class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200"
                    >
                        Les dossiers existants, l’export et les sauvegardes
                        locales restent accessibles même si une vérification
                        commerciale échoue. Seules les fonctions premium sont
                        limitées après la période de grâce.
                    </p>
                </div>

                <form
                    class="rounded-xl border border-slate-200 p-4 dark:border-slate-700"
                    @submit.prevent="activateLicense"
                >
                    <Label for="license-serial">Numéro de série</Label>
                    <Input
                        id="license-serial"
                        v-model="licenseForm.serial"
                        class="mt-2 font-mono uppercase"
                        autocomplete="off"
                        maxlength="64"
                        placeholder="MEDI-XXXX-XXXX-XXXX"
                        :disabled="
                            !permissions.manage_license ||
                            !permissions.sensitive_actions_confirmed ||
                            !licenseActivation.configured ||
                            licenseForm.processing
                        "
                        required
                    />
                    <p
                        v-if="licenseActivation.reason"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        {{ licenseActivation.reason }}
                    </p>
                    <InputError class="mt-2" :message="licenseError" />
                    <Button
                        type="submit"
                        class="mt-4"
                        :disabled="
                            !permissions.manage_license ||
                            !permissions.sensitive_actions_confirmed ||
                            !licenseActivation.configured ||
                            licenseForm.processing ||
                            licenseForm.serial.trim().length < 14
                        "
                    >
                        <LoaderCircle
                            v-if="licenseForm.processing"
                            class="size-4 animate-spin"
                        />
                        <ShieldCheck v-else class="size-4" />
                        {{
                            licenseForm.processing
                                ? 'Activation…'
                                : 'Activer ou renouveler'
                        }}
                    </Button>
                    <div
                        class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700"
                    >
                        <p class="text-xs text-muted-foreground">
                            L’actualisation remplace uniquement le certificat
                            après vérification de sa signature. La désactivation
                            exige une réponse positive du serveur avant de
                            retirer le certificat local.
                        </p>
                        <InputError
                            class="mt-2"
                            :message="licenseActionError"
                        />
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    !permissions.manage_license ||
                                    !permissions.sensitive_actions_confirmed ||
                                    !licenseActivation.refresh_configured ||
                                    license.state === 'not_activated' ||
                                    licenseRefreshProcessing ||
                                    !browserOnline
                                "
                                @click="refreshLicense"
                            >
                                <LoaderCircle
                                    v-if="licenseRefreshProcessing"
                                    class="size-4 animate-spin"
                                />
                                <RefreshCw v-else class="size-4" />
                                Actualiser l’état
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                class="border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300"
                                :disabled="
                                    !permissions.manage_license ||
                                    !permissions.sensitive_actions_confirmed ||
                                    !licenseActivation.deactivation_configured ||
                                    license.state === 'not_activated' ||
                                    licenseDeactivationProcessing ||
                                    !browserOnline
                                "
                                @click="deactivateLicense"
                            >
                                <LoaderCircle
                                    v-if="licenseDeactivationProcessing"
                                    class="size-4 animate-spin"
                                />
                                <Trash2 v-else class="size-4" />
                                Désactiver cet appareil
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section
            v-if="permissions.manage_backups || permissions.manage_drive"
            class="med-panel p-6"
        >
            <div>
                <h2
                    class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                >
                    <HardDrive class="size-5 text-blue-600" />
                    Sauvegarde immédiate vérifiée
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Créez une archive versionnée du cabinet et contrôlez son
                    intégrité avant le téléchargement.
                </p>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <article
                    v-if="permissions.manage_backups"
                    class="rounded-xl border border-blue-200 bg-blue-50/60 p-5 dark:border-blue-900 dark:bg-blue-950/20"
                >
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="flex items-center gap-2 font-semibold">
                            <HardDrive class="size-4 text-blue-600" />
                            Archive DrClickDz (.msbackup)
                        </h3>
                        <span
                            class="rounded-full border px-2 py-0.5 text-xs font-semibold"
                            :class="capabilityClass(capabilities.local_backups)"
                        >
                            {{
                                capabilities.local_backups.available
                                    ? 'Disponible'
                                    : 'Indisponible'
                            }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{
                            capabilities.local_backups.available
                                ? 'Inclut la base SQLite cohérente, les documents gérés, les logos, un manifeste versionné et les sommes SHA-256.'
                                : capabilities.local_backups.reason
                        }}
                    </p>

                    <form
                        v-if="
                            capabilities.local_backups.available &&
                            capabilities.encrypted_backups.available
                        "
                        method="post"
                        action="/app/configuration/backup/local/encrypted"
                        class="mt-4 space-y-3"
                        @submit="
                            !permissions.sensitive_actions_confirmed &&
                            $event.preventDefault()
                        "
                    >
                        <input type="hidden" name="_token" :value="csrfToken" />
                        <div class="grid gap-2">
                            <Label for="backup-passphrase">
                                Phrase secrète de récupération
                            </Label>
                            <Input
                                id="backup-passphrase"
                                name="passphrase"
                                type="password"
                                minlength="12"
                                maxlength="1024"
                                autocomplete="new-password"
                                :disabled="
                                    !permissions.sensitive_actions_confirmed
                                "
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="backup-passphrase-confirmation">
                                Confirmer la phrase secrète
                            </Label>
                            <Input
                                id="backup-passphrase-confirmation"
                                name="passphrase_confirmation"
                                type="password"
                                minlength="12"
                                maxlength="1024"
                                autocomplete="new-password"
                                :disabled="
                                    !permissions.sensitive_actions_confirmed
                                "
                                required
                            />
                        </div>
                        <p class="text-xs text-amber-800 dark:text-amber-300">
                            Cette phrase n’est ni stockée ni récupérable par
                            DrClickDz. Conservez-la séparément : elle sera
                            obligatoire pour restaurer l’archive.
                        </p>
                        <InputError :message="encryptedBackupError" />
                        <Button
                            type="submit"
                            :disabled="!permissions.sensitive_actions_confirmed"
                        >
                            <ShieldCheck class="size-4" />
                            Créer l’archive chiffrée
                        </Button>
                    </form>

                    <div
                        v-else-if="capabilities.local_backups.available"
                        class="mt-4"
                    >
                        <p class="text-xs text-amber-800 dark:text-amber-300">
                            {{ capabilities.encrypted_backups.reason }} Une
                            archive non chiffrée doit rester sur un support
                            local sécurisé.
                        </p>
                        <a
                            v-if="permissions.sensitive_actions_confirmed"
                            href="/app/configuration/backup/local"
                            class="mt-3 inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-blue-300 bg-white px-4 text-sm font-semibold text-blue-700 hover:bg-blue-50 dark:bg-slate-900"
                        >
                            <Download class="size-4" /> Archive locale non
                            chiffrée
                        </a>
                        <span
                            v-else
                            class="mt-3 inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-blue-200 bg-slate-50 px-4 text-sm font-semibold text-slate-500"
                        >
                            <Download class="size-4" /> Confirmation requise
                        </span>
                    </div>

                    <div
                        v-if="
                            legacyRestoreEnabled &&
                            capabilities.local_backups.available
                        "
                        class="mt-4 border-t border-blue-200 pt-4 dark:border-blue-900"
                    >
                        <Label for="restore-backup">
                            Restauration SQLite experte
                        </Label>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label
                                for="restore-backup"
                                class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-xl border border-input bg-white px-3 text-sm font-medium"
                                :class="
                                    !permissions.sensitive_actions_confirmed &&
                                    'pointer-events-none opacity-50'
                                "
                            >
                                <Upload class="size-4" />
                                {{ selectedBackupName ?? 'Choisir une copie' }}
                            </label>
                            <input
                                id="restore-backup"
                                class="sr-only"
                                type="file"
                                accept=".sqlite3,.sqlite"
                                :disabled="
                                    !permissions.sensitive_actions_confirmed
                                "
                                @change="chooseLocalBackup"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    !permissions.sensitive_actions_confirmed ||
                                    restoreForm.processing ||
                                    !restoreForm.backup
                                "
                                @click="restoreLocalBackup"
                            >
                                <LoaderCircle
                                    v-if="restoreForm.processing"
                                    class="size-4 animate-spin"
                                />
                                <RefreshCw v-else class="size-4" />
                                Restaurer
                            </Button>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="restoreForm.errors.backup"
                        />
                    </div>
                </article>

                <article
                    v-if="permissions.manage_drive"
                    class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-5 dark:border-emerald-900 dark:bg-emerald-950/20"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="flex items-center gap-2 font-semibold">
                            <Cloud class="size-4 text-emerald-600" />
                            Google Drive
                        </h3>
                        <span class="text-xs font-semibold">
                            {{
                                backup.google_drive_connected
                                    ? 'Connecté'
                                    : 'Non connecté'
                            }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{
                            !capabilities.google_drive.available
                                ? capabilities.google_drive.reason
                                : !backup.google_drive_configured
                                  ? 'OAuth Google n’est pas configuré sur cette installation.'
                                  : backup.google_drive_connected
                                    ? 'Compte : ' +
                                      (backup.google_drive_email ??
                                          'adresse non fournie')
                                    : 'Autorisez le compte Google destiné aux sauvegardes.'
                        }}
                    </p>
                    <Button
                        v-if="
                            capabilities.google_drive.available &&
                            backup.google_drive_configured &&
                            !backup.google_drive_connected
                        "
                        type="button"
                        class="mt-4 bg-emerald-600 text-white hover:bg-emerald-700"
                        :disabled="!canConnectDrive"
                        @click="connectDrive"
                    >
                        <LoaderCircle
                            v-if="driveConnectProcessing"
                            class="size-4 animate-spin"
                        />
                        <ExternalLink v-else class="size-4" />
                        {{
                            driveConnectProcessing
                                ? 'Préparation…'
                                : 'Connecter Google Drive'
                        }}
                    </Button>
                    <p
                        v-if="driveConnectNotice"
                        class="mt-3 text-xs text-emerald-700 dark:text-emerald-300"
                        role="status"
                    >
                        {{ driveConnectNotice }}
                    </p>
                    <p
                        v-if="driveConnectError"
                        class="mt-3 text-xs text-red-700 dark:text-red-300"
                        role="alert"
                    >
                        {{ driveConnectError }}
                    </p>
                    <Button
                        v-if="backup.google_drive_connected"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-4 border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800 dark:border-red-900 dark:text-red-300"
                        :disabled="!permissions.sensitive_actions_confirmed"
                        @click="disconnectDrive"
                    >
                        <Trash2 class="size-4" />
                        Déconnecter Google Drive
                    </Button>
                    <Button
                        v-if="backup.google_drive_connected"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-4 ml-2"
                        :disabled="!browserOnline || driveConnectionTesting"
                        @click="testDriveConnection"
                    >
                        <LoaderCircle
                            v-if="driveConnectionTesting"
                            class="size-4 animate-spin"
                        />
                        <ShieldCheck v-else class="size-4" />
                        {{
                            driveConnectionTesting
                                ? 'Vérification…'
                                : 'Tester la connexion'
                        }}
                    </Button>
                    <Button
                        v-if="backup.google_drive_connected"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-4 ml-2"
                        :disabled="!browserOnline || remoteDriveBackupsLoading"
                        @click="refreshDriveBackups"
                    >
                        <LoaderCircle
                            v-if="remoteDriveBackupsLoading"
                            class="size-4 animate-spin"
                        />
                        <RefreshCw v-else class="size-4" />
                        {{
                            remoteDriveBackupsLoading
                                ? 'Chargement…'
                                : 'Rafraîchir la liste'
                        }}
                    </Button>
                    <p
                        v-if="backup.google_drive_connected"
                        class="mt-3 text-xs"
                        :class="
                            backup.verification_state === 'failed'
                                ? 'text-red-700 dark:text-red-300'
                                : backup.verification_state === 'verified'
                                  ? 'text-emerald-700 dark:text-emerald-300'
                                  : 'text-muted-foreground'
                        "
                    >
                        État OAuth :
                        {{
                            backup.verification_state === 'verified'
                                ? 'vérifié'
                                : backup.verification_state === 'failed'
                                  ? 'échec de la dernière vérification'
                                  : 'non testé'
                        }}
                        <template v-if="backup.verification_checked_at">
                            ·
                            {{ formatDate(backup.verification_checked_at) }}
                        </template>
                    </p>
                    <form
                        v-if="backup.google_drive_connected"
                        class="mt-4 space-y-3"
                        @submit.prevent="saveToDrive"
                    >
                        <div class="grid gap-2">
                            <Label for="drive-folder">Dossier Drive</Label>
                            <Input
                                id="drive-folder"
                                v-model="driveForm.folder_name"
                                maxlength="100"
                                :disabled="
                                    !capabilities.drive_upload.available ||
                                    !permissions.sensitive_actions_confirmed
                                "
                                required
                            />
                            <InputError
                                :message="driveForm.errors.folder_name"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="drive-passphrase">
                                Phrase secrète de l’archive
                            </Label>
                            <Input
                                id="drive-passphrase"
                                v-model="driveForm.passphrase"
                                type="password"
                                minlength="12"
                                maxlength="1024"
                                autocomplete="new-password"
                                :disabled="
                                    !capabilities.drive_upload.available ||
                                    !permissions.sensitive_actions_confirmed
                                "
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="drive-passphrase-confirmation">
                                Confirmer la phrase secrète
                            </Label>
                            <Input
                                id="drive-passphrase-confirmation"
                                v-model="driveForm.passphrase_confirmation"
                                type="password"
                                minlength="12"
                                maxlength="1024"
                                autocomplete="new-password"
                                :disabled="
                                    !capabilities.drive_upload.available ||
                                    !permissions.sensitive_actions_confirmed
                                "
                                required
                            />
                            <InputError
                                :message="driveForm.errors.passphrase"
                            />
                            <InputError
                                :message="
                                    driveForm.errors.passphrase_confirmation
                                "
                            />
                        </div>
                        <p class="text-xs text-amber-800 dark:text-amber-300">
                            La phrase secrète sert uniquement à chiffrer
                            l’archive locale avant sa mise en file. Elle n’est
                            ni enregistrée ni envoyée à Google.
                        </p>
                        <p
                            v-if="!capabilities.drive_upload.available"
                            class="text-xs text-muted-foreground"
                        >
                            {{ capabilities.drive_upload.reason }}
                        </p>
                        <Button
                            type="submit"
                            :disabled="driveForm.processing || !canSaveDrive"
                        >
                            <LoaderCircle
                                v-if="driveForm.processing"
                                class="size-4 animate-spin"
                            />
                            <CloudOff
                                v-else-if="!browserOnline"
                                class="size-4"
                            />
                            <Cloud v-else class="size-4" />
                            {{
                                driveForm.processing
                                    ? 'Envoi…'
                                    : 'Créer et envoyer maintenant'
                            }}
                        </Button>
                        <InputError :message="driveError('drive_backup')" />
                    </form>
                    <div
                        v-if="backup.last_backup_at"
                        class="mt-4 flex gap-2 border-t border-emerald-200 pt-4 text-xs text-muted-foreground"
                    >
                        <CheckCircle2
                            class="size-4 shrink-0 text-emerald-600"
                        />
                        <span>
                            Dernier envoi confirmé :
                            <strong>{{ backup.last_backup_name }}</strong> ·
                            {{ backup.last_backup_at }}
                        </span>
                    </div>
                    <p
                        v-if="remoteDriveBackupsError"
                        class="mt-4 text-xs text-red-700 dark:text-red-300"
                    >
                        {{ remoteDriveBackupsError }}
                    </p>
                    <InputError
                        class="mt-4"
                        :message="driveRemoteActionError"
                    />
                    <div
                        v-if="remoteDriveBackupsLoaded"
                        class="mt-4 border-t border-emerald-200 pt-4 dark:border-emerald-900"
                    >
                        <p class="mb-2 text-xs font-semibold">
                            Archives DrClickDz reconnues sur Drive ({{
                                remoteDriveBackups.length
                            }})
                        </p>
                        <p
                            v-if="remoteDriveBackups.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            Aucune archive chiffrée DrClickDz v2 dans ce
                            dossier.
                        </p>
                        <div
                            v-else
                            class="max-h-64 overflow-auto rounded-lg border border-emerald-200 dark:border-emerald-900"
                        >
                            <table class="w-full text-left text-xs">
                                <thead
                                    class="bg-emerald-100/70 dark:bg-emerald-950/50"
                                >
                                    <tr>
                                        <th class="px-3 py-2 font-semibold">
                                            Archive
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Date
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Taille
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            SHA-256
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-emerald-200 dark:divide-emerald-900"
                                >
                                    <tr
                                        v-for="remoteBackup in remoteDriveBackups"
                                        :key="remoteBackup.id"
                                    >
                                        <td class="px-3 py-2 font-medium">
                                            {{ remoteBackup.name }}
                                        </td>
                                        <td
                                            class="px-3 py-2 text-muted-foreground"
                                        >
                                            {{
                                                formatDate(
                                                    remoteBackup.created_at,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-2 text-muted-foreground"
                                        >
                                            {{
                                                formatBytes(
                                                    remoteBackup.size_bytes,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="px-3 py-2 font-mono text-muted-foreground"
                                        >
                                            {{ remoteBackup.sha256_hint }}
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex flex-wrap gap-2">
                                                <form
                                                    method="post"
                                                    :action="`/app/configuration/backup/google/files/${encodeURIComponent(remoteBackup.id)}/download`"
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="_token"
                                                        :value="csrfToken"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                        :disabled="
                                                            !browserOnline ||
                                                            !permissions.sensitive_actions_confirmed
                                                        "
                                                    >
                                                        <Download
                                                            class="size-3.5"
                                                        />
                                                        Télécharger & vérifier
                                                    </Button>
                                                </form>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    class="border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300"
                                                    :disabled="
                                                        !browserOnline ||
                                                        !permissions.sensitive_actions_confirmed ||
                                                        deletingRemoteDriveId !==
                                                            null
                                                    "
                                                    @click="
                                                        deleteRemoteDriveBackup(
                                                            remoteBackup,
                                                        )
                                                    "
                                                >
                                                    <LoaderCircle
                                                        v-if="
                                                            deletingRemoteDriveId ===
                                                            remoteBackup.id
                                                        "
                                                        class="size-3.5 animate-spin"
                                                    />
                                                    <Trash2
                                                        v-else
                                                        class="size-3.5"
                                                    />
                                                    Supprimer
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section v-if="permissions.manage_restore" class="med-panel p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                    >
                        <RefreshCw class="size-5 text-amber-600" />
                        Restaurer une archive chiffrée
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                        Authentifiez d’abord l’archive .msbackup et contrôlez
                        son contenu. Les données actives ne sont remplacées
                        qu’après une seconde confirmation et une sauvegarde de
                        sécurité supervisée.
                    </p>
                </div>
                <span
                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                    :class="capabilityClass(capabilities.offline_restore)"
                >
                    {{
                        capabilities.offline_restore.available
                            ? 'Superviseur prêt'
                            : 'Indisponible'
                    }}
                </span>
            </div>

            <p
                v-if="!capabilities.offline_restore.available"
                class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-muted-foreground dark:border-slate-800 dark:bg-slate-950/30"
            >
                {{ capabilities.offline_restore.reason }}
            </p>

            <div
                v-if="desktopShell && capabilities.offline_restore.available"
                class="mt-4 flex gap-3 rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/25 dark:text-blue-100"
            >
                <Cloud class="mt-0.5 size-5 shrink-0" />
                <div>
                    <p class="font-semibold">Restauration gérée côté serveur</p>
                    <p class="mt-1">
                        Le client hébergé n’applique aucune archive locale.
                        Contactez le support DrClickDz pour organiser une
                        restauration supervisée sur le serveur.
                    </p>
                </div>
            </div>

            <div
                v-else-if="offlineRestoreRecoveryRequired"
                class="mt-4 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"
                role="alert"
            >
                <p class="flex items-center gap-2 font-semibold">
                    <AlertTriangle class="size-4" />
                    Récupération hors ligne requise
                </p>
                <p class="mt-2">
                    Les actions de restauration restent bloquées pour éviter une
                    nouvelle modification. Conservez l’ordinateur allumé et
                    suivez le diagnostic fourni par le superviseur ou le support
                    DrClickDz.
                </p>
            </div>

            <div
                v-else-if="!permissions.sensitive_actions_confirmed"
                class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                Confirmez votre mot de passe avant de manipuler une archive de
                restauration.
                <a
                    href="/app/configuration/connectivity-backup/confirm-sensitive-actions"
                    class="ml-1 font-semibold underline underline-offset-2"
                >
                    Confirmer maintenant
                </a>
            </div>

            <div
                v-if="
                    capabilities.offline_restore.available &&
                    !desktopShell &&
                    !offlineRestoreRecoveryRequired &&
                    offlineRestorePreparation === null
                "
                class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]"
            >
                <div class="grid gap-2">
                    <Label for="offline-restore-backup">
                        Archive DrClickDz chiffrée
                    </Label>
                    <label
                        for="offline-restore-backup"
                        class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-dashed border-amber-300 bg-amber-50/60 px-4 py-2 text-sm font-medium hover:bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20"
                        :class="
                            (!permissions.sensitive_actions_confirmed ||
                                offlineRestorePreparing) &&
                            'pointer-events-none opacity-50'
                        "
                    >
                        <Upload class="size-4 shrink-0" />
                        <span class="min-w-0 break-all">
                            {{
                                offlineRestoreArchiveName ??
                                'Choisir une archive .msbackup'
                            }}
                        </span>
                    </label>
                    <input
                        id="offline-restore-backup"
                        ref="offlineRestoreFileInput"
                        class="sr-only"
                        type="file"
                        accept=".msbackup,application/octet-stream"
                        :disabled="
                            !permissions.sensitive_actions_confirmed ||
                            offlineRestorePreparing
                        "
                        @change="chooseOfflineRestoreArchive"
                    />
                    <p class="text-xs text-muted-foreground">
                        Le fichier est vérifié dans un espace temporaire géré.
                        Aucun chemin local ni secret n’est renvoyé à l’écran.
                    </p>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="offline-restore-passphrase">
                        Phrase secrète de récupération
                    </Label>
                    <Input
                        id="offline-restore-passphrase"
                        v-model="offlineRestorePassphrase"
                        type="password"
                        minlength="12"
                        maxlength="1024"
                        autocomplete="off"
                        :disabled="
                            !permissions.sensitive_actions_confirmed ||
                            offlineRestorePreparing
                        "
                        @keydown.enter.prevent="prepareOfflineRestore"
                    />
                    <p class="text-xs text-muted-foreground">
                        La phrase est effacée de ce formulaire après chaque
                        tentative.
                    </p>
                    <Button
                        type="button"
                        class="mt-1 w-fit"
                        :disabled="!canPrepareOfflineRestore"
                        @click="prepareOfflineRestore"
                    >
                        <LoaderCircle
                            v-if="offlineRestorePreparing"
                            class="size-4 animate-spin"
                        />
                        <ShieldCheck v-else class="size-4" />
                        {{
                            offlineRestorePreparing
                                ? 'Authentification…'
                                : 'Vérifier l’archive'
                        }}
                    </Button>
                </div>
            </div>

            <div
                v-if="offlineRestorePreparation"
                class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50/50 p-5 dark:border-emerald-900 dark:bg-emerald-950/20"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 font-semibold">
                        <ShieldCheck class="size-4 text-emerald-600" />
                        Archive authentifiée
                    </h3>
                    <span
                        class="rounded-full border border-emerald-200 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:border-emerald-900 dark:bg-slate-950 dark:text-emerald-300"
                    >
                        Aucune donnée active modifiée
                    </span>
                </div>

                <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs text-muted-foreground">Créée le</dt>
                        <dd class="mt-1 text-sm font-semibold">
                            {{
                                formatDate(
                                    offlineRestorePreparation.backup.created_at,
                                )
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Version DrClickDz
                        </dt>
                        <dd class="mt-1 text-sm font-semibold">
                            {{
                                offlineRestorePreparation.backup
                                    .application_version
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Schéma de sauvegarde
                        </dt>
                        <dd class="mt-1 text-sm font-semibold">
                            v{{
                                offlineRestorePreparation.backup.schema_version
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Contenu total
                        </dt>
                        <dd class="mt-1 text-sm font-semibold">
                            {{ offlineRestorePreparation.backup.file_count }}
                            fichier(s) ·
                            {{
                                formatBytes(
                                    offlineRestorePreparation.backup.size_bytes,
                                )
                            }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-4 overflow-x-auto rounded-lg border">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-muted/50 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Composant</th>
                                <th class="px-3 py-2 font-medium">Fichiers</th>
                                <th class="px-3 py-2 font-medium">Taille</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="component in offlineRestorePreparation
                                    .backup.components"
                                :key="component.name"
                            >
                                <td class="px-3 py-2 font-medium">
                                    {{
                                        offlineRestoreComponentLabel(
                                            component.name,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ component.file_count }}
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ formatBytes(component.size_bytes) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                >
                    <p class="flex items-start gap-2 font-semibold">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        Cette opération arrête temporairement DrClickDz et
                        remplace la base, les documents gérés et le logo par le
                        contenu vérifié ci-dessus.
                    </p>
                    <div class="mt-3 flex items-start gap-2">
                        <Checkbox
                            id="offline-restore-confirmation"
                            :model-value="offlineRestoreConfirmed"
                            :disabled="offlineRestoreApplying"
                            @update:model-value="
                                offlineRestoreConfirmed = $event === true
                            "
                        />
                        <Label
                            for="offline-restore-confirmation"
                            class="cursor-pointer leading-5"
                        >
                            J’ai contrôlé la date, la version et le contenu de
                            cette archive. Je comprends que les données
                            actuelles seront remplacées après création d’une
                            sauvegarde de sécurité.
                        </Label>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        class="bg-red-700 text-white hover:bg-red-800"
                        :disabled="!canApplyOfflineRestore"
                        @click="applyOfflineRestore"
                    >
                        <LoaderCircle
                            v-if="offlineRestoreApplying"
                            class="size-4 animate-spin"
                        />
                        <RefreshCw v-else class="size-4" />
                        Appliquer et redémarrer DrClickDz
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="offlineRestoreApplying"
                        @click="resetOfflineRestorePreparation"
                    >
                        Préparer une autre archive
                    </Button>
                </div>
            </div>

            <p
                v-if="offlineRestoreApplyMessage && !offlineRestoreApplying"
                class="mt-4 rounded-xl border p-4 text-sm"
                :class="
                    offlineRestoreApplyStatus === 'manual_recovery_required'
                        ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200'
                        : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200'
                "
                role="status"
            >
                {{ offlineRestoreApplyMessage }}
            </p>
            <p
                v-if="offlineRestoreError"
                class="mt-4 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"
                role="alert"
            >
                {{ offlineRestoreError }}
            </p>
        </section>

        <section
            v-if="permissions.manage_backups || permissions.manage_drive"
            class="med-panel overflow-hidden"
        >
            <div class="border-b border-sidebar-border/70 px-6 py-5">
                <h2
                    class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                >
                    <DatabaseBackup class="size-5 text-blue-600" />
                    Historique des sauvegardes
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Les vingt dernières tentatives locales, sans exposer leur
                    chemin de stockage ni les détails sensibles d’un échec. Les
                    envois Drive affichent uniquement un état borné, leur
                    progression et le nombre de tentatives.
                </p>
            </div>
            <InputError class="mx-6 mt-4" :message="driveCancelError" />
            <div
                v-if="backupHistory.length === 0"
                class="px-6 py-8 text-sm text-muted-foreground"
            >
                Aucune sauvegarde enregistrée.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="med-table min-w-full">
                    <thead
                        class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Archive</th>
                            <th class="px-4 py-3 font-medium">Google Drive</th>
                            <th class="px-4 py-3 font-medium">Créée</th>
                            <th class="px-4 py-3 font-medium">Taille</th>
                            <th class="px-4 py-3 font-medium">Contrôle</th>
                            <th class="px-4 py-3 font-medium">État</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70">
                        <tr v-for="entry in backupHistory" :key="entry.id">
                            <td class="px-4 py-3">
                                <span class="block font-medium">
                                    {{ entry.filename }}
                                </span>
                            </td>
                            <td class="min-w-64 px-4 py-3">
                                <div
                                    v-if="entry.drive_upload_status !== null"
                                    class="space-y-2"
                                >
                                    <span
                                        class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold"
                                        :class="
                                            entry.drive_upload_status ===
                                            'completed'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300'
                                                : entry.drive_upload_status ===
                                                        'failed' ||
                                                    entry.drive_upload_status ===
                                                        'cancelled'
                                                  ? 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                                  : entry.drive_upload_status ===
                                                          'retrying' ||
                                                      entry.drive_upload_status ===
                                                          'cancel_requested'
                                                    ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300'
                                                    : 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-300'
                                        "
                                    >
                                        {{
                                            driveUploadStatusLabel(
                                                entry.drive_upload_status,
                                            )
                                        }}
                                    </span>
                                    <div
                                        v-if="
                                            boundedDriveUploadProgress(
                                                entry.drive_upload_progress_percent,
                                            ) !== null
                                        "
                                        class="space-y-1"
                                    >
                                        <progress
                                            class="h-2 w-full overflow-hidden rounded-full"
                                            :value="
                                                boundedDriveUploadProgress(
                                                    entry.drive_upload_progress_percent,
                                                ) ?? 0
                                            "
                                            max="100"
                                            aria-label="Progression de l’envoi Google Drive"
                                        />
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                boundedDriveUploadProgress(
                                                    entry.drive_upload_progress_percent,
                                                )
                                            }}
                                            %
                                            <template
                                                v-if="
                                                    boundedDriveUploadBytes(
                                                        entry.drive_upload_bytes,
                                                        entry.size_bytes,
                                                    ) !== null &&
                                                    entry.size_bytes !== null
                                                "
                                            >
                                                ·
                                                {{
                                                    formatBytes(
                                                        boundedDriveUploadBytes(
                                                            entry.drive_upload_bytes,
                                                            entry.size_bytes,
                                                        ) ?? 0,
                                                    )
                                                }}
                                                sur
                                                {{
                                                    formatBytes(
                                                        entry.size_bytes,
                                                    )
                                                }}
                                            </template>
                                        </p>
                                    </div>
                                    <p
                                        v-if="
                                            boundedDriveUploadAttempts(
                                                entry.drive_upload_attempts,
                                            ) > 0
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        Tentative
                                        {{
                                            boundedDriveUploadAttempts(
                                                entry.drive_upload_attempts,
                                            )
                                        }}
                                        sur 3
                                    </p>
                                    <Button
                                        v-if="entry.drive_cancel_available"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="
                                            cancellingDriveUploadId !== null
                                        "
                                        @click="cancelDriveUpload(entry)"
                                    >
                                        <LoaderCircle
                                            v-if="
                                                cancellingDriveUploadId ===
                                                entry.id
                                            "
                                            class="size-3.5 animate-spin"
                                        />
                                        <CloudOff v-else class="size-3.5" />
                                        Annuler l’envoi
                                    </Button>
                                </div>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                >
                                    Aucun envoi demandé
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{
                                    formatDate(
                                        entry.completed_at ?? entry.started_at,
                                    )
                                }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{
                                    entry.size_bytes === null
                                        ? '—'
                                        : formatBytes(entry.size_bytes)
                                }}
                            </td>
                            <td
                                class="px-4 py-3 font-mono text-xs text-muted-foreground"
                            >
                                {{ entry.sha256_hint ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        entry.status === 'completed'
                                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300'
                                            : entry.status === 'failed'
                                              ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300'
                                              : 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-300'
                                    "
                                >
                                    {{
                                        entry.status === 'completed'
                                            ? 'Vérifiée'
                                            : entry.status === 'failed'
                                              ? 'Échec'
                                              : 'En cours'
                                    }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-if="offlineRestoreApplying"
            class="fixed inset-0 z-[100] grid place-items-center bg-slate-950/90 p-6 text-white"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="offline-restore-progress-title"
            aria-describedby="offline-restore-progress-description"
        >
            <div class="max-w-lg text-center">
                <LoaderCircle class="mx-auto size-12 animate-spin" />
                <h2
                    id="offline-restore-progress-title"
                    class="mt-5 text-xl font-bold"
                >
                    Restauration supervisée en cours
                </h2>
                <p
                    id="offline-restore-progress-description"
                    class="mt-3 text-sm leading-6 text-slate-200"
                >
                    {{
                        offlineRestoreApplyMessage ??
                        'DrClickDz vérifie la sauvegarde de sécurité, remplace les données et redémarre ses services. N’éteignez pas cet ordinateur et ne fermez pas l’application.'
                    }}
                </p>
                <p class="mt-3 text-xs text-slate-400">
                    Cette étape ne peut pas être annulée depuis l’interface.
                </p>
            </div>
        </div>
    </div>
</template>
