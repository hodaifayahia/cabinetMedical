use std::{
    env,
    fs::{self, File, OpenOptions},
    io::{self, Read, Write},
    path::{Path, PathBuf},
    sync::Arc,
    time::Duration,
};

use medismart_runtime::{
    generate_runtime_secret, load_lan_listener_settings, load_or_create_installation_identity,
    load_tunnel_settings, read_protected_secret, run_startup_migration_gate,
    verify_cloudflared_executable, verify_packaged_migration_resources, LanListenerSettings,
    LanUploadSupervisor, OfflineRestorePhpConfig, PackagedMigrationResourceConfig,
    PhpOfflineRestoreCommandLauncher, QueueWorkerConfig, QueueWorkerSupervisor, RuntimeLogger,
    SchedulerConfig, SchedulerSupervisor, StartupMigrationGateConfig, Supervisor, SupervisorConfig,
    TunnelConfiguration, TunnelSupervisor, VerifiedLanUploadBoundary, VerifiedRemoteUploadBoundary,
};
use tauri::Manager;

pub struct DesktopInitError {
    code: &'static str,
}

impl DesktopInitError {
    pub fn code(&self) -> &'static str {
        self.code
    }

    fn new(code: &'static str) -> Self {
        Self { code }
    }
}

struct RuntimePaths {
    app_data: PathBuf,
    logs: PathBuf,
    runtime: PathBuf,
    temporary: PathBuf,
    framework_cache: PathBuf,
    configuration: PathBuf,
    storage: PathBuf,
    database: PathBuf,
    app_root: PathBuf,
    php_binary: PathBuf,
    cloudflared_binary: PathBuf,
    cloudflared_manifest: PathBuf,
    resource_root: Option<PathBuf>,
}

pub struct PreparedDesktopRuntime {
    pub supervisor: Supervisor,
    pub queue_worker: Arc<QueueWorkerSupervisor>,
    pub scheduler: Arc<SchedulerSupervisor>,
    pub lan_upload: Arc<LanUploadSupervisor>,
    pub tunnel: TunnelSupervisor,
    pub offline_restore: PreparedOfflineRestoreBridge,
    pub updater_app_key: Option<String>,
    pub updater_installation_id: Option<String>,
}

pub struct PreparedOfflineRestoreBridge {
    pub launcher: Arc<PhpOfflineRestoreCommandLauncher>,
    pub work_root: PathBuf,
    pub journal_root: PathBuf,
}

pub(crate) fn prepare_tunnel_supervisor(
    tunnel: &Arc<TunnelSupervisor>,
    listener_origin: &str,
    verified_boundary: Option<VerifiedRemoteUploadBoundary>,
) {
    match verified_boundary {
        Some(capability) => tunnel.start_for_origin(listener_origin, capability),
        None => tunnel.deny_unverified_origin(),
    }
}

pub(crate) fn prepare_lan_upload_supervisor(
    lan_upload: &Arc<LanUploadSupervisor>,
    listener_origin: &str,
    verified_boundary: Option<VerifiedLanUploadBoundary>,
) {
    match verified_boundary {
        Some(capability) => lan_upload.activate_for_backend(listener_origin, capability),
        None => lan_upload.deny_unverified_origin(),
    }
}

