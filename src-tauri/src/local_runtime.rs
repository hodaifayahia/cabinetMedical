//! Local-first runtime: the bundled Laravel application supervised by this
//! process.
//!
//! In [`RuntimeMode::Local`](crate::runtime_mode::RuntimeMode::Local) the
//! desktop shell owns the clinic's data outright. It starts the packaged PHP
//! runtime against a SQLite database held under the per-install
//! application-data directory and points the webview at the resulting loopback
//! origin. No network is involved, so every clinical workflow keeps working
//! with the machine offline.
//!
//! Two invariants from ADR-001 are preserved here:
//!
//! - Packaged resources (`php/`, `laravel/`, `initial/`) stay **read-only**.
//!   Everything mutable — database, Laravel storage, caches, temp files, logs —
//!   is created under the writable application-data root, never inside
//!   `Program Files`.
//! - The `APP_KEY` and installation identity are generated **per install**.
//!   A product-wide shared key is prohibited, so the encrypted values in one
//!   clinic's database are useless to another.

use std::{
    fs,
    path::{Path, PathBuf},
    sync::{
        mpsc::{self, RecvTimeoutError},
        Arc,
    },
    thread,
    time::Duration,
};

use std::sync::Mutex;

use drclick_runtime::{
    allocate_loopback_port, generate_runtime_secret, load_or_create_installation_identity,
    LanUploadSupervisor, QueueWorkerConfig, QueueWorkerSupervisor, RuntimeLogger, SchedulerConfig,
    SchedulerSupervisor, Supervisor, SupervisorConfig, SupervisorEvent,
};
use tauri::{AppHandle, Manager};
use url::Url;

/// How long the shell waits for Laravel to answer its first health probe before
/// giving up. Generous: a cold first run also applies pending migrations.
const READY_TIMEOUT: Duration = Duration::from_secs(180);

/// How long the queue worker and the scheduler must stay up after starting
/// before they count as started. Must stay within the runtime's bounds,
/// otherwise local mode refuses to start (see the test below).
const BACKGROUND_STARTUP_STABILITY: Duration = Duration::from_secs(20);

/// Laravel's built-in-server router, shipped inside the framework itself.
/// The storage tree Laravel requires to exist before it will boot. The
/// packaged application is read-only, so the launcher creates these under the
/// writable application-data root instead.
const LARAVEL_STORAGE_SUBDIRECTORIES: &[&str] = &[
    "app/public",
    "app/private",
    "framework/cache/data",
    "framework/sessions",
    "framework/views",
    "logs",
];

/// Google OAuth "Desktop app" client for the per-cabinet Drive backup, baked
/// in at build time (validated by `build.rs`). A build without it still runs;
/// Drive backup is then reported as unavailable instead of offered.
const GOOGLE_OAUTH_CLIENT_ID: Option<&str> = option_env!("DRCLICK_GOOGLE_CLIENT_ID");

/// Optional: Google treats an installed application's secret as
/// non-confidential, and the Laravel side sends it only when present.
const GOOGLE_OAUTH_CLIENT_SECRET: Option<&str> = option_env!("DRCLICK_GOOGLE_CLIENT_SECRET");

/// The only scope the application accepts (`GoogleDriveBackup::DRIVE_SCOPE`).
const GOOGLE_DRIVE_SCOPE: &str = "https://www.googleapis.com/auth/drive.file";

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

/// A failure that prevented the local runtime from serving the application.
///
/// `code` is a stable, non-localised identifier for logs and diagnostics;
/// `message` is the French text shown to the clinic.
#[derive(Debug, Clone)]
pub(crate) struct LocalRuntimeError {
    pub(crate) code: &'static str,
    pub(crate) message: String,
}

impl LocalRuntimeError {
    fn new(code: &'static str, message: impl Into<String>) -> Self {
        Self {
            code,
            message: message.into(),
        }
    }

    /// The stored runtime mode could not be read, so the shell refused to guess
    /// which machine owns this installation's data.
    pub(crate) fn damaged_configuration() -> Self {
        Self::new(
            "local_runtime_configuration_damaged",
            crate::DAMAGED_CONFIGURATION_MESSAGE,
        )
    }
}

impl std::fmt::Display for LocalRuntimeError {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(formatter, "[{}] {}", self.code, self.message)
    }
}

// ---------------------------------------------------------------------------
// Writable layout
// ---------------------------------------------------------------------------

/// Every mutable location owned by one installation. All of these live under
/// the writable application-data root; none of them are inside the install
/// directory.
#[derive(Clone, Debug)]
pub(crate) struct LocalPaths {
    pub(crate) configuration: PathBuf,
    pub(crate) database: PathBuf,
    pub(crate) storage: PathBuf,
    pub(crate) runtime: PathBuf,
    pub(crate) temporary: PathBuf,
    pub(crate) framework_cache: PathBuf,
    pub(crate) logs: PathBuf,
}

impl LocalPaths {
    pub(crate) fn rooted_at(app_data_root: &Path) -> Self {
        Self {
            configuration: app_data_root.join("config"),
            database: app_data_root.join("data/database.sqlite"),
            storage: app_data_root.join("data/storage"),
            runtime: app_data_root.join("runtime"),
            temporary: app_data_root.join("tmp"),
            framework_cache: app_data_root.join("cache"),
            logs: app_data_root.join("logs"),
        }
    }

    fn create_all(&self) -> Result<(), LocalRuntimeError> {
        let mut directories = vec![
            self.configuration.clone(),
            self.runtime.clone(),
            self.temporary.clone(),
            self.framework_cache.clone(),
            self.logs.clone(),
        ];

        // Laravel expects its storage tree to already exist. Creating only the
        // root leaves it refusing to boot with "Please provide a valid cache
        // path", because the packaged tree is read-only and it cannot create
        // these itself.
        directories.extend(
            LARAVEL_STORAGE_SUBDIRECTORIES
                .iter()
                .map(|relative| self.storage.join(relative)),
        );

        for directory in &directories {
            fs::create_dir_all(directory).map_err(|error| {
                LocalRuntimeError::new(
                    "local_runtime_io_failed",
                    format!(
                        "Impossible de créer le dossier de données « {} » : {error}",
                        directory.display()
                    ),
                )
            })?;
        }

        if let Some(parent) = self.database.parent() {
            fs::create_dir_all(parent).map_err(|error| {
                LocalRuntimeError::new(
                    "local_runtime_io_failed",
                    format!("Impossible de créer le dossier de la base de données : {error}"),
                )
            })?;
        }

        Ok(())
    }
}

// ---------------------------------------------------------------------------
// Packaged resources
// ---------------------------------------------------------------------------

/// The read-only application payload shipped inside the installer.
#[derive(Clone, Debug)]
pub(crate) struct PackagedRuntime {
    pub(crate) php_binary: PathBuf,
    pub(crate) app_root: PathBuf,
    pub(crate) database_template: Option<PathBuf>,
    /// True for the interpreter shipped in the installer, false for a
    /// developer's own PHP reached through `DRCLICK_LOCAL_APP_ROOT`. Only the
    /// bundled one gets a generated `php.ini`; a developer keeps theirs.
    pub(crate) bundled: bool,
}

impl PackagedRuntime {
    pub(crate) fn public_directory(&self) -> PathBuf {
        self.app_root.join("public")
    }

    /// Directory holding `php.exe`, and therefore `ext/`.
    pub(crate) fn php_directory(&self) -> PathBuf {
        self.php_binary
            .parent()
            .map(Path::to_path_buf)
            .unwrap_or_default()
    }

