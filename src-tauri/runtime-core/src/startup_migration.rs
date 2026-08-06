use std::{
    collections::{BTreeMap, BTreeSet},
    ffi::{OsStr, OsString},
    fmt,
    fs::{self, File, OpenOptions},
    io::{self, BufReader, Read, Write},
    path::{Component, Path, PathBuf},
    process::{Command, ExitStatus, Stdio},
    sync::Arc,
    thread,
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use fs2::FileExt;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use uuid::Uuid;

use crate::RuntimeLogger;

const SCHEMA_VERSION: u8 = 1;
const LARAVEL_MANIFEST: &str = "release.manifest.json";
const PHP_MANIFEST: &str = "php-runtime.manifest.json";
const DATABASE_MANIFEST: &str = "database.manifest.json";
const MIGRATION_CONTRACT: &str = "migration-contract.json";
const MIGRATION_HELPER: &str = "app/Console/Commands/NativeMigrationGate.php";
const LIFECYCLE_LOCK: &str = "restore-lifecycle.lock";
const ACTIVE_JOURNAL: &str = "active-migration.json";
const MAX_MANIFEST_BYTES: u64 = 16 * 1024 * 1024;
const MAX_JOURNAL_BYTES: u64 = 64 * 1024;
const MAX_COMMAND_OUTPUT_BYTES: usize = 256 * 1024;
const MAX_INVENTORY_FILES: usize = 50_000;
const MAX_INVENTORY_DIRECTORIES: usize = 20_000;
const MAX_MIGRATIONS: usize = 2_000;
const MAX_PATH_BYTES: usize = 512;
const SNAPSHOT_RETENTION: usize = 3;
const MINIMUM_FREE_SPACE_HEADROOM: u64 = 64 * 1024 * 1024;
const SQLITE_HEADER: &[u8; 16] = b"SQLite format 3\0";

#[derive(Debug)]
pub struct StartupMigrationError {
    code: &'static str,
    detail: String,
}

impl StartupMigrationError {
    fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self {
            code,
            detail: detail.into(),
        }
    }

    pub fn code(&self) -> &'static str {
        self.code
    }

    pub fn operator_message_fr(&self) -> &'static str {
        match self.code {
            "migration_lock_contended" => {
                "Une autre opération sécurisée utilise la base locale. Fermez l’autre instance puis réessayez."
            }
            "migration_resources_invalid" | "migration_runtime_mismatch" => {
                "Les ressources de mise à niveau de MediSmart ne sont pas valides. Réinstallez cette version avant de rouvrir le cabinet."
            }
            "migration_database_newer_than_release" => {
                "La base locale provient d’une version plus récente. Installez la version MediSmart correspondante."
            }
            "migration_recovery_required" | "migration_recovery_snapshot_invalid" => {
                "La mise à niveau locale est interrompue. Les données sont conservées hors ligne ; contactez l’assistance."
            }
            "migration_disk_space_insufficient" => {
                "L’espace disque est insuffisant pour créer la copie de sécurité avant la mise à niveau."
            }
            _ => {
                "La mise à niveau de la base locale n’a pas abouti. Les services restent arrêtés et les données sont conservées."
            }
        }
    }
}

impl fmt::Display for StartupMigrationError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}: {}", self.code, self.detail)
    }
}

impl std::error::Error for StartupMigrationError {}

#[derive(Clone, Debug)]
pub struct PackagedMigrationResourceConfig {
    pub resource_root: PathBuf,
    pub application_version: String,
    pub expected_laravel_manifest_sha256: String,
    pub expected_php_manifest_sha256: String,
    pub expected_database_manifest_sha256: String,
    pub expected_migration_contract_sha256: String,
}

#[derive(Clone, Debug)]
pub struct VerifiedMigrationResources {
    resource_root: PathBuf,
    app_root: PathBuf,
    php_binary: PathBuf,
    artisan_path: PathBuf,
    helper_path: PathBuf,
    expected_migrations: Vec<String>,
    migration_set_sha256: String,
    migration_contract_sha256: String,
    application_version: String,
}

impl VerifiedMigrationResources {
    pub fn php_binary(&self) -> &Path {
        &self.php_binary
    }

    pub fn app_root(&self) -> &Path {
        &self.app_root
    }
}