pub fn prepare_runtime(app: &tauri::AppHandle) -> Result<PreparedDesktopRuntime, DesktopInitError> {
    let paths = discover_paths(app)?;
    create_runtime_directories(&paths)?;
    let production = !cfg!(debug_assertions);
    let application_version = env!("CARGO_PKG_VERSION").to_owned();

    let health_key = generate_runtime_secret();
    let (app_key, installation_id) = if cfg!(debug_assertions) {
        (None, None)
    } else {
        let identity = load_or_create_installation_identity(&paths.configuration)
            .map_err(|_| DesktopInitError::new("configuration_invalid"))?;
        (
            Some(identity.app_key),
            Some(identity.installation_id.to_string()),
        )
    };

    let mut known_secrets = vec![health_key.clone()];
    if let Some(app_key) = &app_key {
        known_secrets.push(app_key.clone());
    }
    let logger = Arc::new(
        RuntimeLogger::open(
            &paths.logs,
            &known_secrets,
            &[paths.app_data.clone(), paths.app_root.clone()],
        )
        .map_err(|_| DesktopInitError::new("runtime_io_failed"))?,
    );

    let migration_resources = if production {
        let resource_root = paths
            .resource_root
            .clone()
            .ok_or_else(|| DesktopInitError::new("migration_resources_invalid"))?;
        Some(
            verify_packaged_migration_resources(&PackagedMigrationResourceConfig {
                resource_root,
                application_version: application_version.clone(),
                expected_laravel_manifest_sha256: embedded_release_digest(option_env!(
                    "MEDISMART_BUILD_LARAVEL_MANIFEST_SHA256"
                ))?,
                expected_php_manifest_sha256: embedded_release_digest(option_env!(
                    "MEDISMART_BUILD_PHP_MANIFEST_SHA256"
                ))?,
                expected_database_manifest_sha256: embedded_release_digest(option_env!(
                    "MEDISMART_BUILD_DATABASE_MANIFEST_SHA256"
                ))?,
                expected_migration_contract_sha256: embedded_release_digest(option_env!(
                    "MEDISMART_BUILD_MIGRATION_CONTRACT_SHA256"
                ))?,
            })
            .map_err(|error| {
                logger.error(error.code());
                DesktopInitError::new(error.code())
            })?,
        )
    } else {
        None
    };

    if let Err(error) = validate_and_prepare_application(&paths) {
        logger.error(error.code());
        return Err(error);
    }

    logger.info("Desktop runtime directories and packaged resources validated");

    if let Some(resources) = migration_resources.as_ref() {
        let migration_config = StartupMigrationGateConfig {
            app_data_root: paths.app_data.clone(),
            database_path: paths.database.clone(),
            storage_path: paths.storage.clone(),
            temporary_directory: paths.temporary.clone(),
            framework_cache_directory: paths.framework_cache.clone(),
            recovery_root: paths.storage.join("app/private/migration-recovery"),
            app_key: app_key
                .clone()
                .ok_or_else(|| DesktopInitError::new("configuration_invalid"))?,
            installation_id: installation_id
                .clone()
                .ok_or_else(|| DesktopInitError::new("configuration_invalid"))?,
            application_version: application_version.clone(),
            command_timeout: Duration::from_secs(10 * 60),
        };
        run_startup_migration_gate(resources, &migration_config, Arc::clone(&logger)).map_err(
            |error| {
                logger.error(error.code());
                DesktopInitError::new(error.code())
            },
        )?;
    }
    let tunnel = load_tunnel_supervisor(
        &paths,
        &health_key,
        app_key.as_deref(),
        &application_version,
        Arc::clone(&logger),
    )?;
    let tunnel = match installation_id.as_deref() {
        Some(installation_id) => tunnel
            .with_authenticated_native_status(&health_key, installation_id, &application_version)
            .map_err(|error| {
                logger.error(error.code());
                DesktopInitError::new(error.code())
            })?,
        None => tunnel,
    };
    let tunnel_upload_hostname = tunnel.required_attestation_hostname().map(str::to_owned);
    let lan_upload = Arc::new(load_lan_upload_supervisor(&paths, Arc::clone(&logger))?);
    let queue_worker = prepare_queue_worker(
        &paths,
        &health_key,
        app_key.as_deref(),
        installation_id.as_deref(),
        &application_version,
        production,
    )?;
    let scheduler = prepare_scheduler(
        &paths,
        &health_key,
        app_key.as_deref(),
        installation_id.as_deref(),
        &application_version,
        production,
    )?;
    let offline_restore = prepare_offline_restore_bridge(
        &paths,
        app_key.as_deref(),
        installation_id.as_deref(),
        &application_version,
        production,
    )?;
    let config = SupervisorConfig {
        php_binary: paths.php_binary,
        public_directory: paths.app_root.join("public"),
        router_script: paths
            .app_root
            .join("vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"),
        app_root: paths.app_root,
        runtime_directory: paths.runtime,
        temporary_directory: paths.temporary,
        framework_cache_directory: paths.framework_cache,
        database_path: production.then_some(paths.database),
        storage_path: production.then_some(paths.storage),
        app_key: app_key.clone(),
        installation_id: installation_id.clone(),
        application_version: application_version.clone(),
        signed_updater_configured: !cfg!(debug_assertions)
            && option_env!("MEDISMART_UPDATER_PUBLIC_KEY").is_some()
            && option_env!("MEDISMART_UPDATER_ENDPOINT").is_some(),
        production,
        health_key,
        tunnel_upload_hostname,
        health_timeout: Duration::from_secs(45),
        shutdown_timeout: Duration::from_secs(5),
        retry_limit: 2,
        retry_delay: Duration::from_secs(1),
    };

    let supervisor = Supervisor::new(
        config,
        Arc::clone(&queue_worker),
        Arc::clone(&scheduler),
        Arc::clone(&lan_upload),
        logger,
    )
    .map_err(|_| DesktopInitError::new("runtime_io_failed"))?;

    Ok(PreparedDesktopRuntime {
        supervisor,
        queue_worker,
        scheduler,
        lan_upload,
        tunnel,
        offline_restore,
        updater_app_key: app_key,
        updater_installation_id: installation_id,
    })
}

