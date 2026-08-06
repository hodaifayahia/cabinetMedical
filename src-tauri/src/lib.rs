mod desktop;
mod desktop_behavior;
mod oauth_opener;
mod updates;

use std::{
    path::PathBuf,
    sync::{
        atomic::{AtomicBool, AtomicU64, Ordering},
        Arc, Condvar, Mutex, RwLock,
    },
    thread::JoinHandle,
    time::{Duration, Instant},
};

use medismart_runtime::{
    coordinate_offline_restore, verify_prepared_restore_authorization,
    ExclusiveRestoreProcessLease, LanListenerSettings, LanProvisioningState, LanUploadSupervisor,
    OfflineRestoreAuthorizationArtifact, OfflineRestoreError, OfflineRestoreOutcome,
    OfflineRestoreProcessOwner, OfflineRestoreStatus, PhpOfflineRestoreCommandLauncher,
    QueueWorkerSupervisor, SchedulerSupervisor, Supervisor, SupervisorEvent, TunnelSupervisor,
};
use serde::Serialize;
use tauri::{
    plugin::{Builder as PluginBuilder, TauriPlugin},
    webview::WebviewWindowBuilder,
    AppHandle, Manager, RunEvent, WebviewUrl,
};
use tauri_plugin_opener::OpenerExt;
use url::Url;

use crate::desktop_behavior::{install_system_tray, show_desktop_window, DesktopBehaviorState};
use crate::oauth_opener::{validate_and_open_google_oauth_authorization, GoogleOAuthOpenFailure};
use crate::updates::SignedUpdaterState;

struct DesktopState {
    runtime: Mutex<Option<ActiveDesktopRuntime>>,
    transition: Mutex<()>,
    ownership: Arc<RestoreOwnershipState>,
    navigation_policy: NavigationPolicy,
    startup_state: SharedStartupState,
}

impl DesktopState {
    fn new(navigation_policy: NavigationPolicy, startup_state: SharedStartupState) -> Self {
        Self {
            runtime: Mutex::new(None),
            transition: Mutex::new(()),
            ownership: Arc::new(RestoreOwnershipState::default()),
            navigation_policy,
            startup_state,
        }
    }
}

struct ActiveDesktopRuntime {
    supervisor: Arc<Supervisor>,
    queue_worker: Arc<QueueWorkerSupervisor>,
    scheduler: Arc<SchedulerSupervisor>,
    lan_upload: Arc<LanUploadSupervisor>,
    tunnel: Arc<TunnelSupervisor>,
    offline_restore: ActiveOfflineRestoreBridge,
    readiness: Arc<KeyedHealthSignal>,
    main_thread: Option<JoinHandle<()>>,
    queue_thread: Option<JoinHandle<()>>,
    scheduler_thread: Option<JoinHandle<()>>,
}

#[derive(Clone)]
struct ActiveOfflineRestoreBridge {
    launcher: Arc<PhpOfflineRestoreCommandLauncher>,
    work_root: PathBuf,
    journal_root: PathBuf,
}

#[derive(Default)]
struct RestoreOwnershipState {
    epoch: AtomicU64,
    exclusive: AtomicBool,
}

impl RestoreOwnershipState {
    fn runtime_starting(&self) {
        self.exclusive.store(false, Ordering::SeqCst);
        self.epoch.fetch_add(1, Ordering::SeqCst);
    }

    fn shutdown_verified(&self) {
        self.exclusive.store(true, Ordering::SeqCst);
    }

    fn shutdown_unverified(&self) {
        self.exclusive.store(false, Ordering::SeqCst);
    }

    fn lease(self: &Arc<Self>) -> Arc<dyn ExclusiveRestoreProcessLease> {
        Arc::new(DesktopExclusiveRestoreLease {
            ownership: Arc::clone(self),
            epoch: self.epoch.load(Ordering::SeqCst),
        })
    }
}

struct DesktopExclusiveRestoreLease {
    ownership: Arc<RestoreOwnershipState>,
    epoch: u64,
}

impl ExclusiveRestoreProcessLease for DesktopExclusiveRestoreLease {
    fn assert_exclusive(&self) -> Result<(), OfflineRestoreError> {
        if self.ownership.exclusive.load(Ordering::SeqCst)
            && self.ownership.epoch.load(Ordering::SeqCst) == self.epoch
        {
            Ok(())
        } else {
            Err(restore_error(
                "restore_ownership_lost",
                "La restauration est interrompue. Les services locaux ne sont plus dans un état hors ligne vérifié.",
                true,
            ))
        }
    }
}

