use std::{
    fmt, fs,
    path::PathBuf,
    process::{Child, Command, Stdio},
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Condvar, Mutex, RwLock,
    },
    thread,
    time::{Duration, Instant},
};

use serde::{Deserialize, Serialize};

use crate::{
    diagnostics::now_unix_ms,
    supervisor::{configure_platform_process, pump_child_output, request_graceful_termination},
    RuntimeLogger,
};

const POLL_INTERVAL: Duration = Duration::from_millis(100);

pub struct SchedulerConfig {
    pub php_binary: PathBuf,
    pub app_root: PathBuf,
    pub runtime_directory: PathBuf,
    pub temporary_directory: PathBuf,
    pub framework_cache_directory: PathBuf,
    pub database_path: Option<PathBuf>,
    pub storage_path: Option<PathBuf>,
    pub app_key: Option<String>,
    pub installation_id: Option<String>,
    pub application_version: String,
    pub production: bool,
    pub startup_stability_timeout: Duration,
    pub shutdown_timeout: Duration,
    /// Number of launches after the initial attempt. This is capped at two,
    /// giving the scheduler at most three total launches.
    pub retry_limit: u8,
    pub retry_delay: Duration,
}

#[derive(Clone, Copy, Debug, Deserialize, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum SchedulerStatus {
    Active,
    Stopped,
}

impl SchedulerStatus {
    pub const fn as_env_value(self) -> &'static str {
        match self {
            Self::Active => "active",
            Self::Stopped => "stopped",
        }
    }
}

#[derive(Clone, Copy, Debug, Deserialize, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
enum SchedulerPhase {
    Starting,
    Active,
    Retrying,
    Failed,
    Stopping,
    Stopped,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
struct SchedulerSnapshot {
    schema_version: u8,
    phase: SchedulerPhase,
    status: SchedulerStatus,
    process_id: Option<u32>,
    retry_count: u8,
    last_error_code: Option<String>,
    updated_at_unix_ms: u128,
}

impl SchedulerSnapshot {
    fn new(
        phase: SchedulerPhase,
        status: SchedulerStatus,
        retry_count: u8,
        error_code: Option<&'static str>,
    ) -> Self {
        Self {
            schema_version: 1,
            phase,
            status,
            process_id: None,
            retry_count,
            last_error_code: error_code.map(str::to_owned),
            updated_at_unix_ms: now_unix_ms(),
        }
    }
}

#[derive(Debug)]
pub struct SchedulerError {
    code: &'static str,
    detail: String,
}

impl SchedulerError {
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

impl fmt::Display for SchedulerError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}: {}", self.code, self.detail)
    }
}

impl std::error::Error for SchedulerError {}

enum MonitorResult {
    Stopping,
    Exited,
}

pub struct SchedulerSupervisor {
    config: SchedulerConfig,
    logger: Arc<RuntimeLogger>,
    child: Mutex<Option<Child>>,
    status: RwLock<SchedulerStatus>,
    stopping: AtomicBool,
    started: AtomicBool,
    initial_status_settled: Mutex<bool>,
    initial_status_changed: Condvar,
    snapshot_path: PathBuf,
}

impl SchedulerSupervisor {
    pub fn new(
        config: SchedulerConfig,
        logger: Arc<RuntimeLogger>,
    ) -> Result<Self, SchedulerError> {
        validate_config(&config)?;
        fs::create_dir_all(&config.runtime_directory).map_err(|error| {
            SchedulerError::new(
                "scheduler_runtime_io_failed",
                format!("create runtime directory: {error}"),
            )
        })?;
        fs::create_dir_all(&config.temporary_directory).map_err(|error| {
            SchedulerError::new(
                "scheduler_runtime_io_failed",
                format!("create temporary directory: {error}"),
            )
        })?;
        fs::create_dir_all(&config.framework_cache_directory).map_err(|error| {
            SchedulerError::new(
                "scheduler_runtime_io_failed",
                format!("create framework cache directory: {error}"),
            )
        })?;

        let supervisor = Self {
            snapshot_path: config.runtime_directory.join("scheduler-state.json"),
            config,
            logger,
            child: Mutex::new(None),
            status: RwLock::new(SchedulerStatus::Stopped),
            stopping: AtomicBool::new(false),
            started: AtomicBool::new(false),
            initial_status_settled: Mutex::new(false),
            initial_status_changed: Condvar::new(),
        };
        supervisor.write_snapshot(&SchedulerSnapshot::new(
            SchedulerPhase::Stopped,
            SchedulerStatus::Stopped,
            0,
            Some("scheduler_stopped"),
        ));
        Ok(supervisor)
    }