fn embedded_release_digest(value: Option<&'static str>) -> Result<String, DesktopInitError> {
    value
        .filter(|digest| {
            digest.len() == 64
                && digest
                    .bytes()
                    .all(|byte| byte.is_ascii_digit() || matches!(byte, b'a'..=b'f'))
        })
        .map(str::to_owned)
        .ok_or_else(|| DesktopInitError::new("migration_runtime_mismatch"))
}

fn prepare_offline_restore_bridge(
    paths: &RuntimePaths,
    app_key: Option<&str>,
    installation_id: Option<&str>,
    application_version: &str,
    production: bool,
) -> Result<PreparedOfflineRestoreBridge, DesktopInitError> {
    let php_binary = resolve_restore_php_binary(&paths.php_binary)?;
    let mut config = OfflineRestorePhpConfig::new(
        php_binary,
        paths.app_root.join("artisan"),
        paths.app_root.clone(),
    );
    let mut environment = vec![
        ("MEDISMART_DESKTOP_SUPERVISED".into(), "true".into()),
        ("MEDISMART_QUEUE_WORKER_STATUS".into(), "stopped".into()),
        ("MEDISMART_SCHEDULER_STATUS".into(), "stopped".into()),
        ("MEDISMART_LAN_LISTENER_STATUS".into(), "stopped".into()),
        ("MEDISMART_VERSION".into(), application_version.into()),
        ("QUEUE_CONNECTION".into(), "database".into()),
        ("TELESCOPE_ENABLED".into(), "false".into()),
        ("INERTIA_DEVTOOLS_ENABLED".into(), "false".into()),
        ("TMP".into(), paths.temporary.as_os_str().to_owned()),
        ("TEMP".into(), paths.temporary.as_os_str().to_owned()),
        ("TMPDIR".into(), paths.temporary.as_os_str().to_owned()),
        (
            "APP_SERVICES_CACHE".into(),
            paths.framework_cache.join("services.php").into_os_string(),
        ),
        (
            "APP_PACKAGES_CACHE".into(),
            paths.framework_cache.join("packages.php").into_os_string(),
        ),
        (
            "APP_CONFIG_CACHE".into(),
            paths.framework_cache.join("config.php").into_os_string(),
        ),
        (
            "APP_ROUTES_CACHE".into(),
            paths.framework_cache.join("routes.php").into_os_string(),
        ),
        (
            "APP_EVENTS_CACHE".into(),
            paths.framework_cache.join("events.php").into_os_string(),
        ),
    ];

    if production {
        environment.extend([
            ("APP_ENV".into(), "production".into()),
            ("APP_DEBUG".into(), "false".into()),
            ("LOG_CHANNEL".into(), "single".into()),
            ("LOG_LEVEL".into(), "warning".into()),
            ("DB_CONNECTION".into(), "sqlite".into()),
            ("DB_DATABASE".into(), paths.database.as_os_str().to_owned()),
            (
                "LARAVEL_STORAGE_PATH".into(),
                paths.storage.as_os_str().to_owned(),
            ),
        ]);
    }
    if let Some(app_key) = app_key {
        environment.push(("APP_KEY".into(), app_key.into()));
    }
    if let Some(installation_id) = installation_id {
        environment.push((
            "MEDISMART_DESKTOP_INSTALLATION_ID".into(),
            installation_id.into(),
        ));
    }
    config.environment = environment;

    let storage_root = if production {
        paths.storage.clone()
    } else {
        paths.app_root.join("storage")
    };

    Ok(PreparedOfflineRestoreBridge {
        launcher: Arc::new(PhpOfflineRestoreCommandLauncher::new(config)),
        work_root: storage_root.join("app/private/restore-work"),
        journal_root: storage_root.join("app/private/restore-journals"),
    })
}

