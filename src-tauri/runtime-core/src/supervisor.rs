use std::{
    fmt, fs,
    io::{BufRead, BufReader, Read},
    net::{Ipv4Addr, TcpListener},
    path::PathBuf,
    process::{Child, Command, Stdio},
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Mutex,
    },
    thread,
    time::{Duration, Instant},
};

use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine as _};
use rand::RngCore;
use reqwest::{blocking::Client, redirect::Policy, StatusCode};
use serde::Deserialize;

use crate::{
    tunnel::VerifiedRemoteUploadBoundary, LanListenerStatus, LanUploadSupervisor,
    QueueWorkerStatus, QueueWorkerSupervisor, RuntimeLogger, RuntimePhase, RuntimeSnapshot,
    SchedulerStatus, SchedulerSupervisor, VerifiedLanUploadBoundary,
};

const MAX_CHILD_LOG_LINE_BYTES: usize = 8 * 1024;
const MAX_HEALTH_RESPONSE_BYTES: u64 = 64 * 1024;

pub struct SupervisorConfig {
    pub php_binary: PathBuf,
    pub app_root: PathBuf,
    pub public_directory: PathBuf,
    pub router_script: PathBuf,
    pub runtime_directory: PathBuf,
    pub temporary_directory: PathBuf,
    pub framework_cache_directory: PathBuf,
    pub database_path: Option<PathBuf>,
    pub storage_path: Option<PathBuf>,
    pub app_key: Option<String>,
    pub installation_id: Option<String>,
    pub application_version: String,
    pub signed_updater_configured: bool,
    pub production: bool,
    pub health_key: String,
    pub tunnel_upload_hostname: Option<String>,
    pub health_timeout: Duration,
    pub shutdown_timeout: Duration,
    pub retry_limit: u8,
    pub retry_delay: Duration,
}

#[derive(Debug)]
pub enum SupervisorEvent {
    Starting {
        retry_count: u8,
    },
    Ready {
        local_url: String,
        port: u16,
        verified_remote_upload_boundary: Option<VerifiedRemoteUploadBoundary>,
        verified_lan_upload_boundary: Option<VerifiedLanUploadBoundary>,
    },
    Retrying {
        retry_count: u8,
        code: &'static str,
    },
    Failed {
        code: &'static str,
    },
    Stopped,
}

#[derive(Debug)]
pub struct RuntimeError {
    code: &'static str,
    detail: String,
}

impl RuntimeError {
    fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self {
            code,
            detail: detail.into(),
        }
    }

    pub fn code(&self) -> &'static str {
        self.code
    }
}

impl fmt::Display for RuntimeError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}: {}", self.code, self.detail)
    }
}

impl std::error::Error for RuntimeError {}

pub struct Supervisor {
    config: SupervisorConfig,
    queue_worker: Arc<QueueWorkerSupervisor>,
    scheduler: Arc<SchedulerSupervisor>,
    lan_upload: Arc<LanUploadSupervisor>,
    logger: Arc<RuntimeLogger>,
    child: Mutex<Option<Child>>,
    stopping: AtomicBool,
    runtime_contract_refresh_enabled: AtomicBool,
    started: AtomicBool,
    snapshot_path: PathBuf,
}