#[derive(Clone, Copy)]
enum KeyedHealthState {
    Pending,
    Verified,
    Failed(&'static str),
}

struct KeyedHealthSignal {
    state: Mutex<KeyedHealthState>,
    changed: Condvar,
}

impl Default for KeyedHealthSignal {
    fn default() -> Self {
        Self {
            state: Mutex::new(KeyedHealthState::Pending),
            changed: Condvar::new(),
        }
    }
}

impl KeyedHealthSignal {
    fn observe(&self, event: &SupervisorEvent) {
        let next = match event {
            SupervisorEvent::Ready { .. } => Some(KeyedHealthState::Verified),
            SupervisorEvent::Failed { code } => Some(KeyedHealthState::Failed(code)),
            SupervisorEvent::Stopped => Some(KeyedHealthState::Failed("runtime_stopped")),
            SupervisorEvent::Starting { .. } | SupervisorEvent::Retrying { .. } => None,
        };
        if let Some(next) = next {
            if let Ok(mut current) = self.state.lock() {
                if !matches!(*current, KeyedHealthState::Verified) {
                    *current = next;
                    self.changed.notify_all();
                }
            }
        }
    }

    fn wait(&self, timeout: Duration) -> Result<(), &'static str> {
        let deadline = Instant::now() + timeout;
        let mut state = self.state.lock().map_err(|_| "runtime_health_failed")?;
        loop {
            match *state {
                KeyedHealthState::Verified => return Ok(()),
                KeyedHealthState::Failed(code) => return Err(code),
                KeyedHealthState::Pending => {}
            }
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return Err("health_timeout");
            }
            let (next, timed_out) = self
                .changed
                .wait_timeout(state, remaining)
                .map_err(|_| "runtime_health_failed")?;
            state = next;
            if timed_out.timed_out() && matches!(*state, KeyedHealthState::Pending) {
                return Err("health_timeout");
            }
        }
    }
}

#[derive(Clone, Default)]
struct NavigationPolicy {
    loopback_port: Arc<RwLock<Option<u16>>>,
}

impl NavigationPolicy {
    fn set_loopback_port(&self, port: Option<u16>) {
        if let Ok(mut current) = self.loopback_port.write() {
            *current = port;
        }
    }

    fn allows(&self, url: &Url) -> bool {
        if matches!(url.scheme(), "tauri" | "asset" | "about")
            || matches!(url.host_str(), Some("tauri.localhost" | "asset.localhost"))
        {
            return true;
        }

        let allowed_port = self.loopback_port.read().ok().and_then(|port| *port);

        url.scheme() == "http"
            && url.host_str() == Some("127.0.0.1")
            && url.port() == allowed_port
            && url.username().is_empty()
            && url.password().is_none()
    }

    fn current_loopback_port(&self) -> Option<u16> {
        self.loopback_port.read().ok().and_then(|port| *port)
    }
}

#[derive(Clone, Serialize)]
struct StartupViewState {
    phase: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    code: Option<&'static str>,
}

impl Default for StartupViewState {
    fn default() -> Self {
        Self {
            phase: "starting",
            code: None,
        }
    }
}

type SharedStartupState = Arc<Mutex<StartupViewState>>;

trait OrderedRuntimeShutdown {
    fn freeze_main_refresh(&self);
    fn stop_tunnel(&self);
    fn stop_optional_lan(&self);
    fn stop_scheduler(&self);
    fn join_scheduler(&mut self) -> Result<(), ()>;
    fn stop_queue(&self);
    fn join_queue(&mut self) -> Result<(), ()>;
    fn stop_main(&self);
    fn join_main(&mut self) -> Result<(), ()>;
}

impl OrderedRuntimeShutdown for ActiveDesktopRuntime {
    fn freeze_main_refresh(&self) {
        self.supervisor.freeze_runtime_contract_refresh();
    }

    fn stop_tunnel(&self) {
        self.tunnel.shutdown();
    }

    fn stop_optional_lan(&self) {
        self.lan_upload.shutdown();
    }

    fn stop_scheduler(&self) {
        self.scheduler.shutdown();
    }

    fn join_scheduler(&mut self) -> Result<(), ()> {
        join_thread(&mut self.scheduler_thread)
    }

    fn stop_queue(&self) {
        self.queue_worker.shutdown();
    }

    fn join_queue(&mut self) -> Result<(), ()> {
        join_thread(&mut self.queue_thread)
    }

    fn stop_main(&self) {
        self.supervisor.stop();
    }

    fn join_main(&mut self) -> Result<(), ()> {
        join_thread(&mut self.main_thread)
    }
}

fn stop_and_join_in_restore_order(runtime: &mut impl OrderedRuntimeShutdown) -> Result<(), ()> {
    runtime.freeze_main_refresh();
    let mut clean = true;
    runtime.stop_tunnel();
    runtime.stop_optional_lan();
    runtime.stop_scheduler();
    clean &= runtime.join_scheduler().is_ok();
    runtime.stop_queue();
    clean &= runtime.join_queue().is_ok();
    runtime.stop_main();
    clean &= runtime.join_main().is_ok();

    clean.then_some(()).ok_or(())
}

fn join_thread(thread: &mut Option<JoinHandle<()>>) -> Result<(), ()> {
    thread
        .take()
        .map_or(Ok(()), |thread| thread.join().map_err(|_| ()))
}