fn resolve_restore_php_binary(configured: &Path) -> Result<PathBuf, DesktopInitError> {
    if configured.components().count() > 1 || configured.is_absolute() {
        return configured
            .canonicalize()
            .map_err(|_| DesktopInitError::new("missing_php_runtime"));
    }

    let Some(path) = env::var_os("PATH") else {
        return Err(DesktopInitError::new("missing_php_runtime"));
    };
    env::split_paths(&path)
        .map(|directory| directory.join(configured))
        .find(|candidate| candidate.is_file())
        .and_then(|candidate| candidate.canonicalize().ok())
        .ok_or_else(|| DesktopInitError::new("missing_php_runtime"))
}

fn prepare_queue_worker(
    paths: &RuntimePaths,
    health_key: &str,
    app_key: Option<&str>,
    installation_id: Option<&str>,
    application_version: &str,
    production: bool,
) -> Result<Arc<QueueWorkerSupervisor>, DesktopInitError> {
    let mut secret_refs = vec![health_key];
    if let Some(app_key) = app_key {
        secret_refs.push(app_key);
    }
    let logger = Arc::new(
        RuntimeLogger::open_named_with_secret_refs(
            &paths.logs,
            "queue-worker-supervisor.log",
            &secret_refs,
            &[paths.app_data.clone(), paths.app_root.clone()],
        )
        .map_err(|_| DesktopInitError::new("queue_worker_runtime_io_failed"))?,
    );
    let config = QueueWorkerConfig {
        php_binary: paths.php_binary.clone(),
        app_root: paths.app_root.clone(),
        runtime_directory: paths.runtime.clone(),
        temporary_directory: paths.temporary.clone(),
        framework_cache_directory: paths.framework_cache.clone(),
        database_path: production.then(|| paths.database.clone()),
        storage_path: production.then(|| paths.storage.clone()),
        app_key: app_key.map(str::to_owned),
        installation_id: installation_id.map(str::to_owned),
        application_version: application_version.to_owned(),
        production,
        startup_stability_timeout: Duration::from_millis(750),
        shutdown_timeout: Duration::from_secs(5),
        retry_limit: 2,
        retry_delay: Duration::from_secs(1),
    };

    QueueWorkerSupervisor::new(config, logger)
        .map(Arc::new)
        .map_err(|error| DesktopInitError::new(error.code()))
}