    /// The CA bundle staged beside the bundled interpreter, if any. A
    /// developer runtime keeps its own system configuration.
    pub(crate) fn ca_bundle(&self) -> Option<PathBuf> {
        if !self.bundled {
            return None;
        }

        let bundle = self.php_directory().join(PACKAGED_CA_BUNDLE);

        bundle.is_file().then_some(bundle)
    }
}

/// File name of the root-certificate bundle staged next to `php.exe` by
/// `scripts/desktop/stage-local-payload.mjs`.
pub(crate) const PACKAGED_CA_BUNDLE: &str = "cacert.pem";

/// Interpreter settings for the bundled runtime.
///
/// `{extension_dir}` is substituted with an absolute path at startup. It cannot
/// be a relative one: Windows PHP resolves a relative `extension_dir` against
/// the process working directory — which the supervisor sets to the Laravel
/// root — and with no value at all it falls back to the compile-time `C:\php\ext`.
/// Either way every extension fails to load and the application will not boot.
const PHP_INI_TEMPLATE: &str = r#"; Generated by the Drclick desktop launcher. Do not edit: it is rewritten
; on every start, and it is per-installation because extension_dir is absolute.
extension_dir = "{extension_dir}"

extension=curl
extension=exif
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sodium
extension=sqlite3
extension=zip

memory_limit = 512M
; Restoring or uploading a whole clinic backup over a slow disk or the cabinet
; LAN takes minutes, not seconds; a request cut at two minutes left a restore
; half-received. One hour also covers the Drive upload job (timeout 3500 s)
; run by the queue worker, which reads this same file.
max_execution_time = 3600
max_input_time = 3600
; A clinic backup (.msbackup) carries every scanned document, so starting a
; new PC from one needs room for a large upload. PHP spools uploads to disk.
; Laravel caps a restore upload at MEDISMART_BACKUP_RESTORE_UPLOAD_MAX_BYTES
; (25 GiB); PHP must not refuse it first.
post_max_size = 32G
upload_max_filesize = 32G
date.timezone = UTC

; The application makes its HTTP calls through curl. Disabling the URL stream
; wrappers removes a class of file-inclusion and SSRF risk from the one process
; that holds the clinic's database.
allow_url_fopen = Off
allow_url_include = Off

; Errors go to stderr, which the supervisor captures and redacts. They must
; never be rendered into a page a clinician is looking at.
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
"#;

/// Request router for PHP's built-in server.
///
/// Laravel ships one, but it resolves the public directory with `getcwd()`.
/// The supervisor deliberately runs PHP with its working directory set to the
/// application root so `artisan` resolves correctly, so that router would look
/// for `index.php` one level too high and every request would 500. The document
/// root is what `php -S -t` actually sets, and it is correct regardless of the
/// working directory.
///
/// Laravel's version also writes one access line per request to stdout, which
/// the supervisor captures into the desktop log. Request paths here contain
/// patient and appointment identifiers, so that logging is deliberately not
/// reproduced.
const ROUTER_SCRIPT: &str = r#"<?php

// Generated by the Drclick desktop launcher. Do not edit.

$publicPath = $_SERVER['DOCUMENT_ROOT'] ?: getcwd();

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

// Serve an existing file directly; anything else goes through the framework.
if ($uri !== '/' && file_exists($publicPath.$uri) && ! is_dir($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
"#;

/// Write the request router and return its path.
pub(crate) fn write_router_script(runtime_directory: &Path) -> Result<PathBuf, LocalRuntimeError> {
    fs::create_dir_all(runtime_directory).map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_io_failed",
            format!("Impossible de créer le dossier d’exécution : {error}"),
        )
    })?;

    let path = runtime_directory.join("router.php");
    fs::write(&path, ROUTER_SCRIPT).map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_router_failed",
            format!("Impossible d’écrire le routeur de l’application : {error}"),
        )
    })?;

    Ok(path)
}

/// Write the interpreter configuration and return the directory holding it.
///
/// The launcher points PHP at this file with `PHPRC` rather than staging a
/// `php.ini` beside `php.exe`, because the correct `extension_dir` is only
/// known once the installation directory is known.
pub(crate) fn write_php_configuration(
    runtime: &PackagedRuntime,
    runtime_directory: &Path,
) -> Result<PathBuf, LocalRuntimeError> {
    let mut configuration = PHP_INI_TEMPLATE.replace(
        "{extension_dir}",
        &runtime.php_directory().join("ext").to_string_lossy(),
    );

    // The Windows PHP build links curl and OpenSSL without access to the
    // Windows certificate store, so without a bundle every HTTPS call (Google
    // Drive, licensing, the online service) fails with "cURL error 60". The
    // payload ships one beside php.exe; see `packaged_ca_bundle`.
    if let Some(bundle) = runtime.ca_bundle() {
        let bundle = bundle.to_string_lossy();
        configuration.push_str(&format!(
            "\n; Trusted root certificates shipped with the application.\n\
             curl.cainfo = \"{bundle}\"\n\
             openssl.cafile = \"{bundle}\"\n"
        ));
    }

    fs::create_dir_all(runtime_directory).map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_io_failed",
            format!("Impossible de créer le dossier d’exécution : {error}"),
        )
    })?;

    let path = runtime_directory.join("php.ini");
    fs::write(&path, configuration).map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_php_configuration_failed",
            format!("Impossible d’écrire la configuration de PHP : {error}"),
        )
    })?;

    Ok(runtime_directory.to_path_buf())
}

fn php_executable_name() -> &'static str {
    if cfg!(windows) {
        "php.exe"
    } else {
        "php"
    }
}

/// Locate the packaged runtime.
///
/// Release builds always read the bundled resources. Debug builds additionally
/// honour `DRCLICK_LOCAL_APP_ROOT` / `DRCLICK_LOCAL_PHP_BINARY`, which lets
/// `tauri dev` supervise the repository's own Laravel tree with the system PHP
/// instead of requiring a staged release payload.
pub(crate) fn resolve_packaged_runtime(
    app: &AppHandle,
) -> Result<PackagedRuntime, LocalRuntimeError> {
    #[cfg(debug_assertions)]
    if let Some(runtime) = development_override()? {
        return Ok(runtime);
    }

    let resource_root = app.path().resource_dir().map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_resources_missing",
            format!("Impossible de localiser les ressources de l’application : {error}"),
        )
    })?;

    // Windows may hand back a verbatim path (\\?\C:\...). PHP builds paths
    // with "/" (`$publicPath.'/index.php'`, `__DIR__.'/../vendor'`), which a
    // verbatim path rejects, so every request failed. Give PHP the ordinary
    // form of the same folder.
    let resource_root = php_safe_path(&resource_root);

    let php_binary = resource_root.join("php").join(php_executable_name());
    let app_root = resource_root.join("laravel");
    let database_template = resource_root.join("initial").join("database.sqlite");

    let runtime = PackagedRuntime {
        php_binary,
        app_root,
        database_template: Some(database_template),
        bundled: true,
    };

    verify_packaged_runtime(&runtime)?;

    Ok(runtime)
}

/// The ordinary form of a Windows path (`C:\...`, `\\server\share\...`)
/// when it was given in verbatim form (`\\?\...`); unchanged elsewhere.
pub(crate) fn php_safe_path(path: &Path) -> PathBuf {
    dunce::simplified(path).to_path_buf()
}