fn launch_prepared_runtime(
    app: &AppHandle,
    state: &DesktopState,
    prepared: desktop::PreparedDesktopRuntime,
) -> Result<(), &'static str> {
    let desktop::PreparedDesktopRuntime {
        supervisor,
        queue_worker,
        scheduler,
        lan_upload,
        tunnel,
        offline_restore,
        updater_app_key,
        updater_installation_id,
    } = prepared;
    let supervisor = Arc::new(supervisor);
    let tunnel = Arc::new(tunnel);
    let readiness = Arc::new(KeyedHealthSignal::default());
    state.ownership.runtime_starting();
    app.state::<SignedUpdaterState>()
        .configure_authorization(updater_app_key, updater_installation_id);

    let queue_runner = Arc::clone(&queue_worker);
    let queue_thread = match std::thread::Builder::new()
        .name("medismart-queue-worker".to_owned())
        .spawn(move || queue_runner.run())
    {
        Ok(thread) => thread,
        Err(_) => {
            queue_worker.shutdown();
            state.ownership.shutdown_unverified();
            return Err("process_spawn_failed");
        }
    };

    let scheduler_runner = Arc::clone(&scheduler);
    let scheduler_thread = match std::thread::Builder::new()
        .name("medismart-scheduler".to_owned())
        .spawn(move || scheduler_runner.run())
    {
        Ok(thread) => thread,
        Err(_) => {
            scheduler.shutdown();
            queue_worker.shutdown();
            let _ = queue_thread.join();
            state.ownership.shutdown_unverified();
            return Err("process_spawn_failed");
        }
    };

    let app_handle = app.clone();
    let runtime_navigation_policy = state.navigation_policy.clone();
    let runtime_startup_state = Arc::clone(&state.startup_state);
    let main_supervisor = Arc::clone(&supervisor);
    let main_queue = Arc::clone(&queue_worker);
    let main_scheduler = Arc::clone(&scheduler);
    let main_lan_upload = Arc::clone(&lan_upload);
    let main_tunnel = Arc::clone(&tunnel);
    let main_readiness = Arc::clone(&readiness);
    let main_thread = match std::thread::Builder::new()
        .name("medismart-runtime".to_owned())
        .spawn(move || {
            main_queue.wait_for_initial_status(Duration::from_secs(2));
            main_scheduler.wait_for_initial_status(Duration::from_secs(2));
            main_supervisor.run(move |mut event| {
                main_readiness.observe(&event);
                coordinate_lan_upload(&main_lan_upload, &mut event);
                coordinate_tunnel(&main_tunnel, &mut event);
                present_supervisor_event(
                    &app_handle,
                    &runtime_navigation_policy,
                    &runtime_startup_state,
                    event,
                );
            });
            main_scheduler.shutdown();
            main_queue.shutdown();
        }) {
        Ok(thread) => thread,
        Err(_) => {
            scheduler.shutdown();
            let _ = scheduler_thread.join();
            queue_worker.shutdown();
            let _ = queue_thread.join();
            state.ownership.shutdown_unverified();
            return Err("process_spawn_failed");
        }
    };

    let mut active = ActiveDesktopRuntime {
        supervisor,
        queue_worker,
        scheduler,
        lan_upload,
        tunnel,
        offline_restore: ActiveOfflineRestoreBridge {
            launcher: offline_restore.launcher,
            work_root: offline_restore.work_root,
            journal_root: offline_restore.journal_root,
        },
        readiness,
        main_thread: Some(main_thread),
        queue_thread: Some(queue_thread),
        scheduler_thread: Some(scheduler_thread),
    };
    let Ok(mut slot) = state.runtime.lock() else {
        let _ = stop_and_join_in_restore_order(&mut active);
        state.ownership.shutdown_unverified();
        return Err("runtime_io_failed");
    };
    if slot.is_some() {
        drop(slot);
        let _ = stop_and_join_in_restore_order(&mut active);
        state.ownership.shutdown_unverified();
        return Err("runtime_already_active");
    }
    *slot = Some(active);

    Ok(())
}

fn shutdown_active_runtime(state: &DesktopState) -> Result<(), OfflineRestoreError> {
    let mut active = state
        .runtime
        .lock()
        .map_err(|_| {
            restore_error(
                "restore_ownership_failed",
                "Les services locaux n’ont pas pu être verrouillés pour la restauration.",
                true,
            )
        })?
        .take()
        .ok_or_else(|| {
            restore_error(
                "restore_runtime_unavailable",
                "La restauration ne peut pas démarrer car le runtime local n’est pas actif.",
                true,
            )
        })?;

    if stop_and_join_in_restore_order(&mut active).is_err() {
        state.ownership.shutdown_unverified();
        return Err(restore_error(
            "restore_ownership_failed",
            "Les services locaux restent arrêtés, mais leur arrêt exclusif n’a pas pu être vérifié.",
            true,
        ));
    }

    state.ownership.shutdown_verified();
    Ok(())
}