fn prepare_scheduler(
    paths: &RuntimePaths,
    health_key: &str,
    app_key: Option<&str>,
    installation_id: Option<&str>,
    application_version: &str,
    production: bool,
) -> Result<Arc<SchedulerSupervisor>, DesktopInitError> {
    let mut secret_refs = vec![health_key];
    if let Some(app_key) = app_key {
        secret_refs.push(app_key);
    }
    let logger = Arc::new(
        RuntimeLogger::open_named_with_secret_refs(
            &paths.logs,
            "scheduler-supervisor.log",
            &secret_refs,
            &[paths.app_data.clone(), paths.app_root.clone()],
        )
        .map_err(|_| DesktopInitError::new("scheduler_runtime_io_failed"))?,
    );
    let config = SchedulerConfig {
        php_binary: paths.php_binary.clone(),
        app_root: paths.app_root.clone(),
        runtime_directory: paths.runtime.clone(),
        temporary_directory: paths.temporary.clone(),
        framework_cache_directory: paths.framework_cache.clone(),
        database_path: production.then(|| paths.database.clone()),
        storage_path: production.then(|| paths.storage.clone()),
        app_key: app_key.map(str::to_owned),
        installation_id: installation_id.map(str::to_owned),
        application_version: application_version.to_owned(),
        production,
        startup_stability_timeout: Duration::from_millis(750),
        shutdown_timeout: Duration::from_secs(5),
        retry_limit: 2,
        retry_delay: Duration::from_secs(1),
    };

    SchedulerSupervisor::new(config, logger)
        .map(Arc::new)
        .map_err(|error| DesktopInitError::new(error.code()))
}

fn load_tunnel_supervisor(
    paths: &RuntimePaths,
    health_key: &str,
    app_key: Option<&str>,
    application_version: &str,
    fallback_logger: Arc<RuntimeLogger>,
) -> Result<TunnelSupervisor, DesktopInitError> {
    let settings_path = paths.configuration.join("cloudflare-tunnel.json");
    match fs::symlink_metadata(&settings_path) {
        Err(error) if error.kind() == io::ErrorKind::NotFound => {
            return TunnelSupervisor::disabled(&paths.runtime, fallback_logger)
                .map_err(|_| DesktopInitError::new("runtime_io_failed"));
        }
        Err(_) => {
            return TunnelSupervisor::unavailable(
                &paths.runtime,
                fallback_logger,
                None,
                "tunnel_configuration_invalid",
            )
            .map_err(|_| DesktopInitError::new("runtime_io_failed"));
        }
        Ok(_) => {}
    }

    let settings = match load_tunnel_settings(&settings_path) {
        Ok(settings) => settings,
        Err(error) => {
            fallback_logger.warn(error.code());
            return TunnelSupervisor::unavailable(
                &paths.runtime,
                fallback_logger,
                None,
                error.code(),
            )
            .map_err(|_| DesktopInitError::new("runtime_io_failed"));
        }
    };
    if !settings.enabled() {
        return TunnelSupervisor::disabled(&paths.runtime, fallback_logger)
            .map_err(|_| DesktopInitError::new("runtime_io_failed"));
    }
    let hostname = settings.upload_hostname().map(str::to_owned);

    let executable =
        match verify_cloudflared_executable(&paths.cloudflared_binary, &paths.cloudflared_manifest)
        {
            Ok(executable) => executable,
            Err(error) => {
                fallback_logger.warn(error.code());
                return TunnelSupervisor::unavailable(
                    &paths.runtime,
                    fallback_logger,
                    hostname,
                    error.code(),
                )
                .map_err(|_| DesktopInitError::new("runtime_io_failed"));
            }
        };
    let token = match read_protected_secret(&paths.configuration.join("cloudflared.token")) {
        Ok(token) => token,
        Err(_) => {
            return TunnelSupervisor::unavailable(
                &paths.runtime,
                fallback_logger,
                hostname,
                "tunnel_credentials_unavailable",
            )
            .map_err(|_| DesktopInitError::new("runtime_io_failed"));
        }
    };

    let mut secret_refs = vec![health_key, token.expose()];
    if let Some(app_key) = app_key {
        secret_refs.push(app_key);
    }
    let tunnel_logger = match RuntimeLogger::open_named_with_secret_refs(
        &paths.logs,
        "cloudflared-supervisor.log",
        &secret_refs,
        &[paths.app_data.clone(), paths.app_root.clone()],
    ) {
        Ok(logger) => Arc::new(logger),
        Err(_) => {
            return TunnelSupervisor::unavailable(
                &paths.runtime,
                fallback_logger,
                hostname,
                "tunnel_runtime_io_failed",
            )
            .map_err(|_| DesktopInitError::new("runtime_io_failed"));
        }
    };
    let configuration = match TunnelConfiguration::new(
        settings,
        executable,
        token,
        application_version.to_owned(),
        Duration::from_secs(45),
        Duration::from_secs(5),
        2,
        Duration::from_secs(1),
    ) {
        Ok(configuration) => configuration,
        Err(error) => {
            tunnel_logger.warn(error.code());
            return TunnelSupervisor::unavailable(
                &paths.runtime,
                tunnel_logger,
                hostname,
                error.code(),
            )
            .map_err(|_| DesktopInitError::new("runtime_io_failed"));
        }
    };

    TunnelSupervisor::new(configuration, &paths.runtime, tunnel_logger)
        .map_err(|_| DesktopInitError::new("runtime_io_failed"))
}

