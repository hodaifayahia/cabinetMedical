use std::{
    collections::{HashMap, HashSet},
    ffi::OsString,
    fmt,
    fs::{self, File},
    io::{BufRead, BufReader, Read, Write},
    net::{Ipv4Addr, SocketAddrV4, TcpListener, TcpStream},
    path::{Path, PathBuf},
    process::{Child, Command, Stdio},
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc,
    },
    thread,
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine as _};
use rand::RngCore;
use serde::{Deserialize, Serialize};
use serde_json::value::RawValue;
use sha2::{Digest, Sha256};
use uuid::Uuid;
use zeroize::{Zeroize, Zeroizing};

const LEASE_PROTOCOL: &str = "medismart-offline-restore-lease";
const LEASE_VERSION: u8 = 1;
const RESULT_PROTOCOL: &str = "medismart-offline-restore-result";
const RESULT_VERSION: u8 = 1;
const MAXIMUM_LEASE_SECONDS: u64 = 4 * 60 * 60;
const MAXIMUM_PROTOCOL_BYTES: usize = 2048;
const MAXIMUM_COMMAND_OUTPUT_BYTES: usize = 32 * 1024;
const MAXIMUM_PREPARED_PLAN_BYTES: u64 = 16 * 1024 * 1024;
const MAXIMUM_RECOVERY_JOURNAL_BYTES: u64 = 4 * 1024 * 1024;
const MAXIMUM_STAGED_FILES: u64 = 100_000;
const MAXIMUM_STAGED_BYTES: u64 = 512 * 1024 * 1024 * 1024;
const MAXIMUM_STAGED_FILE_BYTES: u64 = 256 * 1024 * 1024 * 1024;
const MAXIMUM_PORTABLE_PATH_BYTES: usize = 2048;
const MAXIMUM_PORTABLE_SEGMENT_BYTES: usize = 255;
const MAXIMUM_PORTABLE_PATH_DEPTH: usize = 32;

pub const OFFLINE_RESTORE_AUTHORIZATION_PROTOCOL: &str = "medismart-offline-restore-authorization";
pub const OFFLINE_RESTORE_AUTHORIZATION_VERSION: u8 = 1;

const MESSAGE_APPLIED: &str = "La restauration hors ligne a été appliquée. Les données de retour arrière sont conservées jusqu’à la validation du redémarrage.";
const MESSAGE_ROLLED_BACK: &str = "La restauration a échoué, mais les données actives précédentes ont été rétablies. La copie de sécurité est conservée.";
const MESSAGE_REFUSED: &str =
    "La restauration a été refusée avant toute modification des données actives.";
const MESSAGE_MANUAL_RECOVERY: &str = "La restauration est interrompue. Gardez l’application hors ligne et contactez l’assistance ; les données de retour arrière et les sauvegardes sont conservées.";

#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum OfflineRestoreStatus {
    AppliedPendingRestart,
    RolledBack,
    RefusedNoMutation,
    ManualRecoveryRequired,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct OfflineRestoreOutcome {
    pub status: OfflineRestoreStatus,
    pub message_fr: &'static str,
}

#[derive(Debug)]
pub struct OfflineRestoreError {
    code: &'static str,
    operator_message_fr: &'static str,
    detail: String,
    keep_runtime_offline: bool,
}

impl OfflineRestoreError {
    pub fn new(
        code: &'static str,
        operator_message_fr: &'static str,
        detail: impl Into<String>,
        keep_runtime_offline: bool,
    ) -> Self {
        Self {
            code,
            operator_message_fr,
            detail: detail.into(),
            keep_runtime_offline,
        }
    }

    pub fn code(&self) -> &'static str {
        self.code
    }

    pub fn operator_message_fr(&self) -> &'static str {
        self.operator_message_fr
    }

    pub fn keep_runtime_offline(&self) -> bool {
        self.keep_runtime_offline
    }
}

impl fmt::Display for OfflineRestoreError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}: {}", self.code, self.detail)
    }
}

impl std::error::Error for OfflineRestoreError {}

/// A non-secret, content-bound authorization emitted by the preparation step.
/// It deliberately contains no archive, executable, or filesystem path.
#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct OfflineRestoreAuthorizationArtifact {
    protocol: String,
    version: u8,
    operation_id: String,
    plan_sha256: String,
}

impl OfflineRestoreAuthorizationArtifact {
    pub fn operation_id(&self) -> &str {
        &self.operation_id
    }

    pub fn plan_sha256(&self) -> &str {
        &self.plan_sha256
    }
}

/// Opaque proof that the native side resolved an authorization only beneath
/// its managed restore directories and independently rechecked its ready
/// journal record plus every staged file size and digest.
#[derive(Debug)]
pub struct VerifiedPreparedRestore {
    operation_id: String,
    plan_sha256: String,
}

impl VerifiedPreparedRestore {
    pub fn operation_id(&self) -> &str {
        &self.operation_id
    }

