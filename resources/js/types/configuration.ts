export type MedicationItem = {
    id: number;
    name: string;
    dci: string | null;
    form: string | null;
    dosage: string | null;
    notes: string | null;
    is_active: boolean;
};

export type ReferentialColumn = { key: string; label: string };

export type ReferentialFieldType =
    'text' | 'number' | 'money' | 'textarea' | 'select';

export type ReferentialOption = {
    value: string;
    label: string;
    description?: string | null;
};

export type ReferentialField = {
    key: string;
    label: string;
    type: ReferentialFieldType;
    required?: boolean;
    placeholder?: string;
    options?: ReferentialOption[];
};

export type ReferentialMeta = {
    slug: string;
    title: string;
    description: string;
    section: string;
    columns: ReferentialColumn[];
    fields: ReferentialField[];
};

export type ReferentialRow = { id: number } & Record<
    string,
    string | number | null
>;

export type UploadMode = 'local' | 'remote' | 'relay';

export type UpdateChannel = 'stable' | 'beta';

export type RuntimeState =
    | 'ready'
    | 'starting'
    | 'degraded'
    | 'stopped'
    | 'offline'
    | 'unavailable'
    | 'unknown';

/**
 * A server-resolved capability. Controls tied to an unavailable capability
 * remain visible for context, but are disabled and display `reason`.
 */
export type ConfigurationCapability = {
    available: boolean;
    reason: string | null;
};

export type ConnectivityBackupSettings = {
    uploads: {
        default_mode: UploadMode;
        session_ttl_minutes: number;
        maximum_files: number;
        maximum_individual_bytes: number;
        maximum_total_bytes: number;
    };
    connectivity: {
        lan_enabled: boolean;
        selected_adapter_id: string | null;
        preferred_port: number | null;
        firewall_diagnostics_enabled: boolean;
    };
    backups: {
        automatic_enabled: boolean;
        schedule_time: string;
        retention_daily: number;
        retention_weekly: number;
        retention_monthly: number;
        maximum_storage_bytes: number | null;
    };
    updates: {
        auto_check: boolean;
        channel: UpdateChannel;
        check_interval_hours: number;
        auto_download: boolean;
    };
};

export type NetworkAdapterOption = {
    id: string;
    label: string;
    address: string | null;
    connected: boolean;
};

export type NativeLanProvisioningState = {
    schema_version: 1;
    requested_enabled: boolean;
    requested_adapter_id: string | null;
    requested_preferred_port: number | null;
    diagnostics_requested: boolean;
    phase:
        | 'active'
        | 'pending_attestation'
        | 'disabled'
        | 'stopped'
        | 'unavailable';
    verified: boolean;
    verified_origin: string | null;
    verified_adapter_id: string | null;
    local_reachability: 'passed' | 'pending' | 'failed' | 'not_run';
    firewall_assessment: 'not_determined';
    firewall_rules_modified: false;
    error_code: string | null;
    adapters: Array<{
        id: string;
        label: string;
        address: string;
        index: number;
    }>;
};

export type NativeLanConfigurationResult = {
    message_fr: string;
    state: NativeLanProvisioningState;
};

export type ConnectivityBackupRuntime = {
    connectivity: {
        state: RuntimeState;
        message: string | null;
        adapter_label: string | null;
        ip_address: string | null;
        active_port: number | null;
        local_url: string | null;
        remote_url: string | null;
        last_checked_at: string | null;
    };
    uploads: {
        state: RuntimeState;
        message: string | null;
        active_sessions: number;
        pending_files: number;
        last_received_at: string | null;
    };
    tunnel: {
        state: RuntimeState;
        message: string | null;
        configured: boolean;
        hostname: string | null;
        service_installed: boolean;
        cloudflared_version: string | null;
        retry_count: string | null;
        desired_state: string;
        last_checked_at: string | null;
        last_error: string | null;
    };
    backups: {
        state: RuntimeState;
        message: string | null;
        last_completed_at: string | null;
        last_filename: string | null;
        last_verified_at: string | null;
    };
    updates: {
        state: RuntimeState;
        message: string | null;
        current_version: string;
        available_version: string | null;
        last_checked_at: string | null;
    };
};

export type ConnectivityBackupCapabilities = {
    local_upload: ConfigurationCapability;
    lan: ConfigurationCapability;
    remote_upload: ConfigurationCapability;
    relay_upload: ConfigurationCapability;
    local_backups: ConfigurationCapability;
    automatic_backups: ConfigurationCapability;
    encrypted_backups: ConfigurationCapability;
    offline_restore: ConfigurationCapability;
    google_drive: ConfigurationCapability;
    drive_upload: ConfigurationCapability;
    updates: ConfigurationCapability;
    automatic_updates: ConfigurationCapability;
    beta_updates: ConfigurationCapability;
};

export type BackupDriveStatus = {
    google_drive_configured: boolean;
    google_drive_email: string | null;
    google_drive_connected: boolean;
    google_drive_folder: string;
    last_backup_at: string | null;
    last_backup_name: string | null;
    verification_state: 'not_tested' | 'verified' | 'failed';
    verification_checked_at: string | null;
};