#[derive(Clone, Debug)]
pub struct StartupMigrationGateConfig {
    pub app_data_root: PathBuf,
    pub database_path: PathBuf,
    pub storage_path: PathBuf,
    pub temporary_directory: PathBuf,
    pub framework_cache_directory: PathBuf,
    pub recovery_root: PathBuf,
    pub app_key: String,
    pub installation_id: String,
    pub application_version: String,
    pub command_timeout: Duration,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum StartupMigrationOutcome {
    Noop,
    Migrated,
    RecoveredThenMigrated,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct LaravelReleaseManifest {
    schema_version: u8,
    composer_lock_sha256: String,
    vite_manifest_sha256: String,
    directories: Vec<String>,
    files: BTreeMap<String, String>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PhpRuntimeManifest {
    schema_version: u8,
    product: String,
    version: String,
    architecture: String,
    extensions: Vec<String>,
    required_extensions: Vec<String>,
    review_manifest_sha256: String,
    directories: Vec<String>,
    files: BTreeMap<String, String>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct InitialDatabaseManifest {
    schema_version: u8,
    sha256: String,
    size: u64,
    migration_count: u64,
    reference_seed_counts: BTreeMap<String, u64>,
    empty_table_count: u64,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct MigrationContractDocument {
    schema_version: u8,
    application_version: String,
    initial_database_sha256: String,
    migration_helper: MigrationContractEntry,
    migrations: Vec<MigrationContractEntry>,
    migration_set_sha256: String,
}

#[derive(Clone, Deserialize)]
#[serde(deny_unknown_fields)]
struct MigrationContractEntry {
    path: String,
    sha256: String,
}

pub fn verify_packaged_migration_resources(
    config: &PackagedMigrationResourceConfig,
) -> Result<VerifiedMigrationResources, StartupMigrationError> {
    validate_version(&config.application_version)?;
    for digest in [
        &config.expected_laravel_manifest_sha256,
        &config.expected_php_manifest_sha256,
        &config.expected_database_manifest_sha256,
        &config.expected_migration_contract_sha256,
    ] {
        validate_sha256(digest)?;
    }

    let resource_root = canonical_plain_directory(&config.resource_root)?;
    let app_root = canonical_child_directory(&resource_root, "laravel")?;
    let php_root = canonical_child_directory(&resource_root, "php")?;
    let initial_root = canonical_child_directory(&resource_root, "initial")?;

    let laravel_bytes = read_anchored_manifest(
        &app_root.join(LARAVEL_MANIFEST),
        &config.expected_laravel_manifest_sha256,
    )?;
    let laravel: LaravelReleaseManifest = decode_strict(&laravel_bytes, "Laravel manifest")?;
    validate_laravel_manifest(&laravel)?;
    verify_component_inventory(
        &app_root,
        LARAVEL_MANIFEST,
        &laravel.directories,
        &laravel.files,
    )?;

    let php_bytes = read_anchored_manifest(
        &php_root.join(PHP_MANIFEST),
        &config.expected_php_manifest_sha256,
    )?;
    let php: PhpRuntimeManifest = decode_strict(&php_bytes, "PHP manifest")?;
    validate_php_manifest(&php)?;
    verify_component_inventory(&php_root, PHP_MANIFEST, &php.directories, &php.files)?;

    let database_manifest_bytes = read_anchored_manifest(
        &initial_root.join(DATABASE_MANIFEST),
        &config.expected_database_manifest_sha256,
    )?;
    let database_manifest: InitialDatabaseManifest =
        decode_strict(&database_manifest_bytes, "initial database manifest")?;
    validate_initial_manifest(&database_manifest)?;
    let initial_database = canonical_child_file(&initial_root, "database.sqlite")?;
    if file_size(&initial_database)? != database_manifest.size
        || sha256_file(&initial_database)? != database_manifest.sha256
    {
        return Err(resource_error("initial database hash or size mismatch"));
    }
    validate_sqlite_header(&initial_database)?;

    let contract_bytes = read_anchored_manifest(
        &initial_root.join(MIGRATION_CONTRACT),
        &config.expected_migration_contract_sha256,
    )?;
    let contract: MigrationContractDocument = decode_strict(&contract_bytes, "migration contract")?;
    validate_migration_contract(
        &contract,
        &config.application_version,
        &database_manifest,
        &laravel.files,
    )?;

    let php_binary = canonical_child_file(&php_root, "php.exe")?;
    let artisan_path = canonical_child_file(&app_root, "artisan")?;
    let helper_path = canonical_relative_file(&app_root, MIGRATION_HELPER)?;
    let expected_migrations = contract
        .migrations
        .iter()
        .map(|entry| {
            Path::new(&entry.path)
                .file_stem()
                .and_then(OsStr::to_str)
                .unwrap_or_default()
                .to_owned()
        })
        .collect();

    Ok(VerifiedMigrationResources {
        resource_root,
        app_root,
        php_binary,
        artisan_path,
        helper_path,
        expected_migrations,
        migration_set_sha256: contract.migration_set_sha256,
        migration_contract_sha256: config.expected_migration_contract_sha256.clone(),
        application_version: config.application_version.clone(),
    })
}

fn validate_laravel_manifest(
    manifest: &LaravelReleaseManifest,
) -> Result<(), StartupMigrationError> {
    if manifest.schema_version != SCHEMA_VERSION {
        return Err(resource_error("unsupported Laravel manifest schema"));
    }
    validate_sha256(&manifest.composer_lock_sha256)?;
    validate_sha256(&manifest.vite_manifest_sha256)?;
    if manifest.files.get("artisan").is_none()
        || manifest.files.get(MIGRATION_HELPER).is_none()
        || manifest.files.get("vendor/autoload.php").is_none()
    {
        return Err(resource_error("Laravel command resources are incomplete"));
    }
    Ok(())
}

fn validate_php_manifest(manifest: &PhpRuntimeManifest) -> Result<(), StartupMigrationError> {
    if manifest.schema_version != SCHEMA_VERSION
        || manifest.product != "php-windows-runtime"
        || manifest.architecture != "x64"
        || !manifest.files.contains_key("php.exe")
    {
        return Err(resource_error("unsupported packaged PHP manifest"));
    }
    validate_version(&manifest.version)?;
    validate_sha256(&manifest.review_manifest_sha256)?;
    let extensions: BTreeSet<_> = manifest.extensions.iter().map(String::as_str).collect();
    let required: BTreeSet<_> = manifest
        .required_extensions
        .iter()
        .map(String::as_str)
        .collect();
    if manifest.extensions.len() != extensions.len()
        || manifest.required_extensions.len() != required.len()
        || !extensions.contains("pdo_sqlite")
        || !extensions.contains("sqlite3")
        || !required.contains("pdo_sqlite")
        || !required.contains("sqlite3")
    {
        return Err(resource_error(
            "packaged PHP SQLite extension contract is invalid",
        ));
    }
    Ok(())
}

fn validate_initial_manifest(
    manifest: &InitialDatabaseManifest,
) -> Result<(), StartupMigrationError> {
    if manifest.schema_version != SCHEMA_VERSION
        || manifest.size < SQLITE_HEADER.len() as u64
        || manifest.migration_count == 0
        || manifest.empty_table_count == 0
        || manifest.reference_seed_counts.is_empty()
    {
        return Err(resource_error("initial database manifest is invalid"));
    }
    validate_sha256(&manifest.sha256)
}

fn validate_migration_contract(
    contract: &MigrationContractDocument,
    application_version: &str,
    initial: &InitialDatabaseManifest,
    laravel_files: &BTreeMap<String, String>,
) -> Result<(), StartupMigrationError> {
    if contract.schema_version != SCHEMA_VERSION
        || contract.application_version != application_version
        || contract.initial_database_sha256 != initial.sha256
        || contract.migrations.is_empty()
        || contract.migrations.len() > MAX_MIGRATIONS
        || contract.migrations.len() as u64 != initial.migration_count
    {
        return Err(resource_error("migration contract identity is invalid"));
    }
    validate_sha256(&contract.initial_database_sha256)?;
    validate_sha256(&contract.migration_set_sha256)?;
    validate_contract_entry(&contract.migration_helper)?;
    if contract.migration_helper.path != MIGRATION_HELPER
        || laravel_files.get(MIGRATION_HELPER) != Some(&contract.migration_helper.sha256)
    {
        return Err(resource_error(
            "migration helper is not bound to Laravel inventory",
        ));
    }

    let mut previous = None::<&str>;
    let mut seen_casefolded = BTreeSet::new();
    let mut hasher = Sha256::new();
    for entry in &contract.migrations {
        validate_contract_entry(entry)?;
        if !entry.path.starts_with("database/migrations/") || !entry.path.ends_with(".php") {
            return Err(resource_error(
                "migration contract contains an invalid path",
            ));
        }
        if previous.is_some_and(|value| value >= entry.path.as_str())
            || !seen_casefolded.insert(entry.path.to_ascii_lowercase())
            || laravel_files.get(&entry.path) != Some(&entry.sha256)
        {
            return Err(resource_error(
                "migration contract ordering or hash is invalid",
            ));
        }
        previous = Some(&entry.path);
        hasher.update(entry.path.as_bytes());
        hasher.update([0]);
        hasher.update(entry.sha256.as_bytes());
        hasher.update(b"\n");
    }
    if hex_lower(&hasher.finalize()) != contract.migration_set_sha256 {
        return Err(resource_error("migration set digest is invalid"));
    }

    let manifest_migrations: BTreeSet<_> = laravel_files
        .keys()
        .filter(|path| path.starts_with("database/migrations/") && path.ends_with(".php"))
        .cloned()
        .collect();
    let contract_migrations: BTreeSet<_> = contract
        .migrations
        .iter()
        .map(|entry| entry.path.clone())
        .collect();
    if manifest_migrations != contract_migrations {
        return Err(resource_error(
            "migration contract is not the exact Laravel migration set",
        ));
    }
    Ok(())
}

fn validate_contract_entry(entry: &MigrationContractEntry) -> Result<(), StartupMigrationError> {
    validate_relative_path(&entry.path)?;
    validate_sha256(&entry.sha256)
}

fn verify_component_inventory(
    root: &Path,
    manifest_name: &str,
    expected_directories: &[String],
    expected_files: &BTreeMap<String, String>,
) -> Result<(), StartupMigrationError> {
    if expected_directories.len() > MAX_INVENTORY_DIRECTORIES
        || expected_files.len() > MAX_INVENTORY_FILES
    {
        return Err(resource_error("component inventory exceeds its bounds"));
    }
    let mut normalized_directories = BTreeSet::new();
    let mut casefolded = BTreeSet::new();
    for path in expected_directories {
        validate_relative_path(path)?;
        if !normalized_directories.insert(path.clone())
            || !casefolded.insert(path.to_ascii_lowercase())
        {
            return Err(resource_error("component directory inventory is ambiguous"));
        }
    }
    casefolded.clear();
    for (path, digest) in expected_files {
        validate_relative_path(path)?;
        validate_sha256(digest)?;
        if path == manifest_name || !casefolded.insert(path.to_ascii_lowercase()) {
            return Err(resource_error("component file inventory is ambiguous"));
        }
    }

    let mut actual_directories = BTreeSet::new();
    let mut actual_files = BTreeMap::new();
    inspect_component_tree(
        root,
        root,
        manifest_name,
        &mut actual_directories,
        &mut actual_files,
    )?;
    if actual_directories != normalized_directories || &actual_files != expected_files {
        return Err(resource_error("component inventory or file hash mismatch"));
    }
    Ok(())
}

fn inspect_component_tree(
    root: &Path,
    directory: &Path,
    manifest_name: &str,
    directories: &mut BTreeSet<String>,
    files: &mut BTreeMap<String, String>,
) -> Result<(), StartupMigrationError> {
    let entries = fs::read_dir(directory)
        .map_err(|error| resource_error(format!("read component directory: {error}")))?;
    for entry in entries {
        let entry =
            entry.map_err(|error| resource_error(format!("read component entry: {error}")))?;
        let path = entry.path();
        let metadata = secure_metadata(&path)?;
        let relative = path
            .strip_prefix(root)
            .ok()
            .and_then(Path::to_str)
            .map(|value| value.replace('\\', "/"))
            .ok_or_else(|| resource_error("component path is not portable UTF-8"))?;
        validate_relative_path(&relative)?;
        if metadata.is_dir() {
            if directories.len() >= MAX_INVENTORY_DIRECTORIES {
                return Err(resource_error(
                    "component directory count exceeds its bound",
                ));
            }
            directories.insert(relative);
            inspect_component_tree(root, &path, manifest_name, directories, files)?;
        } else if metadata.is_file() {
            if relative == manifest_name {
                continue;
            }
            if files.len() >= MAX_INVENTORY_FILES {
                return Err(resource_error("component file count exceeds its bound"));
            }
            files.insert(relative, sha256_file(&path)?);
        } else {
            return Err(resource_error(
                "component contains a special filesystem entry",
            ));
        }
    }
    Ok(())
}

fn read_anchored_manifest(
    path: &Path,
    expected_sha256: &str,
) -> Result<Vec<u8>, StartupMigrationError> {
    let bytes = read_bounded_regular_file(path, MAX_MANIFEST_BYTES)?;
    if hex_lower(&Sha256::digest(&bytes)) != expected_sha256 {
        return Err(StartupMigrationError::new(
            "migration_runtime_mismatch",
            "packaged manifest differs from the release-embedded digest",
        ));
    }
    Ok(bytes)
}

fn decode_strict<T: for<'de> Deserialize<'de>>(
    bytes: &[u8],
    label: &str,
) -> Result<T, StartupMigrationError> {
    serde_json::from_slice(bytes)
        .map_err(|error| resource_error(format!("decode {label}: {error}")))
}

fn validate_version(value: &str) -> Result<(), StartupMigrationError> {
    let valid = regex::Regex::new(
        r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$",
    )
    .is_ok_and(|pattern| pattern.is_match(value));
    if !valid || value.len() > 64 {
        return Err(resource_error("application version is invalid"));
    }
    Ok(())
}

fn validate_sha256(value: &str) -> Result<(), StartupMigrationError> {
    if value.len() != 64
        || !value
            .bytes()
            .all(|byte| byte.is_ascii_digit() || matches!(byte, b'a'..=b'f'))
    {
        return Err(resource_error("SHA-256 value is invalid"));
    }
    Ok(())
}

fn validate_relative_path(value: &str) -> Result<(), StartupMigrationError> {
    if value.is_empty()
        || value.len() > MAX_PATH_BYTES
        || value.starts_with('/')
        || value.ends_with('/')
        || value.contains('\\')
        || value.contains('\0')
        || value
            .split('/')
            .any(|part| part.is_empty() || matches!(part, "." | ".."))
        || Path::new(value)
            .components()
            .any(|component| !matches!(component, Component::Normal(_)))
    {
        return Err(resource_error("release path is not canonical"));
    }
    Ok(())
}

fn resource_error(detail: impl Into<String>) -> StartupMigrationError {
    StartupMigrationError::new("migration_resources_invalid", detail)
}

#[derive(Clone, Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct MigrationInspection {
    protocol: String,
    schema_version: u8,
    operation: String,
    integrity_ok: bool,
    foreign_keys_ok: bool,
    journal_mode: String,
    migrations_table_present: bool,
    expected_migrations: Vec<String>,
    applied_migrations: Vec<String>,
    pending_migrations: Vec<String>,
    required_tables_present: bool,
    missing_required_tables: Vec<String>,
    snapshot_created: bool,
    checkpoint: Option<CheckpointObservation>,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct CheckpointObservation {
    busy: i64,
    log: i64,
    checkpointed: i64,
}

#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
enum JournalPhase {
    SnapshotStarted,
    SafetyBackupVerified,
    MigrationStarted,
    MigrationProcessSucceeded,
    PostValidationSucceeded,
    RestoreStarted,
    Restored,
    Committed,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct MigrationJournalRecord {
    schema_version: u8,
    operation_id: String,
    phase: JournalPhase,
    application_version: String,
    migration_set_sha256: String,
    migration_contract_sha256: String,
    installation_binding_sha256: String,
    snapshot_filename: String,
    snapshot_sha256: Option<String>,
    snapshot_size: Option<u64>,
    failure_code: Option<String>,
    created_at_unix: u64,
    updated_at_unix: u64,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct SignedMigrationJournal {
    record: MigrationJournalRecord,
    hmac_sha256: String,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum DatabaseMigrationState {
    Target,
    Pending,
}

struct ManagedMigrationPaths {
    database: PathBuf,
    storage: PathBuf,
    temporary: PathBuf,
    framework_cache: PathBuf,
    snapshots: PathBuf,
    lifecycle_lock: PathBuf,
    active_journal: PathBuf,
}

trait MigrationCommandLauncher {
    fn inspect(&self, database: &Path) -> Result<MigrationInspection, StartupMigrationError>;
    fn snapshot(
        &self,
        database: &Path,
        snapshot: &Path,
        snapshot_root: &Path,
    ) -> Result<MigrationInspection, StartupMigrationError>;
    fn migrate_forward(&self, database: &Path) -> Result<(), StartupMigrationError>;
}

struct PhpMigrationCommandLauncher<'a> {
    resources: &'a VerifiedMigrationResources,
    config: &'a StartupMigrationGateConfig,
    paths: &'a ManagedMigrationPaths,
}

pub fn run_startup_migration_gate(
    resources: &VerifiedMigrationResources,
    config: &StartupMigrationGateConfig,
    logger: Arc<RuntimeLogger>,
) -> Result<StartupMigrationOutcome, StartupMigrationError> {
    let paths = validate_gate_configuration(resources, config)?;
    let _lease = MigrationLifecycleLease::acquire(&paths.lifecycle_lock)?;
    let launcher = PhpMigrationCommandLauncher {
        resources,
        config,
        paths: &paths,
    };
    coordinate_startup_migration_gate(resources, config, &paths, &launcher, logger)
}

fn coordinate_startup_migration_gate(
    resources: &VerifiedMigrationResources,
    config: &StartupMigrationGateConfig,
    paths: &ManagedMigrationPaths,
    launcher: &dyn MigrationCommandLauncher,
    logger: Arc<RuntimeLogger>,
) -> Result<StartupMigrationOutcome, StartupMigrationError> {
    let recovered =
        recover_interrupted_migration(resources, config, paths, launcher, Arc::clone(&logger))?;

    let inspection = launcher.inspect(&paths.database)?;
    match classify_inspection(resources, &inspection)? {
        DatabaseMigrationState::Target => {
            prune_safety_snapshots(&paths.snapshots, None, &logger);
            Ok(StartupMigrationOutcome::Noop)
        }
        DatabaseMigrationState::Pending => {
            run_forward_migration(resources, config, paths, launcher, &logger)?;
            prune_safety_snapshots(&paths.snapshots, None, &logger);
            Ok(if recovered {
                StartupMigrationOutcome::RecoveredThenMigrated
            } else {
                StartupMigrationOutcome::Migrated
            })
        }
    }
}

fn validate_gate_configuration(
    resources: &VerifiedMigrationResources,
    config: &StartupMigrationGateConfig,
) -> Result<ManagedMigrationPaths, StartupMigrationError> {
    if config.application_version != resources.application_version
        || config.app_key.len() < 32
        || Uuid::parse_str(&config.installation_id)
            .map(|uuid| uuid.hyphenated().to_string() != config.installation_id)
            .unwrap_or(true)
        || config.command_timeout < Duration::from_secs(5)
        || config.command_timeout > Duration::from_secs(30 * 60)
    {
        return Err(StartupMigrationError::new(
            "migration_configuration_invalid",
            "native migration configuration is invalid",
        ));
    }

    let app_data_root = canonical_plain_directory(&config.app_data_root)?;
    let database = canonical_regular_file(&config.database_path)?;
    let storage = canonical_plain_directory(&config.storage_path)?;
    let temporary = canonical_plain_directory(&config.temporary_directory)?;
    let framework_cache = canonical_plain_directory(&config.framework_cache_directory)?;
    if database != app_data_root.join("data/database.sqlite")
        || storage != app_data_root.join("storage")
        || temporary != app_data_root.join("tmp")
        || framework_cache != app_data_root.join("cache")
    {
        return Err(StartupMigrationError::new(
            "migration_configuration_invalid",
            "native migration writable paths escaped AppData",
        ));
    }
    validate_sqlite_header(&database)?;

    let private_root = canonical_child_directory(&storage, "app/private")?;
    let recovery_requested = private_root.join("migration-recovery");
    ensure_private_directory(&recovery_requested)?;
    let recovery_root = canonical_plain_directory(&recovery_requested)?;
    if recovery_root != config.recovery_root {
        let configured = config.recovery_root.canonicalize().map_err(|_| {
            StartupMigrationError::new(
                "migration_configuration_invalid",
                "migration recovery root is unavailable",
            )
        })?;
        if configured != recovery_root {
            return Err(StartupMigrationError::new(
                "migration_configuration_invalid",
                "migration recovery root escaped its fixed location",
            ));
        }
    }
    let snapshots_requested = recovery_root.join("snapshots");
    ensure_private_directory(&snapshots_requested)?;
    let snapshots = canonical_plain_directory(&snapshots_requested)?;

    Ok(ManagedMigrationPaths {
        database,
        storage,
        temporary,
        framework_cache,
        snapshots,
        lifecycle_lock: private_root.join(LIFECYCLE_LOCK),
        active_journal: recovery_root.join(ACTIVE_JOURNAL),
    })
}

struct MigrationLifecycleLease {
    file: File,
}

impl MigrationLifecycleLease {
    fn acquire(path: &Path) -> Result<Self, StartupMigrationError> {
        if let Ok(metadata) = fs::symlink_metadata(path) {
            validate_regular_metadata(path, &metadata, false)?;
        }
        let file = open_managed_lock_file(path)?;
        validate_open_file_identity(path, &file)?;
        file.try_lock_exclusive().map_err(|error| {
            if error.kind() == io::ErrorKind::WouldBlock {
                StartupMigrationError::new(
                    "migration_lock_contended",
                    "migration/restore lifecycle lock is already held",
                )
            } else {
                StartupMigrationError::new(
                    "migration_lock_unavailable",
                    format!("acquire migration/restore lifecycle lock: {error}"),
                )
            }
        })?;
        validate_open_file_identity(path, &file)?;
        Ok(Self { file })
    }
}

impl Drop for MigrationLifecycleLease {
    fn drop(&mut self) {
        let _ = FileExt::unlock(&self.file);
    }
}

fn run_forward_migration(
    resources: &VerifiedMigrationResources,
    config: &StartupMigrationGateConfig,
    paths: &ManagedMigrationPaths,
    launcher: &dyn MigrationCommandLauncher,
    logger: &RuntimeLogger,
) -> Result<(), StartupMigrationError> {
    ensure_snapshot_space(paths)?;
    let operation_id = Uuid::new_v4().hyphenated().to_string();
    let snapshot_filename = format!("migration-safety-{operation_id}.sqlite");
    let snapshot_path = paths.snapshots.join(&snapshot_filename);
    let now = unix_time();
    let mut journal = MigrationJournalRecord {
        schema_version: SCHEMA_VERSION,
        operation_id,
        phase: JournalPhase::SnapshotStarted,
        application_version: config.application_version.clone(),
        migration_set_sha256: resources.migration_set_sha256.clone(),
        migration_contract_sha256: resources.migration_contract_sha256.clone(),
        installation_binding_sha256: installation_binding(&config.installation_id),
        snapshot_filename,
        snapshot_sha256: None,
        snapshot_size: None,
        failure_code: None,
        created_at_unix: now,
        updated_at_unix: now,
    };
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)?;

    let snapshot_inspection =
        match launcher.snapshot(&paths.database, &snapshot_path, &paths.snapshots) {
            Ok(inspection) => inspection,
            Err(error) => {
                journal.failure_code = Some(error.code().to_owned());
                let _ = write_signed_journal(&paths.active_journal, &journal, &config.app_key);
                return Err(error);
            }
        };
    if classify_inspection(resources, &snapshot_inspection)? != DatabaseMigrationState::Pending {
        return Err(StartupMigrationError::new(
            "migration_snapshot_invalid",
            "safety snapshot did not preserve the pending migration state",
        ));
    }
    let snapshot_size = file_size(&snapshot_path)?;
    let snapshot_sha256 = sha256_file(&snapshot_path)?;
    validate_sqlite_header(&snapshot_path)?;
    sync_file_and_parent(&snapshot_path)?;
    journal.snapshot_sha256 = Some(snapshot_sha256);
    journal.snapshot_size = Some(snapshot_size);
    journal.phase = JournalPhase::SafetyBackupVerified;
    journal.updated_at_unix = unix_time();
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)?;

    journal.phase = JournalPhase::MigrationStarted;
    journal.updated_at_unix = unix_time();
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)?;
    if let Err(error) = launcher.migrate_forward(&paths.database) {
        journal.failure_code = Some(error.code().to_owned());
        return rollback_after_failure(resources, config, paths, launcher, journal, &error, logger);
    }

    journal.phase = JournalPhase::MigrationProcessSucceeded;
    journal.updated_at_unix = unix_time();
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)?;
    let postflight = match launcher.inspect(&paths.database) {
        Ok(inspection) => inspection,
        Err(error) => {
            journal.failure_code = Some(error.code().to_owned());
            return rollback_after_failure(
                resources, config, paths, launcher, journal, &error, logger,
            );
        }
    };
    if let Err(error) = require_target_inspection(resources, &postflight) {
        journal.failure_code = Some(error.code().to_owned());
        return rollback_after_failure(resources, config, paths, launcher, journal, &error, logger);
    }

    journal.phase = JournalPhase::PostValidationSucceeded;
    journal.updated_at_unix = unix_time();
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)?;
    journal.phase = JournalPhase::Committed;
    journal.updated_at_unix = unix_time();
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)?;
    remove_managed_file(&paths.active_journal)?;
    logger.info("Forward-only startup migration passed SQLite post-validation");
    Ok(())
}

fn rollback_after_failure(
    resources: &VerifiedMigrationResources,
    config: &StartupMigrationGateConfig,
    paths: &ManagedMigrationPaths,
    launcher: &dyn MigrationCommandLauncher,
    journal: MigrationJournalRecord,
    original: &StartupMigrationError,
    logger: &RuntimeLogger,
) -> Result<(), StartupMigrationError> {
    match restore_verified_snapshot(resources, config, paths, launcher, journal) {
        Ok(()) => {
            logger.warn("Startup migration failed; verified pre-migration database was restored");
            Err(StartupMigrationError::new(
                "migration_failed_rolled_back",
                format!("{}; verified safety snapshot restored", original.code()),
            ))
        }
        Err(error) => {
            logger.error(error.code());
            Err(StartupMigrationError::new(
                "migration_recovery_required",
                format!(
                    "{}; automatic safety restore returned {}",
                    original.code(),
                    error.code()
                ),
            ))
        }
    }
}

fn recover_interrupted_migration(
    resources: &VerifiedMigrationResources,
    config: &StartupMigrationGateConfig,
    paths: &ManagedMigrationPaths,
    launcher: &dyn MigrationCommandLauncher,
    logger: Arc<RuntimeLogger>,
) -> Result<bool, StartupMigrationError> {
    let Some(mut journal) = read_signed_journal(&paths.active_journal, &config.app_key)? else {
        return Ok(false);
    };
    validate_journal(&journal, config, paths)?;
    logger.warn("Interrupted startup migration journal detected; recovery remains offline");

    if journal.phase == JournalPhase::SnapshotStarted {
        let inspection = launcher.inspect(&paths.database).map_err(|error| {
            StartupMigrationError::new(
                "migration_recovery_required",
                format!(
                    "incomplete safety snapshot and current database returned {}",
                    error.code()
                ),
            )
        })?;
        classify_inspection(resources, &inspection)?;
        let partial = paths.snapshots.join(&journal.snapshot_filename);
        if partial.exists() {
            remove_managed_file(&partial)?;
        }
        remove_managed_file(&paths.active_journal)?;
        return Ok(true);
    }

    if journal.phase == JournalPhase::Committed {
        let inspection = launcher.inspect(&paths.database)?;
        require_target_inspection(resources, &inspection)?;
        remove_managed_file(&paths.active_journal)?;
        return Ok(true);
    }

    if journal.phase == JournalPhase::Restored {
        let snapshot = verified_journal_snapshot(&journal, paths)?;
        if sha256_file(&paths.database)? != sha256_file(&snapshot)? {
            return Err(StartupMigrationError::new(
                "migration_recovery_required",
                "restored journal does not match the active database",
            ));
        }
        let inspection = launcher.inspect(&paths.database)?;
        classify_inspection(resources, &inspection)?;
        remove_managed_file(&paths.active_journal)?;
        return Ok(true);
    }

    journal.failure_code = Some("migration_interrupted".to_owned());
    restore_verified_snapshot(resources, config, paths, launcher, journal)?;
    let restored =
        read_signed_journal(&paths.active_journal, &config.app_key)?.ok_or_else(|| {
            StartupMigrationError::new(
                "migration_recovery_required",
                "recovery journal disappeared",
            )
        })?;
    if restored.phase != JournalPhase::Restored {
        return Err(StartupMigrationError::new(
            "migration_recovery_required",
            "recovery did not reach the restored phase",
        ));
    }
    remove_managed_file(&paths.active_journal)?;
    Ok(true)
}

fn restore_verified_snapshot(
    resources: &VerifiedMigrationResources,
    config: &StartupMigrationGateConfig,
    paths: &ManagedMigrationPaths,
    launcher: &dyn MigrationCommandLauncher,
    mut journal: MigrationJournalRecord,
) -> Result<(), StartupMigrationError> {
    let snapshot = verified_journal_snapshot(&journal, paths)?;
    let snapshot_inspection = launcher.inspect(&snapshot)?;
    classify_inspection(resources, &snapshot_inspection)?;

    journal.phase = JournalPhase::RestoreStarted;
    journal.updated_at_unix = unix_time();
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)?;