    pub fn plan_sha256(&self) -> &str {
        &self.plan_sha256
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedPlanDocument<'a> {
    #[serde(borrow)]
    plan: &'a RawValue,
    sha256: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedPlan {
    plan_version: u8,
    operation_id: String,
    encrypted_archive_sha256: String,
    inner_archive_sha256: String,
    manifest: PreparedManifest,
    staged_file_count: u64,
    staged_bytes: u64,
    inventory: Vec<PreparedInventoryItem>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedInventoryItem {
    path: String,
    size: u64,
    sha256: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedManifest {
    format: String,
    format_version: u8,
    backup_id: String,
    schema_version: u64,
    application_version: String,
    created_at: String,
    database_driver: String,
    installation_id: String,
    migration_count: u64,
    latest_migration: serde_json::Value,
    migration_set_sha256: String,
    components: Vec<PreparedManifestComponent>,
    consistency: PreparedManifestConsistency,
    integrity: PreparedManifestIntegrity,
    portability: PreparedManifestPortability,
    encryption: PreparedManifestEncryption,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedManifestComponent {
    name: String,
    path: String,
    file_count: u64,
    size: u64,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedManifestConsistency {
    database: String,
    assets: String,
    writers_quiesced: bool,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedManifestIntegrity {
    profile: String,
    authenticated: bool,
    purpose: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedManifestPortability {
    profile: String,
    machine_bound_state: String,
    secrets: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct PreparedManifestEncryption {
    enabled: bool,
    algorithm: serde_json::Value,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct ReadyJournalRecord {
    sequence: u64,
    operation_id: String,
    event: String,
    occurred_at: String,
    context: ReadyJournalContext,
    sha256: String,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct ReadyJournalContext {
    plan_sha256: String,
    web_apply_enabled: bool,
}

#[derive(Serialize)]
struct UnsignedReadyJournalRecord<'a> {
    sequence: u64,
    operation_id: &'a str,
    event: &'a str,
    occurred_at: &'a str,
    context: &'a ReadyJournalContext,
}

/// Resolve and validate a prepared restore without accepting a caller-owned
/// path. PHP performs the same checks again under the exclusive process lease;
/// this preflight prevents an invalid artifact from taking the runtime down.
pub fn verify_prepared_restore_authorization(
    restore_work_root: &Path,
    restore_journal_root: &Path,
    authorization: &OfflineRestoreAuthorizationArtifact,
) -> Result<VerifiedPreparedRestore, OfflineRestoreError> {
    validate_authorization(authorization)?;

    let work_root = canonical_managed_directory(restore_work_root)?;
    let workspace = canonical_direct_child(&work_root, &authorization.operation_id)?;
    let plan_path = canonical_regular_file(&workspace, "restore-plan.json")?;
    let plan_bytes = read_bounded_file(&plan_path, MAXIMUM_PREPARED_PLAN_BYTES)?;
    let document: PreparedPlanDocument<'_> = serde_json::from_slice(&plan_bytes)
        .map_err(|error| preflight_error(format!("decode prepared plan: {error}")))?;

    if !is_sha256(&document.sha256)
        || !constant_time_eq(
            document.sha256.as_bytes(),
            authorization.plan_sha256.as_bytes(),
        )
        || !constant_time_eq(
            hex_lower(&Sha256::digest(document.plan.get().as_bytes())).as_bytes(),
            document.sha256.as_bytes(),
        )
    {
        return Err(preflight_error("prepared plan digest mismatch"));
    }

    let plan: PreparedPlan = serde_json::from_str(document.plan.get())
        .map_err(|error| preflight_error(format!("decode prepared plan body: {error}")))?;
    validate_prepared_plan(&plan, authorization)?;
    verify_ready_journal(restore_journal_root, authorization)?;
    verify_staged_inventory(&workspace, &plan)?;

    Ok(VerifiedPreparedRestore {
        operation_id: authorization.operation_id.clone(),
        plan_sha256: authorization.plan_sha256.clone(),
    })
}

fn validate_authorization(
    authorization: &OfflineRestoreAuthorizationArtifact,
) -> Result<(), OfflineRestoreError> {
    if authorization.protocol != OFFLINE_RESTORE_AUTHORIZATION_PROTOCOL
        || authorization.version != OFFLINE_RESTORE_AUTHORIZATION_VERSION
        || !is_canonical_uuid(&authorization.operation_id)
        || !is_sha256(&authorization.plan_sha256)
    {
        return Err(OfflineRestoreError::new(
            "restore_authorization_invalid",
            "L’autorisation de restauration est invalide. Aucun service n’a été arrêté.",
            "restore authorization did not match the fixed native contract",
            false,
        ));
    }

    Ok(())
}

fn validate_prepared_plan(
    plan: &PreparedPlan,
    authorization: &OfflineRestoreAuthorizationArtifact,
) -> Result<(), OfflineRestoreError> {
    if plan.plan_version != 1
        || plan.operation_id != authorization.operation_id
        || !is_sha256(&plan.encrypted_archive_sha256)
        || !is_sha256(&plan.inner_archive_sha256)
        || plan.staged_file_count == 0
        || plan.staged_file_count > MAXIMUM_STAGED_FILES
        || plan.staged_bytes == 0
        || plan.staged_bytes > MAXIMUM_STAGED_BYTES
        || plan.inventory.len() as u64 != plan.staged_file_count
    {
        return Err(preflight_error("prepared plan metadata was invalid"));
    }

    validate_manifest(&plan.manifest)?;

    let manifest_files = plan
        .manifest
        .components
        .iter()
        .try_fold(0_u64, |total, component| {
            total.checked_add(component.file_count)
        })
        .ok_or_else(|| preflight_error("prepared manifest count overflow"))?;
    let manifest_bytes = plan
        .manifest
        .components
        .iter()
        .try_fold(0_u64, |total, component| total.checked_add(component.size))
        .ok_or_else(|| preflight_error("prepared manifest size overflow"))?;
    if manifest_files != plan.staged_file_count || manifest_bytes != plan.staged_bytes {
        return Err(preflight_error(
            "prepared manifest totals did not match the inventory",
        ));
    }

    let mut portable_paths = HashSet::with_capacity(plan.inventory.len());
    let mut declared_bytes = 0_u64;

    for item in &plan.inventory {
        validate_managed_inventory_path(&item.path)?;
        if item.size > MAXIMUM_STAGED_FILE_BYTES || !is_sha256(&item.sha256) {
            return Err(preflight_error("prepared inventory metadata was invalid"));
        }
        declared_bytes = declared_bytes
            .checked_add(item.size)
            .ok_or_else(|| preflight_error("prepared inventory size overflow"))?;
        if !portable_paths.insert(item.path.to_lowercase()) {
            return Err(preflight_error(
                "prepared inventory contained a portable path collision",
            ));
        }
    }

    if declared_bytes != plan.staged_bytes {
        return Err(preflight_error(
            "prepared inventory totals did not match the plan",
        ));
    }

    Ok(())
}

fn validate_manifest(manifest: &PreparedManifest) -> Result<(), OfflineRestoreError> {
    let latest_migration_is_valid = manifest.latest_migration.is_null()
        || manifest
            .latest_migration
            .as_str()
            .is_some_and(|value| !value.is_empty() && value.len() <= 255);
    let component_contract = [
        ("database", "database.sqlite3"),
        ("private_storage", "storage/private"),
        ("public_storage", "storage/public"),
    ];
    let mut components = HashMap::with_capacity(manifest.components.len());
    let mut component_bytes = 0_u64;
    let mut component_files = 0_u64;

    for component in &manifest.components {
        if component.file_count > MAXIMUM_STAGED_FILES || component.size > MAXIMUM_STAGED_BYTES {
            return Err(preflight_error("prepared manifest component was invalid"));
        }
        component_files = component_files
            .checked_add(component.file_count)
            .ok_or_else(|| preflight_error("prepared component count overflow"))?;
        component_bytes = component_bytes
            .checked_add(component.size)
            .ok_or_else(|| preflight_error("prepared component size overflow"))?;
        if components
            .insert(component.name.as_str(), component.path.as_str())
            .is_some()
        {
            return Err(preflight_error(
                "prepared manifest contained duplicate components",
            ));
        }
    }

    if manifest.format != "medismart-backup"
        || manifest.format_version != 1
        || manifest.schema_version != 1
        || manifest.database_driver != "sqlite"
        || manifest.application_version.trim().is_empty()
        || manifest.application_version.len() > 128
        || manifest.created_at.is_empty()
        || manifest.created_at.len() > 128
        || !is_canonical_uuid(&manifest.installation_id)
        || !is_canonical_uuid(&manifest.backup_id)
        || manifest.migration_count > MAXIMUM_STAGED_FILES
        || !latest_migration_is_valid
        || !is_sha256(&manifest.migration_set_sha256)
        || manifest.components.len() != component_contract.len()
        || component_files == 0
        || component_files > MAXIMUM_STAGED_FILES
        || component_bytes == 0
        || component_bytes > MAXIMUM_STAGED_BYTES
        || component_contract
            .iter()
            .any(|(name, path)| components.get(name) != Some(path))
        || manifest.consistency.database != "sqlite-vacuum-into"
        || manifest.consistency.assets != "post-snapshot-inventory-and-verification"
        || manifest.consistency.writers_quiesced
        || manifest.integrity.profile != "sha256-v1"
        || manifest.integrity.authenticated
        || manifest.integrity.purpose != "corruption-detection"
        || manifest.portability.profile != "installation-snapshot-v1"
        || manifest.portability.machine_bound_state != "included"
        || manifest.portability.secrets != "source-app-key-bound"
        || manifest.encryption.enabled
        || !manifest.encryption.algorithm.is_null()
    {
        return Err(preflight_error("prepared backup manifest was incompatible"));
    }

    Ok(())
}

fn verify_ready_journal(
    restore_journal_root: &Path,
    authorization: &OfflineRestoreAuthorizationArtifact,
) -> Result<(), OfflineRestoreError> {
    let journal_root = canonical_managed_directory(restore_journal_root)?;
    let filename = format!("{}.jsonl", authorization.operation_id);
    let journal_path = canonical_regular_file(&journal_root, &filename)?;
    let bytes = read_bounded_file(&journal_path, MAXIMUM_RECOVERY_JOURNAL_BYTES)?;

    if !bytes.ends_with(b"\n") {
        return Err(preflight_error("prepared recovery journal was incomplete"));
    }

    let last_line = bytes[..bytes.len() - 1]
        .rsplit(|byte| *byte == b'\n')
        .next()
        .filter(|line| !line.is_empty())
        .ok_or_else(|| preflight_error("prepared recovery journal was empty"))?;
    let record: ReadyJournalRecord = serde_json::from_slice(last_line)
        .map_err(|error| preflight_error(format!("decode ready journal record: {error}")))?;
    let unsigned = UnsignedReadyJournalRecord {
        sequence: record.sequence,
        operation_id: &record.operation_id,
        event: &record.event,
        occurred_at: &record.occurred_at,
        context: &record.context,
    };
    let encoded = serde_json::to_vec(&unsigned)
        .map_err(|error| preflight_error(format!("encode ready journal record: {error}")))?;
    let expected_sha256 = hex_lower(&Sha256::digest(encoded));

    if record.sequence == 0
        || record.operation_id != authorization.operation_id
        || record.event != "ready_for_offline_apply"
        || record.occurred_at.is_empty()
        || record.occurred_at.len() > 128
        || record.context.web_apply_enabled
        || !constant_time_eq(
            record.context.plan_sha256.as_bytes(),
            authorization.plan_sha256.as_bytes(),
        )
        || !is_sha256(&record.sha256)
        || !constant_time_eq(record.sha256.as_bytes(), expected_sha256.as_bytes())
    {
        return Err(preflight_error(
            "prepared recovery journal was not in the ready state",
        ));
    }

    Ok(())
}

fn verify_staged_inventory(
    workspace: &Path,
    plan: &PreparedPlan,
) -> Result<(), OfflineRestoreError> {
    let staged_root = canonical_direct_child(workspace, "staged")?;
    let mut actual = HashMap::with_capacity(plan.inventory.len());
    collect_staged_files(&staged_root, &staged_root, 0, &mut actual)?;

    if actual.len() != plan.inventory.len() {
        return Err(preflight_error(
            "staged files did not match the prepared inventory",
        ));
    }

    for item in &plan.inventory {
        let (size, sha256) = actual
            .get(&item.path)
            .ok_or_else(|| preflight_error("a staged inventory file was missing"))?;
        if *size != item.size || !constant_time_eq(sha256.as_bytes(), item.sha256.as_bytes()) {
            return Err(preflight_error(
                "a staged inventory file failed digest verification",
            ));
        }
    }

    let database = staged_root.join("database.sqlite3");
    let mut header = [0_u8; 16];
    File::open(database)
        .and_then(|mut file| file.read_exact(&mut header))
        .map_err(|error| preflight_error(format!("read staged database header: {error}")))?;
    if &header != b"SQLite format 3\0" {
        return Err(preflight_error("staged database header was invalid"));
    }

    Ok(())
}

fn collect_staged_files(
    staged_root: &Path,
    directory: &Path,
    depth: usize,
    files: &mut HashMap<String, (u64, String)>,
) -> Result<(), OfflineRestoreError> {
    if depth > MAXIMUM_PORTABLE_PATH_DEPTH {
        return Err(preflight_error("staged directory depth exceeded its limit"));
    }

    for entry in fs::read_dir(directory)
        .map_err(|error| preflight_error(format!("read staged directory: {error}")))?
    {
        let entry =
            entry.map_err(|error| preflight_error(format!("read staged entry: {error}")))?;
        let file_type = entry
            .file_type()
            .map_err(|error| preflight_error(format!("read staged entry type: {error}")))?;

        if file_type.is_symlink() {
            return Err(preflight_error(
                "staged inventory contained a symbolic link",
            ));
        }
        if file_type.is_dir() {
            collect_staged_files(staged_root, &entry.path(), depth + 1, files)?;
            continue;
        }
        if !file_type.is_file() || files.len() as u64 >= MAXIMUM_STAGED_FILES {
            return Err(preflight_error(
                "staged inventory contained an invalid entry",
            ));
        }

        let relative = entry
            .path()
            .strip_prefix(staged_root)
            .map_err(|_| preflight_error("staged entry escaped its managed root"))?
            .components()
            .map(|component| component.as_os_str().to_str())
            .collect::<Option<Vec<_>>>()
            .ok_or_else(|| preflight_error("staged entry path was not valid UTF-8"))?
            .join("/");
        validate_managed_inventory_path(&relative)?;
        let metadata = entry
            .metadata()
            .map_err(|error| preflight_error(format!("read staged metadata: {error}")))?;
        if metadata.len() > MAXIMUM_STAGED_FILE_BYTES {
            return Err(preflight_error("staged entry exceeded its size limit"));
        }
        let sha256 = sha256_file(&entry.path())?;
        if files.insert(relative, (metadata.len(), sha256)).is_some() {
            return Err(preflight_error(
                "staged inventory contained duplicate paths",
            ));
        }
    }

    Ok(())
}

fn validate_managed_inventory_path(path: &str) -> Result<(), OfflineRestoreError> {
    if path.is_empty()
        || path.len() > MAXIMUM_PORTABLE_PATH_BYTES
        || path.starts_with('/')
        || path.contains('\\')
        || path.contains(':')
        || path.chars().any(|character| {
            character.is_control() || matches!(character, '<' | '>' | '"' | '|' | '?' | '*')
        })
    {
        return Err(preflight_error("prepared inventory path was unsafe"));
    }

    let segments = path.split('/').collect::<Vec<_>>();
    if segments.len() > MAXIMUM_PORTABLE_PATH_DEPTH
        || segments.iter().any(|segment| {
            segment.is_empty()
                || segment.len() > MAXIMUM_PORTABLE_SEGMENT_BYTES
                || matches!(*segment, "." | "..")
                || segment.ends_with(['.', ' '])
                || is_reserved_windows_name(segment)
        })
    {
        return Err(preflight_error("prepared inventory path was unsafe"));
    }

    if path != "database.sqlite3"
        && ![
            "private/clinical-documents/",
            "private/patient-documents/",
            "private/medical-models/",
            "public/cabinet/",
        ]
        .iter()
        .any(|prefix| path.starts_with(prefix) && path.len() > prefix.len())
    {
        return Err(preflight_error(
            "prepared inventory path was outside managed roots",
        ));
    }

    Ok(())
}

fn is_reserved_windows_name(segment: &str) -> bool {
    let stem = segment.split('.').next().unwrap_or_default().to_uppercase();
    matches!(
        stem.as_str(),
        "CON"
            | "CONIN$"
            | "CONOUT$"
            | "PRN"
            | "AUX"
            | "NUL"
            | "COM1"
            | "COM2"
            | "COM3"
            | "COM4"
            | "COM5"
            | "COM6"
            | "COM7"
            | "COM8"
            | "COM9"
            | "LPT1"
            | "LPT2"
            | "LPT3"
            | "LPT4"
            | "LPT5"
            | "LPT6"
            | "LPT7"
            | "LPT8"
            | "LPT9"
    )
}

fn canonical_managed_directory(path: &Path) -> Result<PathBuf, OfflineRestoreError> {
    let metadata = fs::symlink_metadata(path)
        .map_err(|error| preflight_error(format!("inspect managed directory: {error}")))?;
    if metadata.file_type().is_symlink() || !metadata.is_dir() {
        return Err(preflight_error("managed restore directory was invalid"));
    }

    path.canonicalize()
        .map_err(|error| preflight_error(format!("resolve managed directory: {error}")))
}

fn canonical_direct_child(root: &Path, child: &str) -> Result<PathBuf, OfflineRestoreError> {
    let candidate = root.join(child);
    let metadata = fs::symlink_metadata(&candidate)
        .map_err(|error| preflight_error(format!("inspect managed child: {error}")))?;
    if metadata.file_type().is_symlink() || !metadata.is_dir() {
        return Err(preflight_error("managed restore child was invalid"));
    }
    let canonical = candidate
        .canonicalize()
        .map_err(|error| preflight_error(format!("resolve managed child: {error}")))?;
    if canonical.parent() != Some(root) {
        return Err(preflight_error("managed restore child escaped its root"));
    }

    Ok(canonical)
}

fn canonical_regular_file(root: &Path, filename: &str) -> Result<PathBuf, OfflineRestoreError> {
    let candidate = root.join(filename);
    let metadata = fs::symlink_metadata(&candidate)
        .map_err(|error| preflight_error(format!("inspect managed file: {error}")))?;
    if metadata.file_type().is_symlink() || !metadata.is_file() {
        return Err(preflight_error("managed restore file was invalid"));
    }
    let canonical = candidate
        .canonicalize()
        .map_err(|error| preflight_error(format!("resolve managed file: {error}")))?;
    if canonical.parent() != Some(root) {
        return Err(preflight_error("managed restore file escaped its root"));
    }

    Ok(canonical)
}

fn read_bounded_file(path: &Path, maximum_bytes: u64) -> Result<Vec<u8>, OfflineRestoreError> {
    let metadata = fs::metadata(path)
        .map_err(|error| preflight_error(format!("read managed file metadata: {error}")))?;
    if metadata.len() < 2 || metadata.len() > maximum_bytes {
        return Err(preflight_error("managed restore file exceeded its bounds"));
    }
    let mut file = File::open(path)
        .map_err(|error| preflight_error(format!("open managed restore file: {error}")))?;
    let mut bytes = Vec::with_capacity(metadata.len() as usize);
    Read::by_ref(&mut file)
        .take(maximum_bytes + 1)
        .read_to_end(&mut bytes)
        .map_err(|error| preflight_error(format!("read managed restore file: {error}")))?;
    if bytes.len() as u64 != metadata.len() || bytes.len() as u64 > maximum_bytes {
        return Err(preflight_error(
            "managed restore file changed while reading",
        ));
    }

    Ok(bytes)
}

fn sha256_file(path: &Path) -> Result<String, OfflineRestoreError> {
    let mut file =
        File::open(path).map_err(|error| preflight_error(format!("open staged file: {error}")))?;
    let mut digest = Sha256::new();
    let mut buffer = [0_u8; 64 * 1024];
    loop {
        let read = file
            .read(&mut buffer)
            .map_err(|error| preflight_error(format!("read staged file: {error}")))?;
        if read == 0 {
            break;
        }
        digest.update(&buffer[..read]);
    }

    Ok(hex_lower(&digest.finalize()))
}

fn is_sha256(value: &str) -> bool {
    value.len() == 64
        && value
            .as_bytes()
            .iter()
            .all(|byte| byte.is_ascii_digit() || matches!(byte, b'a'..=b'f'))
}

fn is_canonical_uuid(value: &str) -> bool {
    Uuid::parse_str(value)
        .map(|uuid| uuid.hyphenated().to_string() == value)
        .unwrap_or(false)
}

fn preflight_error(detail: impl Into<String>) -> OfflineRestoreError {
    OfflineRestoreError::new(
        "restore_preflight_failed",
        "La préparation de restauration n’est plus valide. Aucun service n’a été arrêté.",
        detail,
        false,
    )
}

/// A native-owned capability that remains valid only while every Laravel,
/// queue, and document writer stays stopped.
pub trait ExclusiveRestoreProcessLease: Send + Sync {
    fn assert_exclusive(&self) -> Result<(), OfflineRestoreError>;
}

/// Implemented by the desktop lifecycle owner, not by a web or PHP process.
pub trait OfflineRestoreProcessOwner {
    fn stop_writers_and_acquire_restore_lease(
        &self,
    ) -> Result<Arc<dyn ExclusiveRestoreProcessLease>, OfflineRestoreError>;

    /// Must leave the runtime stopped if restored startup or health validation fails.
    fn start_restored_runtime_and_verify(&self) -> Result<(), OfflineRestoreError>;

    fn resume_previous_runtime(&self) -> Result<(), OfflineRestoreError>;
}

pub trait OfflineRestoreCommandLauncher {
    fn launch(
        &self,
        operation_id: &str,
        lease: Arc<dyn ExclusiveRestoreProcessLease>,
    ) -> Result<OfflineRestoreOutcome, OfflineRestoreError>;
}

/// Coordinates ownership and restart policy. An unparseable/native failure is
/// deliberately left offline unless the launcher proves that mutation never began.
pub fn coordinate_offline_restore(
    owner: &dyn OfflineRestoreProcessOwner,
    launcher: &dyn OfflineRestoreCommandLauncher,
    operation_id: &str,
) -> Result<OfflineRestoreOutcome, OfflineRestoreError> {
    validate_operation_id(operation_id)?;

    let lease = owner
        .stop_writers_and_acquire_restore_lease()
        .map_err(|error| {
            OfflineRestoreError::new(
                "restore_ownership_failed",
                "La restauration ne peut pas démarrer : l’arrêt exclusif des services locaux n’a pas pu être vérifié.",
                format!("desktop lifecycle owner returned {}", error.code()),
                true,
            )
        })?;

    lease.assert_exclusive().map_err(|error| {
        OfflineRestoreError::new(
            "restore_ownership_failed",
            "La restauration ne peut pas démarrer : l’arrêt exclusif des services locaux n’a pas pu être vérifié.",
            format!("exclusive restore lease returned {}", error.code()),
            true,
        )
    })?;

    let result = launcher.launch(operation_id, Arc::clone(&lease));
    drop(lease);

    let outcome = match result {
        Ok(outcome) => outcome,
        Err(error) if !error.keep_runtime_offline() => {
            owner.resume_previous_runtime().map_err(|resume_error| {
                OfflineRestoreError::new(
                    "restore_runtime_resume_failed",
                    "Les services locaux restent arrêtés. Contactez l’assistance avant de rouvrir le cabinet.",
                    format!("desktop runtime resume returned {}", resume_error.code()),
                    true,
                )
            })?;

            return Err(error);
        }
        Err(error) => return Err(error),
    };

    match outcome.status {
        OfflineRestoreStatus::AppliedPendingRestart => {
            owner
                .start_restored_runtime_and_verify()
                .map_err(|error| {
                    OfflineRestoreError::new(
                        "restored_runtime_unhealthy",
                        "La restauration est conservée hors ligne : le redémarrage de contrôle n’a pas réussi. Contactez l’assistance.",
                        format!("restored runtime health check returned {}", error.code()),
                        true,
                    )
                })?;
        }
        OfflineRestoreStatus::RolledBack | OfflineRestoreStatus::RefusedNoMutation => {
            owner.resume_previous_runtime().map_err(|error| {
                OfflineRestoreError::new(
                    "restore_runtime_resume_failed",
                    "Les services locaux restent arrêtés. Contactez l’assistance avant de rouvrir le cabinet.",
                    format!("desktop runtime resume returned {}", error.code()),
                    true,
                )
            })?;
        }
        OfflineRestoreStatus::ManualRecoveryRequired => {
            // Intentionally remain offline. Recovery and backup data are retained.
        }
    }

    Ok(outcome)
}

pub struct OfflineRestorePhpConfig {
    pub php_binary: PathBuf,
    pub artisan_path: PathBuf,
    pub app_root: PathBuf,
    pub environment: Vec<(OsString, OsString)>,
    pub command_timeout: Duration,
    pub rollback_grace: Duration,
}

impl OfflineRestorePhpConfig {
    pub fn new(php_binary: PathBuf, artisan_path: PathBuf, app_root: PathBuf) -> Self {
        Self {
            php_binary,
            artisan_path,
            app_root,
            environment: Vec::new(),
            command_timeout: Duration::from_secs(2 * 60 * 60),
            rollback_grace: Duration::from_secs(30),
        }
    }

    fn validate(&self) -> Result<(), OfflineRestoreError> {
        if !self.php_binary.is_file()
            || !self.artisan_path.is_file()
            || !self.app_root.is_dir()
            || self.command_timeout.is_zero()
            || self.command_timeout.as_secs() >= MAXIMUM_LEASE_SECONDS
            || self.rollback_grace.is_zero()
        {
            return Err(OfflineRestoreError::new(
                "restore_command_invalid",
                "La restauration native n’est pas correctement configurée. Aucune donnée n’a été modifiée.",
                "invalid PHP restore command configuration",
                false,
            ));
        }

        Ok(())
    }
}

pub struct PhpOfflineRestoreCommandLauncher {
    config: OfflineRestorePhpConfig,
}

impl PhpOfflineRestoreCommandLauncher {
    pub fn new(config: OfflineRestorePhpConfig) -> Self {
        Self { config }
    }
}

impl OfflineRestoreCommandLauncher for PhpOfflineRestoreCommandLauncher {
    fn launch(
        &self,
        operation_id: &str,
        lease: Arc<dyn ExclusiveRestoreProcessLease>,
    ) -> Result<OfflineRestoreOutcome, OfflineRestoreError> {
        self.config.validate()?;
        validate_operation_id(operation_id)?;
        lease.assert_exclusive()?;

        let validity = self
            .config
            .command_timeout
            .saturating_add(self.config.rollback_grace)
            .saturating_add(Duration::from_secs(5));
        let mut lease_server = RestoreLeaseServer::start(operation_id, lease, validity)?;
        let capability = lease_server.capability_json_line()?;
        let mut child = self.spawn_command(operation_id)?;

        let mut stdin = child.stdin.take().ok_or_else(|| {
            OfflineRestoreError::new(
                "restore_command_io_failed",
                MESSAGE_MANUAL_RECOVERY,
                "native restore stdin was unavailable",
                true,
            )
        })?;

        if stdin.write_all(capability.as_bytes()).is_err() || stdin.flush().is_err() {
            lease_server.revoke();
            let _ = child.kill();
            let _ = child.wait();

            return Err(OfflineRestoreError::new(
                "restore_command_io_failed",
                MESSAGE_MANUAL_RECOVERY,
                "could not deliver the one-shot restore lease over stdin",
                true,
            ));
        }
        drop(stdin);
        drop(capability);

        let stdout = child.stdout.take().map(spawn_bounded_reader);
        let stderr = child.stderr.take().map(spawn_bounded_reader);
        let deadline = Instant::now() + self.config.command_timeout;
        let rollback_deadline = deadline + self.config.rollback_grace;
        let mut timed_out = false;
        let mut forced = false;
        let exit_status = loop {
            match child.try_wait() {
                Ok(Some(status)) => break status,
                Ok(None) if !timed_out && Instant::now() >= deadline => {
                    timed_out = true;
                    lease_server.revoke();
                }
                Ok(None) if timed_out && Instant::now() >= rollback_deadline => {
                    forced = true;
                    let _ = child.kill();
                    break child.wait().map_err(|error| {
                        command_error(
                            "restore_command_wait_failed",
                            format!("wait after forced termination: {error}"),
                            true,
                        )
                    })?;
                }
                Ok(None) => thread::sleep(Duration::from_millis(25)),
                Err(error) => {
                    lease_server.revoke();

                    return Err(command_error(
                        "restore_command_wait_failed",
                        format!("inspect native restore process: {error}"),
                        true,
                    ));
                }
            }
        };

        lease_server.revoke();
        let (stdout, stdout_truncated) = join_output(stdout);
        let (_, stderr_truncated) = join_output(stderr);

        if forced || stdout_truncated || stderr_truncated {
            return Err(command_error(
                "restore_command_incomplete",
                if forced {
                    "native restore exceeded its recovery grace period"
                } else {
                    "native restore emitted oversized output"
                },
                true,
            ));
        }

        parse_native_result(exit_status.code(), &stdout)
    }
}

impl PhpOfflineRestoreCommandLauncher {
    fn spawn_command(&self, operation_id: &str) -> Result<Child, OfflineRestoreError> {
        self.build_command(operation_id).spawn().map_err(|error| {
            OfflineRestoreError::new(
                "restore_command_spawn_failed",
                "La restauration n’a pas démarré. Aucune donnée n’a été modifiée.",
                format!("spawn native PHP restore command: {error}"),
                false,
            )
        })
    }

    fn build_command(&self, operation_id: &str) -> Command {
        let mut command = Command::new(&self.config.php_binary);
        command
            .current_dir(&self.config.app_root)
            .arg(&self.config.artisan_path)
            .arg("medismart:restore:native-apply")
            .arg(operation_id)
            .arg("--no-interaction")
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .envs(self.config.environment.iter().cloned())
            .env("MEDISMART_NATIVE_RESTORE", "1");

        configure_platform_process(&mut command);
        command
    }
}

struct LeaseSecret([u8; 32]);

impl Drop for LeaseSecret {
    fn drop(&mut self) {
        self.0.zeroize();
    }
}

struct RestoreLeaseServer {
    port: u16,
    operation_id: String,
    expires_at_unix: u64,
    secret: Arc<LeaseSecret>,
    active: Arc<AtomicBool>,
    thread: Option<thread::JoinHandle<()>>,
}

impl RestoreLeaseServer {
    fn start(
        operation_id: &str,
        lease: Arc<dyn ExclusiveRestoreProcessLease>,
        validity: Duration,
    ) -> Result<Self, OfflineRestoreError> {
        validate_operation_id(operation_id)?;

        if validity.is_zero() || validity.as_secs() > MAXIMUM_LEASE_SECONDS {
            return Err(command_error(
                "restore_lease_invalid",
                "native restore lease validity is outside the safety window",
                false,
            ));
        }

        let listener =
            TcpListener::bind(SocketAddrV4::new(Ipv4Addr::LOCALHOST, 0)).map_err(|error| {
                command_error(
                    "restore_lease_unavailable",
                    format!("bind loopback restore lease: {error}"),
                    false,
                )
            })?;
        listener.set_nonblocking(true).map_err(|error| {
            command_error(
                "restore_lease_unavailable",
                format!("configure loopback restore lease: {error}"),
                false,
            )
        })?;
        let port = listener
            .local_addr()
            .map_err(|error| {
                command_error(
                    "restore_lease_unavailable",
                    format!("read loopback restore lease address: {error}"),
                    false,
                )
            })?
            .port();
        let expires_at_unix = unix_time().saturating_add(validity.as_secs());
        let mut secret_bytes = [0_u8; 32];
        rand::rng().fill_bytes(&mut secret_bytes);
        let secret = Arc::new(LeaseSecret(secret_bytes));
        let active = Arc::new(AtomicBool::new(true));
        let server_active = Arc::clone(&active);
        let server_secret = Arc::clone(&secret);
        let server_operation_id = operation_id.to_owned();
        let operation_for_thread = server_operation_id.clone();

        let server_thread = thread::spawn(move || {
            while server_active.load(Ordering::SeqCst) && unix_time() < expires_at_unix {
                match listener.accept() {
                    Ok((stream, _)) => {
                        handle_lease_request(
                            stream,
                            &operation_for_thread,
                            expires_at_unix,
                            &server_secret.0,
                            &lease,
                        );
                    }
                    Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                        thread::sleep(Duration::from_millis(10));
                    }
                    Err(_) => thread::sleep(Duration::from_millis(10)),
                }
            }
        });

        Ok(Self {
            port,
            operation_id: server_operation_id,
            expires_at_unix,
            secret,
            active,
            thread: Some(server_thread),
        })
    }

    fn capability_json_line(&self) -> Result<Zeroizing<String>, OfflineRestoreError> {
        let encoded_secret = Zeroizing::new(URL_SAFE_NO_PAD.encode(self.secret.0));
        let capability = LeaseCapability {
            protocol: LEASE_PROTOCOL,
            version: LEASE_VERSION,
            operation_id: &self.operation_id,
            port: self.port,
            expires_at_unix: self.expires_at_unix,
            secret: encoded_secret.as_str(),
        };
        let mut json = serde_json::to_string(&capability).map_err(|error| {
            command_error(
                "restore_lease_invalid",
                format!("encode native restore capability: {error}"),
                false,
            )
        })?;
        json.push('\n');

        Ok(Zeroizing::new(json))
    }

    fn revoke(&mut self) {
        self.active.store(false, Ordering::SeqCst);
    }
}

impl Drop for RestoreLeaseServer {
    fn drop(&mut self) {
        self.revoke();

        if let Some(thread) = self.thread.take() {
            let _ = thread.join();
        }
    }
}

#[derive(Serialize)]
struct LeaseCapability<'a> {
    protocol: &'static str,
    version: u8,
    operation_id: &'a str,
    port: u16,
    expires_at_unix: u64,
    secret: &'a str,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct LeaseRequest {
    protocol: String,
    version: u8,
    operation_id: String,
    expires_at_unix: u64,
    challenge: String,
    proof: String,
}

#[derive(Serialize)]
struct LeaseResponse<'a> {
    protocol: &'static str,
    version: u8,
    ok: bool,
    operation_id: &'a str,
    expires_at_unix: u64,
    challenge: &'a str,
    proof: String,
}

fn handle_lease_request(
    mut stream: TcpStream,
    operation_id: &str,
    expires_at_unix: u64,
    secret: &[u8; 32],
    lease: &Arc<dyn ExclusiveRestoreProcessLease>,
) {
    let _ = stream.set_read_timeout(Some(Duration::from_secs(1)));
    let _ = stream.set_write_timeout(Some(Duration::from_secs(1)));
    let cloned = match stream.try_clone() {
        Ok(cloned) => cloned,
        Err(_) => return,
    };
    let mut reader = BufReader::new(cloned);
    let mut request_bytes = Vec::with_capacity(512);

    if reader
        .by_ref()
        .take((MAXIMUM_PROTOCOL_BYTES + 1) as u64)
        .read_until(b'\n', &mut request_bytes)
        .is_err()
        || request_bytes.len() > MAXIMUM_PROTOCOL_BYTES
        || !request_bytes.ends_with(b"\n")
    {
        return;
    }

    let request: LeaseRequest = match serde_json::from_slice(&request_bytes) {
        Ok(request) => request,
        Err(_) => return,
    };
    let challenge_is_valid = URL_SAFE_NO_PAD
        .decode(request.challenge.as_bytes())
        .is_ok_and(|decoded| decoded.len() == 32);
    let expected_request_proof = lease_proof(
        "request",
        operation_id,
        &request.challenge,
        expires_at_unix,
        secret,
    );

    if request.protocol != LEASE_PROTOCOL
        || request.version != LEASE_VERSION
        || request.operation_id != operation_id
        || request.expires_at_unix != expires_at_unix
        || unix_time() >= expires_at_unix
        || !challenge_is_valid
        || !constant_time_eq(request.proof.as_bytes(), expected_request_proof.as_bytes())
        || lease.assert_exclusive().is_err()
    {
        return;
    }

    let response = LeaseResponse {
        protocol: LEASE_PROTOCOL,
        version: LEASE_VERSION,
        ok: true,
        operation_id,
        expires_at_unix,
        challenge: &request.challenge,
        proof: lease_proof(
            "response",
            operation_id,
            &request.challenge,
            expires_at_unix,
            secret,
        ),
    };
    let mut response_bytes = match serde_json::to_vec(&response) {
        Ok(bytes) => bytes,
        Err(_) => return,
    };
    response_bytes.push(b'\n');
    let _ = stream.write_all(&response_bytes);
    let _ = stream.flush();
}

fn lease_proof(
    direction: &str,
    operation_id: &str,
    challenge: &str,
    expires_at_unix: u64,
    secret: &[u8],
) -> String {
    let message = format!(
        "medismart-restore-lease-{direction}-v1\n{operation_id}\n{challenge}\n{expires_at_unix}"
    );
    hex_lower(&hmac_sha256(secret, message.as_bytes()))
}

fn hmac_sha256(key: &[u8], message: &[u8]) -> [u8; 32] {
    let mut key_block = [0_u8; 64];

    if key.len() > key_block.len() {
        key_block[..32].copy_from_slice(&Sha256::digest(key));
    } else {
        key_block[..key.len()].copy_from_slice(key);
    }

    let mut inner_pad = [0x36_u8; 64];
    let mut outer_pad = [0x5c_u8; 64];

    for index in 0..key_block.len() {
        inner_pad[index] ^= key_block[index];
        outer_pad[index] ^= key_block[index];
    }

    let mut inner = Sha256::new();
    inner.update(inner_pad);
    inner.update(message);
    let inner_hash = inner.finalize();
    let mut outer = Sha256::new();
    outer.update(outer_pad);
    outer.update(inner_hash);

    outer.finalize().into()
}

fn hex_lower(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut encoded = String::with_capacity(bytes.len() * 2);

    for byte in bytes {
        encoded.push(HEX[(byte >> 4) as usize] as char);
        encoded.push(HEX[(byte & 0x0f) as usize] as char);
    }

    encoded
}

fn constant_time_eq(left: &[u8], right: &[u8]) -> bool {
    if left.len() != right.len() {
        return false;
    }

    let mut difference = 0_u8;

    for (left, right) in left.iter().zip(right) {
        difference |= left ^ right;
    }

    difference == 0
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct NativeRestoreResult {
    protocol: String,
    version: u8,
    status: OfflineRestoreStatus,
    message_fr: String,
}

fn parse_native_result(
    exit_code: Option<i32>,
    stdout: &[u8],
) -> Result<OfflineRestoreOutcome, OfflineRestoreError> {
    let output = std::str::from_utf8(stdout).map_err(|_| {
        command_error(
            "restore_result_invalid",
            "native restore result was not UTF-8",
            true,
        )
    })?;
    let lines: Vec<&str> = output
        .lines()
        .filter(|line| !line.trim().is_empty())
        .collect();

    if lines.len() != 1 {
        return Err(command_error(
            "restore_result_invalid",
            "native restore did not emit exactly one result record",
            true,
        ));
    }

    let result: NativeRestoreResult = serde_json::from_str(lines[0]).map_err(|error| {
        command_error(
            "restore_result_invalid",
            format!("parse native restore result: {error}"),
            true,
        )
    })?;

    let (expected_exit, expected_message) = match result.status {
        OfflineRestoreStatus::AppliedPendingRestart => (0, MESSAGE_APPLIED),
        OfflineRestoreStatus::RefusedNoMutation => (10, MESSAGE_REFUSED),
        OfflineRestoreStatus::RolledBack => (20, MESSAGE_ROLLED_BACK),
        OfflineRestoreStatus::ManualRecoveryRequired => (30, MESSAGE_MANUAL_RECOVERY),
    };

    if result.protocol != RESULT_PROTOCOL
        || result.version != RESULT_VERSION
        || exit_code != Some(expected_exit)
        || result.message_fr != expected_message
    {
        return Err(command_error(
            "restore_result_invalid",
            "native restore result did not match its authenticated status contract",
            true,
        ));
    }

    Ok(OfflineRestoreOutcome {
        status: result.status,
        message_fr: expected_message,
    })
}

fn spawn_bounded_reader<R>(mut reader: R) -> thread::JoinHandle<(Vec<u8>, bool)>
where
    R: Read + Send + 'static,
{
    thread::spawn(move || {
        let mut retained = Vec::with_capacity(4096);
        let mut buffer = [0_u8; 4096];
        let mut truncated = false;

        loop {
            let read = match reader.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(read) => read,
            };
            let remaining = MAXIMUM_COMMAND_OUTPUT_BYTES.saturating_sub(retained.len());

            if remaining > 0 {
                retained.extend_from_slice(&buffer[..read.min(remaining)]);
            }
            if read > remaining {
                truncated = true;
            }
        }

        (retained, truncated)
    })
}

fn join_output(reader: Option<thread::JoinHandle<(Vec<u8>, bool)>>) -> (Vec<u8>, bool) {
    reader
        .and_then(|reader| reader.join().ok())
        .unwrap_or_else(|| (Vec::new(), true))
}

fn command_error(
    code: &'static str,
    detail: impl Into<String>,
    keep_runtime_offline: bool,
) -> OfflineRestoreError {
    OfflineRestoreError::new(
        code,
        if keep_runtime_offline {
            MESSAGE_MANUAL_RECOVERY
        } else {
            "La restauration n’a pas démarré. Aucune donnée n’a été modifiée."
        },
        detail,
        keep_runtime_offline,
    )
}

fn validate_operation_id(operation_id: &str) -> Result<(), OfflineRestoreError> {
    if Uuid::parse_str(operation_id).is_err() {
        return Err(OfflineRestoreError::new(
            "restore_operation_invalid",
            "L’identifiant de restauration est invalide. Aucune donnée n’a été modifiée.",
            "restore operation identifier is not a UUID",
            false,
        ));
    }

    Ok(())
}

fn unix_time() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

#[cfg(windows)]
fn configure_platform_process(command: &mut Command) {
    use std::os::windows::process::CommandExt;
    use windows_sys::Win32::System::Threading::{CREATE_NEW_PROCESS_GROUP, CREATE_NO_WINDOW};

    command.creation_flags(CREATE_NEW_PROCESS_GROUP | CREATE_NO_WINDOW);
}

#[cfg(not(windows))]
fn configure_platform_process(_command: &mut Command) {}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    struct PreparedFixture {
        root: PathBuf,
        work_root: PathBuf,
        journal_root: PathBuf,
        authorization: OfflineRestoreAuthorizationArtifact,
        staged_database: PathBuf,
        plan_path: PathBuf,
        journal_path: PathBuf,
    }

    impl Drop for PreparedFixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.root);
        }
    }

    fn prepared_fixture() -> PreparedFixture {
        let operation_id = "9b82c22e-4eef-47ad-b2db-2f2c904d69d2";
        let root =
            std::env::temp_dir().join(format!("medismart-restore-preflight-{}", Uuid::new_v4()));
        let work_root = root.join("restore-work");
        let journal_root = root.join("restore-journals");
        let workspace = work_root.join(operation_id);
        let staged = workspace.join("staged");
        fs::create_dir_all(&staged).unwrap();
        fs::create_dir_all(&journal_root).unwrap();
        let staged_database = staged.join("database.sqlite3");
        let database_bytes = b"SQLite format 3\0validated-fixture";
        fs::write(&staged_database, database_bytes).unwrap();
        let database_sha256 = hex_lower(&Sha256::digest(database_bytes));
        let plan = serde_json::json!({
            "plan_version": 1,
            "operation_id": operation_id,
            "encrypted_archive_sha256": "11".repeat(32),
            "inner_archive_sha256": "22".repeat(32),
            "manifest": {
                "format": "medismart-backup",
                "format_version": 1,
                "backup_id": "8d6708f1-7bb9-43df-abcf-2e1b9fbf2654",
                "schema_version": 1,
                "application_version": "2.2.0-test",
                "created_at": "2026-08-05T10:00:00+00:00",
                "database_driver": "sqlite",
                "installation_id": "8c138db2-b8ca-4551-aec3-5be85fb3537a",
                "migration_count": 1,
                "latest_migration": "2026_08_05_000000_fixture",
                "migration_set_sha256": "33".repeat(32),
                "components": [
                    {"name":"database","path":"database.sqlite3","file_count":1,"size":database_bytes.len()},
                    {"name":"private_storage","path":"storage/private","file_count":0,"size":0},
                    {"name":"public_storage","path":"storage/public","file_count":0,"size":0}
                ],
                "consistency": {
                    "database": "sqlite-vacuum-into",
                    "assets": "post-snapshot-inventory-and-verification",
                    "writers_quiesced": false
                },
                "integrity": {
                    "profile": "sha256-v1",
                    "authenticated": false,
                    "purpose": "corruption-detection"
                },
                "portability": {
                    "profile": "installation-snapshot-v1",
                    "machine_bound_state": "included",
                    "secrets": "source-app-key-bound"
                },
                "encryption": {"enabled":false,"algorithm":null}
            },
            "staged_file_count": 1,
            "staged_bytes": database_bytes.len(),
            "inventory": [
                {"path":"database.sqlite3","size":database_bytes.len(),"sha256":database_sha256}
            ]
        });
        let plan_json = serde_json::to_string(&plan).unwrap();
        let plan_sha256 = hex_lower(&Sha256::digest(plan_json.as_bytes()));
        let plan_path = workspace.join("restore-plan.json");
        fs::write(
            &plan_path,
            format!(
                r#"{{"plan":{plan_json},"sha256":"{plan_sha256}"}}
"#
            ),
        )
        .unwrap();

        let context = ReadyJournalContext {
            plan_sha256: plan_sha256.clone(),
            web_apply_enabled: false,
        };
        let unsigned = UnsignedReadyJournalRecord {
            sequence: 5,
            operation_id,
            event: "ready_for_offline_apply",
            occurred_at: "2026-08-05T10:01:00+00:00",
            context: &context,
        };
        let journal_sha256 = hex_lower(&Sha256::digest(serde_json::to_vec(&unsigned).unwrap()));
        let context_json = serde_json::to_string(&context).unwrap();
        let journal_path = journal_root.join(format!("{operation_id}.jsonl"));
        fs::write(
            &journal_path,
            format!(
                r#"{{"sequence":5,"operation_id":"{operation_id}","event":"ready_for_offline_apply","occurred_at":"2026-08-05T10:01:00+00:00","context":{context_json},"sha256":"{journal_sha256}"}}
"#
            ),
        )
        .unwrap();

        PreparedFixture {
            root,
            work_root,
            journal_root,
            authorization: OfflineRestoreAuthorizationArtifact {
                protocol: OFFLINE_RESTORE_AUTHORIZATION_PROTOCOL.to_owned(),
                version: OFFLINE_RESTORE_AUTHORIZATION_VERSION,
                operation_id: operation_id.to_owned(),
                plan_sha256,
            },
            staged_database,
            plan_path,
            journal_path,
        }
    }

    struct TestLease {
        valid: AtomicBool,
        checks: Arc<Mutex<Vec<&'static str>>>,
    }

    impl ExclusiveRestoreProcessLease for TestLease {
        fn assert_exclusive(&self) -> Result<(), OfflineRestoreError> {
            self.checks.lock().unwrap().push("lease");

            if self.valid.load(Ordering::SeqCst) {
                Ok(())
            } else {
                Err(command_error("ownership_lost", "test ownership lost", true))
            }
        }
    }

    struct TestOwner {
        events: Arc<Mutex<Vec<&'static str>>>,
        lease_checks: Arc<Mutex<Vec<&'static str>>>,
    }

    impl OfflineRestoreProcessOwner for TestOwner {
        fn stop_writers_and_acquire_restore_lease(
            &self,
        ) -> Result<Arc<dyn ExclusiveRestoreProcessLease>, OfflineRestoreError> {
            self.events.lock().unwrap().push("stop");

            Ok(Arc::new(TestLease {
                valid: AtomicBool::new(true),
                checks: Arc::clone(&self.lease_checks),
            }))
        }

        fn start_restored_runtime_and_verify(&self) -> Result<(), OfflineRestoreError> {
            self.events.lock().unwrap().push("start_restored");
            Ok(())
        }

        fn resume_previous_runtime(&self) -> Result<(), OfflineRestoreError> {
            self.events.lock().unwrap().push("resume_previous");
            Ok(())
        }
    }

    struct TestLauncher {
        events: Arc<Mutex<Vec<&'static str>>>,
        outcome: Result<OfflineRestoreOutcome, OfflineRestoreError>,
    }

    impl OfflineRestoreCommandLauncher for TestLauncher {
        fn launch(
            &self,
            _operation_id: &str,
            lease: Arc<dyn ExclusiveRestoreProcessLease>,
        ) -> Result<OfflineRestoreOutcome, OfflineRestoreError> {
            self.events.lock().unwrap().push("launch");
            lease.assert_exclusive()?;

            self.outcome.as_ref().map(Clone::clone).map_err(|error| {
                OfflineRestoreError::new(
                    error.code(),
                    error.operator_message_fr(),
                    error.to_string(),
                    error.keep_runtime_offline(),
                )
            })
        }
    }

    fn outcome(status: OfflineRestoreStatus) -> OfflineRestoreOutcome {
        OfflineRestoreOutcome {
            status,
            message_fr: match status {
                OfflineRestoreStatus::AppliedPendingRestart => MESSAGE_APPLIED,
                OfflineRestoreStatus::RolledBack => MESSAGE_ROLLED_BACK,
                OfflineRestoreStatus::RefusedNoMutation => MESSAGE_REFUSED,
                OfflineRestoreStatus::ManualRecoveryRequired => MESSAGE_MANUAL_RECOVERY,
            },
        }
    }

    #[test]
    fn coordinator_stops_writers_before_launch_and_health_checks_after_apply() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let owner = TestOwner {
            events: Arc::clone(&events),
            lease_checks: Arc::new(Mutex::new(Vec::new())),
        };
        let launcher = TestLauncher {
            events: Arc::clone(&events),
            outcome: Ok(outcome(OfflineRestoreStatus::AppliedPendingRestart)),
        };

        let result =
            coordinate_offline_restore(&owner, &launcher, "9b82c22e-4eef-47ad-b2db-2f2c904d69d2")
                .unwrap();

        assert_eq!(result.status, OfflineRestoreStatus::AppliedPendingRestart);
        assert_eq!(
            *events.lock().unwrap(),
            vec!["stop", "launch", "start_restored"]
        );
    }

    #[test]
    fn manual_recovery_result_never_restarts_runtime() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let owner = TestOwner {
            events: Arc::clone(&events),
            lease_checks: Arc::new(Mutex::new(Vec::new())),
        };
        let launcher = TestLauncher {
            events: Arc::clone(&events),
            outcome: Ok(outcome(OfflineRestoreStatus::ManualRecoveryRequired)),
        };

        coordinate_offline_restore(&owner, &launcher, "9b82c22e-4eef-47ad-b2db-2f2c904d69d2")
            .unwrap();

        assert_eq!(*events.lock().unwrap(), vec!["stop", "launch"]);
    }

    #[test]
    fn refused_result_resumes_previous_runtime() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let owner = TestOwner {
            events: Arc::clone(&events),
            lease_checks: Arc::new(Mutex::new(Vec::new())),
        };
        let launcher = TestLauncher {
            events: Arc::clone(&events),
            outcome: Ok(outcome(OfflineRestoreStatus::RefusedNoMutation)),
        };

        coordinate_offline_restore(&owner, &launcher, "9b82c22e-4eef-47ad-b2db-2f2c904d69d2")
            .unwrap();

        assert_eq!(
            *events.lock().unwrap(),
            vec!["stop", "launch", "resume_previous"]
        );
    }