enum MonitorOutcome {
    Stopping,
    Exited(String),
    RuntimeContractStatusChanged(&'static str),
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
struct PhpRuntimeContract {
    queue_worker: QueueWorkerStatus,
    scheduler: SchedulerStatus,
    lan_listener: LanListenerStatus,
    lan_generation: u64,
}

struct LaunchedRuntime {
    local_url: String,
    port: u16,
    runtime_contract: PhpRuntimeContract,
    verified_remote_boundary: Option<VerifiedRemoteUploadBoundary>,
    verified_lan_boundary: Option<VerifiedLanUploadBoundary>,
}

impl Supervisor {
    pub fn new(
        config: SupervisorConfig,
        queue_worker: Arc<QueueWorkerSupervisor>,
        scheduler: Arc<SchedulerSupervisor>,
        lan_upload: Arc<LanUploadSupervisor>,
        logger: Arc<RuntimeLogger>,
    ) -> Result<Self, RuntimeError> {
        fs::create_dir_all(&config.runtime_directory).map_err(|error| {
            RuntimeError::new(
                "runtime_io_failed",
                format!("create runtime directory: {error}"),
            )
        })?;
        fs::create_dir_all(&config.temporary_directory).map_err(|error| {
            RuntimeError::new(
                "runtime_io_failed",
                format!("create temporary directory: {error}"),
            )
        })?;

        let snapshot_path = config.runtime_directory.join("desktop-state.json");

        Ok(Self {
            config,
            queue_worker,
            scheduler,
            lan_upload,
            logger,
            child: Mutex::new(None),
            stopping: AtomicBool::new(false),
            runtime_contract_refresh_enabled: AtomicBool::new(true),
            started: AtomicBool::new(false),
            snapshot_path,
        })
    }

    pub fn run<F>(self: Arc<Self>, notify: F)
    where
        F: Fn(SupervisorEvent) + Send + 'static,
    {
        if self.started.swap(true, Ordering::SeqCst) {
            return;
        }

        let mut retry_count = 0_u8;

        loop {
            if self.stopping.load(Ordering::SeqCst) {
                break;
            }

            notify(SupervisorEvent::Starting { retry_count });
            let snapshot = RuntimeSnapshot::starting(retry_count);
            self.write_snapshot(&snapshot);

            match self.launch_and_wait(retry_count) {
                Ok(launched) => {
                    notify(SupervisorEvent::Ready {
                        local_url: launched.local_url,
                        port: launched.port,
                        verified_remote_upload_boundary: launched.verified_remote_boundary,
                        verified_lan_upload_boundary: launched.verified_lan_boundary,
                    });

                    match self.monitor_child(launched.runtime_contract) {
                        MonitorOutcome::Stopping => break,
                        MonitorOutcome::RuntimeContractStatusChanged(code) => {
                            self.logger
                                .info(&format!("{code}; refreshing the Laravel runtime contract"));
                            self.stop_current_child();
                            // An immutable environment refresh is not a Laravel
                            // crash and must not consume its retry budget.
                            continue;
                        }
                        MonitorOutcome::Exited(detail) => {
                            self.logger
                                .warn(&format!("Laravel process exited: {detail}"));
                            if !self.prepare_retry(&notify, &mut retry_count, "laravel_exited") {
                                break;
                            }
                        }
                    }
                }
                Err(error) if error.code() == "runtime_stopping" => break,
                Err(error) if is_runtime_contract_status_change(error.code()) => {
                    self.logger.info(&format!(
                        "{} during Laravel startup; refreshing the runtime contract",
                        error.code()
                    ));
                    self.stop_current_child();
                    // Preserve retry_count: only the Laravel child contract is
                    // being replaced; both native services keep running.
                    continue;
                }
                Err(error) => {
                    self.logger.error(&error.to_string());
                    self.stop_current_child();
                    if !self.prepare_retry(&notify, &mut retry_count, error.code()) {
                        break;
                    }
                }
            }
        }

        self.stop_current_child();
        let mut snapshot = RuntimeSnapshot::starting(retry_count);
        snapshot.phase = RuntimePhase::Stopped;
        snapshot.touch();
        self.write_snapshot(&snapshot);
        notify(SupervisorEvent::Stopped);
    }

    pub fn stop(&self) {
        if self.stopping.swap(true, Ordering::SeqCst) {
            return;
        }

        self.logger.info("Desktop shutdown requested");
        let mut snapshot = RuntimeSnapshot::starting(0);
        snapshot.phase = RuntimePhase::Stopping;
        snapshot.touch();
        self.write_snapshot(&snapshot);
        self.stop_current_child();
    }

    /// Prevents a dependency status transition from briefly respawning PHP
    /// while the native restore lifecycle intentionally stops dependencies
    /// before the main Laravel process.
    pub fn freeze_runtime_contract_refresh(&self) {
        self.runtime_contract_refresh_enabled
            .store(false, Ordering::SeqCst);
    }

    fn prepare_retry<F>(&self, notify: &F, retry_count: &mut u8, error_code: &'static str) -> bool
    where
        F: Fn(SupervisorEvent),
    {
        if self.stopping.load(Ordering::SeqCst) {
            return false;
        }

        if *retry_count >= self.config.retry_limit {
            let mut snapshot = RuntimeSnapshot::starting(*retry_count);
            snapshot.phase = RuntimePhase::Failed;
            snapshot.last_error_code = Some("process_retries_exhausted".to_owned());
            snapshot.touch();
            self.write_snapshot(&snapshot);
            notify(SupervisorEvent::Failed {
                code: "process_retries_exhausted",
            });
            return false;
        }

        *retry_count += 1;
        let mut snapshot = RuntimeSnapshot::starting(*retry_count);
        snapshot.phase = RuntimePhase::Retrying;
        snapshot.last_error_code = Some(error_code.to_owned());
        snapshot.touch();
        self.write_snapshot(&snapshot);
        notify(SupervisorEvent::Retrying {
            retry_count: *retry_count,
            code: error_code,
        });

        let delay = self
            .config
            .retry_delay
            .saturating_mul(u32::from(*retry_count));
        let deadline = Instant::now() + delay;
        while Instant::now() < deadline {
            if self.stopping.load(Ordering::SeqCst) {
                return false;
            }
            thread::sleep(Duration::from_millis(50));
        }

        true
    }

    fn launch_and_wait(&self, retry_count: u8) -> Result<LaunchedRuntime, RuntimeError> {
        let port = allocate_loopback_port()?;
        let local_url = format!("http://127.0.0.1:{port}");
        let runtime_contract = self.observed_runtime_contract();

        let mut command = Command::new(&self.config.php_binary);
        command
            .current_dir(&self.config.app_root)
            .arg("-S")
            .arg(format!("127.0.0.1:{port}"))
            .arg("-t")
            .arg(&self.config.public_directory)
            .arg(&self.config.router_script)
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .env("APP_URL", &local_url)
            .env("MEDISMART_LOCAL_URL", &local_url)
            .env("MEDISMART_DESKTOP_SUPERVISED", "true")
            .env("MEDISMART_HEALTH_DETAILS_KEY", &self.config.health_key)
            .env(
                "MEDISMART_NATIVE_TUNNEL_STATUS_PATH",
                self.config
                    .runtime_directory
                    .join("tunnel-public-status.json"),
            )
            .env("MEDISMART_VERSION", &self.config.application_version)
            .env(
                "MEDISMART_SIGNED_UPDATER_CONFIGURED",
                if self.config.signed_updater_configured {
                    "true"
                } else {
                    "false"
                },
            )
            .env("QUEUE_CONNECTION", "database")
            .env("TELESCOPE_ENABLED", "false")
            .env("INERTIA_DEVTOOLS_ENABLED", "false")
            .env("PHP_CLI_SERVER_WORKERS", "1")
            .env("TMP", &self.config.temporary_directory)
            .env("TEMP", &self.config.temporary_directory)
            .env("TMPDIR", &self.config.temporary_directory);

        configure_remote_upload_origin(&mut command, self.config.tunnel_upload_hostname.as_deref());
        let lan_upload_origin = self.lan_upload.required_attestation_origin();
        configure_lan_upload_contract(
            &mut command,
            lan_upload_origin.as_deref(),
            runtime_contract.lan_listener,
        );
        command.env(
            "MEDISMART_LAN_ADAPTERS_FILE",
            self.lan_upload.adapter_inventory_path(),
        );

        configure_queue_worker_status(&mut command, runtime_contract.queue_worker);
        configure_scheduler_status(&mut command, runtime_contract.scheduler);

        command
            .env(
                "APP_SERVICES_CACHE",
                self.config.framework_cache_directory.join("services.php"),
            )
            .env(
                "APP_PACKAGES_CACHE",
                self.config.framework_cache_directory.join("packages.php"),
            )
            .env(
                "APP_CONFIG_CACHE",
                self.config.framework_cache_directory.join("config.php"),
            )
            .env(
                "APP_ROUTES_CACHE",
                self.config.framework_cache_directory.join("routes.php"),
            )
            .env(
                "APP_EVENTS_CACHE",
                self.config.framework_cache_directory.join("events.php"),
            );

        if self.config.production {
            command
                .env("APP_ENV", "production")
                .env("APP_DEBUG", "false")
                .env("LOG_CHANNEL", "single")
                .env("LOG_LEVEL", "warning");
        }

        if let Some(database_path) = &self.config.database_path {
            command
                .env("DB_CONNECTION", "sqlite")
                .env("DB_DATABASE", database_path);
        }
        if let Some(storage_path) = &self.config.storage_path {
            command.env("LARAVEL_STORAGE_PATH", storage_path);
        }
        if let Some(app_key) = &self.config.app_key {
            command.env("APP_KEY", app_key);
        }
        if let Some(installation_id) = &self.config.installation_id {
            command.env("MEDISMART_DESKTOP_INSTALLATION_ID", installation_id);
        }

        configure_platform_process(&mut command);

        let mut child = command.spawn().map_err(|error| {
            RuntimeError::new(
                "process_spawn_failed",
                format!("start bundled PHP: {error}"),
            )
        })?;
        let process_id = child.id();

        if let Some(stdout) = child.stdout.take() {
            pump_child_output(stdout, "PHP-OUT", Arc::clone(&self.logger));
        }
        if let Some(stderr) = child.stderr.take() {
            pump_child_output(stderr, "PHP-ERR", Arc::clone(&self.logger));
        }

        {
            let mut guard = self.child.lock().map_err(|_| {
                RuntimeError::new("runtime_io_failed", "child process lock is poisoned")
            })?;
            *guard = Some(child);
        }

        self.logger.info(&format!(
            "Laravel process started on loopback port {port} (attempt {retry_count})"
        ));

        let mut snapshot = RuntimeSnapshot::starting(retry_count);
        snapshot.local_port = Some(port);
        snapshot.process_id = Some(process_id);
        snapshot.touch();
        self.write_snapshot(&snapshot);

        let (verified_remote_boundary, verified_lan_boundary) =
            self.wait_until_healthy(&local_url, runtime_contract)?;

        snapshot.phase = RuntimePhase::Healthy;
        snapshot.touch();
        self.write_snapshot(&snapshot);
        self.logger
            .info("Authenticated Laravel readiness check passed");

        Ok(LaunchedRuntime {
            local_url,
            port,
            runtime_contract,
            verified_remote_boundary,
            verified_lan_boundary,
        })
    }

    fn wait_until_healthy(
        &self,
        local_url: &str,
        expected_runtime_contract: PhpRuntimeContract,
    ) -> Result<
        (
            Option<VerifiedRemoteUploadBoundary>,
            Option<VerifiedLanUploadBoundary>,
        ),
        RuntimeError,
    > {
        let client = Client::builder()
            .connect_timeout(Duration::from_secs(1))
            .timeout(Duration::from_secs(2))
            .redirect(Policy::none())
            .no_proxy()
            .build()
            .map_err(|error| {
                RuntimeError::new(
                    "health_unavailable",
                    format!("build health client: {error}"),
                )
            })?;
        let deadline = Instant::now() + self.config.health_timeout;
        let health_url = format!("{local_url}/health");

        while Instant::now() < deadline {
            if self.stopping.load(Ordering::SeqCst) {
                return Err(RuntimeError::new(
                    "runtime_stopping",
                    "shutdown requested during readiness polling",
                ));
            }
            if self.runtime_contract_refresh_enabled.load(Ordering::SeqCst) {
                if let Some(code) = runtime_contract_change_code(
                    expected_runtime_contract,
                    self.observed_runtime_contract(),
                ) {
                    return Err(RuntimeError::new(
                        code,
                        "a supervised runtime status changed during Laravel readiness polling",
                    ));
                }
            }
            if self.child_has_exited()? {
                return Err(RuntimeError::new(
                    "laravel_exited",
                    "PHP exited before readiness succeeded",
                ));
            }

            let response = client
                .get(&health_url)
                .header("X-MediSmart-Health-Key", &self.config.health_key)
                .send();

            if let Ok(mut response) = response {
                if response.status() == StatusCode::OK {
                    let mut body = Vec::with_capacity(2048);
                    if response
                        .by_ref()
                        .take(MAX_HEALTH_RESPONSE_BYTES + 1)
                        .read_to_end(&mut body)
                        .is_ok()
                        && body.len() <= MAX_HEALTH_RESPONSE_BYTES as usize
                    {
                        if let Ok(payload) = serde_json::from_slice::<DetailedHealthResponse>(&body)
                        {
                            if payload.is_ready() {
                                let verified_remote_boundary =
                                    self.config.tunnel_upload_hostname.as_deref().and_then(
                                        |hostname| {
                                            VerifiedRemoteUploadBoundary::from_health_response(
                                                &body, hostname, local_url,
                                            )
                                        },
                                    );
                                if self.config.tunnel_upload_hostname.is_some()
                                    && verified_remote_boundary.is_none()
                                {
                                    self.logger.warn(
                                        "Remote upload boundary attestation is unavailable; the tunnel remains disabled",
                                    );
                                }
                                let lan_origin = self.lan_upload.required_attestation_origin();
                                let verified_lan_boundary =
                                    lan_origin.as_deref().and_then(|origin| {
                                        VerifiedLanUploadBoundary::from_health_response(
                                            &body, origin,
                                        )
                                    });
                                if lan_origin.is_some() && verified_lan_boundary.is_none() {
                                    self.logger.warn(
                                        "LAN upload boundary attestation is unavailable; the listener remains closed",
                                    );
                                }
                                return Ok((verified_remote_boundary, verified_lan_boundary));
                            }
                        }
                    }
                }
            }

            thread::sleep(Duration::from_millis(200));
        }

        Err(RuntimeError::new(
            "health_timeout",
            "Laravel did not return an authenticated healthy response before the deadline",
        ))
    }

    fn child_has_exited(&self) -> Result<bool, RuntimeError> {
        let mut guard = self.child.lock().map_err(|_| {
            RuntimeError::new("runtime_io_failed", "child process lock is poisoned")
        })?;
        let Some(child) = guard.as_mut() else {
            return Ok(true);
        };

        child
            .try_wait()
            .map(|status| status.is_some())
            .map_err(|error| {
                RuntimeError::new("runtime_io_failed", format!("inspect PHP process: {error}"))
            })
    }

    fn observed_runtime_contract(&self) -> PhpRuntimeContract {
        self.lan_upload.reconcile_network_if_due();
        PhpRuntimeContract {
            queue_worker: self.queue_worker.status_for_php(),
            scheduler: self.scheduler.status_for_php(),
            lan_listener: self.lan_upload.status_for_php(),
            lan_generation: self.lan_upload.contract_generation(),
        }
    }

    fn monitor_child(&self, expected_runtime_contract: PhpRuntimeContract) -> MonitorOutcome {
        loop {
            if self.stopping.load(Ordering::SeqCst) {
                return MonitorOutcome::Stopping;
            }
            if self.runtime_contract_refresh_enabled.load(Ordering::SeqCst) {
                if let Some(code) = runtime_contract_change_code(
                    expected_runtime_contract,
                    self.observed_runtime_contract(),
                ) {
                    return MonitorOutcome::RuntimeContractStatusChanged(code);
                }
            }

            let status = {
                let Ok(mut guard) = self.child.lock() else {
                    return MonitorOutcome::Exited("process lock poisoned".to_owned());
                };
                match guard.as_mut() {
                    Some(child) => child.try_wait(),
                    None => return MonitorOutcome::Stopping,
                }
            };

            match status {
                Ok(Some(exit_status)) => {
                    if let Ok(mut guard) = self.child.lock() {
                        guard.take();
                    }
                    return MonitorOutcome::Exited(format!("status {exit_status}"));
                }
                Ok(None) => thread::sleep(Duration::from_millis(100)),
                Err(error) => {
                    return MonitorOutcome::Exited(format!("status unavailable: {error}"));
                }
            }
        }
    }

    fn stop_current_child(&self) {
        let child = self.child.lock().ok().and_then(|mut child| child.take());
        let Some(mut child) = child else {
            return;
        };

        request_graceful_termination(child.id());
        let deadline = Instant::now() + self.config.shutdown_timeout;
        while Instant::now() < deadline {
            match child.try_wait() {
                Ok(Some(_)) => {
                    self.logger.info("Laravel process stopped cleanly");
                    return;
                }
                Ok(None) => thread::sleep(Duration::from_millis(50)),
                Err(error) => {
                    self.logger
                        .warn(&format!("Could not inspect PHP shutdown: {error}"));
                    break;
                }
            }
        }

        self.logger
            .warn("Laravel process exceeded its shutdown grace period; forcing termination");
        let _ = child.kill();
        let _ = child.wait();
    }

    fn write_snapshot(&self, snapshot: &RuntimeSnapshot) {
        let Ok(bytes) = serde_json::to_vec_pretty(snapshot) else {
            return;
        };
        let temporary = self.snapshot_path.with_extension("json.tmp");

        if fs::write(&temporary, bytes).is_err() {
            return;
        }
        if self.snapshot_path.exists() {
            let _ = fs::remove_file(&self.snapshot_path);
        }
        let _ = fs::rename(temporary, &self.snapshot_path);
    }
}

impl Drop for Supervisor {
    fn drop(&mut self) {
        self.stopping.store(true, Ordering::SeqCst);
        self.stop_current_child();
    }
}

pub fn allocate_loopback_port() -> Result<u16, RuntimeError> {
    let listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).map_err(|error| {
        RuntimeError::new(
            "port_unavailable",
            format!("reserve a loopback port: {error}"),
        )
    })?;
    listener
        .local_addr()
        .map(|address| address.port())
        .map_err(|error| {
            RuntimeError::new("port_unavailable", format!("read loopback port: {error}"))
        })
}

pub fn generate_runtime_secret() -> String {
    let mut bytes = [0_u8; 32];
    rand::rng().fill_bytes(&mut bytes);
    URL_SAFE_NO_PAD.encode(bytes)
}

fn configure_queue_worker_status(command: &mut Command, status: QueueWorkerStatus) {
    command.env("MEDISMART_QUEUE_WORKER_STATUS", status.as_env_value());
}

fn configure_scheduler_status(command: &mut Command, status: SchedulerStatus) {
    command.env("MEDISMART_SCHEDULER_STATUS", status.as_env_value());
}

fn configure_lan_upload_contract(
    command: &mut Command,
    origin: Option<&str>,
    status: LanListenerStatus,
) {
    command.env_remove("MEDISMART_LAN_UPLOAD_URL");
    if let Some(origin) = origin {
        command.env("MEDISMART_LAN_UPLOAD_URL", origin);
    }
    command.env("MEDISMART_LAN_LISTENER_STATUS", status.as_env_value());
}

fn runtime_contract_change_code(
    expected: PhpRuntimeContract,
    observed: PhpRuntimeContract,
) -> Option<&'static str> {
    if expected.queue_worker != observed.queue_worker {
        Some("queue_worker_status_changed")
    } else if expected.scheduler != observed.scheduler {
        Some("scheduler_status_changed")
    } else if expected.lan_listener != observed.lan_listener {
        Some("lan_listener_status_changed")
    } else if expected.lan_generation != observed.lan_generation {
        Some("lan_listener_configuration_changed")
    } else {
        None
    }
}

fn is_runtime_contract_status_change(code: &str) -> bool {
    matches!(
        code,
        "queue_worker_status_changed"
            | "scheduler_status_changed"
            | "lan_listener_status_changed"
            | "lan_listener_configuration_changed"
    )
}

fn configure_remote_upload_origin(command: &mut Command, hostname: Option<&str>) {
    command.env_remove("MEDISMART_REMOTE_UPLOAD_URL");
    if let Some(hostname) = hostname {
        command.env("MEDISMART_REMOTE_UPLOAD_URL", format!("https://{hostname}"));
    }
}

#[cfg(windows)]
pub(crate) fn configure_platform_process(command: &mut Command) {
    use std::os::windows::process::CommandExt;
    use windows_sys::Win32::System::Threading::{CREATE_NEW_PROCESS_GROUP, CREATE_NO_WINDOW};

    command.creation_flags(CREATE_NEW_PROCESS_GROUP | CREATE_NO_WINDOW);
}

#[cfg(not(windows))]
pub(crate) fn configure_platform_process(_command: &mut Command) {}

pub(crate) fn request_graceful_termination(process_id: u32) {
    #[cfg(unix)]
    unsafe {
        libc::kill(process_id as i32, libc::SIGTERM);
    }

    #[cfg(windows)]
    unsafe {
        use windows_sys::Win32::System::Console::{GenerateConsoleCtrlEvent, CTRL_BREAK_EVENT};

        GenerateConsoleCtrlEvent(CTRL_BREAK_EVENT, process_id);
    }
}

pub(crate) fn pump_child_output<R>(reader: R, stream: &'static str, logger: Arc<RuntimeLogger>)
where
    R: Read + Send + 'static,
{
    thread::spawn(move || {
        let mut reader = BufReader::with_capacity(MAX_CHILD_LOG_LINE_BYTES + 1, reader);
        let mut line = Vec::with_capacity(512);
        let mut dropping_oversized_line = false;

        loop {
            let available = match reader.fill_buf() {
                Ok([]) => break,
                Ok(bytes) => bytes,
                Err(_) => break,
            };
            let consumed;

            if let Some(newline) = available.iter().position(|byte| *byte == b'\n') {
                let segment = &available[..newline];
                consumed = newline + 1;

                if dropping_oversized_line
                    || line.len().saturating_add(segment.len()) > MAX_CHILD_LOG_LINE_BYTES
                {
                    logger.child_output(stream, "[oversized process line omitted]");
                } else {
                    line.extend_from_slice(segment);
                    logger.child_output(stream, &String::from_utf8_lossy(&line));
                }
                line.clear();
                dropping_oversized_line = false;
            } else {
                consumed = available.len();
                if !dropping_oversized_line
                    && line.len().saturating_add(available.len()) <= MAX_CHILD_LOG_LINE_BYTES
                {
                    line.extend_from_slice(available);
                } else {
                    line.clear();
                    dropping_oversized_line = true;
                }
            }

            reader.consume(consumed);
        }

        if dropping_oversized_line {
            logger.child_output(stream, "[oversized process line omitted]");
        } else if !line.is_empty() {
            logger.child_output(stream, &String::from_utf8_lossy(&line));
        }
    });
}

#[derive(Debug, Deserialize)]
struct DetailedHealthResponse {
    status: String,
    database: Option<DatabaseHealth>,
    storage: Option<StorageHealth>,
    queue: Option<QueueHealth>,
}

impl DetailedHealthResponse {
    fn is_ready(&self) -> bool {
        self.status == "healthy"
            && self
                .database
                .as_ref()
                .is_some_and(|database| database.connected && database.foundation_ready)
            && self
                .storage
                .as_ref()
                .is_some_and(|storage| storage.writable)
            && self.queue.as_ref().is_some_and(|queue| queue.available)
    }
}

#[derive(Debug, Deserialize)]
struct DatabaseHealth {
    connected: bool,
    foundation_ready: bool,
}

#[derive(Debug, Deserialize)]
struct StorageHealth {
    writable: bool,
}

#[derive(Debug, Deserialize)]
struct QueueHealth {
    available: bool,
}

#[cfg(test)]
mod tests {
    #[cfg(unix)]
    use std::path::Path;

    #[cfg(unix)]
    use uuid::Uuid;

    use super::*;

    #[test]
    fn php_child_receives_only_the_observed_queue_worker_status() {
        for (status, expected) in [
            (QueueWorkerStatus::Active, "active"),
            (QueueWorkerStatus::Stopped, "stopped"),
        ] {
            let mut command = Command::new("php");
            configure_queue_worker_status(&mut command, status);
            let value = command
                .get_envs()
                .find(|(name, _)| *name == "MEDISMART_QUEUE_WORKER_STATUS")
                .and_then(|(_, value)| value)
                .and_then(|value| value.to_str());

            assert_eq!(value, Some(expected));
        }
    }

    #[test]
    fn php_child_receives_only_the_observed_scheduler_status() {
        for (status, expected) in [
            (SchedulerStatus::Active, "active"),
            (SchedulerStatus::Stopped, "stopped"),
        ] {
            let mut command = Command::new("php");
            configure_scheduler_status(&mut command, status);
            let value = command
                .get_envs()
                .find(|(name, _)| *name == "MEDISMART_SCHEDULER_STATUS")
                .and_then(|(_, value)| value)
                .and_then(|value| value.to_str());

            assert_eq!(value, Some(expected));
        }
    }

    #[test]
    fn php_child_receives_only_the_native_lan_origin_and_observed_status() {
        let mut enabled = Command::new("php");
        enabled.env("MEDISMART_LAN_UPLOAD_URL", "http://10.0.0.1:9999");
        configure_lan_upload_contract(
            &mut enabled,
            Some("http://192.168.1.40:43124"),
            LanListenerStatus::Active,
        );
        let origin = enabled
            .get_envs()
            .find(|(name, _)| *name == "MEDISMART_LAN_UPLOAD_URL")
            .and_then(|(_, value)| value)
            .and_then(|value| value.to_str());
        let status = enabled
            .get_envs()
            .find(|(name, _)| *name == "MEDISMART_LAN_LISTENER_STATUS")
            .and_then(|(_, value)| value)
            .and_then(|value| value.to_str());
        assert_eq!(origin, Some("http://192.168.1.40:43124"));
        assert_eq!(status, Some("active"));

        let mut disabled = Command::new("php");
        disabled.env("MEDISMART_LAN_UPLOAD_URL", "http://10.0.0.1:9999");
        configure_lan_upload_contract(&mut disabled, None, LanListenerStatus::Stopped);
        assert!(disabled
            .get_envs()
            .find(|(name, _)| *name == "MEDISMART_LAN_UPLOAD_URL")
            .is_some_and(|(_, value)| value.is_none()));
    }

    #[test]
    fn scheduler_status_change_is_a_contract_refresh_not_a_laravel_crash() {
        let expected = PhpRuntimeContract {
            queue_worker: QueueWorkerStatus::Active,
            scheduler: SchedulerStatus::Stopped,
            lan_listener: LanListenerStatus::Stopped,
            lan_generation: 0,
        };
        let observed = PhpRuntimeContract {
            queue_worker: QueueWorkerStatus::Active,
            scheduler: SchedulerStatus::Active,
            lan_listener: LanListenerStatus::Stopped,
            lan_generation: 0,
        };

        let code = runtime_contract_change_code(expected, observed).unwrap();

        assert_eq!(code, "scheduler_status_changed");
        assert!(is_runtime_contract_status_change(code));
        assert!(!is_runtime_contract_status_change("laravel_exited"));
    }

    #[test]
    fn lan_listener_status_change_is_a_contract_refresh_not_a_laravel_crash() {
        let expected = PhpRuntimeContract {
            queue_worker: QueueWorkerStatus::Active,
            scheduler: SchedulerStatus::Active,
            lan_listener: LanListenerStatus::Stopped,
            lan_generation: 0,
        };
        let observed = PhpRuntimeContract {
            queue_worker: QueueWorkerStatus::Active,
            scheduler: SchedulerStatus::Active,
            lan_listener: LanListenerStatus::Active,
            lan_generation: 0,
        };

        let code = runtime_contract_change_code(expected, observed).unwrap();

        assert_eq!(code, "lan_listener_status_changed");
        assert!(is_runtime_contract_status_change(code));
    }

    #[test]
    fn lan_configuration_generation_change_refreshes_the_php_environment() {
        let expected = PhpRuntimeContract {
            queue_worker: QueueWorkerStatus::Active,
            scheduler: SchedulerStatus::Active,
            lan_listener: LanListenerStatus::Stopped,
            lan_generation: 4,
        };
        let observed = PhpRuntimeContract {
            lan_generation: 5,
            ..expected
        };

        let code = runtime_contract_change_code(expected, observed).unwrap();

        assert_eq!(code, "lan_listener_configuration_changed");
        assert!(is_runtime_contract_status_change(code));
    }

    #[cfg(unix)]
    #[test]
    fn scheduler_change_stops_only_laravel_while_the_queue_stays_alive() {
        let directory =
            std::env::temp_dir().join(format!("medismart-runtime-contract-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        fs::write(
            directory.join("artisan"),
            "trap 'exit 0' TERM; while :; do sleep 1; done\n",
        )
        .unwrap();

        let queue_logger = test_service_logger(&directory, "queue-worker-supervisor.log");
        let scheduler_logger = test_service_logger(&directory, "scheduler-supervisor.log");
        let queue_worker = Arc::new(
            QueueWorkerSupervisor::new(
                crate::QueueWorkerConfig {
                    php_binary: PathBuf::from("/bin/sh"),
                    app_root: directory.clone(),
                    runtime_directory: directory.join("runtime"),
                    temporary_directory: directory.join("tmp"),
                    framework_cache_directory: directory.join("cache"),
                    database_path: Some(directory.join("database.sqlite")),
                    storage_path: Some(directory.join("storage")),
                    app_key: Some("base64:test-runtime-contract-secret".to_owned()),
                    installation_id: Some(Uuid::new_v4().to_string()),
                    application_version: "0.1.0-test".to_owned(),
                    production: false,
                    startup_stability_timeout: Duration::from_millis(50),
                    shutdown_timeout: Duration::from_secs(2),
                    retry_limit: 0,
                    retry_delay: Duration::from_millis(10),
                },
                queue_logger,
            )
            .unwrap(),
        );
        let scheduler = Arc::new(
            SchedulerSupervisor::new(
                crate::SchedulerConfig {
                    php_binary: PathBuf::from("/bin/sh"),
                    app_root: directory.clone(),
                    runtime_directory: directory.join("runtime"),
                    temporary_directory: directory.join("tmp"),
                    framework_cache_directory: directory.join("cache"),
                    database_path: Some(directory.join("database.sqlite")),
                    storage_path: Some(directory.join("storage")),
                    app_key: Some("base64:test-runtime-contract-secret".to_owned()),
                    installation_id: Some(Uuid::new_v4().to_string()),
                    application_version: "0.1.0-test".to_owned(),
                    production: false,
                    startup_stability_timeout: Duration::from_millis(50),
                    shutdown_timeout: Duration::from_secs(2),
                    retry_limit: 0,
                    retry_delay: Duration::from_millis(10),
                },
                scheduler_logger,
            )
            .unwrap(),
        );
        let queue_thread = {
            let queue_worker = Arc::clone(&queue_worker);
            thread::spawn(move || queue_worker.run())
        };
        let scheduler_thread = {
            let scheduler = Arc::clone(&scheduler);
            thread::spawn(move || scheduler.run())
        };
        assert_eq!(
            queue_worker.wait_for_initial_status(Duration::from_secs(2)),
            QueueWorkerStatus::Active
        );
        assert_eq!(
            scheduler.wait_for_initial_status(Duration::from_secs(2)),
            SchedulerStatus::Active
        );

        let supervisor = Supervisor::new(
            test_supervisor_config(&directory),
            Arc::clone(&queue_worker),
            Arc::clone(&scheduler),
            Arc::new(
                LanUploadSupervisor::disabled(
                    &directory.join("runtime"),
                    test_service_logger(&directory, "lan-supervisor.log"),
                )
                .unwrap(),
            ),
            test_service_logger(&directory, "desktop-supervisor.log"),
        )
        .unwrap();
        let laravel_stopped = directory.join("laravel-stopped");
        let mut laravel = Command::new("/bin/sh");
        laravel
            .arg("-c")
            .arg(format!(
                "trap \"printf stopped > '{}'; exit 0\" TERM; while :; do sleep 1; done",
                laravel_stopped.display()
            ))
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null());
        configure_platform_process(&mut laravel);
        *supervisor.child.lock().unwrap() = Some(laravel.spawn().unwrap());
        let expected = supervisor.observed_runtime_contract();

        scheduler.shutdown();
        scheduler_thread.join().unwrap();
        let outcome = supervisor.monitor_child(expected);
        assert!(matches!(
            outcome,
            MonitorOutcome::RuntimeContractStatusChanged("scheduler_status_changed")
        ));
        supervisor.stop_current_child();

        assert_eq!(fs::read_to_string(&laravel_stopped).unwrap(), "stopped");
        assert_eq!(queue_worker.status_for_php(), QueueWorkerStatus::Active);
        assert_eq!(scheduler.status_for_php(), SchedulerStatus::Stopped);

        queue_worker.shutdown();
        queue_thread.join().unwrap();
        drop(supervisor);
        drop(scheduler);
        drop(queue_worker);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    fn test_service_logger(directory: &Path, file_name: &str) -> Arc<RuntimeLogger> {
        Arc::new(
            RuntimeLogger::open_named(
                &directory.join("logs"),
                file_name,
                &["base64:test-runtime-contract-secret".to_owned()],
                &[directory.to_path_buf()],
            )
            .unwrap(),
        )
    }

    #[cfg(unix)]
    fn test_supervisor_config(directory: &Path) -> SupervisorConfig {
        SupervisorConfig {
            php_binary: PathBuf::from("/bin/sh"),
            app_root: directory.to_path_buf(),
            public_directory: directory.to_path_buf(),
            router_script: directory.join("router.php"),
            runtime_directory: directory.join("runtime"),
            temporary_directory: directory.join("tmp"),
            framework_cache_directory: directory.join("cache"),
            database_path: Some(directory.join("database.sqlite")),
            storage_path: Some(directory.join("storage")),
            app_key: Some("base64:test-runtime-contract-secret".to_owned()),
            installation_id: Some(Uuid::new_v4().to_string()),
            application_version: "0.1.0-test".to_owned(),
            signed_updater_configured: false,
            production: false,
            health_key: "test-health-key".to_owned(),
            tunnel_upload_hostname: None,
            health_timeout: Duration::from_secs(1),
            shutdown_timeout: Duration::from_secs(2),
            retry_limit: 0,
            retry_delay: Duration::from_millis(10),
        }
    }

    #[test]
    fn php_child_receives_only_the_validated_tunnel_hostname_as_remote_origin() {
        let mut enabled = Command::new("php");
        enabled.env("MEDISMART_REMOTE_UPLOAD_URL", "https://stale.example");
        configure_remote_upload_origin(&mut enabled, Some("uploads.clinic.example"));
        let enabled_value = enabled
            .get_envs()
            .find(|(name, _)| *name == "MEDISMART_REMOTE_UPLOAD_URL")
            .and_then(|(_, value)| value)
            .and_then(|value| value.to_str());
        assert_eq!(enabled_value, Some("https://uploads.clinic.example"));

        let mut disabled = Command::new("php");
        disabled.env("MEDISMART_REMOTE_UPLOAD_URL", "https://stale.example");
        configure_remote_upload_origin(&mut disabled, None);
        let disabled_entry = disabled
            .get_envs()
            .find(|(name, _)| *name == "MEDISMART_REMOTE_UPLOAD_URL")
            .unwrap();
        assert!(disabled_entry.1.is_none());
    }

    #[test]
    fn allocated_port_is_loopback_bindable_after_reservation_is_released() {
        let port = allocate_loopback_port().unwrap();
        let listener = TcpListener::bind((Ipv4Addr::LOCALHOST, port)).unwrap();

        assert_eq!(listener.local_addr().unwrap().ip(), Ipv4Addr::LOCALHOST);
    }

    #[test]
    fn detailed_health_is_required_for_readiness() {
        let minimal: DetailedHealthResponse =
            serde_json::from_str(r#"{"status":"healthy"}"#).unwrap();
        let detailed_body = br#"{
                "status":"healthy",
                "database":{"connected":true,"foundation_ready":true},
                "storage":{"writable":true},
                "queue":{"available":true}
            }"#;
        let detailed: DetailedHealthResponse = serde_json::from_slice(detailed_body).unwrap();

        assert!(!minimal.is_ready());
        assert!(detailed.is_ready());
        assert!(VerifiedRemoteUploadBoundary::from_health_response(
            detailed_body,
            "uploads.example.test",
            "http://127.0.0.1:43123",
        )
        .is_none());
    }

    #[test]
    fn authenticated_health_mints_only_the_exact_complete_tunnel_capability() {
        let listener_origin = "http://127.0.0.1:43123";
        let exact_body = br#"{
                "status":"healthy",
                "database":{"connected":true,"foundation_ready":true},
                "storage":{"writable":true},
                "queue":{"available":true},
                "remote_upload_boundary":{
                    "schema_version":1,
                    "status":"ready",
                    "hostname":"uploads.example.test",
                    "listener_origin":"http://127.0.0.1:43123",
                    "route_set":"public_upload_v1",
                    "upload_routes_only":true,
                    "exact_host_enforced":true,
                    "trusted_proxy_enforced":true,
                    "forwarded_https_enforced":true,
                    "local_tokens_rejected_on_remote_host":true
                }
            }"#;
        let exact: DetailedHealthResponse = serde_json::from_slice(exact_body).unwrap();
        assert!(exact.is_ready());
        assert!(VerifiedRemoteUploadBoundary::from_health_response(
            exact_body,
            "uploads.example.test",
            listener_origin,
        )
        .is_some());

        let extra_field_body = br#"{
                "status":"healthy",
                "database":{"connected":true,"foundation_ready":true},
                "storage":{"writable":true},
                "queue":{"available":true},
                "remote_upload_boundary":{
                    "schema_version":1,
                    "status":"ready",
                    "hostname":"uploads.example.test",
                    "listener_origin":"http://127.0.0.1:43123",
                    "route_set":"public_upload_v1",
                    "upload_routes_only":true,
                    "exact_host_enforced":true,
                    "trusted_proxy_enforced":true,
                    "forwarded_https_enforced":true,
                    "local_tokens_rejected_on_remote_host":true,
                    "future_control":true
                }
            }"#;
        let extra_field: DetailedHealthResponse = serde_json::from_slice(extra_field_body).unwrap();
        assert!(extra_field.is_ready());
        assert!(VerifiedRemoteUploadBoundary::from_health_response(
            extra_field_body,
            "uploads.example.test",
            listener_origin,
        )
        .is_none());
    }

    #[test]
    fn generated_health_credentials_are_random_and_not_empty() {
        let first = generate_runtime_secret();
        let second = generate_runtime_secret();

        assert_ne!(first, second);
        assert!(first.len() >= 40);
        assert!(!first.contains('='));
    }
}