    let temporary = paths.database.with_extension(format!(
        "sqlite.migration-restore-{}.tmp",
        Uuid::new_v4().hyphenated()
    ));
    copy_new_synced(&snapshot, &temporary)?;
    if sha256_file(&temporary)? != journal.snapshot_sha256.clone().unwrap_or_default() {
        let _ = remove_managed_file(&temporary);
        return Err(StartupMigrationError::new(
            "migration_recovery_snapshot_invalid",
            "staged restore copy differs from the verified snapshot",
        ));
    }
    remove_sqlite_sidecars(&paths.database)?;
    replace_file(&temporary, &paths.database)?;
    sync_parent(&paths.database)?;
    validate_sqlite_header(&paths.database)?;
    let restored = launcher.inspect(&paths.database)?;
    classify_inspection(resources, &restored)?;
    if sha256_file(&paths.database)? != journal.snapshot_sha256.clone().unwrap_or_default() {
        return Err(StartupMigrationError::new(
            "migration_recovery_required",
            "restored database does not match its safety snapshot",
        ));
    }

    journal.phase = JournalPhase::Restored;
    journal.updated_at_unix = unix_time();
    write_signed_journal(&paths.active_journal, &journal, &config.app_key)
}

fn verified_journal_snapshot(
    journal: &MigrationJournalRecord,
    paths: &ManagedMigrationPaths,
) -> Result<PathBuf, StartupMigrationError> {
    let expected_hash = journal.snapshot_sha256.as_deref().ok_or_else(|| {
        StartupMigrationError::new(
            "migration_recovery_snapshot_invalid",
            "recovery journal has no verified snapshot hash",
        )
    })?;
    let expected_size = journal.snapshot_size.ok_or_else(|| {
        StartupMigrationError::new(
            "migration_recovery_snapshot_invalid",
            "recovery journal has no verified snapshot size",
        )
    })?;
    validate_sha256(expected_hash)?;
    validate_snapshot_filename(&journal.snapshot_filename)?;
    let snapshot = canonical_relative_file(&paths.snapshots, &journal.snapshot_filename)?;
    if file_size(&snapshot)? != expected_size || sha256_file(&snapshot)? != expected_hash {
        return Err(StartupMigrationError::new(
            "migration_recovery_snapshot_invalid",
            "recovery snapshot hash or size mismatch",
        ));
    }
    validate_sqlite_header(&snapshot)?;
    Ok(snapshot)
}