    pub fn run(self: Arc<Self>) {
        if self.started.swap(true, Ordering::SeqCst) {
            return;
        }

        let mut retry_count = 0_u8;
        let mut retries_exhausted = false;

        loop {
            if self.stopping.load(Ordering::SeqCst) {
                break;
            }

            self.set_status(SchedulerStatus::Stopped);
            let phase = if retry_count == 0 {
                SchedulerPhase::Starting
            } else {
                SchedulerPhase::Retrying
            };
            self.write_snapshot(&SchedulerSnapshot::new(
                phase,
                SchedulerStatus::Stopped,
                retry_count,
                None,
            ));

            let error_code = match self.launch_and_stabilize(retry_count) {
                Ok(process_id) => {
                    self.set_status(SchedulerStatus::Active);
                    self.settle_initial_status();
                    let mut snapshot = SchedulerSnapshot::new(
                        SchedulerPhase::Active,
                        SchedulerStatus::Active,
                        retry_count,
                        None,
                    );
                    snapshot.process_id = Some(process_id);
                    self.write_snapshot(&snapshot);
                    self.logger.info("Laravel scheduler is active");

                    match self.monitor_child() {
                        MonitorResult::Stopping => break,
                        MonitorResult::Exited => "scheduler_exited",
                    }
                }
                Err(error) if error.code() == "scheduler_stopping" => break,
                Err(error) => error.code(),
            };

            self.set_status(SchedulerStatus::Stopped);
            self.settle_initial_status();
            self.stop_current_child();
            if self.stopping.load(Ordering::SeqCst) {
                break;
            }

            if retry_count >= self.config.retry_limit {
                retries_exhausted = true;
                self.logger.warn("Laravel scheduler retries exhausted");
                self.write_snapshot(&SchedulerSnapshot::new(
                    SchedulerPhase::Failed,
                    SchedulerStatus::Stopped,
                    retry_count,
                    Some("scheduler_retries_exhausted"),
                ));
                break;
            }

            retry_count += 1;
            self.logger.warn(error_code);
            self.write_snapshot(&SchedulerSnapshot::new(
                SchedulerPhase::Retrying,
                SchedulerStatus::Stopped,
                retry_count,
                Some(error_code),
            ));
            let delay = self
                .config
                .retry_delay
                .saturating_mul(u32::from(retry_count));
            if !self.wait_for_retry(delay) {
                break;
            }
        }

        self.set_status(SchedulerStatus::Stopped);
        self.settle_initial_status();
        self.stop_current_child();
        if !retries_exhausted {
            self.write_snapshot(&SchedulerSnapshot::new(
                SchedulerPhase::Stopped,
                SchedulerStatus::Stopped,
                retry_count,
                Some("scheduler_stopped"),
            ));
        }
    }

    pub fn wait_for_initial_status(&self, timeout: Duration) -> SchedulerStatus {
        if let Ok(settled) = self.initial_status_settled.lock() {
            let _ = self
                .initial_status_changed
                .wait_timeout_while(settled, timeout, |settled| !*settled);
        }
        self.status_for_php()
    }

    pub fn status_for_php(&self) -> SchedulerStatus {
        if self
            .status
            .read()
            .map_or(true, |status| *status != SchedulerStatus::Active)
        {
            return SchedulerStatus::Stopped;
        }

        let Ok(mut child) = self.child.lock() else {
            return SchedulerStatus::Stopped;
        };
        let Some(child) = child.as_mut() else {
            return SchedulerStatus::Stopped;
        };
        match child.try_wait() {
            Ok(None) => SchedulerStatus::Active,
            Ok(Some(_)) | Err(_) => SchedulerStatus::Stopped,
        }
    }