fn start_runtime_and_verify(
    app: &AppHandle,
    state: &DesktopState,
) -> Result<(), OfflineRestoreError> {
    let prepared = desktop::prepare_runtime(app).map_err(|error| {
        restore_error(
            error.code(),
            "Le runtime local n’a pas pu être préparé.",
            true,
        )
    })?;
    launch_prepared_runtime(app, state, prepared)
        .map_err(|code| restore_error(code, "Le runtime local n’a pas pu démarrer.", true))?;
    let readiness = state
        .runtime
        .lock()
        .ok()
        .and_then(|runtime| {
            runtime
                .as_ref()
                .map(|runtime| Arc::clone(&runtime.readiness))
        })
        .ok_or_else(|| {
            restore_error(
                "restored_runtime_unhealthy",
                "Le contrôle de santé du runtime restauré est indisponible.",
                true,
            )
        })?;

    if let Err(code) = readiness.wait(Duration::from_secs(3 * 60)) {
        let _ = shutdown_active_runtime(state);
        return Err(restore_error(
            code,
            "Le runtime restauré n’a pas réussi son contrôle de santé authentifié.",
            true,
        ));
    }

    Ok(())
}

struct TauriOfflineRestoreOwner<'a> {
    app: &'a AppHandle,
    state: &'a DesktopState,
}

impl OfflineRestoreProcessOwner for TauriOfflineRestoreOwner<'_> {
    fn stop_writers_and_acquire_restore_lease(
        &self,
    ) -> Result<Arc<dyn ExclusiveRestoreProcessLease>, OfflineRestoreError> {
        shutdown_active_runtime(self.state)?;
        Ok(self.state.ownership.lease())
    }

    fn start_restored_runtime_and_verify(&self) -> Result<(), OfflineRestoreError> {
        start_runtime_and_verify(self.app, self.state)
    }

    fn resume_previous_runtime(&self) -> Result<(), OfflineRestoreError> {
        start_runtime_and_verify(self.app, self.state)
    }
}

#[derive(Serialize)]
struct OfflineRestoreCommandResponse {
    status: OfflineRestoreStatus,
    message_fr: &'static str,
    runtime_state: &'static str,
}

#[derive(Debug, Serialize)]
struct OfflineRestoreCommandError {
    code: &'static str,
    message_fr: &'static str,
    runtime_state: &'static str,
}

#[tauri::command]
async fn apply_prepared_offline_restore(
    app: AppHandle,
    authorization: OfflineRestoreAuthorizationArtifact,
) -> Result<OfflineRestoreCommandResponse, OfflineRestoreCommandError> {
    let worker_app = app.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let state = worker_app.state::<DesktopState>();
        apply_prepared_offline_restore_blocking(&worker_app, state.inner(), authorization)
    })
    .await
    .map_err(|_| OfflineRestoreCommandError {
        code: "restore_lifecycle_unavailable",
        message_fr: "L’état du runtime local ne peut plus être vérifié.",
        runtime_state: "offline_recovery_required",
    })?
}

fn apply_prepared_offline_restore_blocking(
    app: &AppHandle,
    state: &DesktopState,
    authorization: OfflineRestoreAuthorizationArtifact,
) -> Result<OfflineRestoreCommandResponse, OfflineRestoreCommandError> {
    let _transition = match state.transition.try_lock() {
        Ok(transition) => transition,
        Err(std::sync::TryLockError::WouldBlock) => {
            return Err(OfflineRestoreCommandError {
                code: "restore_busy",
                message_fr: "Une autre transition du runtime est déjà en cours.",
                runtime_state: "unchanged",
            });
        }
        Err(std::sync::TryLockError::Poisoned(_)) => {
            return Err(OfflineRestoreCommandError {
                code: "restore_lifecycle_unavailable",
                message_fr: "L’état du runtime local ne peut plus être vérifié.",
                runtime_state: "offline_recovery_required",
            });
        }
    };
    let bridge = state
        .runtime
        .lock()
        .map_err(|_| OfflineRestoreCommandError {
            code: "restore_runtime_unavailable",
            message_fr: "Le runtime local est indisponible.",
            runtime_state: "unchanged",
        })?
        .as_ref()
        .map(|runtime| runtime.offline_restore.clone())
        .ok_or(OfflineRestoreCommandError {
            code: "restore_runtime_unavailable",
            message_fr: "Le runtime local doit être actif avant une restauration.",
            runtime_state: "offline_recovery_required",
        })?;

    let verified = verify_prepared_restore_authorization(
        &bridge.work_root,
        &bridge.journal_root,
        &authorization,
    )
    .map_err(|error| OfflineRestoreCommandError {
        code: error.code(),
        message_fr: error.operator_message_fr(),
        runtime_state: "unchanged",
    })?;
    let owner = TauriOfflineRestoreOwner { app, state };
    let outcome =
        coordinate_offline_restore(&owner, bridge.launcher.as_ref(), verified.operation_id())
            .map_err(command_error_from_restore)?;

    Ok(command_response(outcome))
}

fn command_response(outcome: OfflineRestoreOutcome) -> OfflineRestoreCommandResponse {
    OfflineRestoreCommandResponse {
        status: outcome.status,
        message_fr: outcome.message_fr,
        runtime_state: if outcome.status == OfflineRestoreStatus::ManualRecoveryRequired {
            "offline_recovery_required"
        } else {
            "verified_running"
        },
    }
}