fn classify_inspection(
    resources: &VerifiedMigrationResources,
    inspection: &MigrationInspection,
) -> Result<DatabaseMigrationState, StartupMigrationError> {
    validate_inspection_envelope(resources, inspection)?;
    if !inspection.integrity_ok
        || !inspection.foreign_keys_ok
        || !inspection.migrations_table_present
    {
        return Err(StartupMigrationError::new(
            "migration_database_invalid",
            "SQLite preflight integrity, foreign keys, or migration repository failed",
        ));
    }
    if inspection.applied_migrations.len() > resources.expected_migrations.len()
        || resources.expected_migrations[..inspection.applied_migrations.len()]
            != inspection.applied_migrations
    {
        return Err(StartupMigrationError::new(
            "migration_database_newer_than_release",
            "applied migrations are not an exact prefix of the bundled migration set",
        ));
    }
    let expected_pending =
        resources.expected_migrations[inspection.applied_migrations.len()..].to_vec();
    if inspection.pending_migrations != expected_pending {
        return Err(StartupMigrationError::new(
            "migration_database_invalid",
            "reported pending migrations do not match the exact migration prefix",
        ));
    }
    if inspection.pending_migrations.is_empty() {
        if !inspection.required_tables_present || !inspection.missing_required_tables.is_empty() {
            return Err(StartupMigrationError::new(
                "migration_database_invalid",
                "post-migration required tables are missing",
            ));
        }
        Ok(DatabaseMigrationState::Target)
    } else {
        Ok(DatabaseMigrationState::Pending)
    }
}

fn require_target_inspection(
    resources: &VerifiedMigrationResources,
    inspection: &MigrationInspection,
) -> Result<(), StartupMigrationError> {
    if classify_inspection(resources, inspection)? != DatabaseMigrationState::Target {
        return Err(StartupMigrationError::new(
            "migration_postflight_failed",
            "forward migration did not reach the exact target state",
        ));
    }
    Ok(())
}

fn validate_inspection_envelope(
    resources: &VerifiedMigrationResources,
    inspection: &MigrationInspection,
) -> Result<(), StartupMigrationError> {
    if inspection.protocol != "medismart-native-migration-state"
        || inspection.schema_version != SCHEMA_VERSION
        || !matches!(inspection.operation.as_str(), "inspect" | "snapshot")
        || inspection.expected_migrations != resources.expected_migrations
        || !matches!(inspection.journal_mode.as_str(), "delete" | "wal")
        || inspection.required_tables_present != inspection.missing_required_tables.is_empty()
    {
        return Err(StartupMigrationError::new(
            "migration_helper_contract_invalid",
            "native migration helper returned an inconsistent contract",
        ));
    }
    if inspection.operation == "snapshot" {
        let checkpoint = inspection.checkpoint.as_ref().ok_or_else(|| {
            StartupMigrationError::new(
                "migration_helper_contract_invalid",
                "snapshot result omitted checkpoint evidence",
            )
        })?;
        if !inspection.snapshot_created
            || checkpoint.busy != 0
            || checkpoint.log != checkpoint.checkpointed
        {
            return Err(StartupMigrationError::new(
                "migration_snapshot_invalid",
                "snapshot checkpoint evidence is invalid",
            ));
        }
    } else if inspection.snapshot_created || inspection.checkpoint.is_some() {
        return Err(StartupMigrationError::new(
            "migration_helper_contract_invalid",
            "inspection result claimed snapshot authority",
        ));
    }
    let mut seen = BTreeSet::new();
    for name in inspection
        .expected_migrations
        .iter()
        .chain(inspection.applied_migrations.iter())
        .chain(inspection.pending_migrations.iter())
    {
        if !valid_migration_name(name) {
            return Err(StartupMigrationError::new(
                "migration_helper_contract_invalid",
                "migration helper returned an invalid migration name",
            ));
        }
    }
    for name in &inspection.applied_migrations {
        if !seen.insert(name.to_ascii_lowercase()) {
            return Err(StartupMigrationError::new(
                "migration_database_invalid",
                "applied migration names are duplicated",
            ));
        }
    }
    Ok(())
}

fn valid_migration_name(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 240
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
}

impl MigrationCommandLauncher for PhpMigrationCommandLauncher<'_> {
    fn inspect(&self, database: &Path) -> Result<MigrationInspection, StartupMigrationError> {
        let output = self.run_helper("inspect", database, None, None)?;
        parse_inspection(&output, "inspect")
    }

    fn snapshot(
        &self,
        database: &Path,
        snapshot: &Path,
        snapshot_root: &Path,
    ) -> Result<MigrationInspection, StartupMigrationError> {
        let output = self.run_helper("snapshot", database, Some(snapshot), Some(snapshot_root))?;
        parse_inspection(&output, "snapshot")
    }

    fn migrate_forward(&self, database: &Path) -> Result<(), StartupMigrationError> {
        self.verify_execution_surface()?;
        let mut command = self.base_command(database);
        command
            .arg(&self.resources.artisan_path)
            .arg("migrate")
            .arg("--force")
            .arg("--no-interaction")
            .arg("--no-ansi");
        let result = run_bounded_command(command, self.config.command_timeout)?;
        if !result.status.success() || result.stdout_truncated || result.stderr_truncated {
            return Err(StartupMigrationError::new(
                "migration_process_failed",
                "fixed forward Laravel migration command did not complete successfully",
            ));
        }
        Ok(())
    }
}