    pub fn shutdown(&self) {
        if self.stopping.swap(true, Ordering::SeqCst) {
            return;
        }

        self.set_status(SchedulerStatus::Stopped);
        self.settle_initial_status();
        self.write_snapshot(&SchedulerSnapshot::new(
            SchedulerPhase::Stopping,
            SchedulerStatus::Stopped,
            0,
            None,
        ));
        self.stop_current_child();
        self.write_snapshot(&SchedulerSnapshot::new(
            SchedulerPhase::Stopped,
            SchedulerStatus::Stopped,
            0,
            Some("scheduler_stopped"),
        ));
    }

    fn launch_and_stabilize(&self, retry_count: u8) -> Result<u32, SchedulerError> {
        let mut command = build_scheduler_command(&self.config);
        let mut child = command.spawn().map_err(|error| {
            SchedulerError::new(
                "scheduler_spawn_failed",
                format!("start bundled Laravel scheduler: {error}"),
            )
        })?;
        let process_id = child.id();
        if let Some(stdout) = child.stdout.take() {
            pump_child_output(stdout, "SCHEDULER-OUT", Arc::clone(&self.logger));
        }
        if let Some(stderr) = child.stderr.take() {
            pump_child_output(stderr, "SCHEDULER-ERR", Arc::clone(&self.logger));
        }

        {
            let mut current = self.child.lock().map_err(|_| {
                SchedulerError::new(
                    "scheduler_runtime_io_failed",
                    "scheduler process lock is poisoned",
                )
            })?;
            if self.stopping.load(Ordering::SeqCst) {
                drop(current);
                terminate_child(child, self.config.shutdown_timeout, &self.logger);
                return Err(SchedulerError::new(
                    "scheduler_stopping",
                    "shutdown requested before scheduler startup",
                ));
            }
            *current = Some(child);
        }

        let mut snapshot = SchedulerSnapshot::new(
            SchedulerPhase::Starting,
            SchedulerStatus::Stopped,
            retry_count,
            None,
        );
        snapshot.process_id = Some(process_id);
        self.write_snapshot(&snapshot);

        let deadline = Instant::now() + self.config.startup_stability_timeout;
        while Instant::now() < deadline {
            if self.stopping.load(Ordering::SeqCst) {
                return Err(SchedulerError::new(
                    "scheduler_stopping",
                    "shutdown requested during scheduler startup",
                ));
            }
            if !self.child_is_running()? {
                if let Ok(mut current) = self.child.lock() {
                    current.take();
                }
                return Err(SchedulerError::new(
                    "scheduler_exited",
                    "scheduler exited before its startup stability window",
                ));
            }
            thread::sleep(POLL_INTERVAL.min(deadline.saturating_duration_since(Instant::now())));
        }

        if !self.child_is_running()? {
            if let Ok(mut current) = self.child.lock() {
                current.take();
            }
            return Err(SchedulerError::new(
                "scheduler_exited",
                "scheduler exited at the startup stability boundary",
            ));
        }

        Ok(process_id)
    }

    fn monitor_child(&self) -> MonitorResult {
        loop {
            if self.stopping.load(Ordering::SeqCst) {
                return MonitorResult::Stopping;
            }
            match self.child_is_running() {
                Ok(true) => thread::sleep(POLL_INTERVAL),
                Ok(false) | Err(_) => {
                    if let Ok(mut current) = self.child.lock() {
                        current.take();
                    }
                    return MonitorResult::Exited;
                }
            }
        }
    }

    fn child_is_running(&self) -> Result<bool, SchedulerError> {
        let mut current = self.child.lock().map_err(|_| {
            SchedulerError::new(
                "scheduler_runtime_io_failed",
                "scheduler process lock is poisoned",
            )
        })?;
        let Some(child) = current.as_mut() else {
            return Ok(false);
        };
        child
            .try_wait()
            .map(|status| status.is_none())
            .map_err(|error| {
                SchedulerError::new(
                    "scheduler_runtime_io_failed",
                    format!("inspect scheduler process: {error}"),
                )
            })
    }