fn command_error_from_restore(error: OfflineRestoreError) -> OfflineRestoreCommandError {
    OfflineRestoreCommandError {
        code: error.code(),
        message_fr: error.operator_message_fr(),
        runtime_state: if error.keep_runtime_offline() {
            "offline_recovery_required"
        } else {
            "verified_running"
        },
    }
}

#[derive(Serialize)]
struct GoogleOAuthOpenResponse {
    opened: bool,
}

#[derive(Debug, Serialize)]
struct GoogleOAuthOpenError {
    code: &'static str,
    message_fr: &'static str,
}

#[derive(Serialize)]
struct LanConfigurationCommandResponse {
    message_fr: &'static str,
    state: LanProvisioningState,
}

#[derive(Debug, Serialize)]
struct LanConfigurationCommandError {
    code: &'static str,
    message_fr: &'static str,
    #[serde(skip_serializing_if = "Option::is_none")]
    state: Option<Box<LanProvisioningState>>,
}

fn active_lan_upload(
    state: &DesktopState,
) -> Result<Arc<LanUploadSupervisor>, LanConfigurationCommandError> {
    state
        .runtime
        .lock()
        .map_err(|_| LanConfigurationCommandError {
            code: "lan_runtime_unavailable",
            message_fr: "Le runtime LAN local ne peut pas être inspecté.",
            state: None,
        })?
        .as_ref()
        .map(|runtime| Arc::clone(&runtime.lan_upload))
        .ok_or(LanConfigurationCommandError {
            code: "lan_runtime_unavailable",
            message_fr: "Le runtime LAN local doit être actif.",
            state: None,
        })
}

#[tauri::command]
fn list_lan_adapters(
    state: tauri::State<'_, DesktopState>,
) -> Result<LanProvisioningState, LanConfigurationCommandError> {
    Ok(active_lan_upload(state.inner())?.provisioning_state())
}

#[tauri::command]
async fn apply_lan_listener_configuration(
    app: AppHandle,
    configuration: LanListenerSettings,
) -> Result<LanConfigurationCommandResponse, LanConfigurationCommandError> {
    let worker_app = app.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let state = worker_app.state::<DesktopState>();
        let _transition = match state.transition.try_lock() {
            Ok(transition) => transition,
            Err(std::sync::TryLockError::WouldBlock) => {
                return Err(LanConfigurationCommandError {
                    code: "lan_runtime_busy",
                    message_fr: "Une autre transition du runtime est déjà en cours.",
                    state: None,
                });
            }
            Err(std::sync::TryLockError::Poisoned(_)) => {
                return Err(LanConfigurationCommandError {
                    code: "lan_runtime_unavailable",
                    message_fr: "Le runtime LAN local ne peut pas être modifié.",
                    state: None,
                });
            }
        };
        let lan_upload = active_lan_upload(state.inner())?;
        match lan_upload.apply_configuration(configuration) {
            Ok(runtime_state) => Ok(LanConfigurationCommandResponse {
                message_fr: if runtime_state.requested_enabled {
                    "Préférence enregistrée. Le listener reste fermé jusqu’à sa nouvelle attestation."
                } else {
                    "La réception LAN native est désactivée."
                },
                state: runtime_state,
            }),
            Err(error) => Err(LanConfigurationCommandError {
                code: error.code(),
                message_fr: error.operator_message_fr(),
                state: Some(Box::new(lan_upload.provisioning_state())),
            }),
        }
    })
    .await
    .map_err(|_| LanConfigurationCommandError {
        code: "lan_runtime_unavailable",
        message_fr: "Le runtime LAN local ne peut plus être modifié.",
        state: None,
    })?
}

#[tauri::command]
fn open_google_oauth_authorization(
    app: AppHandle,
    state: tauri::State<'_, DesktopState>,
    authorization_url: String,
) -> Result<GoogleOAuthOpenResponse, GoogleOAuthOpenError> {
    let _transition = match state.transition.try_lock() {
        Ok(transition) => transition,
        Err(std::sync::TryLockError::WouldBlock) => {
            return Err(GoogleOAuthOpenError {
                code: "oauth_runtime_busy",
                message_fr: "Une autre transition du runtime est déjà en cours.",
            });
        }
        Err(std::sync::TryLockError::Poisoned(_)) => {
            return Err(GoogleOAuthOpenError {
                code: "oauth_runtime_unavailable",
                message_fr: "Le runtime local ne peut pas vérifier la demande Google.",
            });
        }
    };
    let current_loopback_port =
        state
            .navigation_policy
            .current_loopback_port()
            .ok_or(GoogleOAuthOpenError {
                code: "oauth_runtime_unavailable",
                message_fr: "Le runtime local doit être prêt avant la connexion Google.",
            })?;

    validate_and_open_google_oauth_authorization(
        &authorization_url,
        current_loopback_port,
        |validated_url| {
            app.opener()
                .open_url(validated_url, None::<&str>)
                .map_err(|_| ())
        },
    )
    .map_err(|failure| match failure {
        GoogleOAuthOpenFailure::InvalidAuthorizationUrl => GoogleOAuthOpenError {
            code: "oauth_authorization_url_invalid",
            message_fr: "La demande d’autorisation Google est invalide.",
        },
        GoogleOAuthOpenFailure::BrowserOpenFailed => GoogleOAuthOpenError {
            code: "oauth_browser_open_failed",
            message_fr: "Le navigateur système n’a pas pu être ouvert.",
        },
    })?;

    Ok(GoogleOAuthOpenResponse { opened: true })
}