#[cfg(debug_assertions)]
fn development_override() -> Result<Option<PackagedRuntime>, LocalRuntimeError> {
    let Ok(app_root) = std::env::var("DRCLICK_LOCAL_APP_ROOT") else {
        return Ok(None);
    };
    let app_root = PathBuf::from(app_root);
    let php_binary = std::env::var("DRCLICK_LOCAL_PHP_BINARY")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from(php_executable_name()));

    let runtime = PackagedRuntime {
        php_binary,
        app_root,
        // A developer tree keeps its own database; never seed over it.
        database_template: None,
        bundled: false,
    };

    verify_development_runtime(&runtime)?;

    Ok(Some(runtime))
}

/// Reject an incomplete payload *before* spawning anything, so a broken build
/// surfaces as one clear message instead of a crash loop.
pub(crate) fn verify_packaged_runtime(runtime: &PackagedRuntime) -> Result<(), LocalRuntimeError> {
    if !runtime.php_binary.is_file() {
        return Err(LocalRuntimeError::new(
            "local_runtime_php_missing",
            "Le moteur PHP intégré est absent de cette installation. Réinstallez Drclick.",
        ));
    }

    verify_development_runtime(runtime)?;

    match runtime.database_template.as_ref() {
        Some(template) if !template.is_file() => Err(LocalRuntimeError::new(
            "local_runtime_database_template_missing",
            "Le modèle de base de données est absent de cette installation. Réinstallez Drclick.",
        )),
        _ => Ok(()),
    }
}

/// The application-tree checks shared by packaged and development runtimes.
fn verify_development_runtime(runtime: &PackagedRuntime) -> Result<(), LocalRuntimeError> {
    if !runtime.app_root.join("artisan").is_file() {
        return Err(LocalRuntimeError::new(
            "local_runtime_application_missing",
            "L’application Drclick est absente de cette installation. Réinstallez Drclick.",
        ));
    }

    if !runtime.public_directory().join("index.php").is_file() {
        return Err(LocalRuntimeError::new(
            "local_runtime_application_missing",
            "L’application Drclick est incomplète. Réinstallez Drclick.",
        ));
    }

    // The launcher supplies its own request router, so what has to be present
    // here is the Composer autoloader — without it nothing boots at all.
    if !runtime.app_root.join("vendor/autoload.php").is_file() {
        return Err(LocalRuntimeError::new(
            "local_runtime_application_missing",
            "Les dépendances de l’application sont absentes. Réinstallez Drclick.",
        ));
    }

    Ok(())
}

/// Copy the empty migrated template into place on first run.
///
/// This never overwrites an existing database: a clinic's records are only ever
/// created once, and a stray re-seed would destroy them.
pub(crate) fn seed_database_if_absent(
    template: Option<&Path>,
    database: &Path,
) -> Result<bool, LocalRuntimeError> {
    if database.exists() {
        return Ok(false);
    }

    let Some(template) = template else {
        // No template (development tree): Laravel's migrations create the file.
        return Ok(false);
    };

    fs::copy(template, database).map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_database_seed_failed",
            format!("Impossible d’initialiser la base de données locale : {error}"),
        )
    })?;

    Ok(true)
}