    #[test]
    fn uncertain_launcher_failure_keeps_runtime_offline() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let owner = TestOwner {
            events: Arc::clone(&events),
            lease_checks: Arc::new(Mutex::new(Vec::new())),
        };
        let launcher = TestLauncher {
            events: Arc::clone(&events),
            outcome: Err(command_error("uncertain", "test uncertain state", true)),
        };

        let error =
            coordinate_offline_restore(&owner, &launcher, "9b82c22e-4eef-47ad-b2db-2f2c904d69d2")
                .unwrap_err();

        assert!(error.keep_runtime_offline());
        assert_eq!(*events.lock().unwrap(), vec!["stop", "launch"]);
    }

    #[test]
    fn php_and_rust_hmac_contract_has_a_fixed_vector() {
        let secret = [0x42_u8; 32];
        let proof = lease_proof(
            "response",
            "9b82c22e-4eef-47ad-b2db-2f2c904d69d2",
            "Y2hhbGxlbmdlLWZvci1maXhlZC12ZWN0b3I",
            1_900_000_000,
            &secret,
        );

        assert_eq!(
            proof,
            "049206f19d95a817781b3e7e73f60678ef80df3d11bac325db92130e2cfd05b4"
        );
    }

    #[test]
    fn native_result_requires_matching_exit_status_and_fixed_french_message() {
        let line = format!(
            "{{\"protocol\":\"{RESULT_PROTOCOL}\",\"version\":1,\"status\":\"rolled_back\",\"message_fr\":\"{MESSAGE_ROLLED_BACK}\"}}\n"
        );

        assert_eq!(
            parse_native_result(Some(20), line.as_bytes())
                .unwrap()
                .status,
            OfflineRestoreStatus::RolledBack
        );
        assert!(parse_native_result(Some(0), line.as_bytes()).is_err());
    }

    #[test]
    fn loopback_lease_refuses_requests_after_native_ownership_is_lost() {
        let checks = Arc::new(Mutex::new(Vec::new()));
        let lease = Arc::new(TestLease {
            valid: AtomicBool::new(true),
            checks,
        });
        let operation_id = "9b82c22e-4eef-47ad-b2db-2f2c904d69d2";
        let server =
            RestoreLeaseServer::start(operation_id, lease.clone(), Duration::from_secs(30))
                .unwrap();
        let challenge = URL_SAFE_NO_PAD.encode([7_u8; 32]);
        let proof = lease_proof(
            "request",
            operation_id,
            &challenge,
            server.expires_at_unix,
            &server.secret.0,
        );
        let request = format!(
            "{{\"protocol\":\"{LEASE_PROTOCOL}\",\"version\":1,\"operation_id\":\"{operation_id}\",\"expires_at_unix\":{},\"challenge\":\"{challenge}\",\"proof\":\"{proof}\"}}\n",
            server.expires_at_unix
        );

        let mut stream = TcpStream::connect((Ipv4Addr::LOCALHOST, server.port)).unwrap();
        stream.write_all(request.as_bytes()).unwrap();
        let mut response = String::new();
        BufReader::new(stream).read_line(&mut response).unwrap();
        assert!(response.contains("\"ok\":true"));

        lease.valid.store(false, Ordering::SeqCst);
        let mut stream = TcpStream::connect((Ipv4Addr::LOCALHOST, server.port)).unwrap();
        stream
            .set_read_timeout(Some(Duration::from_millis(250)))
            .unwrap();
        stream.write_all(request.as_bytes()).unwrap();
        let mut denied = String::new();
        let _ = BufReader::new(stream).read_line(&mut denied);
        assert!(denied.is_empty());
    }

    #[test]
    fn managed_preflight_rechecks_plan_journal_sqlite_and_inventory_hashes() {
        let fixture = prepared_fixture();

        let verified = verify_prepared_restore_authorization(
            &fixture.work_root,
            &fixture.journal_root,
            &fixture.authorization,
        )
        .unwrap();

        assert_eq!(
            verified.operation_id(),
            fixture.authorization.operation_id()
        );
        assert_eq!(verified.plan_sha256(), fixture.authorization.plan_sha256());
    }

    #[test]
    fn failed_preflight_retains_preparation_and_journal_artifacts() {
        let fixture = prepared_fixture();
        fs::write(&fixture.staged_database, b"tampered after preparation").unwrap();

        let error = verify_prepared_restore_authorization(
            &fixture.work_root,
            &fixture.journal_root,
            &fixture.authorization,
        )
        .unwrap_err();

        assert_eq!(error.code(), "restore_preflight_failed");
        assert!(!error.keep_runtime_offline());
        assert!(fixture.staged_database.exists());
        assert!(fixture.plan_path.exists());
        assert!(fixture.journal_path.exists());
    }

    #[test]
    fn native_php_launcher_has_one_fixed_command_shape() {
        let config = OfflineRestorePhpConfig::new(
            PathBuf::from("fixed-php"),
            PathBuf::from("fixed-artisan"),
            PathBuf::from("fixed-root"),
        );
        let launcher = PhpOfflineRestoreCommandLauncher::new(config);
        let operation_id = "9b82c22e-4eef-47ad-b2db-2f2c904d69d2";
        let command = launcher.build_command(operation_id);
        let arguments = command
            .get_args()
            .map(|argument| argument.to_string_lossy().into_owned())
            .collect::<Vec<_>>();

        assert_eq!(command.get_program(), "fixed-php");
        assert_eq!(
            arguments,
            vec![
                "fixed-artisan",
                "medismart:restore:native-apply",
                operation_id,
                "--no-interaction",
            ]
        );
        assert_eq!(
            command
                .get_envs()
                .find(|(name, _)| *name == "MEDISMART_NATIVE_RESTORE")
                .and_then(|(_, value)| value)
                .and_then(|value| value.to_str()),
            Some("1")
        );
    }
}