impl PhpMigrationCommandLauncher<'_> {
    fn run_helper(
        &self,
        operation: &'static str,
        database: &Path,
        snapshot: Option<&Path>,
        snapshot_root: Option<&Path>,
    ) -> Result<Vec<u8>, StartupMigrationError> {
        self.verify_execution_surface()?;
        let mut command = self.base_command(database);
        command
            .arg(&self.resources.artisan_path)
            .arg("medismart:migration:native-state")
            .arg(operation)
            .arg("--no-interaction")
            .arg("--no-ansi")
            .env("MEDISMART_NATIVE_MIGRATION", "1");
        if let (Some(snapshot), Some(snapshot_root)) = (snapshot, snapshot_root) {
            command
                .env("MEDISMART_MIGRATION_SNAPSHOT", snapshot)
                .env("MEDISMART_MIGRATION_RECOVERY_ROOT", snapshot_root);
        }
        let result = run_bounded_command(command, self.config.command_timeout)?;
        if !result.status.success() || result.stdout_truncated || result.stderr_truncated {
            return Err(StartupMigrationError::new(
                "migration_helper_failed",
                format!("fixed native migration helper operation {operation} failed"),
            ));
        }
        Ok(result.stdout)
    }

    fn verify_execution_surface(&self) -> Result<(), StartupMigrationError> {
        if !self.resources.resource_root.is_dir()
            || !self.resources.helper_path.is_file()
            || !self.resources.artisan_path.is_file()
            || !self.resources.php_binary.is_file()
            || !self
                .resources
                .helper_path
                .starts_with(&self.resources.app_root)
        {
            return Err(StartupMigrationError::new(
                "migration_runtime_mismatch",
                "fixed migration execution surface is unavailable",
            ));
        }
        Ok(())
    }

    fn base_command(&self, database: &Path) -> Command {
        let mut command = Command::new(&self.resources.php_binary);
        command
            .env_clear()
            .current_dir(&self.resources.app_root)
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .env("APP_ENV", "production")
            .env("APP_DEBUG", "false")
            .env("APP_KEY", &self.config.app_key)
            .env("MEDISMART_DESKTOP_SUPERVISED", "true")
            .env(
                "MEDISMART_DESKTOP_INSTALLATION_ID",
                &self.config.installation_id,
            )
            .env("MEDISMART_VERSION", &self.config.application_version)
            .env("MEDISMART_QUEUE_WORKER_STATUS", "stopped")
            .env("MEDISMART_SCHEDULER_STATUS", "stopped")
            .env("MEDISMART_LAN_LISTENER_STATUS", "stopped")
            .env("DB_CONNECTION", "sqlite")
            .env("DB_DATABASE", database)
            .env("MEDISMART_MIGRATION_DATABASE", database)
            .env("LARAVEL_STORAGE_PATH", &self.paths.storage)
            .env("QUEUE_CONNECTION", "sync")
            .env("CACHE_STORE", "file")
            .env("SESSION_DRIVER", "array")
            .env("TELESCOPE_ENABLED", "false")
            .env("INERTIA_DEVTOOLS_ENABLED", "false")
            .env("LOG_CHANNEL", "single")
            .env("LOG_LEVEL", "warning")
            .env("TMP", &self.paths.temporary)
            .env("TEMP", &self.paths.temporary)
            .env("TMPDIR", &self.paths.temporary)
            .env(
                "APP_SERVICES_CACHE",
                self.paths.framework_cache.join("services.php"),
            )
            .env(
                "APP_PACKAGES_CACHE",
                self.paths.framework_cache.join("packages.php"),
            )
            .env(
                "APP_CONFIG_CACHE",
                self.paths.framework_cache.join("config.php"),
            )
            .env(
                "APP_ROUTES_CACHE",
                self.paths.framework_cache.join("routes.php"),
            )
            .env(
                "APP_EVENTS_CACHE",
                self.paths.framework_cache.join("events.php"),
            )
            .env(
                "PHPRC",
                self.resources
                    .php_binary
                    .parent()
                    .unwrap_or(&self.resources.resource_root),
            )
            .env("PHP_INI_SCAN_DIR", OsString::new());
        copy_safe_windows_environment(&mut command);
        configure_platform_process(&mut command);
        command
    }
}

struct CommandResult {
    status: ExitStatus,
    stdout: Vec<u8>,
    stdout_truncated: bool,
    stderr_truncated: bool,
}

fn run_bounded_command(
    mut command: Command,
    timeout: Duration,
) -> Result<CommandResult, StartupMigrationError> {
    let mut child = command.spawn().map_err(|error| {
        StartupMigrationError::new(
            "migration_process_spawn_failed",
            format!("spawn fixed bundled PHP command: {error}"),
        )
    })?;
    let stdout = child.stdout.take().map(spawn_bounded_reader);
    let stderr = child.stderr.take().map(spawn_bounded_reader);
    let deadline = Instant::now() + timeout;
    let status = loop {
        match child.try_wait() {
            Ok(Some(status)) => break status,
            Ok(None) if Instant::now() >= deadline => {
                let _ = child.kill();
                let _ = child.wait();
                let _ = join_bounded_reader(stdout);
                let _ = join_bounded_reader(stderr);
                return Err(StartupMigrationError::new(
                    "migration_process_timeout",
                    "fixed bundled PHP command exceeded its absolute deadline",
                ));
            }
            Ok(None) => thread::sleep(Duration::from_millis(25)),
            Err(error) => {
                let _ = child.kill();
                let _ = child.wait();
                return Err(StartupMigrationError::new(
                    "migration_process_failed",
                    format!("inspect fixed bundled PHP command: {error}"),
                ));
            }
        }
    };
    let (stdout, stdout_truncated) = join_bounded_reader(stdout);
    let (_, stderr_truncated) = join_bounded_reader(stderr);
    Ok(CommandResult {
        status,
        stdout,
        stdout_truncated,
        stderr_truncated,
    })
}

fn spawn_bounded_reader<R: Read + Send + 'static>(
    mut reader: R,
) -> thread::JoinHandle<(Vec<u8>, bool)> {
    thread::spawn(move || {
        let mut output = Vec::with_capacity(4096);
        let mut chunk = [0_u8; 4096];
        let mut truncated = false;
        loop {
            match reader.read(&mut chunk) {
                Ok(0) => break,
                Ok(count) => {
                    let available = MAX_COMMAND_OUTPUT_BYTES.saturating_sub(output.len());
                    output.extend_from_slice(&chunk[..count.min(available)]);
                    if count > available {
                        truncated = true;
                    }
                }
                Err(_) => {
                    truncated = true;
                    break;
                }
            }
        }
        (output, truncated)
    })
}

fn join_bounded_reader(reader: Option<thread::JoinHandle<(Vec<u8>, bool)>>) -> (Vec<u8>, bool) {
    reader.map_or_else(
        || (Vec::new(), true),
        |reader| reader.join().unwrap_or_else(|_| (Vec::new(), true)),
    )
}

fn parse_inspection(
    bytes: &[u8],
    expected_operation: &str,
) -> Result<MigrationInspection, StartupMigrationError> {
    if bytes.is_empty() || bytes.len() > MAX_COMMAND_OUTPUT_BYTES {
        return Err(StartupMigrationError::new(
            "migration_helper_contract_invalid",
            "native migration helper output exceeded its bounds",
        ));
    }
    let inspection: MigrationInspection = serde_json::from_slice(bytes).map_err(|error| {
        StartupMigrationError::new(
            "migration_helper_contract_invalid",
            format!("decode native migration helper result: {error}"),
        )
    })?;
    if inspection.operation != expected_operation {
        return Err(StartupMigrationError::new(
            "migration_helper_contract_invalid",
            "native migration helper operation did not match the request",
        ));
    }
    Ok(inspection)
}

fn copy_safe_windows_environment(command: &mut Command) {
    for name in ["SystemRoot", "WINDIR"] {
        if let Some(value) = std::env::var_os(name) {
            let path = PathBuf::from(&value);
            if path.is_absolute() && path.is_dir() {
                command.env(name, value);
            }
        }
    }
}

#[cfg(windows)]
fn configure_platform_process(command: &mut Command) {
    use std::os::windows::process::CommandExt;
    use windows_sys::Win32::System::Threading::{CREATE_NEW_PROCESS_GROUP, CREATE_NO_WINDOW};

    command.creation_flags(CREATE_NEW_PROCESS_GROUP | CREATE_NO_WINDOW);
}

#[cfg(not(windows))]
fn configure_platform_process(_command: &mut Command) {}

fn write_signed_journal(
    path: &Path,
    record: &MigrationJournalRecord,
    app_key: &str,
) -> Result<(), StartupMigrationError> {
    let record_bytes = serde_json::to_vec(record).map_err(|error| {
        StartupMigrationError::new(
            "migration_journal_invalid",
            format!("encode migration journal record: {error}"),
        )
    })?;
    let document = SignedMigrationJournal {
        record: record.clone(),
        hmac_sha256: hex_lower(&hmac_sha256(app_key.as_bytes(), &record_bytes)),
    };
    let bytes = serde_json::to_vec_pretty(&document).map_err(|error| {
        StartupMigrationError::new(
            "migration_journal_invalid",
            format!("encode signed migration journal: {error}"),
        )
    })?;
    if bytes.len() as u64 > MAX_JOURNAL_BYTES {
        return Err(StartupMigrationError::new(
            "migration_journal_invalid",
            "migration journal exceeded its size bound",
        ));
    }
    write_file_atomically(path, &bytes)
}

fn read_signed_journal(
    path: &Path,
    app_key: &str,
) -> Result<Option<MigrationJournalRecord>, StartupMigrationError> {
    match fs::symlink_metadata(path) {
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(None),
        Err(error) => {
            return Err(StartupMigrationError::new(
                "migration_recovery_required",
                format!("inspect active migration journal: {error}"),
            ))
        }
        Ok(metadata) => validate_regular_metadata(path, &metadata, false)?,
    }
    let bytes = read_bounded_regular_file(path, MAX_JOURNAL_BYTES)?;
    let document: SignedMigrationJournal = serde_json::from_slice(&bytes).map_err(|error| {
        StartupMigrationError::new(
            "migration_recovery_required",
            format!("decode active migration journal: {error}"),
        )
    })?;
    validate_sha256(&document.hmac_sha256)?;
    let record_bytes = serde_json::to_vec(&document.record).map_err(|error| {
        StartupMigrationError::new(
            "migration_recovery_required",
            format!("encode active migration record for authentication: {error}"),
        )
    })?;
    let expected = hex_lower(&hmac_sha256(app_key.as_bytes(), &record_bytes));
    if !constant_time_eq(expected.as_bytes(), document.hmac_sha256.as_bytes()) {
        return Err(StartupMigrationError::new(
            "migration_recovery_required",
            "active migration journal authentication failed",
        ));
    }
    Ok(Some(document.record))
}

fn validate_journal(
    journal: &MigrationJournalRecord,
    config: &StartupMigrationGateConfig,
    paths: &ManagedMigrationPaths,
) -> Result<(), StartupMigrationError> {
    if journal.schema_version != SCHEMA_VERSION
        || Uuid::parse_str(&journal.operation_id)
            .map(|uuid| uuid.hyphenated().to_string() != journal.operation_id)
            .unwrap_or(true)
        || journal.application_version.len() > 64
        || journal.installation_binding_sha256 != installation_binding(&config.installation_id)
        || journal.created_at_unix == 0
        || journal.updated_at_unix < journal.created_at_unix
        || journal.updated_at_unix > unix_time().saturating_add(300)
    {
        return Err(StartupMigrationError::new(
            "migration_recovery_required",
            "active migration journal identity is invalid",
        ));
    }
    validate_sha256(&journal.migration_set_sha256)?;
    validate_sha256(&journal.migration_contract_sha256)?;
    validate_snapshot_filename(&journal.snapshot_filename)?;
    if journal.snapshot_sha256.is_some() != journal.snapshot_size.is_some()
        || journal
            .snapshot_size
            .is_some_and(|size| size < SQLITE_HEADER.len() as u64)
    {
        return Err(StartupMigrationError::new(
            "migration_recovery_required",
            "active migration journal snapshot evidence is incomplete",
        ));
    }
    if let Some(digest) = &journal.snapshot_sha256 {
        validate_sha256(digest)?;
    }
    let expected_snapshot = paths.snapshots.join(&journal.snapshot_filename);
    if expected_snapshot.parent() != Some(paths.snapshots.as_path()) {
        return Err(StartupMigrationError::new(
            "migration_recovery_required",
            "active migration journal snapshot escaped its managed root",
        ));
    }
    Ok(())
}