    fn stop_current_child(&self) {
        let child = self.child.lock().ok().and_then(|mut child| child.take());
        if let Some(child) = child {
            terminate_child(child, self.config.shutdown_timeout, &self.logger);
        }
    }

    fn wait_for_retry(&self, duration: Duration) -> bool {
        let deadline = Instant::now() + duration;
        while Instant::now() < deadline {
            if self.stopping.load(Ordering::SeqCst) {
                return false;
            }
            thread::sleep(POLL_INTERVAL.min(deadline.saturating_duration_since(Instant::now())));
        }
        !self.stopping.load(Ordering::SeqCst)
    }

    fn set_status(&self, status: SchedulerStatus) {
        if let Ok(mut current) = self.status.write() {
            *current = status;
        }
    }

    fn settle_initial_status(&self) {
        if let Ok(mut settled) = self.initial_status_settled.lock() {
            *settled = true;
            self.initial_status_changed.notify_all();
        }
    }

    fn write_snapshot(&self, snapshot: &SchedulerSnapshot) {
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

impl Drop for SchedulerSupervisor {
    fn drop(&mut self) {
        self.stopping.store(true, Ordering::SeqCst);
        if let Ok(status) = self.status.get_mut() {
            *status = SchedulerStatus::Stopped;
        }
        let child = self.child.get_mut().ok().and_then(Option::take);
        if let Some(child) = child {
            terminate_child(child, self.config.shutdown_timeout, &self.logger);
        }
    }
}

fn validate_config(config: &SchedulerConfig) -> Result<(), SchedulerError> {
    if config.application_version.trim().is_empty()
        || config.startup_stability_timeout.is_zero()
        || config.startup_stability_timeout > Duration::from_secs(10)
        || config.shutdown_timeout.is_zero()
        || config.shutdown_timeout > Duration::from_secs(30)
        || config.retry_limit > 2
        || config.retry_delay > Duration::from_secs(30)
    {
        return Err(SchedulerError::new(
            "scheduler_configuration_invalid",
            "scheduler bounds are invalid",
        ));
    }

    if config.production
        && (config.database_path.is_none()
            || config.storage_path.is_none()
            || config.app_key.as_deref().is_none_or(str::is_empty)
            || config.installation_id.as_deref().is_none_or(str::is_empty))
    {
        return Err(SchedulerError::new(
            "scheduler_configuration_invalid",
            "production scheduler runtime inputs are incomplete",
        ));
    }

    Ok(())
}

fn build_scheduler_command(config: &SchedulerConfig) -> Command {
    let mut command = Command::new(&config.php_binary);
    command
        .current_dir(&config.app_root)
        .arg("artisan")
        .arg("schedule:work")
        .arg("--no-interaction")
        .arg("--quiet")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .env("MEDISMART_DESKTOP_SUPERVISED", "true")
        .env("MEDISMART_QUEUE_WORKER_STATUS", "stopped")
        .env("MEDISMART_SCHEDULER_STATUS", "active")
        .env("MEDISMART_LAN_LISTENER_STATUS", "stopped")
        .env("MEDISMART_VERSION", &config.application_version)
        .env("QUEUE_CONNECTION", "database")
        .env("TELESCOPE_ENABLED", "false")
        .env("INERTIA_DEVTOOLS_ENABLED", "false")
        .env("TMP", &config.temporary_directory)
        .env("TEMP", &config.temporary_directory)
        .env("TMPDIR", &config.temporary_directory)
        .env(
            "APP_SERVICES_CACHE",
            config.framework_cache_directory.join("services.php"),
        )
        .env(
            "APP_PACKAGES_CACHE",
            config.framework_cache_directory.join("packages.php"),
        )
        .env(
            "APP_CONFIG_CACHE",
            config.framework_cache_directory.join("config.php"),
        )
        .env(
            "APP_ROUTES_CACHE",
            config.framework_cache_directory.join("routes.php"),
        )
        .env(
            "APP_EVENTS_CACHE",
            config.framework_cache_directory.join("events.php"),
        );

    if config.production {
        command
            .env("APP_ENV", "production")
            .env("APP_DEBUG", "false")
            .env("LOG_CHANNEL", "single")
            .env("LOG_LEVEL", "warning");
    }
    if let Some(database_path) = &config.database_path {
        command
            .env("DB_CONNECTION", "sqlite")
            .env("DB_DATABASE", database_path);
    }
    if let Some(storage_path) = &config.storage_path {
        command.env("LARAVEL_STORAGE_PATH", storage_path);
    }
    if let Some(app_key) = &config.app_key {
        command.env("APP_KEY", app_key);
    }
    if let Some(installation_id) = &config.installation_id {
        command.env("MEDISMART_DESKTOP_INSTALLATION_ID", installation_id);
    }

    configure_platform_process(&mut command);
    command
}

fn terminate_child(mut child: Child, timeout: Duration, logger: &RuntimeLogger) {
    request_graceful_termination(child.id());
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        match child.try_wait() {
            Ok(Some(_)) => {
                logger.info("Laravel scheduler stopped cleanly");
                return;
            }
            Ok(None) => thread::sleep(Duration::from_millis(50)),
            Err(_) => break,
        }
    }

    logger.warn("Laravel scheduler exceeded its shutdown deadline; forcing termination");
    let _ = child.kill();
    let _ = child.wait();
}

#[cfg(test)]
mod tests {
    use std::{collections::HashMap, ffi::OsString, path::Path};