fn restore_error(
    code: &'static str,
    operator_message_fr: &'static str,
    keep_runtime_offline: bool,
) -> OfflineRestoreError {
    OfflineRestoreError::new(code, operator_message_fr, code, keep_runtime_offline)
}

pub fn run() {
    let navigation_policy = NavigationPolicy::default();
    let policy_for_guard = navigation_policy.clone();
    let startup_state: SharedStartupState = Arc::new(Mutex::new(StartupViewState::default()));

    let application = tauri::Builder::default()
        .plugin(
            tauri_plugin_opener::Builder::new()
                .open_js_links_on_click(false)
                .build(),
        )
        .plugin(tauri_plugin_single_instance::init(
            |app, _arguments, _working_directory| {
                show_desktop_window(app);
            },
        ))
        .plugin(navigation_guard(policy_for_guard))
        .manage(DesktopState::new(
            navigation_policy.clone(),
            Arc::clone(&startup_state),
        ))
        .manage(DesktopBehaviorState::default())
        .manage(SignedUpdaterState::compiled())
        .invoke_handler(tauri::generate_handler![
            apply_prepared_offline_restore,
            open_google_oauth_authorization,
            list_lan_adapters,
            apply_lan_listener_configuration,
            updates::signed_updater_status,
            updates::check_for_signed_update,
            updates::install_signed_update
        ])
        .setup({
            let startup_state = Arc::clone(&startup_state);

            move |app| {
                if let Some(plugin) = updates::configured_plugin() {
                    app.handle().plugin(plugin)?;
                }

                install_system_tray(app.handle())?;
                build_startup_window(app, Arc::clone(&startup_state))?;

                match desktop::prepare_runtime(app.handle()) {
                    Ok(runtime) => {
                        let desktop_state = app.state::<DesktopState>();
                        if let Err(code) =
                            launch_prepared_runtime(app.handle(), &desktop_state, runtime)
                        {
                            set_startup_state(
                                app.handle(),
                                &startup_state,
                                StartupViewState {
                                    phase: "failed",
                                    code: Some(code),
                                },
                            );
                        }
                    }
                    Err(error) => {
                        set_startup_state(
                            app.handle(),
                            &startup_state,
                            StartupViewState {
                                phase: "failed",
                                code: Some(error.code()),
                            },
                        );
                    }
                }

                Ok(())
            }
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                let behavior = window.app_handle().state::<DesktopBehaviorState>();

                if behavior.should_hide_on_close(window.label()) {
                    api.prevent_close();
                    let _ = window.hide();
                }
            }
        })
        .build(tauri::generate_context!())
        .expect("failed to build the MediSmart desktop shell");

    application.run(|app, event| {
        if matches!(event, RunEvent::ExitRequested { .. } | RunEvent::Exit) {
            let state = app.state::<DesktopState>();
            if let Ok(_transition) = state.transition.lock() {
                let _ = shutdown_active_runtime(&state);
            };
        }
    });
}

fn coordinate_tunnel(tunnel: &Arc<TunnelSupervisor>, event: &mut SupervisorEvent) {
    match event {
        SupervisorEvent::Ready {
            local_url,
            verified_remote_upload_boundary,
            ..
        } => desktop::prepare_tunnel_supervisor(
            tunnel,
            local_url,
            verified_remote_upload_boundary.take(),
        ),
        SupervisorEvent::Starting { .. }
        | SupervisorEvent::Retrying { .. }
        | SupervisorEvent::Failed { .. }
        | SupervisorEvent::Stopped => tunnel.stop_active(),
    }
}

fn coordinate_lan_upload(lan_upload: &Arc<LanUploadSupervisor>, event: &mut SupervisorEvent) {
    match event {
        SupervisorEvent::Ready {
            local_url,
            verified_lan_upload_boundary,
            ..
        } => desktop::prepare_lan_upload_supervisor(
            lan_upload,
            local_url,
            verified_lan_upload_boundary.take(),
        ),
        SupervisorEvent::Starting { .. } | SupervisorEvent::Retrying { .. } => {
            // Keep the reserved adapter/port and the already-attested PHP
            // contract stable while the loopback backend is replaced.
            lan_upload.suspend_backend();
        }
        SupervisorEvent::Failed { .. } | SupervisorEvent::Stopped => lan_upload.shutdown(),
    }
}

fn navigation_guard(policy: NavigationPolicy) -> TauriPlugin<tauri::Wry> {
    PluginBuilder::new("medismart-navigation-guard")
        .on_navigation(move |_webview, url| policy.allows(url))
        .build()
}