fn installation_binding(installation_id: &str) -> String {
    hex_lower(&Sha256::digest(
        format!("medismart-migration-installation-v1\n{installation_id}").as_bytes(),
    ))
}

fn hmac_sha256(key: &[u8], message: &[u8]) -> [u8; 32] {
    let mut key_block = [0_u8; 64];
    if key.len() > key_block.len() {
        key_block[..32].copy_from_slice(&Sha256::digest(key));
    } else {
        key_block[..key.len()].copy_from_slice(key);
    }
    let mut inner = [0x36_u8; 64];
    let mut outer = [0x5c_u8; 64];
    for index in 0..key_block.len() {
        inner[index] ^= key_block[index];
        outer[index] ^= key_block[index];
    }
    let mut inner_hash = Sha256::new();
    inner_hash.update(inner);
    inner_hash.update(message);
    let inner_digest = inner_hash.finalize();
    let mut outer_hash = Sha256::new();
    outer_hash.update(outer);
    outer_hash.update(inner_digest);
    outer_hash.finalize().into()
}

fn constant_time_eq(left: &[u8], right: &[u8]) -> bool {
    if left.len() != right.len() {
        return false;
    }
    left.iter()
        .zip(right)
        .fold(0_u8, |difference, (left, right)| {
            difference | (left ^ right)
        })
        == 0
}

fn canonical_plain_directory(path: &Path) -> Result<PathBuf, StartupMigrationError> {
    ensure_no_reparse_components(path)?;
    let metadata = fs::symlink_metadata(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_configuration_invalid",
            format!("inspect managed directory: {error}"),
        )
    })?;
    if metadata.file_type().is_symlink()
        || metadata_is_reparse_point(&metadata)
        || !metadata.is_dir()
    {
        return Err(StartupMigrationError::new(
            "migration_configuration_invalid",
            "managed migration directory is not a plain directory",
        ));
    }
    path.canonicalize().map_err(|error| {
        StartupMigrationError::new(
            "migration_configuration_invalid",
            format!("resolve managed directory: {error}"),
        )
    })
}

fn canonical_child_directory(
    root: &Path,
    relative: &str,
) -> Result<PathBuf, StartupMigrationError> {
    validate_relative_path(relative)?;
    let candidate = root.join(relative);
    let canonical = canonical_plain_directory(&candidate)?;
    if !canonical.starts_with(root) {
        return Err(resource_error(
            "packaged directory escaped its component root",
        ));
    }
    Ok(canonical)
}

fn canonical_child_file(root: &Path, relative: &str) -> Result<PathBuf, StartupMigrationError> {
    canonical_relative_file(root, relative)
}

fn canonical_relative_file(root: &Path, relative: &str) -> Result<PathBuf, StartupMigrationError> {
    validate_relative_path(relative)?;
    let candidate = root.join(relative);
    let canonical = canonical_regular_file(&candidate)?;
    if !canonical.starts_with(root) {
        return Err(resource_error("managed file escaped its component root"));
    }
    Ok(canonical)
}

fn canonical_regular_file(path: &Path) -> Result<PathBuf, StartupMigrationError> {
    ensure_no_reparse_components(path)?;
    let metadata = fs::symlink_metadata(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_database_invalid",
            format!("inspect managed file: {error}"),
        )
    })?;
    validate_regular_metadata(path, &metadata, false)?;
    let canonical = path.canonicalize().map_err(|error| {
        StartupMigrationError::new(
            "migration_database_invalid",
            format!("resolve managed file: {error}"),
        )
    })?;
    if canonical.parent().is_none() {
        return Err(StartupMigrationError::new(
            "migration_database_invalid",
            "managed file has no parent",
        ));
    }
    Ok(canonical)
}

fn secure_metadata(path: &Path) -> Result<fs::Metadata, StartupMigrationError> {
    let metadata = fs::symlink_metadata(path)
        .map_err(|error| resource_error(format!("inspect packaged resource: {error}")))?;
    if metadata.file_type().is_symlink() || metadata_is_reparse_point(&metadata) {
        return Err(resource_error(
            "packaged resource contains a link or reparse point",
        ));
    }
    Ok(metadata)
}

fn validate_regular_metadata(
    path: &Path,
    metadata: &fs::Metadata,
    allow_multiple_links: bool,
) -> Result<(), StartupMigrationError> {
    if metadata.file_type().is_symlink()
        || metadata_is_reparse_point(metadata)
        || !metadata.is_file()
    {
        return Err(StartupMigrationError::new(
            "migration_filesystem_unsafe",
            format!(
                "managed path is not a plain regular file: {}",
                path.display()
            ),
        ));
    }
    #[cfg(unix)]
    if !allow_multiple_links {
        use std::os::unix::fs::MetadataExt;
        if metadata.nlink() != 1 {
            return Err(StartupMigrationError::new(
                "migration_filesystem_unsafe",
                "managed file has multiple hard links",
            ));
        }
    }
    #[cfg(not(unix))]
    let _ = allow_multiple_links;
    Ok(())
}

#[cfg(windows)]
fn metadata_is_reparse_point(metadata: &fs::Metadata) -> bool {
    use std::os::windows::fs::MetadataExt;
    use windows_sys::Win32::Storage::FileSystem::FILE_ATTRIBUTE_REPARSE_POINT;

    metadata.file_attributes() & FILE_ATTRIBUTE_REPARSE_POINT != 0
}

#[cfg(not(windows))]
fn metadata_is_reparse_point(_metadata: &fs::Metadata) -> bool {
    false
}

fn ensure_no_reparse_components(path: &Path) -> Result<(), StartupMigrationError> {
    let mut current = PathBuf::new();
    for component in path.components() {
        current.push(component.as_os_str());
        if matches!(component, Component::Prefix(_) | Component::RootDir) {
            continue;
        }
        match fs::symlink_metadata(&current) {
            Ok(metadata)
                if metadata.file_type().is_symlink() || metadata_is_reparse_point(&metadata) =>
            {
                return Err(StartupMigrationError::new(
                    "migration_filesystem_unsafe",
                    "managed path contains a link or reparse point",
                ));
            }
            Ok(_) => {}
            Err(error) if error.kind() == io::ErrorKind::NotFound => break,
            Err(error) => {
                return Err(StartupMigrationError::new(
                    "migration_filesystem_unsafe",
                    format!("inspect managed path component: {error}"),
                ))
            }
        }
    }
    Ok(())
}

fn ensure_private_directory(path: &Path) -> Result<(), StartupMigrationError> {
    ensure_no_reparse_components(path)?;
    match fs::symlink_metadata(path) {
        Ok(metadata)
            if metadata.is_dir()
                && !metadata.file_type().is_symlink()
                && !metadata_is_reparse_point(&metadata) => {}
        Ok(_) => {
            return Err(StartupMigrationError::new(
                "migration_filesystem_unsafe",
                "managed recovery path is not a plain directory",
            ))
        }
        Err(error) if error.kind() == io::ErrorKind::NotFound => {
            fs::create_dir(path).map_err(|error| {
                StartupMigrationError::new(
                    "migration_runtime_io_failed",
                    format!("create managed migration directory: {error}"),
                )
            })?;
        }
        Err(error) => {
            return Err(StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("inspect managed migration directory: {error}"),
            ))
        }
    }
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        fs::set_permissions(path, fs::Permissions::from_mode(0o700)).map_err(|error| {
            StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("secure managed migration directory: {error}"),
            )
        })?;
    }
    Ok(())
}

fn open_managed_lock_file(path: &Path) -> Result<File, StartupMigrationError> {
    let mut options = OpenOptions::new();
    options.read(true).write(true).create(true);
    configure_secure_open(&mut options, true);
    options.open(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_lock_unavailable",
            format!("open migration/restore lifecycle lock: {error}"),
        )
    })
}

fn open_new_managed_file(path: &Path) -> Result<File, StartupMigrationError> {
    let mut options = OpenOptions::new();
    options.read(true).write(true).create_new(true);
    configure_secure_open(&mut options, true);
    options.open(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("create managed temporary file: {error}"),
        )
    })
}

fn open_read_no_follow(path: &Path) -> Result<File, StartupMigrationError> {
    let mut options = OpenOptions::new();
    options.read(true);
    configure_secure_open(&mut options, false);
    options.open(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("open managed file: {error}"),
        )
    })
}

fn open_readwrite_no_follow(path: &Path) -> Result<File, StartupMigrationError> {
    let mut options = OpenOptions::new();
    options.read(true).write(true);
    configure_secure_open(&mut options, false);
    options.open(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("open managed file for synchronization: {error}"),
        )
    })
}

#[cfg(unix)]
fn configure_secure_open(options: &mut OpenOptions, private_mode: bool) {
    use std::os::unix::fs::OpenOptionsExt;
    options.custom_flags(libc::O_NOFOLLOW);
    if private_mode {
        options.mode(0o600);
    }
}

#[cfg(windows)]
fn configure_secure_open(options: &mut OpenOptions, _private_mode: bool) {
    use std::os::windows::fs::OpenOptionsExt;
    use windows_sys::Win32::Storage::FileSystem::FILE_FLAG_OPEN_REPARSE_POINT;

    options.custom_flags(FILE_FLAG_OPEN_REPARSE_POINT);
}

#[cfg(not(any(unix, windows)))]
fn configure_secure_open(_options: &mut OpenOptions, _private_mode: bool) {}

fn validate_open_file_identity(path: &Path, file: &File) -> Result<(), StartupMigrationError> {
    let path_metadata = fs::symlink_metadata(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_filesystem_unsafe",
            format!("inspect opened managed file path: {error}"),
        )
    })?;
    validate_regular_metadata(path, &path_metadata, false)?;
    let file_metadata = file.metadata().map_err(|error| {
        StartupMigrationError::new(
            "migration_filesystem_unsafe",
            format!("inspect opened managed file handle: {error}"),
        )
    })?;
    if !file_metadata.is_file() {
        return Err(StartupMigrationError::new(
            "migration_filesystem_unsafe",
            "managed file handle is not regular",
        ));
    }
    #[cfg(unix)]
    {
        use std::os::unix::fs::MetadataExt;
        if file_metadata.nlink() != 1
            || file_metadata.dev() != path_metadata.dev()
            || file_metadata.ino() != path_metadata.ino()
        {
            return Err(StartupMigrationError::new(
                "migration_filesystem_unsafe",
                "managed file handle identity changed",
            ));
        }
    }
    #[cfg(windows)]
    validate_windows_link_count(file)?;
    Ok(())
}