/// A baked build setting, or `None` when the build left it unset or blank (an
/// unconfigured GitHub secret arrives as an empty string).
fn baked_setting(value: Option<&'static str>) -> Option<&'static str> {
    value.map(str::trim).filter(|value| !value.is_empty())
}

/// Hand the baked Google OAuth client to every PHP child through this
/// process's environment, which they all inherit. Like the other `set_var`
/// calls in [`start`], it must run before any child is spawned.
///
/// A build without a client ID leaves the environment untouched, so a
/// developer's own `GOOGLE_CLIENT_ID` keeps working under `tauri dev`.
fn export_google_drive_client() {
    let Some(client_id) = baked_setting(GOOGLE_OAUTH_CLIENT_ID) else {
        return;
    };

    std::env::set_var("GOOGLE_CLIENT_ID", client_id);
    std::env::set_var("GOOGLE_DRIVE_SCOPE", GOOGLE_DRIVE_SCOPE);

    match baked_setting(GOOGLE_OAUTH_CLIENT_SECRET) {
        Some(client_secret) => std::env::set_var("GOOGLE_CLIENT_SECRET", client_secret),
        None => std::env::remove_var("GOOGLE_CLIENT_SECRET"),
    }
}

/// Give Laravel the hosted origin already selected for this desktop build.
/// The same origin is used by the connection screen and the signed updater,
/// so the online-service linking form never ships with an empty address.
fn export_online_service_url() {
    std::env::set_var(
        "MEDISMART_ONLINE_SERVICE_URL",
        crate::CLOUD_SERVER_URL.trim_end_matches('/'),
    );
}

// ---------------------------------------------------------------------------
// Supervision
// ---------------------------------------------------------------------------

/// A running local runtime. Dropping the handle does not stop the child; call
/// [`LocalRuntime::shutdown`] from the application exit path so PHP, the queue
/// worker, and the scheduler terminate with the window.
pub(crate) struct LocalRuntime {
    supervisor: Arc<Supervisor>,
    queue_worker: Arc<QueueWorkerSupervisor>,
    scheduler: Arc<SchedulerSupervisor>,
    blueprint: SupervisorBlueprint,
    url: Url,
    /// The optional second PHP listener that serves this PC's database to the
    /// cabinet LAN ("poste principal"). Independent of the loopback listener,
    /// so turning it on or off never moves this PC's own window to another
    /// origin (which would empty its localStorage and its PIN enrolment).
    lan_host: Mutex<Option<RunningLanHost>>,
}

struct RunningLanHost {
    supervisor: Arc<Supervisor>,
    port: u16,
    health: Arc<Mutex<LanHostHealth>>,
}

/// What the LAN host listener last reported.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) enum LanHostHealth {
    Starting,
    Running,
    Failed(&'static str),
    Stopped,
}

impl LocalRuntime {
    /// The loopback origin the webview should load.
    pub(crate) fn url(&self) -> &Url {
        &self.url
    }

    /// Stop PHP (both listeners), the queue worker and the scheduler.
    pub(crate) fn shutdown(&self) {
        self.stop_lan_host();
        // A dependency going down must not look like a reason to respawn PHP.
        self.supervisor.freeze_runtime_contract_refresh();
        self.supervisor.stop();
        self.queue_worker.shutdown();
        self.scheduler.shutdown();
    }

    /// The port and health of the LAN host listener, when it is running.
    pub(crate) fn lan_host(&self) -> Option<(u16, LanHostHealth)> {
        let guard = self.lan_host.lock().ok()?;
        let running = guard.as_ref()?;
        let health = running
            .health
            .lock()
            .map(|health| health.clone())
            .unwrap_or(LanHostHealth::Failed("lan_host_state_poisoned"));

        Some((running.port, health))
    }

    /// Start serving this PC's database on `0.0.0.0:<port>`.
    ///
    /// Blocks until Laravel answers its authenticated health check on the new
    /// listener, so the caller can report success or a precise failure.
    pub(crate) fn start_lan_host(&self, port: u16) -> Result<(), LocalRuntimeError> {
        if let Some((current, LanHostHealth::Running | LanHostHealth::Starting)) = self.lan_host() {
            if current == port {
                return Ok(());
            }
        }
        self.stop_lan_host();

        let supervisor = Arc::new(
            Supervisor::new(
                self.blueprint.config(
                    self.blueprint.paths.runtime.join("lan-host"),
                    generate_runtime_secret(),
                    Some(port),
                ),
                Arc::clone(&self.queue_worker),
                Arc::clone(&self.scheduler),
                Arc::clone(&self.blueprint.lan_upload),
                Arc::clone(&self.blueprint.logger),
            )
            .map_err(|error| {
                LocalRuntimeError::new(
                    "lan_host_supervisor_failed",
                    format!("Impossible de préparer le partage réseau : {error}"),
                )
            })?,
        );

        let health = Arc::new(Mutex::new(LanHostHealth::Starting));
        let receiver = run_supervisor(
            Arc::clone(&supervisor),
            "drclick-lan-host-supervisor",
            Some(Arc::clone(&health)),
        )?;

        if let Ok(mut guard) = self.lan_host.lock() {
            *guard = Some(RunningLanHost {
                supervisor: Arc::clone(&supervisor),
                port,
                health,
            });
        }

        match receiver.recv_timeout(LAN_HOST_READY_TIMEOUT) {
            Ok(Ok(_)) => Ok(()),
            outcome => {
                self.stop_lan_host();
                Err(match outcome {
                    Ok(Err("process_retries_exhausted")) => LocalRuntimeError::new(
                        "lan_host_start_failed",
                        format!(
                            "Le partage réseau n’a pas pu démarrer : le port {port} est \
                             peut-être déjà utilisé par un autre programme."
                        ),
                    ),
                    Ok(Err(code)) => LocalRuntimeError::new(
                        "lan_host_start_failed",
                        format!("Le partage réseau n’a pas pu démarrer ({code})."),
                    ),
                    _ => LocalRuntimeError::new(
                        "lan_host_start_timeout",
                        "Le partage réseau n’a pas répondu à temps.",
                    ),
                })
            }
        }
    }

    /// Close the LAN listener. This PC's own window keeps working.
    pub(crate) fn stop_lan_host(&self) {
        let running = self.lan_host.lock().ok().and_then(|mut guard| guard.take());
        if let Some(running) = running {
            running.supervisor.freeze_runtime_contract_refresh();
            running.supervisor.stop();
            if let Ok(mut health) = running.health.lock() {
                *health = LanHostHealth::Stopped;
            }
        }
    }
}

/// How long enabling the LAN listener may take before it is reported failed.
const LAN_HOST_READY_TIMEOUT: Duration = Duration::from_secs(90);

/// Everything needed to start another supervised PHP listener against the
/// same installation (same database, storage, key, and native services).
#[derive(Clone)]
struct SupervisorBlueprint {
    php_binary: PathBuf,
    app_root: PathBuf,
    public_directory: PathBuf,
    router_script: PathBuf,
    paths: LocalPaths,
    app_key: String,
    installation_id: String,
    application_version: String,
    production: bool,
    lan_upload: Arc<LanUploadSupervisor>,
    logger: Arc<RuntimeLogger>,
}

impl SupervisorBlueprint {
    fn config(
        &self,
        runtime_directory: PathBuf,
        health_key: String,
        lan_host_port: Option<u16>,
    ) -> SupervisorConfig {
        SupervisorConfig {
            php_binary: self.php_binary.clone(),
            app_root: self.app_root.clone(),
            public_directory: self.public_directory.clone(),
            router_script: self.router_script.clone(),
            runtime_directory,
            temporary_directory: self.paths.temporary.clone(),
            framework_cache_directory: self.paths.framework_cache.clone(),
            database_path: Some(self.paths.database.clone()),
            storage_path: Some(self.paths.storage.clone()),
            app_key: Some(self.app_key.clone()),
            installation_id: Some(self.installation_id.clone()),
            application_version: self.application_version.clone(),
            signed_updater_configured: crate::updates::is_configured(),
            production: self.production,
            health_key,
            tunnel_upload_hostname: None,
            lan_host_port,
            health_timeout: Duration::from_secs(60),
            shutdown_timeout: Duration::from_secs(15),
            retry_limit: 3,
            retry_delay: Duration::from_secs(2),
        }
    }
}

type ReadySignal = mpsc::Receiver<Result<String, &'static str>>;

/// Run a long-lived supervision loop (queue worker, scheduler) on its own
/// named thread, so starting it never holds up the rest of the startup.
fn spawn_background_service(
    name: &str,
    service: impl FnOnce() + Send + 'static,
) -> Result<(), LocalRuntimeError> {
    thread::Builder::new()
        .name(name.to_owned())
        .spawn(service)
        .map(|_| ())
        .map_err(|error| {
            LocalRuntimeError::new(
                "local_runtime_thread_failed",
                format!("Impossible de démarrer un service de l’application : {error}"),
            )
        })
}

/// Run `supervisor` on its own thread and hand back a channel that receives
/// the first `Ready` URL or `Failed` code.
fn run_supervisor(
    supervisor: Arc<Supervisor>,
    thread_name: &str,
    health: Option<Arc<Mutex<LanHostHealth>>>,
) -> Result<ReadySignal, LocalRuntimeError> {
    let (sender, receiver) = mpsc::channel::<Result<String, &'static str>>();
    thread::Builder::new()
        .name(thread_name.to_owned())
        .spawn(move || {
            supervisor.run(move |event| {
                let update = |value: LanHostHealth| {
                    if let Some(health) = health.as_ref() {
                        if let Ok(mut current) = health.lock() {
                            *current = value;
                        }
                    }
                };
                match event {
                    SupervisorEvent::Ready { local_url, .. } => {
                        update(LanHostHealth::Running);
                        // The receiver is gone once startup has been resolved;
                        // later restarts reuse the same port and need no signal.
                        let _ = sender.send(Ok(local_url));
                    }
                    SupervisorEvent::Retrying { .. } | SupervisorEvent::Starting { .. } => {
                        update(LanHostHealth::Starting);
                    }
                    SupervisorEvent::Failed { code } => {
                        update(LanHostHealth::Failed(code));
                        let _ = sender.send(Err(code));
                    }
                    SupervisorEvent::Stopped => update(LanHostHealth::Stopped),
                }
            });
        })
        .map_err(|error| {
            LocalRuntimeError::new(
                "local_runtime_supervisor_failed",
                format!("Impossible de lancer le superviseur : {error}"),
            )
        })?;

    Ok(receiver)
}

/// Prepare the writable tree and start supervising the bundled application.
///
/// Blocks until Laravel answers its first health probe, so the caller can build
/// the window straight onto a working origin rather than showing an empty shell
/// that may or may not become usable.
pub(crate) fn start(app: &AppHandle) -> Result<LocalRuntime, LocalRuntimeError> {
    let app_data_root = app.path().app_local_data_dir().map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_io_failed",
            format!("Impossible d’ouvrir le dossier de données de l’application : {error}"),
        )
    })?;

    let packaged = resolve_packaged_runtime(app)?;
    let paths = LocalPaths::rooted_at(&app_data_root);
    paths.create_all()?;
    seed_database_if_absent(packaged.database_template.as_deref(), &paths.database)?;

    let identity = load_or_create_installation_identity(&paths.configuration).map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_identity_failed",
            format!("Impossible de préparer l’identité de cette installation : {error}"),
        )
    })?;

    // Two settings the supervisor's own configuration cannot carry are handed
    // to the PHP children through this process's environment, which they all
    // inherit. Both writes must happen here, before any child is spawned below:
    // `set_var` is only sound while nothing else is reading the environment.
    //
    // PHPRC points PHP at the generated php.ini. Its `extension_dir` has to be
    // absolute, so it cannot be staged beside php.exe.
    if packaged.bundled {
        let configuration_dir = write_php_configuration(&packaged, &paths.runtime)?;
        std::env::set_var("PHPRC", &configuration_dir);

        // Same bundle for code that configures TLS itself rather than through
        // php.ini (Guzzle `verify`, OpenSSL defaults).
        if let Some(bundle) = packaged.ca_bundle() {
            std::env::set_var("MEDISMART_CA_BUNDLE", &bundle);
            std::env::set_var("SSL_CERT_FILE", &bundle);
            std::env::set_var("CURL_CA_BUNDLE", &bundle);
        }
    }

    // The supervisor exports APP_CONFIG_CACHE, APP_ROUTES_CACHE,
    // APP_EVENTS_CACHE, and APP_PACKAGES_CACHE, but not APP_SERVICES_CACHE.
    // Without it Laravel resolves the service manifest to
    // `bootstrap/cache/services.php` inside the packaged application, which is
    // read-only on a real installation — so it cannot register its providers
    // and every request fails with `Class "view" does not exist`.
    //
    // `bootstrap/app.php` additionally has to register the Windows drive-letter
    // prefixes, or Laravel treats `C:\...` as relative and appends it to the
    // installation directory anyway.
    std::env::set_var(
        "APP_SERVICES_CACHE",
        paths.framework_cache.join("services.php"),
    );

    // The web server, queue worker and scheduler all need the Google client:
    // OAuth consent, the queued Drive upload and the scheduled Drive copy.
    export_google_drive_client();

    // Pre-fill Configuration › Service en ligne with this build's hosted
    // control-plane origin (including any deployment-specific override).
    export_online_service_url();

    let router_script = write_router_script(&paths.runtime)?;

    let health_key = generate_runtime_secret();
    let port = allocate_loopback_port().map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_port_unavailable",
            format!("Impossible de réserver un port local : {error}"),
        )
    })?;
    // The port is reserved only to prove one is free; the supervisor rebinds it.
    let _ = port;

    // Secrets are redacted from every log line, and the private data locations
    // are rewritten so a copied diagnostic never leaks a patient file path.
    let logger = RuntimeLogger::open(
        &paths.logs,
        &[health_key.clone(), identity.app_key.clone()],
        &[paths.database.clone(), paths.storage.clone()],
    )
    .map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_logging_failed",
            format!("Impossible d’ouvrir le journal du superviseur : {error}"),
        )
    })?;
    let logger = Arc::new(logger);

    let application_version = env!("CARGO_PKG_VERSION").to_owned();
    let production = !cfg!(debug_assertions);

    let queue_worker = Arc::new(
        QueueWorkerSupervisor::new(
            QueueWorkerConfig {
                php_binary: packaged.php_binary.clone(),
                app_root: packaged.app_root.clone(),
                runtime_directory: paths.runtime.join("queue"),
                temporary_directory: paths.temporary.clone(),
                framework_cache_directory: paths.framework_cache.clone(),
                database_path: Some(paths.database.clone()),
                storage_path: Some(paths.storage.clone()),
                app_key: Some(identity.app_key.clone()),
                installation_id: Some(identity.installation_id.to_string()),
                application_version: application_version.clone(),
                production,
                startup_stability_timeout: BACKGROUND_STARTUP_STABILITY,
                shutdown_timeout: Duration::from_secs(10),
                retry_limit: 2,
                retry_delay: Duration::from_secs(3),
            },
            Arc::clone(&logger),
        )
        .map_err(|error| {
            LocalRuntimeError::new(
                "local_runtime_queue_worker_failed",
                format!("Impossible de préparer le service de traitement : {error}"),
            )
        })?,
    );

    let scheduler = Arc::new(
        SchedulerSupervisor::new(
            SchedulerConfig {
                php_binary: packaged.php_binary.clone(),
                app_root: packaged.app_root.clone(),
                runtime_directory: paths.runtime.join("scheduler"),
                temporary_directory: paths.temporary.clone(),
                framework_cache_directory: paths.framework_cache.clone(),
                database_path: Some(paths.database.clone()),
                storage_path: Some(paths.storage.clone()),
                app_key: Some(identity.app_key.clone()),
                installation_id: Some(identity.installation_id.to_string()),
                application_version: application_version.clone(),
                production,
                startup_stability_timeout: BACKGROUND_STARTUP_STABILITY,
                shutdown_timeout: Duration::from_secs(10),
                retry_limit: 2,
                retry_delay: Duration::from_secs(3),
            },
            Arc::clone(&logger),
        )
        .map_err(|error| {
            LocalRuntimeError::new(
                "local_runtime_scheduler_failed",
                format!("Impossible de préparer le planificateur : {error}"),
            )
        })?,
    );

    // The phone-upload listener is opt-in and off by default: a local-first
    // installation must not open a LAN socket unless the clinic asks for it.
    let lan_upload = Arc::new(
        LanUploadSupervisor::disabled(&paths.runtime.join("lan"), Arc::clone(&logger)).map_err(
            |error| {
                LocalRuntimeError::new(
                    "local_runtime_lan_failed",
                    format!("Impossible de préparer le service de téléversement : {error}"),
                )
            },
        )?,
    );

    let blueprint = SupervisorBlueprint {
        php_binary: packaged.php_binary.clone(),
        app_root: packaged.app_root.clone(),
        public_directory: packaged.public_directory(),
        router_script: router_script.clone(),
        paths: paths.clone(),
        app_key: identity.app_key.clone(),
        installation_id: identity.installation_id.to_string(),
        application_version,
        production,
        lan_upload: Arc::clone(&lan_upload),
        logger: Arc::clone(&logger),
    };

    let supervisor = Supervisor::new(
        blueprint.config(paths.runtime.clone(), health_key, None),
        Arc::clone(&queue_worker),
        Arc::clone(&scheduler),
        Arc::clone(&lan_upload),
        Arc::clone(&logger),
    )
    .map_err(|error| {
        LocalRuntimeError::new(
            "local_runtime_supervisor_failed",
            format!("Impossible de démarrer l’application locale : {error}"),
        )
    })?;
    let supervisor = Arc::new(supervisor);

    // Both `run` calls supervise their process for the life of the app: on
    // the startup thread, a healthy queue worker blocked here forever and
    // Laravel itself never started. Each gets its own thread.
    spawn_background_service("drclick-queue-worker", {
        let queue_worker = Arc::clone(&queue_worker);
        move || queue_worker.run()
    })?;
    spawn_background_service("drclick-scheduler", {
        let scheduler = Arc::clone(&scheduler);
        move || scheduler.run()
    })?;

    let receiver = run_supervisor(Arc::clone(&supervisor), "drclick-laravel-supervisor", None)?;

    match receiver.recv_timeout(READY_TIMEOUT) {
        Ok(Ok(local_url)) => {
            let url = Url::parse(&local_url).map_err(|_| {
                LocalRuntimeError::new(
                    "local_runtime_invalid_url",
                    "Le superviseur a signalé une adresse locale invalide.",
                )
            })?;

            Ok(LocalRuntime {
                supervisor,
                queue_worker,
                scheduler,
                blueprint,
                url,
                lan_host: Mutex::new(None),
            })
        }
        Ok(Err(code)) => {
            supervisor.stop();
            Err(LocalRuntimeError::new(
                "local_runtime_start_failed",
                format!(
                    "L’application locale n’a pas pu démarrer ({code}). \
                     Consultez le journal du superviseur."
                ),
            ))
        }
        Err(RecvTimeoutError::Timeout) => {
            supervisor.stop();
            Err(LocalRuntimeError::new(
                "local_runtime_start_timeout",
                "L’application locale n’a pas répondu à temps au démarrage.",
            ))
        }
        Err(RecvTimeoutError::Disconnected) => {
            supervisor.stop();
            Err(LocalRuntimeError::new(
                "local_runtime_start_failed",
                "Le superviseur de l’application locale s’est arrêté au démarrage.",
            ))
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    use std::env;

    #[cfg(windows)]
    #[test]
    fn php_never_receives_a_verbatim_windows_path() {
        assert_eq!(
            php_safe_path(Path::new(r"\\?\C:\Users\Dr\AppData\Local\Drclick")),
            PathBuf::from(r"C:\Users\Dr\AppData\Local\Drclick"),
        );
        assert_eq!(
            php_safe_path(Path::new(r"\\?\UNC\server\share\Drclick")),
            PathBuf::from(r"\\server\share\Drclick"),
        );
    }

    #[test]
    fn ordinary_paths_reach_php_unchanged() {
        let path = Path::new("/opt/drclick/laravel");
        assert_eq!(php_safe_path(path), path.to_path_buf());
    }

    #[test]
    fn a_background_service_runs_without_holding_up_startup() {
        let (sender, receiver) = mpsc::channel();
        let (release, wait) = mpsc::channel::<()>();

        // A service that never returns on its own, like the queue worker.
        spawn_background_service("drclick-test-service", move || {
            sender.send(()).unwrap();
            let _ = wait.recv();
        })
        .unwrap();

        // Reaching this line at all is the point: the caller was not blocked.
        receiver
            .recv_timeout(Duration::from_secs(5))
            .expect("the service runs on its own thread");
        drop(release);
    }

    #[test]
    fn background_services_start_within_the_runtime_bounds() {
        // A value above these bounds made every local start fail with
        // "queue worker bounds are invalid".
        assert!(
            BACKGROUND_STARTUP_STABILITY <= drclick_runtime::MAX_QUEUE_WORKER_STARTUP_STABILITY
        );
        assert!(BACKGROUND_STARTUP_STABILITY <= drclick_runtime::MAX_SCHEDULER_STARTUP_STABILITY);
    }

    fn scratch(name: &str) -> PathBuf {
        let directory = env::temp_dir().join(format!("drclick-local-runtime-{name}"));
        let _ = fs::remove_dir_all(&directory);
        fs::create_dir_all(&directory).unwrap();
        directory
    }

    #[test]
    fn every_mutable_location_lives_under_the_application_data_root() {
        let root = PathBuf::from("/opt/drclick-data");
        let paths = LocalPaths::rooted_at(&root);

        for path in [
            &paths.configuration,
            &paths.database,
            &paths.storage,
            &paths.runtime,
            &paths.temporary,
            &paths.framework_cache,
            &paths.logs,
        ] {
            assert!(
                path.starts_with(&root),
                "{} escaped the application-data root",
                path.display()
            );
        }
    }

    #[test]
    fn creating_the_layout_is_idempotent() {
        let root = scratch("layout");
        let paths = LocalPaths::rooted_at(&root);

        paths.create_all().unwrap();
        paths.create_all().unwrap();

        assert!(paths.storage.is_dir());
        assert!(paths.runtime.is_dir());
        assert!(paths.logs.is_dir());
        assert!(paths.database.parent().unwrap().is_dir());
    }

    #[test]
    fn the_template_seeds_a_missing_database() {
        let root = scratch("seed");
        let template = root.join("template.sqlite");
        let database = root.join("database.sqlite");
        fs::write(&template, b"empty-migrated-template").unwrap();

        let seeded = seed_database_if_absent(Some(&template), &database).unwrap();

        assert!(seeded);
        assert_eq!(fs::read(&database).unwrap(), b"empty-migrated-template");
    }

    #[test]
    fn seeding_never_overwrites_an_existing_clinic_database() {
        let root = scratch("no-clobber");
        let template = root.join("template.sqlite");
        let database = root.join("database.sqlite");
        fs::write(&template, b"empty-migrated-template").unwrap();
        fs::write(&database, b"REAL PATIENT RECORDS").unwrap();

        let seeded = seed_database_if_absent(Some(&template), &database).unwrap();

        assert!(!seeded);
        assert_eq!(fs::read(&database).unwrap(), b"REAL PATIENT RECORDS");
    }

    #[test]
    fn a_development_tree_without_a_template_is_left_to_migrations() {
        let root = scratch("no-template");
        let database = root.join("database.sqlite");

        let seeded = seed_database_if_absent(None, &database).unwrap();

        assert!(!seeded);
        assert!(!database.exists());
    }

    #[test]
    fn a_missing_php_runtime_is_reported_before_anything_is_spawned() {
        let root = scratch("missing-php");
        let runtime = PackagedRuntime {
            php_binary: root.join("php").join(php_executable_name()),
            app_root: root.join("laravel"),
            database_template: None,
            bundled: true,
        };

        let error = verify_packaged_runtime(&runtime).unwrap_err();

        assert_eq!(error.code, "local_runtime_php_missing");
    }

    #[test]
    fn a_missing_application_tree_is_reported_before_anything_is_spawned() {
        let root = scratch("missing-app");
        let php_binary = root.join(php_executable_name());
        fs::write(&php_binary, b"#!/bin/sh\n").unwrap();
        let runtime = PackagedRuntime {
            php_binary,
            app_root: root.join("laravel"),
            database_template: None,
            bundled: true,
        };

        let error = verify_packaged_runtime(&runtime).unwrap_err();

        assert_eq!(error.code, "local_runtime_application_missing");
    }

    #[test]
    fn a_complete_payload_passes_verification() {
        let root = scratch("complete");
        let php_binary = root.join(php_executable_name());
        fs::write(&php_binary, b"#!/bin/sh\n").unwrap();

        let app_root = root.join("laravel");
        fs::create_dir_all(app_root.join("public")).unwrap();
        fs::write(app_root.join("artisan"), b"<?php").unwrap();
        fs::write(app_root.join("public/index.php"), b"<?php").unwrap();
        fs::create_dir_all(app_root.join("vendor")).unwrap();
        fs::write(app_root.join("vendor/autoload.php"), b"<?php").unwrap();

        let template = root.join("database.sqlite");
        fs::write(&template, b"").unwrap();

        let runtime = PackagedRuntime {
            php_binary,
            app_root,
            database_template: Some(template),
            bundled: true,
        };

        assert!(verify_packaged_runtime(&runtime).is_ok());
    }

    #[test]
    fn the_public_directory_is_derived_from_the_application_root() {
        let runtime = PackagedRuntime {
            php_binary: PathBuf::from("php"),
            app_root: PathBuf::from("/opt/app"),
            database_template: None,
            bundled: true,
        };

        assert_eq!(runtime.public_directory(), PathBuf::from("/opt/app/public"));
    }

    #[test]
    fn the_generated_router_resolves_the_public_path_from_the_document_root() {
        let root = scratch("router");

        let path = write_router_script(&root).unwrap();
        let script = fs::read_to_string(&path).unwrap();

        // getcwd() is the application root under supervision, one level above
        // public/, so a router relying on it 500s every request.
        assert!(script.contains("$_SERVER['DOCUMENT_ROOT']"));
        assert!(!script.contains("$publicPath = getcwd();"));
        assert!(script.contains("require_once $publicPath.'/index.php';"));
        // Request paths carry patient identifiers; they must not be logged.
        assert!(!script.contains("php://stdout"));
    }

    #[test]
    fn an_application_without_its_autoloader_is_refused() {
        let root = scratch("no-vendor");
        let php_binary = root.join(php_executable_name());
        fs::write(
            &php_binary,
            b"#!/bin/sh
",
        )
        .unwrap();
        let app_root = root.join("laravel");
        fs::create_dir_all(app_root.join("public")).unwrap();
        fs::write(app_root.join("artisan"), b"<?php").unwrap();
        fs::write(app_root.join("public/index.php"), b"<?php").unwrap();

        let runtime = PackagedRuntime {
            php_binary,
            app_root,
            database_template: None,
            bundled: true,
        };

        let error = verify_packaged_runtime(&runtime).unwrap_err();

        assert_eq!(error.code, "local_runtime_application_missing");
    }

    #[test]
    fn the_generated_php_configuration_points_at_an_absolute_extension_directory() {
        let root = scratch("php-ini");
        let runtime = PackagedRuntime {
            php_binary: root.join("php").join(php_executable_name()),
            app_root: root.join("laravel"),
            database_template: None,
            bundled: true,
        };
        let runtime_directory = root.join("runtime");

        let directory = write_php_configuration(&runtime, &runtime_directory).unwrap();
        let configuration = fs::read_to_string(directory.join("php.ini")).unwrap();

        // A relative extension_dir would resolve against the supervisor's
        // working directory (the Laravel root); an absent one falls back to the
        // compile-time C:\php\ext. Either way nothing loads.
        assert!(!configuration.contains("{extension_dir}"));
        let expected = root.join("php").join("ext");
        assert!(
            configuration.contains(&expected.to_string_lossy().to_string()),
            "extension_dir must be the absolute staged path: {configuration}"
        );
        assert!(configuration.contains("extension=pdo_sqlite"));
        assert!(configuration.contains("allow_url_fopen = Off"));
        // A 25 GiB restore upload and hour-long transfers must not be cut.
        assert!(configuration.contains("post_max_size = 32G"));
        assert!(configuration.contains("upload_max_filesize = 32G"));
        assert!(configuration.contains("max_execution_time = 3600"));
        assert!(configuration.contains("max_input_time = 3600"));
    }

    #[test]
    fn a_shipped_ca_bundle_is_used_by_curl_and_openssl() {
        let root = scratch("php-ini-ca-bundle");
        let php_directory = root.join("php");
        fs::create_dir_all(&php_directory).unwrap();
        fs::write(
            php_directory.join(PACKAGED_CA_BUNDLE),
            b"-----BEGIN CERTIFICATE-----",
        )
        .unwrap();
        let runtime = PackagedRuntime {
            php_binary: php_directory.join(php_executable_name()),
            app_root: root.join("laravel"),
            database_template: None,
            bundled: true,
        };

        let directory = write_php_configuration(&runtime, &root.join("runtime")).unwrap();
        let configuration = fs::read_to_string(directory.join("php.ini")).unwrap();
        let bundle = php_directory.join(PACKAGED_CA_BUNDLE);

        assert_eq!(runtime.ca_bundle(), Some(bundle.clone()));
        assert!(configuration.contains(&format!("curl.cainfo = \"{}\"", bundle.display())));
        assert!(configuration.contains(&format!("openssl.cafile = \"{}\"", bundle.display())));
    }

    #[test]
    fn without_a_shipped_bundle_php_keeps_its_defaults() {
        let root = scratch("php-ini-no-ca-bundle");
        let runtime = PackagedRuntime {
            php_binary: root.join("php").join(php_executable_name()),
            app_root: root.join("laravel"),
            database_template: None,
            bundled: true,
        };

        let directory = write_php_configuration(&runtime, &root.join("runtime")).unwrap();
        let configuration = fs::read_to_string(directory.join("php.ini")).unwrap();

        assert_eq!(runtime.ca_bundle(), None);
        assert!(!configuration.contains("curl.cainfo"));

        // A developer's own PHP keeps its own TLS configuration.
        let developer = PackagedRuntime {
            bundled: false,
            ..runtime
        };
        assert_eq!(developer.ca_bundle(), None);
    }

    #[test]
    fn the_php_directory_is_derived_from_the_interpreter_path() {
        let runtime = PackagedRuntime {
            php_binary: PathBuf::from("/opt/drclick/php/php.exe"),
            app_root: PathBuf::from("/opt/drclick/laravel"),
            database_template: None,
            bundled: true,
        };

        assert_eq!(runtime.php_directory(), PathBuf::from("/opt/drclick/php"));
    }

    #[test]
    fn the_laravel_storage_tree_is_created_so_the_application_can_boot() {
        let root = scratch("storage-tree");
        let paths = LocalPaths::rooted_at(&root);

        paths.create_all().unwrap();

        // Missing any of these leaves Laravel refusing to start with
        // "Please provide a valid cache path".
        for relative in LARAVEL_STORAGE_SUBDIRECTORIES {
            assert!(
                paths.storage.join(relative).is_dir(),
                "storage/{relative} must exist"
            );
        }
    }

    #[test]
    fn a_blank_baked_google_setting_counts_as_unconfigured() {
        assert_eq!(baked_setting(None), None);
        assert_eq!(baked_setting(Some("")), None);
        assert_eq!(baked_setting(Some("   ")), None);
        assert_eq!(
            baked_setting(Some(" 123-abc.apps.googleusercontent.com ")),
            Some("123-abc.apps.googleusercontent.com")
        );
    }

    fn unique_scratch(name: &str) -> PathBuf {
        scratch(&format!("x-{name}-{}", std::process::id()))
    }

    fn complete_application(root: &Path) -> PathBuf {
        let app_root = root.join("laravel");
        fs::create_dir_all(app_root.join("public")).unwrap();
        fs::create_dir_all(app_root.join("vendor")).unwrap();
        fs::write(app_root.join("artisan"), b"<?php").unwrap();
        fs::write(app_root.join("public/index.php"), b"<?php").unwrap();
        fs::write(app_root.join("vendor/autoload.php"), b"<?php").unwrap();
        app_root
    }

    #[test]
    fn errors_display_their_stable_code_and_french_message() {
        let error = LocalRuntimeError::new("local_runtime_php_missing", "PHP absent");

        assert_eq!(error.to_string(), "[local_runtime_php_missing] PHP absent");
    }

    #[test]
    fn the_damaged_configuration_error_reuses_the_shell_message() {
        let error = LocalRuntimeError::damaged_configuration();

        assert_eq!(error.code, "local_runtime_configuration_damaged");
        assert_eq!(error.message, crate::DAMAGED_CONFIGURATION_MESSAGE);
    }

    #[test]
    fn the_layout_matches_the_documented_tree() {
        let root = PathBuf::from("/data");
        let paths = LocalPaths::rooted_at(&root);

        assert_eq!(paths.configuration, root.join("config"));
        assert_eq!(paths.database, root.join("data/database.sqlite"));
        assert_eq!(paths.storage, root.join("data/storage"));
        assert_eq!(paths.runtime, root.join("runtime"));
        assert_eq!(paths.temporary, root.join("tmp"));
        assert_eq!(paths.framework_cache, root.join("cache"));
        assert_eq!(paths.logs, root.join("logs"));
        assert!(!paths.database.starts_with(&paths.storage));
    }

    #[test]
    fn creating_the_layout_fails_cleanly_when_a_file_blocks_a_directory() {
        let root = unique_scratch("blocked-layout");
        fs::write(root.join("logs"), b"not a directory").unwrap();
        let paths = LocalPaths::rooted_at(&root);

        let error = paths.create_all().unwrap_err();

        assert_eq!(error.code, "local_runtime_io_failed");
        assert_eq!(fs::read(root.join("logs")).unwrap(), b"not a directory");
    }

    #[test]
    fn a_missing_template_file_is_a_seed_failure_and_creates_nothing() {
        let root = unique_scratch("missing-template");
        let database = root.join("database.sqlite");

        let error =
            seed_database_if_absent(Some(&root.join("absent.sqlite")), &database).unwrap_err();

        assert_eq!(error.code, "local_runtime_database_seed_failed");
        assert!(!database.exists());
    }

    #[test]
    fn a_missing_public_entry_point_is_reported_as_incomplete() {
        let root = unique_scratch("no-index");
        let php_binary = root.join(php_executable_name());
        fs::write(&php_binary, b"#!/bin/sh\n").unwrap();
        let app_root = complete_application(&root);
        fs::remove_file(app_root.join("public/index.php")).unwrap();

        let error = verify_packaged_runtime(&PackagedRuntime {
            php_binary,
            app_root,
            database_template: None,
            bundled: true,
        })
        .unwrap_err();

        assert_eq!(error.code, "local_runtime_application_missing");
        assert!(error.message.contains("incomplète"));
    }

    #[test]
    fn a_declared_but_missing_database_template_is_reported() {
        let root = unique_scratch("no-template-file");
        let php_binary = root.join(php_executable_name());
        fs::write(&php_binary, b"#!/bin/sh\n").unwrap();
        let app_root = complete_application(&root);

        let error = verify_packaged_runtime(&PackagedRuntime {
            php_binary,
            app_root,
            database_template: Some(root.join("initial/database.sqlite")),
            bundled: true,
        })
        .unwrap_err();

        assert_eq!(error.code, "local_runtime_database_template_missing");
    }

    #[test]
    fn a_directory_in_place_of_the_interpreter_is_refused() {
        let root = unique_scratch("php-dir");
        let php_binary = root.join(php_executable_name());
        fs::create_dir_all(&php_binary).unwrap();
        let app_root = complete_application(&root);

        let error = verify_packaged_runtime(&PackagedRuntime {
            php_binary,
            app_root,
            database_template: None,
            bundled: true,
        })
        .unwrap_err();

        assert_eq!(error.code, "local_runtime_php_missing");
    }

    #[test]
    fn development_trees_are_checked_without_requiring_a_bundled_interpreter() {
        let root = unique_scratch("dev-tree");
        let app_root = complete_application(&root);
        let runtime = PackagedRuntime {
            php_binary: PathBuf::from("php"),
            app_root,
            database_template: None,
            bundled: false,
        };

        assert!(verify_development_runtime(&runtime).is_ok());
        assert_eq!(
            verify_packaged_runtime(&runtime).unwrap_err().code,
            "local_runtime_php_missing"
        );
    }

    #[test]
    fn a_bare_interpreter_name_has_no_php_directory() {
        let runtime = PackagedRuntime {
            php_binary: PathBuf::from("php"),
            app_root: PathBuf::from("/opt/app"),
            database_template: None,
            bundled: false,
        };

        assert_eq!(runtime.php_directory(), PathBuf::new());
    }

    #[test]
    fn the_interpreter_name_follows_the_platform() {
        if cfg!(windows) {
            assert_eq!(php_executable_name(), "php.exe");
        } else {
            assert_eq!(php_executable_name(), "php");
        }
    }

    #[test]
    fn writing_the_router_creates_its_directory_and_is_repeatable() {
        let root = unique_scratch("router-repeat");
        let runtime_directory = root.join("nested/runtime");

        let first = write_router_script(&runtime_directory).unwrap();
        let second = write_router_script(&runtime_directory).unwrap();

        assert_eq!(first, runtime_directory.join("router.php"));
        assert_eq!(first, second);
        assert_eq!(fs::read_to_string(&first).unwrap(), ROUTER_SCRIPT);
    }

    #[test]
    fn writing_the_router_into_a_file_path_fails_with_an_io_error() {
        let root = unique_scratch("router-blocked");
        let blocker = root.join("runtime");
        fs::write(&blocker, b"file").unwrap();

        let error = write_router_script(&blocker).unwrap_err();

        assert_eq!(error.code, "local_runtime_io_failed");
    }

    #[test]
    fn the_router_lets_php_serve_existing_static_files_but_not_directories() {
        assert!(ROUTER_SCRIPT.starts_with("<?php"));
        assert!(ROUTER_SCRIPT.contains("$uri !== '/'"));
        assert!(ROUTER_SCRIPT.contains("! is_dir($publicPath.$uri)"));
        assert!(ROUTER_SCRIPT.contains("return false;"));
        assert!(!ROUTER_SCRIPT.contains("error_log"));
    }

    #[test]
    fn the_php_configuration_enables_every_required_extension_once() {
        let root = unique_scratch("php-ini-extensions");
        let runtime = PackagedRuntime {
            php_binary: root.join("php").join(php_executable_name()),
            app_root: root.join("laravel"),
            database_template: None,
            bundled: true,
        };

        let directory = write_php_configuration(&runtime, &root.join("runtime")).unwrap();
        let configuration = fs::read_to_string(directory.join("php.ini")).unwrap();

        assert_eq!(directory, root.join("runtime"));
        for extension in [
            "curl",
            "exif",
            "fileinfo",
            "gd",
            "intl",
            "mbstring",
            "openssl",
            "pdo_sqlite",
            "sodium",
            "sqlite3",
            "zip",
        ] {
            assert_eq!(
                configuration
                    .matches(&format!("extension={extension}\n"))
                    .count(),
                1,
                "{extension}"
            );
        }
        assert_eq!(configuration.matches("extension_dir = ").count(), 1);
        for setting in [
            "allow_url_include = Off",
            "expose_php = Off",
            "display_errors = Off",
            "display_startup_errors = Off",
            "log_errors = On",
            "date.timezone = UTC",
        ] {
            assert!(configuration.contains(setting), "{setting}");
        }
    }

    #[test]
    fn the_php_configuration_is_rewritten_on_every_start() {
        let root = unique_scratch("php-ini-rewrite");
        let runtime_directory = root.join("runtime");
        fs::create_dir_all(&runtime_directory).unwrap();
        fs::write(runtime_directory.join("php.ini"), b"display_errors = On\n").unwrap();
        let runtime = PackagedRuntime {
            php_binary: root.join("php").join(php_executable_name()),
            app_root: root.join("laravel"),
            database_template: None,
            bundled: true,
        };

        write_php_configuration(&runtime, &runtime_directory).unwrap();

        let configuration = fs::read_to_string(runtime_directory.join("php.ini")).unwrap();
        assert!(!configuration.contains("display_errors = On"));
    }

    #[test]
    fn the_online_service_url_is_exported_without_a_trailing_slash() {
        export_online_service_url();

        let exported = std::env::var("MEDISMART_ONLINE_SERVICE_URL").unwrap();
        assert_eq!(exported, crate::CLOUD_SERVER_URL.trim_end_matches('/'));
        assert!(!exported.ends_with('/'));
    }

    #[test]
    fn the_drive_scope_is_the_least_privileged_file_scope() {
        assert_eq!(
            GOOGLE_DRIVE_SCOPE,
            "https://www.googleapis.com/auth/drive.file"
        );
    }
}