fn load_lan_upload_supervisor(
    paths: &RuntimePaths,
    fallback_logger: Arc<RuntimeLogger>,
) -> Result<LanUploadSupervisor, DesktopInitError> {
    let settings_path = paths.configuration.join("lan-listener.json");
    let settings = match fs::symlink_metadata(&settings_path) {
        Err(error) if error.kind() == io::ErrorKind::NotFound => {
            LanListenerSettings::disabled_defaults()
        }
        Err(_) => {
            return LanUploadSupervisor::recoverable_unavailable(
                &settings_path,
                &paths.runtime,
                fallback_logger,
                "lan_configuration_invalid",
            )
            .map_err(|_| DesktopInitError::new("runtime_io_failed"));
        }
        Ok(_) => match load_lan_listener_settings(&settings_path) {
            Ok(settings) => settings,
            Err(error) => {
                fallback_logger.warn(error.code());
                return LanUploadSupervisor::recoverable_unavailable(
                    &settings_path,
                    &paths.runtime,
                    fallback_logger,
                    error.code(),
                )
                .map_err(|_| DesktopInitError::new("runtime_io_failed"));
            }
        },
    };

    LanUploadSupervisor::managed(settings, &settings_path, &paths.runtime, fallback_logger)
        .map_err(|_| DesktopInitError::new("runtime_io_failed"))
}