#[cfg(windows)]
fn validate_windows_link_count(file: &File) -> Result<(), StartupMigrationError> {
    use std::os::windows::io::AsRawHandle;
    use windows_sys::Win32::Storage::FileSystem::{
        GetFileInformationByHandle, BY_HANDLE_FILE_INFORMATION,
    };

    let mut information = unsafe { std::mem::zeroed::<BY_HANDLE_FILE_INFORMATION>() };
    let result = unsafe { GetFileInformationByHandle(file.as_raw_handle() as _, &mut information) };
    if result == 0 || information.nNumberOfLinks != 1 {
        return Err(StartupMigrationError::new(
            "migration_filesystem_unsafe",
            "managed Windows file identity or link count is invalid",
        ));
    }
    Ok(())
}

fn read_bounded_regular_file(
    path: &Path,
    maximum_bytes: u64,
) -> Result<Vec<u8>, StartupMigrationError> {
    let file = open_read_no_follow(path)?;
    validate_open_file_identity(path, &file)?;
    let size = file
        .metadata()
        .map_err(|error| {
            StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("inspect managed file size: {error}"),
            )
        })?
        .len();
    if size == 0 || size > maximum_bytes {
        return Err(StartupMigrationError::new(
            "migration_resources_invalid",
            "managed file exceeded its size bound",
        ));
    }
    let mut bytes = Vec::with_capacity(size as usize);
    BufReader::new(file)
        .take(maximum_bytes + 1)
        .read_to_end(&mut bytes)
        .map_err(|error| {
            StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("read managed file: {error}"),
            )
        })?;
    if bytes.len() as u64 != size {
        return Err(StartupMigrationError::new(
            "migration_filesystem_unsafe",
            "managed file changed while being read",
        ));
    }
    Ok(bytes)
}

fn sha256_file(path: &Path) -> Result<String, StartupMigrationError> {
    let file = open_read_no_follow(path)?;
    validate_open_file_identity(path, &file)?;
    let expected_size = file
        .metadata()
        .map_err(|error| {
            StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("inspect file before hashing: {error}"),
            )
        })?
        .len();
    let mut reader = BufReader::new(file);
    let mut digest = Sha256::new();
    let mut buffer = [0_u8; 64 * 1024];
    let mut total = 0_u64;
    loop {
        let read = reader.read(&mut buffer).map_err(|error| {
            StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("hash managed file: {error}"),
            )
        })?;
        if read == 0 {
            break;
        }
        total = total.saturating_add(read as u64);
        digest.update(&buffer[..read]);
    }
    if total != expected_size {
        return Err(StartupMigrationError::new(
            "migration_filesystem_unsafe",
            "managed file changed while being hashed",
        ));
    }
    Ok(hex_lower(&digest.finalize()))
}

fn file_size(path: &Path) -> Result<u64, StartupMigrationError> {
    let file = open_read_no_follow(path)?;
    validate_open_file_identity(path, &file)?;
    file.metadata()
        .map(|metadata| metadata.len())
        .map_err(|error| {
            StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("inspect managed file size: {error}"),
            )
        })
}

fn validate_sqlite_header(path: &Path) -> Result<(), StartupMigrationError> {
    let mut file = open_read_no_follow(path)?;
    validate_open_file_identity(path, &file)?;
    let mut header = [0_u8; 16];
    file.read_exact(&mut header).map_err(|error| {
        StartupMigrationError::new(
            "migration_database_invalid",
            format!("read SQLite header: {error}"),
        )
    })?;
    if &header != SQLITE_HEADER {
        return Err(StartupMigrationError::new(
            "migration_database_invalid",
            "managed database does not have a SQLite header",
        ));
    }
    Ok(())
}

fn write_file_atomically(path: &Path, bytes: &[u8]) -> Result<(), StartupMigrationError> {
    let temporary = path.with_extension("json.tmp");
    if let Ok(metadata) = fs::symlink_metadata(&temporary) {
        validate_regular_metadata(&temporary, &metadata, false)?;
        fs::remove_file(&temporary).map_err(|error| {
            StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("remove stale journal temporary: {error}"),
            )
        })?;
    }
    if let Ok(metadata) = fs::symlink_metadata(path) {
        validate_regular_metadata(path, &metadata, false)?;
    }
    let mut file = open_new_managed_file(&temporary)?;
    file.write_all(bytes).map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("write migration journal temporary: {error}"),
        )
    })?;
    file.sync_all().map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("synchronize migration journal temporary: {error}"),
        )
    })?;
    validate_open_file_identity(&temporary, &file)?;
    drop(file);
    replace_file(&temporary, path)?;
    sync_parent(path)
}

fn copy_new_synced(source_path: &Path, destination: &Path) -> Result<(), StartupMigrationError> {
    let mut source = open_read_no_follow(source_path)?;
    validate_open_file_identity(source_path, &source)?;
    let mut destination_file = open_new_managed_file(destination)?;
    io::copy(&mut source, &mut destination_file).map_err(|error| {
        StartupMigrationError::new(
            "migration_recovery_required",
            format!("stage verified database restore: {error}"),
        )
    })?;
    destination_file.sync_all().map_err(|error| {
        StartupMigrationError::new(
            "migration_recovery_required",
            format!("synchronize staged database restore: {error}"),
        )
    })?;
    validate_open_file_identity(destination, &destination_file)?;
    Ok(())
}

fn sync_file_and_parent(path: &Path) -> Result<(), StartupMigrationError> {
    let file = open_readwrite_no_follow(path)?;
    validate_open_file_identity(path, &file)?;
    file.sync_all().map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("synchronize managed file: {error}"),
        )
    })?;
    sync_parent(path)
}

fn sync_parent(path: &Path) -> Result<(), StartupMigrationError> {
    #[cfg(not(windows))]
    {
        let parent = path.parent().ok_or_else(|| {
            StartupMigrationError::new("migration_runtime_io_failed", "managed path has no parent")
        })?;
        File::open(parent)
            .and_then(|directory| directory.sync_all())
            .map_err(|error| {
                StartupMigrationError::new(
                    "migration_runtime_io_failed",
                    format!("synchronize managed directory: {error}"),
                )
            })?;
    }
    #[cfg(windows)]
    let _ = path;
    Ok(())
}

#[cfg(not(windows))]
fn replace_file(temporary: &Path, target: &Path) -> Result<(), StartupMigrationError> {
    fs::rename(temporary, target).map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("publish managed file replacement: {error}"),
        )
    })
}

#[cfg(windows)]
fn replace_file(temporary: &Path, target: &Path) -> Result<(), StartupMigrationError> {
    use std::os::windows::ffi::OsStrExt;
    use windows_sys::Win32::Storage::FileSystem::{
        MoveFileExW, MOVEFILE_REPLACE_EXISTING, MOVEFILE_WRITE_THROUGH,
    };

    let source = temporary
        .as_os_str()
        .encode_wide()
        .chain(std::iter::once(0))
        .collect::<Vec<_>>();
    let destination = target
        .as_os_str()
        .encode_wide()
        .chain(std::iter::once(0))
        .collect::<Vec<_>>();
    let result = unsafe {
        MoveFileExW(
            source.as_ptr(),
            destination.as_ptr(),
            MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH,
        )
    };
    if result == 0 {
        Err(StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!(
                "publish managed Windows file replacement: {}",
                io::Error::last_os_error()
            ),
        ))
    } else {
        Ok(())
    }
}

fn remove_managed_file(path: &Path) -> Result<(), StartupMigrationError> {
    match fs::symlink_metadata(path) {
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(()),
        Err(error) => {
            return Err(StartupMigrationError::new(
                "migration_runtime_io_failed",
                format!("inspect managed file before removal: {error}"),
            ))
        }
        Ok(metadata) => validate_regular_metadata(path, &metadata, false)?,
    }
    let file = open_read_no_follow(path)?;
    validate_open_file_identity(path, &file)?;
    drop(file);
    fs::remove_file(path).map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("remove managed migration file: {error}"),
        )
    })?;
    sync_parent(path)
}

fn remove_sqlite_sidecars(database: &Path) -> Result<(), StartupMigrationError> {
    for suffix in ["-wal", "-shm", "-journal"] {
        let mut sidecar = database.as_os_str().to_os_string();
        sidecar.push(suffix);
        let sidecar = PathBuf::from(sidecar);
        remove_managed_file(&sidecar)?;
    }
    Ok(())
}

fn ensure_snapshot_space(paths: &ManagedMigrationPaths) -> Result<(), StartupMigrationError> {
    let database_size = file_size(&paths.database)?;
    let sidecar_size = ["-wal", "-shm", "-journal"]
        .iter()
        .filter_map(|suffix| {
            let mut sidecar = paths.database.as_os_str().to_os_string();
            sidecar.push(suffix);
            fs::metadata(PathBuf::from(sidecar)).ok()
        })
        .fold(0_u64, |total, metadata| {
            total.saturating_add(metadata.len())
        });
    let required = database_size
        .saturating_add(sidecar_size)
        .saturating_mul(3)
        .saturating_add(MINIMUM_FREE_SPACE_HEADROOM);
    let available = fs2::available_space(&paths.snapshots).map_err(|error| {
        StartupMigrationError::new(
            "migration_runtime_io_failed",
            format!("inspect migration recovery free space: {error}"),
        )
    })?;
    if available < required {
        return Err(StartupMigrationError::new(
            "migration_disk_space_insufficient",
            "migration recovery directory lacks bounded safety-snapshot headroom",
        ));
    }
    Ok(())
}

fn validate_snapshot_filename(value: &str) -> Result<(), StartupMigrationError> {
    let operation = value
        .strip_prefix("migration-safety-")
        .and_then(|value| value.strip_suffix(".sqlite"))
        .ok_or_else(|| {
            StartupMigrationError::new(
                "migration_recovery_required",
                "managed migration snapshot filename is invalid",
            )
        })?;
    if Uuid::parse_str(operation)
        .map(|uuid| uuid.hyphenated().to_string() != operation)
        .unwrap_or(true)
    {
        return Err(StartupMigrationError::new(
            "migration_recovery_required",
            "managed migration snapshot identifier is invalid",
        ));
    }
    Ok(())
}

