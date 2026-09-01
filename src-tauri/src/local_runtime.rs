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
}

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
max_execution_time = 120
post_max_size = 64M
upload_max_filesize = 32M
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
    let configuration = PHP_INI_TEMPLATE.replace(
        "{extension_dir}",
        &runtime.php_directory().join("ext").to_string_lossy(),
    );

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
pub(crate) fn verify_packaged_runtime(
    runtime: &PackagedRuntime,
) -> Result<(), LocalRuntimeError> {
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

// ---------------------------------------------------------------------------
// Supervision
// ---------------------------------------------------------------------------

/// A running local runtime. Dropping the handle does not stop the child; call
/// [`LocalRuntime::shutdown`] from the application exit path so PHP, the queue
/// worker, and the scheduler terminate with the window.
pub(crate) struct LocalRuntime {
    supervisor: Arc<Supervisor>,
    url: Url,
}

impl LocalRuntime {
    /// The loopback origin the webview should load.
    pub(crate) fn url(&self) -> &Url {
        &self.url
    }

    pub(crate) fn shutdown(&self) {
        self.supervisor.stop();
    }
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
                startup_stability_timeout: Duration::from_secs(20),
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
                startup_stability_timeout: Duration::from_secs(20),
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

    let supervisor = Supervisor::new(
        SupervisorConfig {
            php_binary: packaged.php_binary.clone(),
            app_root: packaged.app_root.clone(),
            public_directory: packaged.public_directory(),
            router_script: router_script.clone(),
            runtime_directory: paths.runtime.clone(),
            temporary_directory: paths.temporary.clone(),
            framework_cache_directory: paths.framework_cache.clone(),
            database_path: Some(paths.database.clone()),
            storage_path: Some(paths.storage.clone()),
            app_key: Some(identity.app_key.clone()),
            installation_id: Some(identity.installation_id.to_string()),
            application_version,
            signed_updater_configured: crate::updates::is_configured(),
            production,
            health_key,
            tunnel_upload_hostname: None,
            health_timeout: Duration::from_secs(60),
            shutdown_timeout: Duration::from_secs(15),
            retry_limit: 3,
            retry_delay: Duration::from_secs(2),
        },
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

    Arc::clone(&queue_worker).run();
    Arc::clone(&scheduler).run();

    let (sender, receiver) = mpsc::channel::<Result<String, &'static str>>();
    let supervisor_for_thread = Arc::clone(&supervisor);
    thread::Builder::new()
        .name("drclick-laravel-supervisor".to_owned())
        .spawn(move || {
            supervisor_for_thread.run(move |event| match event {
                SupervisorEvent::Ready { local_url, .. } => {
                    // The receiver is gone once startup has been resolved;
                    // later restarts reuse the same port and need no signal.
                    let _ = sender.send(Ok(local_url));
                }
                SupervisorEvent::Failed { code } => {
                    let _ = sender.send(Err(code));
                }
                _ => {}
            });
        })
        .map_err(|error| {
            LocalRuntimeError::new(
                "local_runtime_supervisor_failed",
                format!("Impossible de lancer le superviseur : {error}"),
            )
        })?;

    match receiver.recv_timeout(READY_TIMEOUT) {
        Ok(Ok(local_url)) => {
            let url = Url::parse(&local_url).map_err(|_| {
                LocalRuntimeError::new(
                    "local_runtime_invalid_url",
                    "Le superviseur a signalé une adresse locale invalide.",
                )
            })?;

            Ok(LocalRuntime { supervisor, url })
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
        fs::write(&php_binary, b"#!/bin/sh
").unwrap();
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
    }

    #[test]
    fn the_php_directory_is_derived_from_the_interpreter_path() {
        let runtime = PackagedRuntime {
            php_binary: PathBuf::from("/opt/drclick/php/php.exe"),
            app_root: PathBuf::from("/opt/drclick/laravel"),
            database_template: None,
            bundled: true,
        };

        assert_eq!(
            runtime.php_directory(),
            PathBuf::from("/opt/drclick/php")
        );
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
}