fn build_startup_window(
    app: &mut tauri::App,
    startup_state: SharedStartupState,
) -> tauri::Result<()> {
    WebviewWindowBuilder::new(app, "startup", WebviewUrl::App("index.html".into()))
        .title("MediSmart — Démarrage")
        .inner_size(720.0, 520.0)
        .min_inner_size(560.0, 420.0)
        .resizable(true)
        .center()
        .on_page_load(move |webview, _payload| {
            let state = startup_state
                .lock()
                .map(|state| state.clone())
                .unwrap_or_default();
            render_startup_state(&webview, &state);
        })
        .build()?;

    Ok(())
}

fn present_supervisor_event(
    app: &AppHandle,
    navigation_policy: &NavigationPolicy,
    startup_state: &SharedStartupState,
    event: SupervisorEvent,
) {
    match event {
        SupervisorEvent::Starting { retry_count } => {
            navigation_policy.set_loopback_port(None);
            show_startup(app);
            set_startup_state(
                app,
                startup_state,
                StartupViewState {
                    phase: if retry_count == 0 {
                        "starting"
                    } else {
                        "retrying"
                    },
                    code: None,
                },
            );
        }
        SupervisorEvent::Ready {
            local_url, port, ..
        } => {
            navigation_policy.set_loopback_port(Some(port));
            match Url::parse(&local_url) {
                Ok(url) => {
                    if show_main_window(app, url).is_err() {
                        navigation_policy.set_loopback_port(None);
                        show_startup(app);
                        set_startup_state(
                            app,
                            startup_state,
                            StartupViewState {
                                phase: "failed",
                                code: Some("startup_failed"),
                            },
                        );
                    }
                }
                Err(_) => {
                    navigation_policy.set_loopback_port(None);
                    show_startup(app);
                    set_startup_state(
                        app,
                        startup_state,
                        StartupViewState {
                            phase: "failed",
                            code: Some("configuration_invalid"),
                        },
                    );
                }
            }
        }
        SupervisorEvent::Retrying { .. } => {
            navigation_policy.set_loopback_port(None);
            if let Some(main) = app.get_webview_window("main") {
                let _ = main.hide();
            }
            show_startup(app);
            set_startup_state(
                app,
                startup_state,
                StartupViewState {
                    phase: "retrying",
                    code: None,
                },
            );
        }
        SupervisorEvent::Failed { code } => {
            navigation_policy.set_loopback_port(None);
            if let Some(main) = app.get_webview_window("main") {
                let _ = main.hide();
            }
            show_startup(app);
            set_startup_state(
                app,
                startup_state,
                StartupViewState {
                    phase: "failed",
                    code: Some(code),
                },
            );
        }
        SupervisorEvent::Stopped => {}
    }
}

fn show_main_window(app: &AppHandle, url: Url) -> Result<(), ()> {
    let result = if let Some(main) = app.get_webview_window("main") {
        main.navigate(url).and_then(|_| main.show())
    } else {
        WebviewWindowBuilder::new(app, "main", WebviewUrl::External(url))
            .title("MediSmart")
            .inner_size(1280.0, 820.0)
            .min_inner_size(1024.0, 680.0)
            .center()
            .build()
            .map(|_| ())
    };

    if result.is_ok() {
        if let Some(main) = app.get_webview_window("main") {
            let _ = main.show();
            let _ = main.set_focus();
        }
        if let Some(startup) = app.get_webview_window("startup") {
            let _ = startup.hide();
        }
    }

    result.map_err(|_| ())
}

fn show_startup(app: &AppHandle) {
    if let Some(startup) = app.get_webview_window("startup") {
        let _ = startup.show();
        let _ = startup.set_focus();
    }
}

fn set_startup_state(app: &AppHandle, shared_state: &SharedStartupState, state: StartupViewState) {
    if let Ok(mut current) = shared_state.lock() {
        *current = state.clone();
    }
    if let Some(startup) = app.get_webview_window("startup") {
        render_startup_state(&startup, &state);
    }
}