fn prune_safety_snapshots(root: &Path, preserve: Option<&str>, logger: &RuntimeLogger) {
    let Ok(entries) = fs::read_dir(root) else {
        logger.warn("Migration safety snapshot retention could not inspect its managed directory");
        return;
    };
    let mut snapshots = Vec::new();
    for entry in entries.flatten() {
        let Some(name) = entry.file_name().to_str().map(str::to_owned) else {
            continue;
        };
        if validate_snapshot_filename(&name).is_err() || preserve == Some(name.as_str()) {
            continue;
        }
        let path = entry.path();
        let Ok(metadata) = fs::symlink_metadata(&path) else {
            continue;
        };
        if validate_regular_metadata(&path, &metadata, false).is_err() {
            continue;
        }
        let modified = metadata.modified().unwrap_or(UNIX_EPOCH);
        snapshots.push((modified, path));
    }
    snapshots.sort_by(|left, right| right.0.cmp(&left.0));
    for (_, path) in snapshots.into_iter().skip(SNAPSHOT_RETENTION) {
        if remove_managed_file(&path).is_err() {
            logger.warn("A managed migration safety snapshot could not be pruned");
        }
    }
}

fn hex_lower(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut output = String::with_capacity(bytes.len() * 2);
    for byte in bytes {
        output.push(HEX[(byte >> 4) as usize] as char);
        output.push(HEX[(byte & 0x0f) as usize] as char);
    }
    output
}

fn unix_time() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

#[cfg(test)]
mod tests {
    use std::sync::atomic::{AtomicBool, Ordering};

    use super::*;

    const TEST_MIGRATION: &str = "2026_08_05_000000_create_test_table";

    struct FakeLauncher {
        fail_next_migration: AtomicBool,
    }

    impl FakeLauncher {
        fn new(fail_next_migration: bool) -> Self {
            Self {
                fail_next_migration: AtomicBool::new(fail_next_migration),
            }
        }

        fn inspection(&self, database: &Path, operation: &str) -> MigrationInspection {
            let target = fs::read(database)
                .expect("test database should remain readable")
                .ends_with(b"target");
            MigrationInspection {
                protocol: "medismart-native-migration-state".to_owned(),
                schema_version: SCHEMA_VERSION,
                operation: operation.to_owned(),
                integrity_ok: true,
                foreign_keys_ok: true,
                journal_mode: "delete".to_owned(),
                migrations_table_present: true,
                expected_migrations: vec![TEST_MIGRATION.to_owned()],
                applied_migrations: if target {
                    vec![TEST_MIGRATION.to_owned()]
                } else {
                    Vec::new()
                },
                pending_migrations: if target {
                    Vec::new()
                } else {
                    vec![TEST_MIGRATION.to_owned()]
                },
                required_tables_present: target,
                missing_required_tables: if target {
                    Vec::new()
                } else {
                    vec!["test_table".to_owned()]
                },
                snapshot_created: operation == "snapshot",
                checkpoint: (operation == "snapshot").then_some(CheckpointObservation {
                    busy: 0,
                    log: 0,
                    checkpointed: 0,
                }),
            }
        }
    }

    impl MigrationCommandLauncher for FakeLauncher {
        fn inspect(&self, database: &Path) -> Result<MigrationInspection, StartupMigrationError> {
            Ok(self.inspection(database, "inspect"))
        }

        fn snapshot(
            &self,
            database: &Path,
            snapshot: &Path,
            _snapshot_root: &Path,
        ) -> Result<MigrationInspection, StartupMigrationError> {
            fs::copy(database, snapshot).map_err(|error| {
                StartupMigrationError::new(
                    "test_snapshot_failed",
                    format!("copy test snapshot: {error}"),
                )
            })?;

            Ok(self.inspection(snapshot, "snapshot"))
        }

        fn migrate_forward(&self, database: &Path) -> Result<(), StartupMigrationError> {
            if self.fail_next_migration.swap(false, Ordering::SeqCst) {
                fs::write(database, sqlite_bytes(b"interrupted")).map_err(|error| {
                    StartupMigrationError::new(
                        "test_migration_failed",
                        format!("write interrupted test database: {error}"),
                    )
                })?;

                return Err(StartupMigrationError::new(
                    "migration_process_failed",
                    "simulated forward migration interruption",
                ));
            }

            fs::write(database, sqlite_bytes(b"target")).map_err(|error| {
                StartupMigrationError::new(
                    "test_migration_failed",
                    format!("write migrated test database: {error}"),
                )
            })
        }
    }

    struct MigrationFixture {
        root: PathBuf,
        resources: VerifiedMigrationResources,
        config: StartupMigrationGateConfig,
        paths: ManagedMigrationPaths,
        logger: Arc<RuntimeLogger>,
    }

    impl MigrationFixture {
        fn new() -> Self {
            let root = std::env::temp_dir()
                .join(format!("medismart-startup-migration-{}", Uuid::new_v4()));
            let database = root.join("data/database.sqlite");
            let storage = root.join("storage");
            let temporary = root.join("tmp");
            let framework_cache = root.join("cache");
            let recovery_root = storage.join("app/private/migration-recovery");
            let snapshots = recovery_root.join("snapshots");
            let log_directory = root.join("logs");

            for directory in [
                database.parent().expect("database parent"),
                storage.as_path(),
                temporary.as_path(),
                framework_cache.as_path(),
                snapshots.as_path(),
                log_directory.as_path(),
            ] {
                fs::create_dir_all(directory).expect("create migration test directory");
            }
            fs::write(&database, sqlite_bytes(b"pending")).expect("create pending test database");

            let application_version = "2.0.0-test".to_owned();
            let application_key = "base64:test-startup-migration-key-material".to_owned();
            let installation_id = Uuid::new_v4().hyphenated().to_string();
            let resources = VerifiedMigrationResources {
                resource_root: root.join("resources"),
                app_root: root.join("resources/laravel"),
                php_binary: root.join("resources/php/php.exe"),
                artisan_path: root.join("resources/laravel/artisan"),
                helper_path: root.join("resources/laravel").join(MIGRATION_HELPER),
                expected_migrations: vec![TEST_MIGRATION.to_owned()],
                migration_set_sha256: "a".repeat(64),
                migration_contract_sha256: "b".repeat(64),
                application_version: application_version.clone(),
            };
            let config = StartupMigrationGateConfig {
                app_data_root: root.clone(),
                database_path: database.clone(),
                storage_path: storage.clone(),
                temporary_directory: temporary.clone(),
                framework_cache_directory: framework_cache.clone(),
                recovery_root: recovery_root.clone(),
                app_key: application_key.clone(),
                installation_id,
                application_version,
                command_timeout: Duration::from_secs(30),
            };
            let paths = ManagedMigrationPaths {
                database,
                storage,
                temporary,
                framework_cache,
                snapshots,
                lifecycle_lock: root.join("storage/app/private").join(LIFECYCLE_LOCK),
                active_journal: recovery_root.join(ACTIVE_JOURNAL),
            };
            let logger = Arc::new(
                RuntimeLogger::open(
                    &log_directory,
                    &[application_key],
                    std::slice::from_ref(&root),
                )
                .expect("open migration test logger"),
            );

            Self {
                root,
                resources,
                config,
                paths,
                logger,
            }
        }
    }

    impl Drop for MigrationFixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.root);
        }
    }

    #[test]
    fn coordinator_snapshots_migrates_and_commits_before_services_start() {
        let fixture = MigrationFixture::new();
        let launcher = FakeLauncher::new(false);

        let outcome = coordinate_startup_migration_gate(
            &fixture.resources,
            &fixture.config,
            &fixture.paths,
            &launcher,
            Arc::clone(&fixture.logger),
        )
        .expect("forward migration should complete");

        assert_eq!(outcome, StartupMigrationOutcome::Migrated);
        assert!(fs::read(&fixture.paths.database)
            .expect("read migrated database")
            .ends_with(b"target"));
        assert!(!fixture.paths.active_journal.exists());
        assert_eq!(
            fs::read_dir(&fixture.paths.snapshots)
                .expect("read snapshot directory")
                .count(),
            1
        );
    }

    #[test]
    fn interrupted_migration_restores_then_retries_from_authenticated_journal() {
        let fixture = MigrationFixture::new();
        let pending = fs::read(&fixture.paths.database).expect("read initial database");
        let launcher = FakeLauncher::new(true);

        let failure = coordinate_startup_migration_gate(
            &fixture.resources,
            &fixture.config,
            &fixture.paths,
            &launcher,
            Arc::clone(&fixture.logger),
        )
        .expect_err("simulated migration should roll back");

        assert_eq!(failure.code(), "migration_failed_rolled_back");
        assert_eq!(
            fs::read(&fixture.paths.database).expect("read restored database"),
            pending
        );
        assert!(fixture.paths.active_journal.exists());

        let outcome = coordinate_startup_migration_gate(
            &fixture.resources,
            &fixture.config,
            &fixture.paths,
            &launcher,
            Arc::clone(&fixture.logger),
        )
        .expect("next startup should recover and retry");

        assert_eq!(outcome, StartupMigrationOutcome::RecoveredThenMigrated);
        assert!(fs::read(&fixture.paths.database)
            .expect("read retried database")
            .ends_with(b"target"));
        assert!(!fixture.paths.active_journal.exists());
    }

    #[test]
    fn signed_recovery_journal_rejects_tampering() {
        let fixture = MigrationFixture::new();
        let now = unix_time();
        let record = MigrationJournalRecord {
            schema_version: SCHEMA_VERSION,
            operation_id: Uuid::new_v4().hyphenated().to_string(),
            phase: JournalPhase::MigrationStarted,
            application_version: fixture.config.application_version.clone(),
            migration_set_sha256: fixture.resources.migration_set_sha256.clone(),
            migration_contract_sha256: fixture.resources.migration_contract_sha256.clone(),
            installation_binding_sha256: installation_binding(&fixture.config.installation_id),
            snapshot_filename: format!("migration-safety-{}.sqlite", Uuid::new_v4().hyphenated()),
            snapshot_sha256: None,
            snapshot_size: None,
            failure_code: None,
            created_at_unix: now,
            updated_at_unix: now,
        };
        write_signed_journal(
            &fixture.paths.active_journal,
            &record,
            &fixture.config.app_key,
        )
        .expect("write signed journal");
        let mut document: serde_json::Value = serde_json::from_slice(
            &fs::read(&fixture.paths.active_journal).expect("read signed journal"),
        )
        .expect("decode signed journal");
        document["record"]["phase"] = serde_json::Value::String("committed".to_owned());
        fs::write(
            &fixture.paths.active_journal,
            serde_json::to_vec_pretty(&document).expect("encode tampered journal"),
        )
        .expect("write tampered journal");

        let error = read_signed_journal(&fixture.paths.active_journal, &fixture.config.app_key)
            .expect_err("tampered journal must fail closed");

        assert_eq!(error.code(), "migration_recovery_required");
    }

    fn sqlite_bytes(marker: &[u8]) -> Vec<u8> {
        let mut bytes = SQLITE_HEADER.to_vec();
        bytes.extend_from_slice(marker);
        bytes
    }
}