fn discover_paths(app: &tauri::AppHandle) -> Result<RuntimePaths, DesktopInitError> {
    let app_data = app
        .path()
        .app_local_data_dir()
        .map_err(|_| DesktopInitError::new("runtime_io_failed"))?;
    let logs = app_data.join("logs");
    let runtime = app_data.join("runtime");
    let temporary = app_data.join("tmp");
    let framework_cache = app_data.join("cache");
    let configuration = app_data.join("config");
    let storage = app_data.join("storage");
    let database = app_data.join("data/database.sqlite");

    if cfg!(debug_assertions) {
        let default_root = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
            .parent()
            .ok_or_else(|| DesktopInitError::new("configuration_invalid"))?
            .to_path_buf();
        let app_root = env::var_os("MEDISMART_DESKTOP_APP_ROOT")
            .map(PathBuf::from)
            .unwrap_or(default_root);
        let php_binary = env::var_os("MEDISMART_PHP_BINARY")
            .map(PathBuf::from)
            .unwrap_or_else(|| PathBuf::from("php"));
        let cloudflared_root =
            PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("resources/cloudflared");

        return Ok(RuntimePaths {
            app_data,
            logs,
            runtime,
            temporary,
            framework_cache,
            configuration,
            storage,
            database,
            app_root,
            php_binary,
            cloudflared_binary: cloudflared_root.join(cloudflared_executable_name()),
            cloudflared_manifest: cloudflared_root.join("cloudflared.manifest.json"),
            resource_root: None,
        });
    }

    let resource_root = app
        .path()
        .resource_dir()
        .map_err(|_| DesktopInitError::new("missing_laravel_resources"))?;

    Ok(RuntimePaths {
        app_data,
        logs,
        runtime,
        temporary,
        framework_cache,
        configuration,
        storage,
        database,
        app_root: resource_root.join("laravel"),
        php_binary: resource_root.join("php/php.exe"),
        cloudflared_binary: resource_root.join("cloudflared/cloudflared.exe"),
        cloudflared_manifest: resource_root.join("cloudflared/cloudflared.manifest.json"),
        resource_root: Some(resource_root),
    })
}

#[cfg(windows)]
fn cloudflared_executable_name() -> &'static str {
    "cloudflared.exe"
}

#[cfg(not(windows))]
fn cloudflared_executable_name() -> &'static str {
    "cloudflared"
}

fn create_runtime_directories(paths: &RuntimePaths) -> Result<(), DesktopInitError> {
    let directories = [
        &paths.app_data,
        &paths.logs,
        &paths.runtime,
        &paths.temporary,
        &paths.framework_cache,
        &paths.configuration,
        &paths.storage,
        &paths.storage.join("app/private"),
        &paths.storage.join("framework/cache/data"),
        &paths.storage.join("framework/sessions"),
        &paths.storage.join("framework/views"),
        &paths.storage.join("logs"),
        paths
            .database
            .parent()
            .ok_or_else(|| DesktopInitError::new("runtime_io_failed"))?,
    ];

    for directory in directories {
        fs::create_dir_all(directory).map_err(|_| DesktopInitError::new("runtime_io_failed"))?;
    }

    Ok(())
}

fn validate_and_prepare_application(paths: &RuntimePaths) -> Result<(), DesktopInitError> {
    let app_root = paths
        .app_root
        .canonicalize()
        .map_err(|_| DesktopInitError::new("missing_laravel_resources"))?;
    for required in [
        "artisan",
        "config/queue.php",
        "database/migrations/0001_01_01_000002_create_jobs_table.php",
        "public/index.php",
        "vendor/autoload.php",
        "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php",
        "vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php",
        "vendor/laravel/framework/src/Illuminate/Queue/Worker.php",
        "app/Console/Commands/NativeMigrationGate.php",
        "app/Console/Commands/NativeApplyOfflineRestore.php",
        "app/Backups/OfflineRestoreExecutor.php",
        "app/Backups/PreparedRestore.php",
        "app/Backups/SupervisorOfflineRestoreGuard.php",
    ] {
        let candidate = app_root.join(required);
        let canonical = candidate
            .canonicalize()
            .map_err(|_| DesktopInitError::new("missing_laravel_resources"))?;
        if !canonical.starts_with(&app_root) || !canonical.is_file() {
            return Err(DesktopInitError::new("missing_laravel_resources"));
        }
    }

    if cfg!(debug_assertions) {
        return Ok(());
    }

    let resource_root = paths
        .resource_root
        .as_ref()
        .and_then(|root| root.canonicalize().ok())
        .ok_or_else(|| DesktopInitError::new("missing_laravel_resources"))?;
    if !app_root.starts_with(&resource_root) {
        return Err(DesktopInitError::new("missing_laravel_resources"));
    }

    let php_binary = paths
        .php_binary
        .canonicalize()
        .map_err(|_| DesktopInitError::new("missing_php_runtime"))?;
    if !php_binary.starts_with(&resource_root) || !php_binary.is_file() {
        return Err(DesktopInitError::new("missing_php_runtime"));
    }

    let initial_root = resource_root.join("initial");
    if !paths.database.exists() {
        copy_initial_database(&initial_root.join("database.sqlite"), &paths.database)?;
    }
    if fs::symlink_metadata(&paths.database)
        .map(|metadata| metadata.file_type().is_symlink())
        .unwrap_or(true)
    {
        return Err(DesktopInitError::new("configuration_invalid"));
    }
    validate_sqlite_header(&paths.database)
        .map_err(|_| DesktopInitError::new("configuration_invalid"))?;

    let initial_storage = initial_root.join("storage");
    if initial_storage.is_dir() {
        copy_initial_tree(&initial_storage, &paths.storage)?;
    }

    Ok(())
}

