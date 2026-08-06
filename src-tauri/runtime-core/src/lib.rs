mod diagnostics;
mod installation;
#[cfg(feature = "supervisor")]
mod lan_upload;
mod logging;
mod native_tunnel_status;
mod offline_restore;
mod protected_secret;
#[cfg(feature = "supervisor")]
mod queue_worker;
#[cfg(feature = "supervisor")]
mod scheduler;
#[cfg(feature = "supervisor")]
mod startup_migration;
#[cfg(feature = "supervisor")]
mod supervisor;
#[cfg(feature = "supervisor")]
mod tunnel;

pub use diagnostics::{RuntimePhase, RuntimeSnapshot};
pub use installation::{load_or_create_installation_identity, InstallationIdentity};
#[cfg(feature = "supervisor")]
pub use lan_upload::{
    discover_lan_adapter_candidates, load_lan_listener_settings, LanAdapterCandidate,
    LanListenerConfiguration, LanListenerError, LanListenerSettings, LanListenerStatus,
    LanProvisioningState, LanUploadSupervisor, VerifiedLanUploadBoundary,
};
pub use logging::RuntimeLogger;
pub use native_tunnel_status::{
    read_authenticated_native_tunnel_status, AuthenticatedNativeTunnelStatus, NativeTunnelPhase,
    NativeTunnelStatusError, NativeTunnelStatusPublisher, NativeTunnelStatusUpdate,
};
pub use offline_restore::{
    coordinate_offline_restore, verify_prepared_restore_authorization,
    ExclusiveRestoreProcessLease, OfflineRestoreAuthorizationArtifact,
    OfflineRestoreCommandLauncher, OfflineRestoreError, OfflineRestoreOutcome,
    OfflineRestorePhpConfig, OfflineRestoreProcessOwner, OfflineRestoreStatus,
    PhpOfflineRestoreCommandLauncher, VerifiedPreparedRestore,
    OFFLINE_RESTORE_AUTHORIZATION_PROTOCOL, OFFLINE_RESTORE_AUTHORIZATION_VERSION,
};
pub use protected_secret::{read_protected_secret, write_new_protected_secret, ProtectedSecret};
#[cfg(feature = "supervisor")]
pub use queue_worker::{
    QueueWorkerConfig, QueueWorkerError, QueueWorkerStatus, QueueWorkerSupervisor,
    SUPERVISED_QUEUE_NAMES,
};
#[cfg(feature = "supervisor")]
pub use scheduler::{SchedulerConfig, SchedulerError, SchedulerStatus, SchedulerSupervisor};
#[cfg(feature = "supervisor")]
pub use startup_migration::{
    run_startup_migration_gate, verify_packaged_migration_resources,
    PackagedMigrationResourceConfig, StartupMigrationError, StartupMigrationGateConfig,
    StartupMigrationOutcome, VerifiedMigrationResources,
};
#[cfg(feature = "supervisor")]
pub use supervisor::{
    allocate_loopback_port, generate_runtime_secret, RuntimeError, Supervisor, SupervisorConfig,
    SupervisorEvent,
};
#[cfg(feature = "supervisor")]
pub use tunnel::{
    load_tunnel_settings, verify_cloudflared_executable, CloudflaredExecutable,
    TunnelConfiguration, TunnelError, TunnelSettings, TunnelSupervisor,
    VerifiedRemoteUploadBoundary,
};