    use uuid::Uuid;

    use super::*;

    fn test_directory(label: &str) -> PathBuf {
        let path = std::env::temp_dir().join(format!("medismart-{label}-{}", Uuid::new_v4()));
        fs::create_dir_all(&path).unwrap();
        path
    }

    fn test_config(directory: &Path, php_binary: PathBuf) -> SchedulerConfig {
        SchedulerConfig {
            php_binary,
            app_root: directory.to_path_buf(),
            runtime_directory: directory.join("runtime"),
            temporary_directory: directory.join("tmp"),
            framework_cache_directory: directory.join("cache"),
            database_path: Some(directory.join("database.sqlite")),
            storage_path: Some(directory.join("storage")),
            app_key: Some("base64:test-scheduler-application-secret".to_owned()),
            installation_id: Some(Uuid::new_v4().to_string()),
            application_version: "0.1.0-test".to_owned(),
            production: false,
            startup_stability_timeout: Duration::from_millis(100),
            shutdown_timeout: Duration::from_secs(2),
            retry_limit: 2,
            retry_delay: Duration::from_millis(10),
        }
    }

    fn test_logger(directory: &Path) -> Arc<RuntimeLogger> {
        Arc::new(
            RuntimeLogger::open_named(
                directory,
                "scheduler-supervisor.log",
                &["base64:test-scheduler-application-secret".to_owned()],
                &[directory.to_path_buf()],
            )
            .unwrap(),
        )
    }