fn copy_initial_database(source: &Path, destination: &Path) -> Result<(), DesktopInitError> {
    if fs::symlink_metadata(source)
        .map(|metadata| metadata.file_type().is_symlink())
        .unwrap_or(true)
    {
        return Err(DesktopInitError::new("missing_database_seed"));
    }
    validate_sqlite_header(source).map_err(|_| DesktopInitError::new("missing_database_seed"))?;

    let mut source_file =
        File::open(source).map_err(|_| DesktopInitError::new("missing_database_seed"))?;
    let mut destination_file = OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(destination)
        .map_err(|_| DesktopInitError::new("runtime_io_failed"))?;

    let result = io::copy(&mut source_file, &mut destination_file)
        .and_then(|_| destination_file.flush())
        .and_then(|_| destination_file.sync_all());
    if result.is_err() {
        drop(destination_file);
        let _ = fs::remove_file(destination);
        return Err(DesktopInitError::new("runtime_io_failed"));
    }

    Ok(())
}

fn validate_sqlite_header(path: &Path) -> io::Result<()> {
    let mut file = File::open(path)?;
    let mut header = [0_u8; 16];
    file.read_exact(&mut header)?;
    if &header != b"SQLite format 3\0" {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "invalid SQLite header",
        ));
    }
    Ok(())
}

fn copy_initial_tree(source: &Path, destination: &Path) -> Result<(), DesktopInitError> {
    for entry in fs::read_dir(source).map_err(|_| DesktopInitError::new("runtime_io_failed"))? {
        let entry = entry.map_err(|_| DesktopInitError::new("runtime_io_failed"))?;
        let file_type = entry
            .file_type()
            .map_err(|_| DesktopInitError::new("runtime_io_failed"))?;
        if file_type.is_symlink() {
            return Err(DesktopInitError::new("configuration_invalid"));
        }

        let target = destination.join(entry.file_name());
        if file_type.is_dir() {
            fs::create_dir_all(&target).map_err(|_| DesktopInitError::new("runtime_io_failed"))?;
            copy_initial_tree(&entry.path(), &target)?;
        } else if file_type.is_file() && !target.exists() {
            fs::copy(entry.path(), target)
                .map_err(|_| DesktopInitError::new("runtime_io_failed"))?;
        }
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use std::io::Write;

    use super::*;

    #[test]
    fn sqlite_seed_validation_requires_the_real_header() {
        let directory = std::env::temp_dir().join(format!(
            "medismart-sqlite-header-{}",
            medismart_runtime::generate_runtime_secret()
        ));
        fs::create_dir_all(&directory).unwrap();
        let valid = directory.join("valid.sqlite");
        let invalid = directory.join("invalid.sqlite");

        File::create(&valid)
            .unwrap()
            .write_all(b"SQLite format 3\0remaining")
            .unwrap();
        fs::write(&invalid, b"not a database").unwrap();

        assert!(validate_sqlite_header(&valid).is_ok());
        assert!(validate_sqlite_header(&invalid).is_err());
        fs::remove_dir_all(directory).unwrap();
    }
}
