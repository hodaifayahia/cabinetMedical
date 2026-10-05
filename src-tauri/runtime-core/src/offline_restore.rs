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

    type LabeledJsonMutation = (&'static str, Box<dyn Fn(&mut serde_json::Value)>);

    const OPERATION_ID: &str = "9b82c22e-4eef-47ad-b2db-2f2c904d69d2";

    fn status_name(status: OfflineRestoreStatus) -> String {
        serde_json::to_value(status)
            .unwrap()
            .as_str()
            .unwrap()
            .to_owned()
    }

    fn result_line(status: OfflineRestoreStatus, message: &str) -> String {
        serde_json::json!({
            "protocol": RESULT_PROTOCOL,
            "version": RESULT_VERSION,
            "status": status_name(status),
            "message_fr": message,
        })
        .to_string()
    }

    fn write_journal(
        fixture: &PreparedFixture,
        sequence: u64,
        event: &str,
        web_apply_enabled: bool,
        plan_sha256: &str,
    ) {
        let context = ReadyJournalContext {
            plan_sha256: plan_sha256.to_owned(),
            web_apply_enabled,
        };
        let unsigned = UnsignedReadyJournalRecord {
            sequence,
            operation_id: OPERATION_ID,
            event,
            occurred_at: "2026-08-05T10:01:00+00:00",
            context: &context,
        };
        let sha256 = hex_lower(&Sha256::digest(serde_json::to_vec(&unsigned).unwrap()));
        let mut record = serde_json::to_value(&unsigned).unwrap();
        record["sha256"] = serde_json::json!(sha256);
        fs::write(&fixture.journal_path, format!("{record}\n")).unwrap();
    }

    fn rewrite_plan(fixture: &mut PreparedFixture, change: impl FnOnce(&mut serde_json::Value)) {
        let document: serde_json::Value =
            serde_json::from_slice(&fs::read(&fixture.plan_path).unwrap()).unwrap();
        let mut plan = document["plan"].clone();
        change(&mut plan);
        let plan_json = serde_json::to_string(&plan).unwrap();
        let plan_sha256 = hex_lower(&Sha256::digest(plan_json.as_bytes()));
        fs::write(
            &fixture.plan_path,
            format!(r#"{{"plan":{plan_json},"sha256":"{plan_sha256}"}}"#),
        )
        .unwrap();
        write_journal(fixture, 5, "ready_for_offline_apply", false, &plan_sha256);
        fixture.authorization.plan_sha256 = plan_sha256;
    }

    fn preflight(
        fixture: &PreparedFixture,
    ) -> Result<VerifiedPreparedRestore, OfflineRestoreError> {
        verify_prepared_restore_authorization(
            &fixture.work_root,
            &fixture.journal_root,
            &fixture.authorization,
        )
    }

    fn assert_preflight_fails(fixture: &PreparedFixture, label: &str) {
        let error = preflight(fixture)
            .err()
            .unwrap_or_else(|| panic!("{label} was accepted"));
        assert_eq!(error.code(), "restore_preflight_failed", "{label}");
        assert!(!error.keep_runtime_offline(), "{label}");
    }

    fn add_staged_file(fixture: &mut PreparedFixture, relative: &str, bytes: &[u8]) {
        let staged = fixture.staged_database.parent().unwrap().to_path_buf();
        let path = staged.join(relative);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(&path, bytes).unwrap();
        let sha256 = hex_lower(&Sha256::digest(bytes));
        let size = bytes.len() as u64;
        let relative = relative.to_owned();
        rewrite_plan(fixture, move |plan| {
            plan["inventory"]
                .as_array_mut()
                .unwrap()
                .push(serde_json::json!({
                    "path": relative, "size": size, "sha256": sha256,
                }));
            plan["staged_file_count"] =
                serde_json::json!(plan["staged_file_count"].as_u64().unwrap() + 1);
            plan["staged_bytes"] = serde_json::json!(plan["staged_bytes"].as_u64().unwrap() + size);
            let private = &mut plan["manifest"]["components"][1];
            private["file_count"] = serde_json::json!(private["file_count"].as_u64().unwrap() + 1);
            private["size"] = serde_json::json!(private["size"].as_u64().unwrap() + size);
        });
    }

    struct ScriptedOwner {
        events: Arc<Mutex<Vec<&'static str>>>,
        stop_fails: bool,
        lease_valid: bool,
        start_fails: bool,
        resume_fails: bool,
    }

    impl ScriptedOwner {
        fn new(events: &Arc<Mutex<Vec<&'static str>>>) -> Self {
            Self {
                events: Arc::clone(events),
                stop_fails: false,
                lease_valid: true,
                start_fails: false,
                resume_fails: false,
            }
        }
    }

    impl OfflineRestoreProcessOwner for ScriptedOwner {
        fn stop_writers_and_acquire_restore_lease(
            &self,
        ) -> Result<Arc<dyn ExclusiveRestoreProcessLease>, OfflineRestoreError> {
            self.events.lock().unwrap().push("stop");
            if self.stop_fails {
                return Err(command_error("stop_failed", "test", true));
            }
            Ok(Arc::new(TestLease {
                valid: AtomicBool::new(self.lease_valid),
                checks: Arc::new(Mutex::new(Vec::new())),
            }))
        }

        fn start_restored_runtime_and_verify(&self) -> Result<(), OfflineRestoreError> {
            self.events.lock().unwrap().push("start_restored");
            if self.start_fails {
                return Err(command_error("unhealthy", "test", true));
            }
            Ok(())
        }

        fn resume_previous_runtime(&self) -> Result<(), OfflineRestoreError> {
            self.events.lock().unwrap().push("resume_previous");
            if self.resume_fails {
                return Err(command_error("resume_failed", "test", true));
            }
            Ok(())
        }
    }

    fn run_coordinator(
        owner: &ScriptedOwner,
        outcome: Result<OfflineRestoreOutcome, OfflineRestoreError>,
        operation_id: &str,
    ) -> Result<OfflineRestoreOutcome, OfflineRestoreError> {
        let launcher = TestLauncher {
            events: Arc::clone(&owner.events),
            outcome,
        };
        coordinate_offline_restore(owner, &launcher, operation_id)
    }

    #[test]
    fn invalid_operation_id_is_rejected_before_any_service_stops() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let owner = ScriptedOwner::new(&events);

        let error = run_coordinator(
            &owner,
            Ok(outcome(OfflineRestoreStatus::AppliedPendingRestart)),
            "not-a-uuid",
        )
        .unwrap_err();

        assert_eq!(error.code(), "restore_operation_invalid");
        assert!(!error.keep_runtime_offline());
        assert!(events.lock().unwrap().is_empty());
    }

    #[test]
    fn failing_to_stop_writers_keeps_runtime_offline_without_launching() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let mut owner = ScriptedOwner::new(&events);
        owner.stop_fails = true;

        let error = run_coordinator(
            &owner,
            Ok(outcome(OfflineRestoreStatus::AppliedPendingRestart)),
            OPERATION_ID,
        )
        .unwrap_err();

        assert_eq!(error.code(), "restore_ownership_failed");
        assert!(error.keep_runtime_offline());
        assert_eq!(*events.lock().unwrap(), vec!["stop"]);
    }

    #[test]
    fn a_non_exclusive_lease_aborts_before_launch() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let mut owner = ScriptedOwner::new(&events);
        owner.lease_valid = false;

        let error = run_coordinator(
            &owner,
            Ok(outcome(OfflineRestoreStatus::AppliedPendingRestart)),
            OPERATION_ID,
        )
        .unwrap_err();

        assert_eq!(error.code(), "restore_ownership_failed");
        assert!(error.keep_runtime_offline());
        assert_eq!(*events.lock().unwrap(), vec!["stop"]);
    }

    #[test]
    fn safe_launcher_failure_resumes_the_previous_runtime_and_returns_the_original_error() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let owner = ScriptedOwner::new(&events);

        let error = run_coordinator(
            &owner,
            Err(command_error("restore_command_spawn_failed", "test", false)),
            OPERATION_ID,
        )
        .unwrap_err();

        assert_eq!(error.code(), "restore_command_spawn_failed");
        assert!(!error.keep_runtime_offline());
        assert_eq!(
            *events.lock().unwrap(),
            vec!["stop", "launch", "resume_previous"]
        );
    }

    #[test]
    fn failing_to_resume_after_a_safe_failure_keeps_runtime_offline() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let mut owner = ScriptedOwner::new(&events);
        owner.resume_fails = true;

        let error = run_coordinator(
            &owner,
            Err(command_error("restore_command_spawn_failed", "test", false)),
            OPERATION_ID,
        )
        .unwrap_err();

        assert_eq!(error.code(), "restore_runtime_resume_failed");
        assert!(error.keep_runtime_offline());
    }

    #[test]
    fn rolled_back_result_resumes_previous_runtime() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let owner = ScriptedOwner::new(&events);

        let result = run_coordinator(
            &owner,
            Ok(outcome(OfflineRestoreStatus::RolledBack)),
            OPERATION_ID,
        )
        .unwrap();

        assert_eq!(result.message_fr, MESSAGE_ROLLED_BACK);
        assert_eq!(
            *events.lock().unwrap(),
            vec!["stop", "launch", "resume_previous"]
        );
    }

    #[test]
    fn unhealthy_restored_runtime_is_reported_and_kept_offline() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let mut owner = ScriptedOwner::new(&events);
        owner.start_fails = true;

        let error = run_coordinator(
            &owner,
            Ok(outcome(OfflineRestoreStatus::AppliedPendingRestart)),
            OPERATION_ID,
        )
        .unwrap_err();

        assert_eq!(error.code(), "restored_runtime_unhealthy");
        assert!(error.keep_runtime_offline());
        assert_eq!(
            *events.lock().unwrap(),
            vec!["stop", "launch", "start_restored"]
        );
    }

    #[test]
    fn failing_to_resume_after_refusal_is_an_error() {
        let events = Arc::new(Mutex::new(Vec::new()));
        let mut owner = ScriptedOwner::new(&events);
        owner.resume_fails = true;

        let error = run_coordinator(
            &owner,
            Ok(outcome(OfflineRestoreStatus::RefusedNoMutation)),
            OPERATION_ID,
        )
        .unwrap_err();

        assert_eq!(error.code(), "restore_runtime_resume_failed");
        assert!(error.keep_runtime_offline());
    }

    #[test]
    fn native_results_map_each_status_to_its_exit_code() {
        for (status, exit, message) in [
            (
                OfflineRestoreStatus::AppliedPendingRestart,
                0,
                MESSAGE_APPLIED,
            ),
            (OfflineRestoreStatus::RefusedNoMutation, 10, MESSAGE_REFUSED),
            (OfflineRestoreStatus::RolledBack, 20, MESSAGE_ROLLED_BACK),
            (
                OfflineRestoreStatus::ManualRecoveryRequired,
                30,
                MESSAGE_MANUAL_RECOVERY,
            ),
        ] {
            let line = result_line(status, message);
            let parsed = parse_native_result(Some(exit), line.as_bytes()).unwrap();
            assert_eq!(parsed, outcome(status));
            for wrong_exit in [None, Some(exit + 1), Some(-1)] {
                assert_eq!(
                    parse_native_result(wrong_exit, line.as_bytes())
                        .unwrap_err()
                        .code(),
                    "restore_result_invalid"
                );
            }
        }
    }

    #[test]
    fn native_result_tolerates_surrounding_blank_lines_only() {
        let line = result_line(OfflineRestoreStatus::RolledBack, MESSAGE_ROLLED_BACK);

        assert!(parse_native_result(Some(20), format!("\n  \n{line}\n\n").as_bytes()).is_ok());
        assert!(parse_native_result(Some(20), format!("{line}\n{line}\n").as_bytes()).is_err());
        assert!(parse_native_result(Some(20), format!("noise\n{line}\n").as_bytes()).is_err());
        assert!(parse_native_result(Some(20), b"").is_err());
        assert!(parse_native_result(Some(20), b"\xff\xfe").is_err());
    }

    #[test]
    fn native_result_rejects_any_deviation_from_the_record_contract() {
        let valid: serde_json::Value = serde_json::from_str(&result_line(
            OfflineRestoreStatus::RolledBack,
            MESSAGE_ROLLED_BACK,
        ))
        .unwrap();
        let mut cases = Vec::new();
        for (field, value) in [
            ("protocol", serde_json::json!("other-protocol")),
            ("version", serde_json::json!(2)),
            ("message_fr", serde_json::json!(MESSAGE_APPLIED)),
            ("status", serde_json::json!("exploded")),
        ] {
            let mut case = valid.clone();
            case[field] = value;
            cases.push(case);
        }
        let mut extra = valid.clone();
        extra["detail"] = serde_json::json!("x");
        cases.push(extra);
        let mut missing = valid.clone();
        missing.as_object_mut().unwrap().remove("message_fr");
        cases.push(missing);

        for case in cases {
            let error = parse_native_result(Some(20), case.to_string().as_bytes()).unwrap_err();
            assert_eq!(error.code(), "restore_result_invalid", "{case}");
            assert!(error.keep_runtime_offline());
            assert_eq!(error.operator_message_fr(), MESSAGE_MANUAL_RECOVERY);
        }
    }

    #[test]
    fn restore_statuses_serialize_as_snake_case() {
        assert_eq!(
            status_name(OfflineRestoreStatus::AppliedPendingRestart),
            "applied_pending_restart"
        );
        assert_eq!(status_name(OfflineRestoreStatus::RolledBack), "rolled_back");
        assert_eq!(
            status_name(OfflineRestoreStatus::RefusedNoMutation),
            "refused_no_mutation"
        );
        assert_eq!(
            status_name(OfflineRestoreStatus::ManualRecoveryRequired),
            "manual_recovery_required"
        );
    }

    #[test]
    fn command_errors_pick_the_operator_message_from_the_offline_flag() {
        let offline = command_error("x", "detail", true);
        let safe = command_error("y", "detail", false);

        assert_eq!(offline.operator_message_fr(), MESSAGE_MANUAL_RECOVERY);
        assert!(offline.keep_runtime_offline());
        assert_ne!(safe.operator_message_fr(), MESSAGE_MANUAL_RECOVERY);
        assert!(!safe.keep_runtime_offline());
        assert_eq!(safe.to_string(), "y: detail");
    }

    #[test]
    fn hmac_matches_rfc_4231_vectors_including_long_keys() {
        assert_eq!(
            hex_lower(&hmac_sha256(&[0x0b; 20], b"Hi There")),
            "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7"
        );
        assert_eq!(
            hex_lower(&hmac_sha256(
                &[0xaa; 131],
                b"Test Using Larger Than Block-Size Key - Hash Key First"
            )),
            "60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54"
        );
    }

    #[test]
    fn lease_proofs_are_bound_to_direction_and_every_input() {
        let secret = [0x42_u8; 32];
        let base = lease_proof("request", OPERATION_ID, "challenge", 10, &secret);

        assert_eq!(base.len(), 64);
        assert_ne!(
            base,
            lease_proof("response", OPERATION_ID, "challenge", 10, &secret)
        );
        assert_ne!(
            base,
            lease_proof("request", "other", "challenge", 10, &secret)
        );
        assert_ne!(
            base,
            lease_proof("request", OPERATION_ID, "challengf", 10, &secret)
        );
        assert_ne!(
            base,
            lease_proof("request", OPERATION_ID, "challenge", 11, &secret)
        );
        assert_ne!(
            base,
            lease_proof("request", OPERATION_ID, "challenge", 10, &[0x43_u8; 32])
        );
    }

    #[test]
    fn hex_and_digest_helpers_are_strict() {
        assert_eq!(hex_lower(&[0x00, 0x0f, 0xa0, 0xff]), "000fa0ff");
        assert_eq!(hex_lower(&[]), "");
        assert!(is_sha256(&"ab".repeat(32)));
        assert!(!is_sha256(&"AB".repeat(32)));
        assert!(!is_sha256(&"ab".repeat(31)));
        assert!(!is_sha256(&"gg".repeat(32)));
        assert!(constant_time_eq(b"same", b"same"));
        assert!(!constant_time_eq(b"same", b"Same"));
        assert!(!constant_time_eq(b"same", b"sam"));
    }

    #[test]
    fn only_canonical_hyphenated_lowercase_uuids_are_canonical() {
        assert!(is_canonical_uuid(OPERATION_ID));
        for invalid in [
            OPERATION_ID.to_uppercase(),
            OPERATION_ID.replace('-', ""),
            format!("{{{OPERATION_ID}}}"),
            format!("urn:uuid:{OPERATION_ID}"),
            String::new(),
            "not-a-uuid".to_owned(),
        ] {
            assert!(!is_canonical_uuid(&invalid), "{invalid}");
        }
    }

    #[test]
    fn managed_inventory_paths_are_portable_and_inside_managed_roots() {
        for valid in [
            "database.sqlite3",
            "private/clinical-documents/a.pdf",
            "private/patient-documents/2026/scan 1.png",
            "private/medical-models/model.json",
            "public/cabinet/logo.png",
        ] {
            assert!(validate_managed_inventory_path(valid).is_ok(), "{valid}");
        }
        let too_deep = format!("public/cabinet/{}x", "d/".repeat(31));
        let long_segment = format!("public/cabinet/{}", "a".repeat(256));
        let too_long = format!("public/cabinet/{}", "a/".repeat(1100));
        for invalid in [
            "",
            "/database.sqlite3",
            "database.sqlite",
            "private/clinical-documents/",
            "private/clinical-documents",
            "private/other/a.pdf",
            "public/cabinet/../../etc/passwd",
            "public/cabinet/./a",
            "public/cabinet//a",
            "public\\cabinet\\a",
            "public/cabinet/C:a",
            "public/cabinet/a?.png",
            "public/cabinet/a*.png",
            "public/cabinet/a|b",
            "public/cabinet/a<b>",
            "public/cabinet/\"q\"",
            "public/cabinet/tab\tname",
            "public/cabinet/trailing.",
            "public/cabinet/trailing ",
            "public/cabinet/CON",
            "public/cabinet/nul.txt",
            "public/cabinet/com1.log",
            too_deep.as_str(),
            long_segment.as_str(),
            too_long.as_str(),
        ] {
            assert_eq!(
                validate_managed_inventory_path(invalid)
                    .err()
                    .map(|error| error.code()),
                Some("restore_preflight_failed"),
                "{invalid:?}"
            );
        }
    }

    #[test]
    fn reserved_windows_device_names_are_detected_by_stem() {
        for reserved in [
            "CON", "con", "Con.txt", "PRN", "AUX.log", "NUL", "COM1", "com9.x", "LPT1", "lpt9",
            "CONIN$", "conout$",
        ] {
            assert!(is_reserved_windows_name(reserved), "{reserved}");
        }
        for allowed in [
            "COM10", "LPT0", "COM", "CONSOLE", "nullable", "aux-file", "x.con",
        ] {
            assert!(!is_reserved_windows_name(allowed), "{allowed}");
        }
    }

    #[test]
    fn authorization_must_match_the_fixed_native_contract() {
        let fixture = prepared_fixture();
        let mutations: Vec<fn(&mut OfflineRestoreAuthorizationArtifact)> = vec![
            |authorization| authorization.protocol = "other".to_owned(),
            |authorization| authorization.version = 2,
            |authorization| authorization.operation_id = authorization.operation_id.to_uppercase(),
            |authorization| authorization.operation_id = "../escape".to_owned(),
            |authorization| authorization.plan_sha256 = authorization.plan_sha256.to_uppercase(),
            |authorization| authorization.plan_sha256.truncate(10),
        ];
        for mutation in mutations {
            let mut authorization = fixture.authorization.clone();
            mutation(&mut authorization);
            let error = verify_prepared_restore_authorization(
                &fixture.work_root,
                &fixture.journal_root,
                &authorization,
            )
            .unwrap_err();
            assert_eq!(error.code(), "restore_authorization_invalid");
            assert!(!error.keep_runtime_offline());
        }
    }

    #[test]
    fn authorization_artifacts_reject_unknown_fields_and_round_trip() {
        let fixture = prepared_fixture();
        let json = serde_json::to_value(&fixture.authorization).unwrap();
        let decoded: OfflineRestoreAuthorizationArtifact =
            serde_json::from_value(json.clone()).unwrap();
        assert_eq!(decoded, fixture.authorization);

        let mut with_path = json;
        with_path["archive_path"] = serde_json::json!("C:/evil.zip");
        assert!(serde_json::from_value::<OfflineRestoreAuthorizationArtifact>(with_path).is_err());
    }

    #[test]
    fn rewritten_fixture_plan_still_verifies() {
        let mut fixture = prepared_fixture();
        rewrite_plan(&mut fixture, |_| {});

        assert!(preflight(&fixture).is_ok());
    }

    #[test]
    fn plan_digest_must_match_authorization_and_contents() {
        let mut fixture = prepared_fixture();
        fixture.authorization.plan_sha256 = "ab".repeat(32);
        assert_preflight_fails(&fixture, "authorization digest mismatch");

        let fixture = prepared_fixture();
        let original = fs::read_to_string(&fixture.plan_path).unwrap();
        fs::write(
            &fixture.plan_path,
            original.replace("2.2.0-test", "2.2.1-test"),
        )
        .unwrap();
        assert_preflight_fails(&fixture, "plan body tampered");
    }

    #[test]
    fn plan_document_and_workspace_must_be_present_and_well_formed() {
        let fixture = prepared_fixture();
        fs::write(&fixture.plan_path, b"{").unwrap();
        assert_preflight_fails(&fixture, "truncated plan");

        let fixture = prepared_fixture();
        fs::write(&fixture.plan_path, b"x").unwrap();
        assert_preflight_fails(&fixture, "one-byte plan");

        let fixture = prepared_fixture();
        fs::remove_file(&fixture.plan_path).unwrap();
        assert_preflight_fails(&fixture, "missing plan");

        let fixture = prepared_fixture();
        assert!(verify_prepared_restore_authorization(
            &fixture.root.join("absent"),
            &fixture.journal_root,
            &fixture.authorization
        )
        .is_err());
    }

    #[test]
    fn plan_metadata_mutations_are_rejected() {
        let cases: Vec<LabeledJsonMutation> = vec![
            (
                "plan_version",
                Box::new(|plan| plan["plan_version"] = serde_json::json!(2)),
            ),
            (
                "operation_id",
                Box::new(|plan| {
                    plan["operation_id"] = serde_json::json!("8d6708f1-7bb9-43df-abcf-2e1b9fbf2654")
                }),
            ),
            (
                "encrypted digest",
                Box::new(|plan| plan["encrypted_archive_sha256"] = serde_json::json!("nope")),
            ),
            (
                "inner digest",
                Box::new(|plan| plan["inner_archive_sha256"] = serde_json::json!("AB".repeat(32))),
            ),
            (
                "zero count",
                Box::new(|plan| plan["staged_file_count"] = serde_json::json!(0)),
            ),
            (
                "count mismatch",
                Box::new(|plan| plan["staged_file_count"] = serde_json::json!(2)),
            ),
            (
                "zero bytes",
                Box::new(|plan| plan["staged_bytes"] = serde_json::json!(0)),
            ),
            (
                "bytes mismatch",
                Box::new(|plan| {
                    plan["staged_bytes"] =
                        serde_json::json!(plan["staged_bytes"].as_u64().unwrap() + 1);
                }),
            ),
            (
                "unknown field",
                Box::new(|plan| plan["web_apply"] = serde_json::json!(true)),
            ),
            (
                "unsafe inventory path",
                Box::new(|plan| {
                    plan["inventory"][0]["path"] = serde_json::json!("../database.sqlite3")
                }),
            ),
            (
                "inventory digest",
                Box::new(|plan| plan["inventory"][0]["sha256"] = serde_json::json!("x")),
            ),
        ];
        for (label, change) in cases {
            let mut fixture = prepared_fixture();
            rewrite_plan(&mut fixture, |plan| change(plan));
            assert_preflight_fails(&fixture, label);
        }
    }

    #[test]
    fn manifest_contract_mutations_are_rejected() {
        let cases: Vec<LabeledJsonMutation> = vec![
            (
                "format",
                Box::new(|m| m["format"] = serde_json::json!("other-backup")),
            ),
            (
                "format_version",
                Box::new(|m| m["format_version"] = serde_json::json!(2)),
            ),
            (
                "schema_version",
                Box::new(|m| m["schema_version"] = serde_json::json!(2)),
            ),
            (
                "driver",
                Box::new(|m| m["database_driver"] = serde_json::json!("mysql")),
            ),
            (
                "blank version",
                Box::new(|m| m["application_version"] = serde_json::json!("   ")),
            ),
            (
                "long version",
                Box::new(|m| m["application_version"] = serde_json::json!("1".repeat(129))),
            ),
            (
                "empty created_at",
                Box::new(|m| m["created_at"] = serde_json::json!("")),
            ),
            (
                "installation uuid",
                Box::new(|m| {
                    m["installation_id"] = serde_json::json!("8C138DB2-B8CA-4551-AEC3-5BE85FB3537A")
                }),
            ),
            (
                "backup uuid",
                Box::new(|m| m["backup_id"] = serde_json::json!("backup")),
            ),
            (
                "empty latest migration",
                Box::new(|m| m["latest_migration"] = serde_json::json!("")),
            ),
            (
                "numeric latest migration",
                Box::new(|m| m["latest_migration"] = serde_json::json!(5)),
            ),
            (
                "long latest migration",
                Box::new(|m| m["latest_migration"] = serde_json::json!("m".repeat(256))),
            ),
            (
                "migration digest",
                Box::new(|m| m["migration_set_sha256"] = serde_json::json!("33")),
            ),
            (
                "writers quiesced",
                Box::new(|m| m["consistency"]["writers_quiesced"] = serde_json::json!(true)),
            ),
            (
                "consistency database",
                Box::new(|m| m["consistency"]["database"] = serde_json::json!("copy")),
            ),
            (
                "authenticated",
                Box::new(|m| m["integrity"]["authenticated"] = serde_json::json!(true)),
            ),
            (
                "integrity profile",
                Box::new(|m| m["integrity"]["profile"] = serde_json::json!("md5")),
            ),
            (
                "portability secrets",
                Box::new(|m| m["portability"]["secrets"] = serde_json::json!("included")),
            ),
            (
                "encryption",
                Box::new(|m| m["encryption"]["enabled"] = serde_json::json!(true)),
            ),
            (
                "algorithm",
                Box::new(|m| m["encryption"]["algorithm"] = serde_json::json!("aes")),
            ),
            (
                "component path",
                Box::new(|m| m["components"][1]["path"] = serde_json::json!("storage/other")),
            ),
            (
                "missing component",
                Box::new(|m| {
                    m["components"].as_array_mut().unwrap().pop();
                }),
            ),
            (
                "duplicate component",
                Box::new(|m| m["components"][2]["name"] = serde_json::json!("private_storage")),
            ),
            (
                "extra manifest field",
                Box::new(|m| m["notes"] = serde_json::json!("x")),
            ),
        ];
        for (label, change) in cases {
            let mut fixture = prepared_fixture();
            rewrite_plan(&mut fixture, |plan| change(&mut plan["manifest"]));
            assert_preflight_fails(&fixture, label);
        }
    }

    #[test]
    fn null_latest_migration_is_accepted() {
        let mut fixture = prepared_fixture();
        rewrite_plan(&mut fixture, |plan| {
            plan["manifest"]["latest_migration"] = serde_json::Value::Null;
        });

        assert!(preflight(&fixture).is_ok());
    }

    #[test]
    fn ready_journal_must_be_signed_ready_and_bound_to_the_plan() {
        let fixture = prepared_fixture();
        let plan_sha256 = fixture.authorization.plan_sha256.clone();

        write_journal(&fixture, 0, "ready_for_offline_apply", false, &plan_sha256);
        assert_preflight_fails(&fixture, "sequence zero");
        write_journal(&fixture, 5, "prepared", false, &plan_sha256);
        assert_preflight_fails(&fixture, "wrong event");
        write_journal(&fixture, 5, "ready_for_offline_apply", true, &plan_sha256);
        assert_preflight_fails(&fixture, "web apply enabled");
        write_journal(
            &fixture,
            5,
            "ready_for_offline_apply",
            false,
            &"ab".repeat(32),
        );
        assert_preflight_fails(&fixture, "other plan");

        write_journal(&fixture, 5, "ready_for_offline_apply", false, &plan_sha256);
        assert!(preflight(&fixture).is_ok());
        let signed = fs::read_to_string(&fixture.journal_path).unwrap();
        fs::write(
            &fixture.journal_path,
            signed.replace("\"sequence\":5", "\"sequence\":6"),
        )
        .unwrap();
        assert_preflight_fails(&fixture, "tampered sequence");
    }

    #[test]
    fn only_the_last_complete_journal_line_is_authoritative() {
        let fixture = prepared_fixture();
        let ready = fs::read_to_string(&fixture.journal_path).unwrap();

        fs::write(
            &fixture.journal_path,
            format!("{{\"older\":\"record\"}}\n{ready}"),
        )
        .unwrap();
        assert!(preflight(&fixture).is_ok());

        fs::write(
            &fixture.journal_path,
            format!("{ready}{{\"later\":true}}\n"),
        )
        .unwrap();
        assert_preflight_fails(&fixture, "later record");

        fs::write(&fixture.journal_path, ready.trim_end()).unwrap();
        assert_preflight_fails(&fixture, "missing trailing newline");

        fs::write(&fixture.journal_path, format!("{ready}\n")).unwrap();
        assert_preflight_fails(&fixture, "blank last line");

        fs::remove_file(&fixture.journal_path).unwrap();
        assert_preflight_fails(&fixture, "missing journal");
    }

    #[test]
    fn staged_files_must_exactly_match_the_inventory() {
        let mut fixture = prepared_fixture();
        add_staged_file(
            &mut fixture,
            "private/patient-documents/scan.pdf",
            b"%PDF-1.7 fixture",
        );
        assert!(preflight(&fixture).is_ok());

        let staged = fixture.staged_database.parent().unwrap().to_path_buf();
        fs::write(
            staged.join("private/patient-documents/extra.pdf"),
            b"unexpected",
        )
        .unwrap();
        assert_preflight_fails(&fixture, "extra staged file");
        fs::remove_file(staged.join("private/patient-documents/extra.pdf")).unwrap();

        fs::write(
            staged.join("private/patient-documents/scan.pdf"),
            b"%PDF-1.7 fixturX",
        )
        .unwrap();
        assert_preflight_fails(&fixture, "same-size tampering");
        fs::remove_file(staged.join("private/patient-documents/scan.pdf")).unwrap();
        assert_preflight_fails(&fixture, "missing staged file");
    }

    #[test]
    fn staged_files_outside_managed_roots_are_rejected() {
        let fixture = prepared_fixture();
        let staged = fixture.staged_database.parent().unwrap().to_path_buf();
        fs::create_dir_all(staged.join("unmanaged")).unwrap();
        fs::write(staged.join("unmanaged/file.txt"), b"x").unwrap();

        assert_preflight_fails(&fixture, "unmanaged staged path");
    }

    #[test]
    fn case_insensitive_inventory_collisions_are_rejected() {
        let mut fixture = prepared_fixture();
        add_staged_file(&mut fixture, "private/patient-documents/Scan.pdf", b"one");
        add_staged_file(&mut fixture, "private/patient-documents/scan.pdf", b"two");

        assert_preflight_fails(&fixture, "portable collision");
    }

    #[test]
    fn staged_database_must_carry_the_sqlite_header() {
        let mut fixture = prepared_fixture();
        let bytes = b"Not SQLite header but long enough".to_vec();
        fs::write(&fixture.staged_database, &bytes).unwrap();
        let sha256 = hex_lower(&Sha256::digest(&bytes));
        let size = bytes.len();
        rewrite_plan(&mut fixture, move |plan| {
            plan["inventory"][0]["sha256"] = serde_json::json!(sha256);
            plan["inventory"][0]["size"] = serde_json::json!(size);
            plan["staged_bytes"] = serde_json::json!(size);
            plan["manifest"]["components"][0]["size"] = serde_json::json!(size);
        });

        assert_preflight_fails(&fixture, "sqlite header");
    }

    #[cfg(unix)]
    #[test]
    fn symlinks_anywhere_in_the_managed_tree_are_rejected() {
        let fixture = prepared_fixture();
        let staged = fixture.staged_database.parent().unwrap().to_path_buf();
        std::os::unix::fs::symlink(&fixture.staged_database, staged.join("link")).unwrap();
        assert_preflight_fails(&fixture, "staged symlink");

        let fixture = prepared_fixture();
        let workspace = fixture.plan_path.parent().unwrap().to_path_buf();
        let moved = fixture.root.join("moved-plan.json");
        fs::rename(&fixture.plan_path, &moved).unwrap();
        std::os::unix::fs::symlink(&moved, workspace.join("restore-plan.json")).unwrap();
        assert_preflight_fails(&fixture, "plan symlink");

        let fixture = prepared_fixture();
        let link_root = fixture.root.join("work-link");
        std::os::unix::fs::symlink(&fixture.work_root, &link_root).unwrap();
        assert!(verify_prepared_restore_authorization(
            &link_root,
            &fixture.journal_root,
            &fixture.authorization
        )
        .is_err());
    }

    #[test]
    fn bounded_reader_keeps_the_limit_and_flags_truncation() {
        let exact = spawn_bounded_reader(std::io::Cursor::new(vec![
            b'a';
            MAXIMUM_COMMAND_OUTPUT_BYTES
        ]))
        .join()
        .unwrap();
        assert_eq!(exact.0.len(), MAXIMUM_COMMAND_OUTPUT_BYTES);
        assert!(!exact.1);

        let over = spawn_bounded_reader(std::io::Cursor::new(vec![
            b'a';
            MAXIMUM_COMMAND_OUTPUT_BYTES + 1
        ]))
        .join()
        .unwrap();
        assert_eq!(over.0.len(), MAXIMUM_COMMAND_OUTPUT_BYTES);
        assert!(over.1);

        assert_eq!(join_output(None), (Vec::new(), true));
    }

    #[test]
    fn lease_server_rejects_invalid_validity_windows_and_operation_ids() {
        let lease: Arc<dyn ExclusiveRestoreProcessLease> = Arc::new(TestLease {
            valid: AtomicBool::new(true),
            checks: Arc::new(Mutex::new(Vec::new())),
        });

        for validity in [
            Duration::ZERO,
            Duration::from_secs(MAXIMUM_LEASE_SECONDS + 1),
        ] {
            let error = RestoreLeaseServer::start(OPERATION_ID, Arc::clone(&lease), validity)
                .err()
                .unwrap();
            assert_eq!(error.code(), "restore_lease_invalid");
            assert!(!error.keep_runtime_offline());
        }
        assert_eq!(
            RestoreLeaseServer::start("bad", lease, Duration::from_secs(5))
                .err()
                .unwrap()
                .code(),
            "restore_operation_invalid"
        );
    }

    #[test]
    fn lease_capability_line_describes_the_loopback_server() {
        let lease: Arc<dyn ExclusiveRestoreProcessLease> = Arc::new(TestLease {
            valid: AtomicBool::new(true),
            checks: Arc::new(Mutex::new(Vec::new())),
        });
        let server =
            RestoreLeaseServer::start(OPERATION_ID, lease, Duration::from_secs(60)).unwrap();

        let line = server.capability_json_line().unwrap();
        assert!(line.ends_with('\n'));
        let capability: serde_json::Value = serde_json::from_str(line.trim_end()).unwrap();

        assert_eq!(capability["protocol"], LEASE_PROTOCOL);
        assert_eq!(capability["version"], 1);
        assert_eq!(capability["operation_id"], OPERATION_ID);
        assert_eq!(capability["port"], server.port);
        assert_eq!(capability["expires_at_unix"], server.expires_at_unix);
        assert!(server.expires_at_unix >= unix_time() + 59);
        let secret = URL_SAFE_NO_PAD
            .decode(capability["secret"].as_str().unwrap())
            .unwrap();
        assert_eq!(secret, server.secret.0);
    }

    fn lease_request(
        server: &RestoreLeaseServer,
        change: impl FnOnce(&mut serde_json::Value),
    ) -> String {
        let challenge = URL_SAFE_NO_PAD.encode([9_u8; 32]);
        let proof = lease_proof(
            "request",
            OPERATION_ID,
            &challenge,
            server.expires_at_unix,
            &server.secret.0,
        );
        let mut request = serde_json::json!({
            "protocol": LEASE_PROTOCOL,
            "version": 1,
            "operation_id": OPERATION_ID,
            "expires_at_unix": server.expires_at_unix,
            "challenge": challenge,
            "proof": proof,
        });
        change(&mut request);
        format!("{request}\n")
    }

    fn exchange(server: &RestoreLeaseServer, request: &str) -> String {
        let mut stream = TcpStream::connect((Ipv4Addr::LOCALHOST, server.port)).unwrap();
        stream
            .set_read_timeout(Some(Duration::from_secs(3)))
            .unwrap();
        stream.write_all(request.as_bytes()).unwrap();
        let mut response = String::new();
        let _ = BufReader::new(stream).read_line(&mut response);
        response
    }

    #[test]
    fn lease_server_answers_with_a_verifiable_response_proof() {
        let lease: Arc<dyn ExclusiveRestoreProcessLease> = Arc::new(TestLease {
            valid: AtomicBool::new(true),
            checks: Arc::new(Mutex::new(Vec::new())),
        });
        let server =
            RestoreLeaseServer::start(OPERATION_ID, lease, Duration::from_secs(30)).unwrap();

        let response: serde_json::Value =
            serde_json::from_str(&exchange(&server, &lease_request(&server, |_| {}))).unwrap();

        let challenge = URL_SAFE_NO_PAD.encode([9_u8; 32]);
        assert_eq!(response["ok"], true);
        assert_eq!(response["challenge"], challenge);
        assert_eq!(
            response["proof"],
            lease_proof(
                "response",
                OPERATION_ID,
                &challenge,
                server.expires_at_unix,
                &server.secret.0
            )
        );
    }

    #[test]
    fn lease_server_silently_drops_forged_or_malformed_requests() {
        let lease: Arc<dyn ExclusiveRestoreProcessLease> = Arc::new(TestLease {
            valid: AtomicBool::new(true),
            checks: Arc::new(Mutex::new(Vec::new())),
        });
        let server =
            RestoreLeaseServer::start(OPERATION_ID, lease, Duration::from_secs(30)).unwrap();
        let short_challenge = URL_SAFE_NO_PAD.encode([9_u8; 16]);
        let short_proof = lease_proof(
            "request",
            OPERATION_ID,
            &short_challenge,
            server.expires_at_unix,
            &server.secret.0,
        );

        let forged = vec![
            lease_request(&server, |r| r["proof"] = serde_json::json!("00".repeat(32))),
            lease_request(&server, |r| r["protocol"] = serde_json::json!("other")),
            lease_request(&server, |r| r["version"] = serde_json::json!(2)),
            lease_request(&server, |r| {
                r["expires_at_unix"] = serde_json::json!(server.expires_at_unix + 1)
            }),
            lease_request(&server, |r| {
                r["operation_id"] = serde_json::json!("8d6708f1-7bb9-43df-abcf-2e1b9fbf2654")
            }),
            lease_request(&server, |r| r["extra"] = serde_json::json!(1)),
            lease_request(&server, |r| {
                r["challenge"] = serde_json::json!(short_challenge);
                r["proof"] = serde_json::json!(short_proof);
            }),
            lease_request(&server, |_| {}).trim_end().to_owned(),
            "not json\n".to_owned(),
            format!("{}\n", "x".repeat(MAXIMUM_PROTOCOL_BYTES + 10)),
        ];
        for request in forged {
            let mut stream = TcpStream::connect((Ipv4Addr::LOCALHOST, server.port)).unwrap();
            stream
                .set_read_timeout(Some(Duration::from_secs(3)))
                .unwrap();
            stream.write_all(request.as_bytes()).unwrap();
            let _ = stream.shutdown(std::net::Shutdown::Write);
            let mut response = String::new();
            let _ = BufReader::new(stream).read_line(&mut response);
            assert!(response.is_empty(), "answered {request:?}");
        }
        assert!(exchange(&server, &lease_request(&server, |_| {})).contains("\"ok\":true"));
    }

    #[test]
    fn php_config_validation_requires_real_paths_and_bounded_timeouts() {
        let root =
            std::env::temp_dir().join(format!("medismart-restore-config-{}", Uuid::new_v4()));
        fs::create_dir_all(&root).unwrap();
        let php = root.join("php");
        let artisan = root.join("artisan");
        fs::write(&php, b"").unwrap();
        fs::write(&artisan, b"").unwrap();
        let valid = || OfflineRestorePhpConfig::new(php.clone(), artisan.clone(), root.clone());

        assert!(valid().validate().is_ok());
        let mut cases = Vec::new();
        let mut config = valid();
        config.php_binary = root.join("missing-php");
        cases.push(config);
        let mut config = valid();
        config.artisan_path = root.clone();
        cases.push(config);
        let mut config = valid();
        config.app_root = artisan.clone();
        cases.push(config);
        let mut config = valid();
        config.command_timeout = Duration::ZERO;
        cases.push(config);
        let mut config = valid();
        config.command_timeout = Duration::from_secs(MAXIMUM_LEASE_SECONDS);
        cases.push(config);
        let mut config = valid();
        config.rollback_grace = Duration::ZERO;
        cases.push(config);
        for config in cases {
            let error = config.validate().unwrap_err();
            assert_eq!(error.code(), "restore_command_invalid");
            assert!(!error.keep_runtime_offline());
        }
        let mut boundary = valid();
        boundary.command_timeout = Duration::from_secs(MAXIMUM_LEASE_SECONDS - 1);
        assert!(boundary.validate().is_ok());
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn php_launcher_passes_configured_environment_and_working_directory() {
        let mut config = OfflineRestorePhpConfig::new(
            PathBuf::from("php"),
            PathBuf::from("artisan"),
            PathBuf::from("app-root"),
        );
        config.environment.push((
            OsString::from("DB_DATABASE"),
            OsString::from("/data/db.sqlite"),
        ));
        config.environment.push((
            OsString::from("MEDISMART_NATIVE_RESTORE"),
            OsString::from("0"),
        ));
        let command = PhpOfflineRestoreCommandLauncher::new(config).build_command(OPERATION_ID);

        assert_eq!(command.get_current_dir(), Some(Path::new("app-root")));
        let env = |name: &str| {
            command
                .get_envs()
                .find(|(key, _)| *key == name)
                .and_then(|(_, value)| value)
                .map(|value| value.to_string_lossy().into_owned())
        };
        assert_eq!(env("DB_DATABASE").as_deref(), Some("/data/db.sqlite"));
        assert_eq!(env("MEDISMART_NATIVE_RESTORE").as_deref(), Some("1"));
    }

    #[cfg(unix)]
    fn scripted_launcher(root: &Path, script: &str) -> PhpOfflineRestoreCommandLauncher {
        let artisan = root.join("artisan");
        fs::write(&artisan, script).unwrap();
        let mut config =
            OfflineRestorePhpConfig::new(PathBuf::from("/bin/sh"), artisan, root.to_path_buf());
        config.command_timeout = Duration::from_secs(10);
        config.rollback_grace = Duration::from_secs(1);
        PhpOfflineRestoreCommandLauncher::new(config)
    }

    #[cfg(unix)]
    fn valid_lease() -> Arc<dyn ExclusiveRestoreProcessLease> {
        Arc::new(TestLease {
            valid: AtomicBool::new(true),
            checks: Arc::new(Mutex::new(Vec::new())),
        })
    }

    #[cfg(unix)]
    #[test]
    fn php_launcher_receives_the_capability_on_stdin_and_parses_the_result() {
        let root =
            std::env::temp_dir().join(format!("medismart-restore-launch-{}", Uuid::new_v4()));
        fs::create_dir_all(&root).unwrap();
        let result_path = root.join("result.json");
        let capability_path = root.join("capability.json");
        let arguments_path = root.join("arguments.txt");
        fs::write(
            &result_path,
            format!(
                "{}\n",
                result_line(OfflineRestoreStatus::RolledBack, MESSAGE_ROLLED_BACK)
            ),
        )
        .unwrap();
        let launcher = scripted_launcher(
            &root,
            &format!(
                "IFS= read -r line; printf '%s' \"$line\" > '{}'; printf '%s\\n' \"$@\" > '{}'; cat '{}'; exit 20\n",
                capability_path.display(),
                arguments_path.display(),
                result_path.display()
            ),
        );

        let outcome = launcher.launch(OPERATION_ID, valid_lease()).unwrap();

        assert_eq!(outcome.status, OfflineRestoreStatus::RolledBack);
        let capability: serde_json::Value =
            serde_json::from_str(&fs::read_to_string(&capability_path).unwrap()).unwrap();
        assert_eq!(capability["operation_id"], OPERATION_ID);
        assert_eq!(capability["protocol"], LEASE_PROTOCOL);
        let arguments = fs::read_to_string(&arguments_path).unwrap();
        assert_eq!(
            arguments.lines().collect::<Vec<_>>(),
            vec![
                "medismart:restore:native-apply",
                OPERATION_ID,
                "--no-interaction"
            ]
        );
        assert!(!arguments.contains(capability["secret"].as_str().unwrap()));
        fs::remove_dir_all(root).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn php_launcher_treats_oversized_output_as_incomplete() {
        let root =
            std::env::temp_dir().join(format!("medismart-restore-launch-{}", Uuid::new_v4()));
        fs::create_dir_all(&root).unwrap();
        let launcher = scripted_launcher(
            &root,
            &format!(
                "head -c {} /dev/zero >&2; exit 0\n",
                MAXIMUM_COMMAND_OUTPUT_BYTES + 1
            ),
        );

        let error = launcher.launch(OPERATION_ID, valid_lease()).unwrap_err();

        assert_eq!(error.code(), "restore_command_incomplete");
        assert!(error.keep_runtime_offline());
        fs::remove_dir_all(root).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn php_launcher_forces_termination_after_timeout_and_grace() {
        let root =
            std::env::temp_dir().join(format!("medismart-restore-launch-{}", Uuid::new_v4()));
        fs::create_dir_all(&root).unwrap();
        let mut launcher = scripted_launcher(&root, "exec sleep 30\n");
        launcher.config.command_timeout = Duration::from_millis(100);
        launcher.config.rollback_grace = Duration::from_millis(100);

        let started = Instant::now();
        let error = launcher.launch(OPERATION_ID, valid_lease()).unwrap_err();

        assert_eq!(error.code(), "restore_command_incomplete");
        assert!(error.keep_runtime_offline());
        assert!(started.elapsed() < Duration::from_secs(10));
        fs::remove_dir_all(root).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn php_launcher_refuses_to_start_without_an_exclusive_lease() {
        let root =
            std::env::temp_dir().join(format!("medismart-restore-launch-{}", Uuid::new_v4()));
        fs::create_dir_all(&root).unwrap();
        let marker = root.join("ran");
        let launcher = scripted_launcher(&root, &format!("touch '{}'\n", marker.display()));
        let lease: Arc<dyn ExclusiveRestoreProcessLease> = Arc::new(TestLease {
            valid: AtomicBool::new(false),
            checks: Arc::new(Mutex::new(Vec::new())),
        });

        assert!(launcher.launch(OPERATION_ID, lease).is_err());
        assert!(launcher.launch("bad-id", valid_lease()).is_err());
        assert!(!marker.exists());
        fs::remove_dir_all(root).unwrap();
    }
}