fn render_startup_state<R: tauri::Runtime>(
    webview: &tauri::WebviewWindow<R>,
    state: &StartupViewState,
) {
    if let Ok(payload) = serde_json::to_string(state) {
        let _ = webview.eval(format!(
            "window.__medismartSetStartupState && window.__medismartSetStartupState({payload});"
        ));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct FakeShutdown {
        events: Mutex<Vec<&'static str>>,
        fail_scheduler_join: bool,
    }

    impl FakeShutdown {
        fn record(&self, event: &'static str) {
            self.events.lock().unwrap().push(event);
        }
    }

    impl OrderedRuntimeShutdown for FakeShutdown {
        fn freeze_main_refresh(&self) {
            self.record("freeze_main_refresh");
        }

        fn stop_tunnel(&self) {
            self.record("stop_tunnel");
        }

        fn stop_optional_lan(&self) {
            self.record("stop_optional_lan");
        }

        fn stop_scheduler(&self) {
            self.record("stop_scheduler");
        }

        fn join_scheduler(&mut self) -> Result<(), ()> {
            self.record("join_scheduler");
            (!self.fail_scheduler_join).then_some(()).ok_or(())
        }

        fn stop_queue(&self) {
            self.record("stop_queue");
        }

        fn join_queue(&mut self) -> Result<(), ()> {
            self.record("join_queue");
            Ok(())
        }

        fn stop_main(&self) {
            self.record("stop_main");
        }

        fn join_main(&mut self) -> Result<(), ()> {
            self.record("join_main");
            Ok(())
        }
    }

    #[test]
    fn navigation_is_restricted_to_the_selected_loopback_origin() {
        let policy = NavigationPolicy::default();
        policy.set_loopback_port(Some(49152));

        assert_eq!(policy.current_loopback_port(), Some(49152));
        assert!(policy.allows(&Url::parse("http://127.0.0.1:49152/app").unwrap()));
        assert!(!policy.allows(&Url::parse("http://127.0.0.1:8000/app").unwrap()));
        assert!(!policy.allows(&Url::parse("http://localhost:49152/app").unwrap()));
        assert!(!policy.allows(&Url::parse("https://example.com/").unwrap()));
        assert!(!policy.allows(&Url::parse("http://user:password@127.0.0.1:49152/app").unwrap()));
        policy.set_loopback_port(None);
        assert_eq!(policy.current_loopback_port(), None);
    }

    #[test]
    fn oauth_command_result_contract_never_echoes_the_authorization_url() {
        assert_eq!(
            serde_json::to_value(GoogleOAuthOpenResponse { opened: true }).unwrap(),
            serde_json::json!({ "opened": true })
        );
        assert_eq!(
            serde_json::to_value(GoogleOAuthOpenError {
                code: "oauth_authorization_url_invalid",
                message_fr: "La demande d’autorisation Google est invalide.",
            })
            .unwrap(),
            serde_json::json!({
                "code": "oauth_authorization_url_invalid",
                "message_fr": "La demande d’autorisation Google est invalide."
            })
        );
    }

    #[test]
    fn restore_shutdown_order_is_tunnel_lan_scheduler_queue_then_main() {
        let mut runtime = FakeShutdown {
            events: Mutex::new(Vec::new()),
            fail_scheduler_join: false,
        };

        stop_and_join_in_restore_order(&mut runtime).unwrap();

        assert_eq!(
            *runtime.events.lock().unwrap(),
            vec![
                "freeze_main_refresh",
                "stop_tunnel",
                "stop_optional_lan",
                "stop_scheduler",
                "join_scheduler",
                "stop_queue",
                "join_queue",
                "stop_main",
                "join_main",
            ]
        );
    }

    #[test]
    fn shutdown_join_failure_still_stops_every_remaining_writer() {
        let mut runtime = FakeShutdown {
            events: Mutex::new(Vec::new()),
            fail_scheduler_join: true,
        };

        assert!(stop_and_join_in_restore_order(&mut runtime).is_err());
        assert!(runtime
            .events
            .lock()
            .unwrap()
            .ends_with(&["stop_main", "join_main"]));
    }

    #[test]
    fn restore_command_artifact_has_no_path_or_command_surface() {
        let valid = serde_json::json!({
            "protocol": "medismart-offline-restore-authorization",
            "version": 1,
            "operation_id": "9b82c22e-4eef-47ad-b2db-2f2c904d69d2",
            "plan_sha256": "42".repeat(32),
        });
        let artifact: OfflineRestoreAuthorizationArtifact =
            serde_json::from_value(valid.clone()).unwrap();
        assert_eq!(
            artifact.operation_id(),
            "9b82c22e-4eef-47ad-b2db-2f2c904d69d2"
        );

        let mut with_path = valid;
        with_path.as_object_mut().unwrap().insert(
            "archive_path".to_owned(),
            serde_json::json!("C:/backup.msbackup"),
        );
        assert!(serde_json::from_value::<OfflineRestoreAuthorizationArtifact>(with_path).is_err());
    }

    #[test]
    fn native_command_acl_is_limited_to_the_loopback_main_window() {
        let capability: serde_json::Value =
            serde_json::from_str(include_str!("../capabilities/desktop-windows.json")).unwrap();

        assert_eq!(capability["local"], false);
        assert_eq!(capability["windows"], serde_json::json!(["main"]));
        assert_eq!(
            capability["remote"]["urls"],
            serde_json::json!(["http://127.0.0.1:*/*"])
        );
        assert_eq!(
            capability["permissions"],
            serde_json::json!([
                "allow-apply-prepared-offline-restore",
                "allow-open-google-oauth-authorization",
                "allow-list-lan-adapters",
                "allow-apply-lan-listener-configuration",
                "allow-signed-updater-status",
                "allow-check-for-signed-update",
                "allow-install-signed-update"
            ])
        );
        assert!(capability["permissions"]
            .as_array()
            .unwrap()
            .iter()
            .filter_map(serde_json::Value::as_str)
            .all(|permission| !permission.starts_with("opener:")
                && !permission.starts_with("shell:")
                && !permission.starts_with("fs:")));
    }

    #[test]
    fn exclusive_lease_is_revoked_before_any_runtime_restart() {
        let ownership = Arc::new(RestoreOwnershipState::default());
        ownership.shutdown_verified();
        let lease = ownership.lease();
        lease.assert_exclusive().unwrap();

        ownership.runtime_starting();

        assert!(lease.assert_exclusive().is_err());
    }
}