export type BackupHistoryEntry = {
    id: string;
    filename: string;
    size_bytes: number | null;
    sha256_hint: string | null;
    status: 'running' | 'completed' | 'failed';
    started_at: string | null;
    completed_at: string | null;
    drive_uploaded: boolean;
    drive_upload_status:
        | 'queued'
        | 'uploading'
        | 'retrying'
        | 'cancel_requested'
        | 'cancelled'
        | 'completed'
        | 'failed'
        | null;
    drive_upload_bytes: number | null;
    drive_upload_progress_percent: number | null;
    drive_upload_attempts: number;
    drive_cancel_available: boolean;
};

export type OfflineRestoreAuthorization = {
    protocol: 'medismart-offline-restore-authorization';
    version: 1;
    operation_id: string;
    plan_sha256: string;
};

export type OfflineRestoreComponentSummary = {
    name: 'database' | 'private_storage' | 'public_storage';
    file_count: number;
    size_bytes: number;
};

export type OfflineRestorePreparation = {
    authorization: OfflineRestoreAuthorization;
    backup: {
        created_at: string;
        application_version: string;
        schema_version: number;
        components: OfflineRestoreComponentSummary[];
        file_count: number;
        size_bytes: number;
    };
};

export type OfflineRestoreRuntimeState =
    'unchanged' | 'verified_running' | 'offline_recovery_required';

export type OfflineRestoreApplyResult = {
    status:
        | 'applied_pending_restart'
        | 'rolled_back'
        | 'refused_no_mutation'
        | 'manual_recovery_required';
    message_fr: string;
    runtime_state: 'verified_running' | 'offline_recovery_required';
};

export type RemoteDriveBackup = {
    id: string;
    name: string;
    size_bytes: number;
    created_at: string;
    sha256_hint: string;
    backup_record_id: string;
};

export type ActiveUploadSession = {
    id: string;
    mode: UploadMode;
    url: string;
    expires_at: string;
    remaining_seconds: number;
    reachability: {
        state: 'not_tested' | 'verified' | 'failed';
        checked_at: string | null;
        message: string | null;
    };
};

export type ActiveUploadSessionSummary = {
    id: string;
    mode: UploadMode;
    status: 'pending' | 'uploading';
    patient_name: string | null;
    expires_at: string;
    remaining_seconds: number;
};

export type PendingUploadSummary = {
    id: string;
    name: string;
    size_bytes: number;
    received_at: string;
    status: 'pending' | 'accepted' | 'rejected';
    patient_id: number | null;
    patient_name: string | null;
};

export type ConfigurationPatientOption = {
    id: number;
    label: string;
};

export type ConnectivityBackupPermissions = {
    manage_settings: boolean;
    manage_backups: boolean;
    manage_restore: boolean;
    manage_drive: boolean;
    manage_license: boolean;
    view_diagnostics: boolean;
    manage_upload_sessions: boolean;
    sensitive_actions_confirmed: boolean;
};

export type LicenseRuntimeStatus = {
    state:
        | 'not_activated'
        | 'active'
        | 'offline_grace'
        | 'expired'
        | 'suspended'
        | 'revoked'
        | 'device_limit_reached'
        | 'clock_rollback'
        | 'server_unavailable'
        | 'invalid';
    edition: string | null;
    expires_at: string | null;
    offline_grace_until: string | null;
    last_verified_at: string | null;
    clock_warning: boolean;
};

export type LicenseActivationPresentation = {
    configured: boolean;
    refresh_configured: boolean;
    deactivation_configured: boolean;
    installation_id_hint: string;
    reason: string | null;
};

export type HostedEntitlementPresentation = {
    plan: 'trial' | 'lifetime' | null;
    plan_label: string | null;
    status: LicenseRuntimeStatus['state'] | 'inactive';
    status_label: string;
    expires_at: string | null;
};

/**
 * Inertia props contract for `configuration/ConnectivityAndBackup.vue`.
 *
 * `settings` contains persisted effective values. `runtime` contains observed,
 * read-only state and must never be inferred by the client. `capabilities`
 * represents installation/license support and is used to disable unsupported
 * controls. Action feedback is returned by the server through normal Inertia
 * errors/flash data; the page does not manufacture success messages.
 */
export type ConnectivityBackupPageProps = {
    settings: ConnectivityBackupSettings;
    runtime: ConnectivityBackupRuntime;
    capabilities: ConnectivityBackupCapabilities;
    adapters: NetworkAdapterOption[];
    backup: BackupDriveStatus;
    backupHistory?: BackupHistoryEntry[];
    permissions: ConnectivityBackupPermissions;
    license: LicenseRuntimeStatus;
    hostedEntitlement: HostedEntitlementPresentation | null;
    licenseActivation: LicenseActivationPresentation;
    legacyRestoreEnabled: boolean;
    activeUpload?: ActiveUploadSession | null;
    activeUploadSessions?: ActiveUploadSessionSummary[];
    pendingUploads?: PendingUploadSummary[];
    patients?: ConfigurationPatientOption[];
    qrDataUrl?: string | null;
};