    #[test]
    fn command_is_exact_and_secrets_and_paths_are_environment_only() {
        let directory = test_directory("scheduler-command");
        let config = test_config(&directory, PathBuf::from("php"));
        let command = build_scheduler_command(&config);
        let arguments = command
            .get_args()
            .map(|argument| argument.to_string_lossy().into_owned())
            .collect::<Vec<_>>();

        assert_eq!(
            arguments,
            ["artisan", "schedule:work", "--no-interaction", "--quiet",]
        );
        assert_eq!(command.get_program(), "php");
        assert_eq!(command.get_current_dir(), Some(directory.as_path()));
        let command_line = arguments.join(" ");
        assert!(!command_line.contains("cmd"));
        assert!(!command_line.contains("base64:test-scheduler-application-secret"));
        assert!(!command_line.contains(config.installation_id.as_deref().unwrap()));
        assert!(!command_line.contains(directory.to_string_lossy().as_ref()));

        let environment = command
            .get_envs()
            .map(|(name, value)| (name.to_os_string(), value.map(OsString::from)))
            .collect::<HashMap<_, _>>();
        let expected_environment = [
            ("MEDISMART_DESKTOP_SUPERVISED", OsString::from("true")),
            ("MEDISMART_QUEUE_WORKER_STATUS", OsString::from("stopped")),
            ("MEDISMART_SCHEDULER_STATUS", OsString::from("active")),
            ("MEDISMART_LAN_LISTENER_STATUS", OsString::from("stopped")),
            (
                "MEDISMART_VERSION",
                OsString::from(config.application_version.as_str()),
            ),
            ("QUEUE_CONNECTION", OsString::from("database")),
            ("TELESCOPE_ENABLED", OsString::from("false")),
            ("INERTIA_DEVTOOLS_ENABLED", OsString::from("false")),
            ("TMP", config.temporary_directory.clone().into_os_string()),
            ("TEMP", config.temporary_directory.clone().into_os_string()),
            (
                "TMPDIR",
                config.temporary_directory.clone().into_os_string(),
            ),
            (
                "APP_SERVICES_CACHE",
                config
                    .framework_cache_directory
                    .join("services.php")
                    .into_os_string(),
            ),
            (
                "APP_PACKAGES_CACHE",
                config
                    .framework_cache_directory
                    .join("packages.php")
                    .into_os_string(),
            ),
            (
                "APP_CONFIG_CACHE",
                config
                    .framework_cache_directory
                    .join("config.php")
                    .into_os_string(),
            ),
            (
                "APP_ROUTES_CACHE",
                config
                    .framework_cache_directory
                    .join("routes.php")
                    .into_os_string(),
            ),
            (
                "APP_EVENTS_CACHE",
                config
                    .framework_cache_directory
                    .join("events.php")
                    .into_os_string(),
            ),
            ("DB_CONNECTION", OsString::from("sqlite")),
            (
                "DB_DATABASE",
                config.database_path.clone().unwrap().into_os_string(),
            ),
            (
                "LARAVEL_STORAGE_PATH",
                config.storage_path.clone().unwrap().into_os_string(),
            ),
            (
                "APP_KEY",
                OsString::from(config.app_key.as_deref().unwrap()),
            ),
            (
                "MEDISMART_DESKTOP_INSTALLATION_ID",
                OsString::from(config.installation_id.as_deref().unwrap()),
            ),
        ]
        .into_iter()
        .map(|(name, value)| (OsString::from(name), Some(value)))
        .collect::<HashMap<_, _>>();
        assert_eq!(environment, expected_environment);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn production_configuration_fails_closed_without_installation_secrets() {
        let directory = test_directory("scheduler-config");
        let mut config = test_config(&directory, PathBuf::from("php"));
        config.production = true;
        config.app_key = None;

        let error = SchedulerSupervisor::new(config, test_logger(&directory))
            .err()
            .unwrap();

        assert_eq!(error.code(), "scheduler_configuration_invalid");
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn production_command_forces_non_debug_single_file_logging() {
        let directory = test_directory("scheduler-production-env");
        let mut config = test_config(&directory, PathBuf::from("php"));
        config.production = true;
        let command = build_scheduler_command(&config);
        let environment = command
            .get_envs()
            .map(|(name, value)| (name.to_os_string(), value.map(OsString::from)))
            .collect::<HashMap<_, _>>();

        for (name, expected) in [
            ("APP_ENV", "production"),
            ("APP_DEBUG", "false"),
            ("LOG_CHANNEL", "single"),
            ("LOG_LEVEL", "warning"),
        ] {
            assert_eq!(
                environment
                    .get(&OsString::from(name))
                    .and_then(Option::as_deref),
                Some(std::ffi::OsStr::new(expected))
            );
        }
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn state_contains_only_stable_nonsecret_fields() {
        let directory = test_directory("scheduler-state");
        let supervisor = SchedulerSupervisor::new(
            test_config(&directory, PathBuf::from("php")),
            test_logger(&directory),
        )
        .unwrap();

        let state = fs::read_to_string(directory.join("runtime/scheduler-state.json")).unwrap();

        assert!(state.contains("scheduler_stopped"));
        assert!(!state.contains("base64:test-scheduler-application-secret"));
        assert!(!state.contains(directory.to_string_lossy().as_ref()));
        assert!(!state.contains("DB_DATABASE"));
        assert!(!state.contains("argv"));
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn restart_attempts_are_bounded_to_three_total_launches() {
        let directory = test_directory("scheduler-retries");
        let attempts = directory.join("attempts");
        fs::write(
            directory.join("artisan"),
            format!("printf x >> '{}'; exit 1\n", attempts.display()),
        )
        .unwrap();
        let supervisor = Arc::new(
            SchedulerSupervisor::new(
                test_config(&directory, PathBuf::from("/bin/sh")),
                test_logger(&directory),
            )
            .unwrap(),
        );

        Arc::clone(&supervisor).run();

        assert_eq!(fs::read(&attempts).unwrap(), b"xxx");
        assert_eq!(supervisor.status_for_php(), SchedulerStatus::Stopped);
        let state = fs::read_to_string(directory.join("runtime/scheduler-state.json")).unwrap();
        assert!(state.contains("scheduler_retries_exhausted"));
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn stable_crash_transitions_to_stopped_then_retries_successfully() {
        let directory = test_directory("scheduler-crash-retry");
        let attempts = directory.join("attempts");
        let stopped = directory.join("stopped");
        fs::write(
            directory.join("artisan"),
            format!(
                "count=0\nif [ -f '{0}' ]; then count=$(head -n 1 '{0}'); fi\ncount=$((count + 1))\nprintf '%s' \"$count\" > '{0}'\nif [ \"$count\" -eq 1 ]; then sleep 1; exit 7; fi\ntrap \"printf stopped > '{1}'; exit 0\" TERM\nwhile :; do sleep 1; done\n",
                attempts.display(),
                stopped.display(),
            ),
        )
        .unwrap();
        let supervisor = Arc::new(
            SchedulerSupervisor::new(
                test_config(&directory, PathBuf::from("/bin/sh")),
                test_logger(&directory),
            )
            .unwrap(),
        );
        let runner = {
            let supervisor = Arc::clone(&supervisor);
            thread::spawn(move || supervisor.run())
        };

        assert_eq!(
            supervisor.wait_for_initial_status(Duration::from_secs(2)),
            SchedulerStatus::Active
        );
        let deadline = Instant::now() + Duration::from_secs(4);
        while Instant::now() < deadline
            && (fs::read_to_string(&attempts).ok().as_deref() != Some("2")
                || supervisor.status_for_php() != SchedulerStatus::Active)
        {
            thread::sleep(Duration::from_millis(20));
        }
        assert_eq!(fs::read_to_string(&attempts).unwrap(), "2");
        assert_eq!(supervisor.status_for_php(), SchedulerStatus::Active);
        let state = fs::read_to_string(directory.join("runtime/scheduler-state.json")).unwrap();
        assert!(state.contains(r#""retry_count": 1"#));

        supervisor.shutdown();
        runner.join().unwrap();
        assert_eq!(fs::read_to_string(&stopped).unwrap(), "stopped");
        assert_eq!(supervisor.status_for_php(), SchedulerStatus::Stopped);
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn shutdown_requests_graceful_termination_after_stability() {
        let directory = test_directory("scheduler-shutdown");
        let stopped = directory.join("stopped");
        fs::write(
            directory.join("artisan"),
            format!(
                "trap \"printf stopped > '{}'; exit 0\" TERM; while :; do sleep 1; done\n",
                stopped.display()
            ),
        )
        .unwrap();
        let mut config = test_config(&directory, PathBuf::from("/bin/sh"));
        config.retry_limit = 0;
        let supervisor =
            Arc::new(SchedulerSupervisor::new(config, test_logger(&directory)).unwrap());
        let runner = {
            let supervisor = Arc::clone(&supervisor);
            thread::spawn(move || supervisor.run())
        };

        thread::sleep(Duration::from_millis(20));
        assert_eq!(supervisor.status_for_php(), SchedulerStatus::Stopped);
        assert_eq!(
            supervisor.wait_for_initial_status(Duration::from_secs(2)),
            SchedulerStatus::Active
        );
        supervisor.shutdown();
        runner.join().unwrap();

        assert_eq!(fs::read_to_string(&stopped).unwrap(), "stopped");
        assert_eq!(supervisor.status_for_php(), SchedulerStatus::Stopped);
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }
}
