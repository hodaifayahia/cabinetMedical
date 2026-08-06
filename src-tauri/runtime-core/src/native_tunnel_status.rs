use std::{
    fmt,
    fs::{self, File, OpenOptions},
    io::{self, Read, Write},
    path::{Path, PathBuf},
};

use rand::RngCore;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use uuid::Uuid;
use zeroize::Zeroizing;

const STATUS_SCHEMA_VERSION: u8 = 1;
const MAX_STATUS_BYTES: u64 = 16 * 1024;
const SIGNATURE_DOMAIN: &[u8] = b"medismart-native-tunnel-status-v1";

#[derive(Debug)]
pub struct NativeTunnelStatusError {
    code: &'static str,
    detail: String,
}

impl NativeTunnelStatusError {
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

impl fmt::Display for NativeTunnelStatusError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}: {}", self.code, self.detail)
    }
}

impl std::error::Error for NativeTunnelStatusError {}

#[derive(Clone, Copy, Debug, Deserialize, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum NativeTunnelPhase {
    Starting,
    Ready,
    Retrying,
    Unavailable,
    Stopping,
    Stopped,
}

impl NativeTunnelPhase {
    fn as_str(self) -> &'static str {
        match self {
            Self::Starting => "starting",
            Self::Ready => "ready",
            Self::Retrying => "retrying",
            Self::Unavailable => "unavailable",
            Self::Stopping => "stopping",
            Self::Stopped => "stopped",
        }
    }
}

/// Secret-free status contract published by the native process. Laravel must
/// verify its HMAC and exact runtime identity before treating `Ready` as proof
/// that remote QR traffic can reach the upload-only listener.
#[derive(Clone, Debug, Deserialize, PartialEq, Eq, Serialize)]
#[serde(deny_unknown_fields)]
pub struct AuthenticatedNativeTunnelStatus {
    pub schema_version: u8,
    pub runtime_instance_id: Uuid,
    pub installation_id: Uuid,
    pub application_version: String,
    pub configured_hostname: Option<String>,
    pub phase: NativeTunnelPhase,
    pub listener_origin: Option<String>,
    pub cloudflared_version: Option<String>,
    pub executable_verified: bool,
    pub retry_count: u8,
    pub last_error_code: Option<String>,
    pub updated_at_unix_ms: u64,
    pub sequence: u64,
    pub signature: String,
}

#[derive(Clone, Debug)]
pub struct NativeTunnelStatusUpdate<'a> {
    pub configured_hostname: Option<&'a str>,
    pub phase: NativeTunnelPhase,
    pub listener_origin: Option<&'a str>,
    pub cloudflared_version: Option<&'a str>,
    pub executable_verified: bool,
    pub retry_count: u8,
    pub last_error_code: Option<&'a str>,
    pub updated_at_unix_ms: u64,
}

pub struct NativeTunnelStatusPublisher {
    path: PathBuf,
    authentication_key: Zeroizing<Vec<u8>>,
    runtime_instance_id: Uuid,
    installation_id: Uuid,
    application_version: String,
    sequence: u64,
    latest: Option<AuthenticatedNativeTunnelStatus>,
}

impl NativeTunnelStatusPublisher {
    pub fn new(
        path: PathBuf,
        authentication_key: &str,
        installation_id: Uuid,
        application_version: String,
    ) -> Result<Self, NativeTunnelStatusError> {
        if authentication_key.len() < 32
            || authentication_key.len() > 1024
            || authentication_key.contains('\0')
            || application_version.is_empty()
            || application_version.len() > 128
            || application_version.trim() != application_version
            || application_version
                .bytes()
                .any(|byte| byte.is_ascii_control())
            || path.file_name().and_then(|name| name.to_str()) != Some("tunnel-public-status.json")
        {
            return Err(NativeTunnelStatusError::new(
                "native_tunnel_status_configuration_invalid",
                "native tunnel status identity or path is invalid",
            ));
        }

        let parent = path.parent().ok_or_else(|| {
            NativeTunnelStatusError::new(
                "native_tunnel_status_configuration_invalid",
                "native tunnel status path has no parent",
            )
        })?;
        ensure_directory(parent)?;

        Ok(Self {
            path,
            authentication_key: Zeroizing::new(authentication_key.as_bytes().to_vec()),
            runtime_instance_id: Uuid::new_v4(),
            installation_id,
            application_version,
            sequence: 0,
            latest: None,
        })
    }

    pub fn publish(
        &mut self,
        update: NativeTunnelStatusUpdate<'_>,
    ) -> Result<AuthenticatedNativeTunnelStatus, NativeTunnelStatusError> {
        validate_update(&update)?;
        self.sequence = self.sequence.checked_add(1).ok_or_else(|| {
            NativeTunnelStatusError::new(
                "native_tunnel_status_sequence_exhausted",
                "native tunnel status sequence is exhausted",
            )
        })?;

        let mut status = AuthenticatedNativeTunnelStatus {
            schema_version: STATUS_SCHEMA_VERSION,
            runtime_instance_id: self.runtime_instance_id,
            installation_id: self.installation_id,
            application_version: self.application_version.clone(),
            configured_hostname: update.configured_hostname.map(str::to_owned),
            phase: update.phase,
            listener_origin: update.listener_origin.map(str::to_owned),
            cloudflared_version: update.cloudflared_version.map(str::to_owned),
            executable_verified: update.executable_verified,
            retry_count: update.retry_count,
            last_error_code: update.last_error_code.map(str::to_owned),
            updated_at_unix_ms: update.updated_at_unix_ms,
            sequence: self.sequence,
            signature: String::new(),
        };
        status.signature = hmac_sha256_hex(
            self.authentication_key.as_slice(),
            &signature_payload(&status)?,
        );
        let bytes = serde_json::to_vec(&status).map_err(|error| {
            NativeTunnelStatusError::new(
                "native_tunnel_status_write_failed",
                format!("serialize authenticated tunnel status: {error}"),
            )
        })?;
        atomic_replace_private(&self.path, &bytes)?;
        self.latest = Some(status.clone());

        Ok(status)
    }

    pub fn latest(&self) -> Option<AuthenticatedNativeTunnelStatus> {
        self.latest.clone()
    }
}

pub fn read_authenticated_native_tunnel_status(
    path: &Path,
    authentication_key: &str,
) -> Result<AuthenticatedNativeTunnelStatus, NativeTunnelStatusError> {
    if authentication_key.len() < 32
        || authentication_key.len() > 1024
        || authentication_key.contains('\0')
    {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_authentication_failed",
            "native tunnel status authentication key is invalid",
        ));
    }
    ensure_regular_file(path, "native_tunnel_status_invalid")?;
    let metadata = fs::metadata(path).map_err(|error| {
        NativeTunnelStatusError::new(
            "native_tunnel_status_invalid",
            format!("read native tunnel status metadata: {error}"),
        )
    })?;
    if metadata.len() == 0 || metadata.len() > MAX_STATUS_BYTES {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_invalid",
            "native tunnel status has an invalid size",
        ));
    }

    let file = File::open(path).map_err(|error| {
        NativeTunnelStatusError::new(
            "native_tunnel_status_invalid",
            format!("open native tunnel status: {error}"),
        )
    })?;
    let mut bytes = Vec::with_capacity(metadata.len() as usize);
    file.take(MAX_STATUS_BYTES + 1)
        .read_to_end(&mut bytes)
        .map_err(|error| {
            NativeTunnelStatusError::new(
                "native_tunnel_status_invalid",
                format!("read native tunnel status: {error}"),
            )
        })?;
    if bytes.len() as u64 != metadata.len() || bytes.len() as u64 > MAX_STATUS_BYTES {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_invalid",
            "native tunnel status changed while it was read",
        ));
    }

    let status: AuthenticatedNativeTunnelStatus =
        serde_json::from_slice(&bytes).map_err(|error| {
            NativeTunnelStatusError::new(
                "native_tunnel_status_invalid",
                format!("parse native tunnel status: {error}"),
            )
        })?;
    validate_status(&status)?;
    let expected = hmac_sha256_hex(authentication_key.as_bytes(), &signature_payload(&status)?);
    if !constant_time_equal(expected.as_bytes(), status.signature.as_bytes()) {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_authentication_failed",
            "native tunnel status signature is invalid",
        ));
    }

    Ok(status)
}

fn validate_update(update: &NativeTunnelStatusUpdate<'_>) -> Result<(), NativeTunnelStatusError> {
    if update.updated_at_unix_ms == 0
        || update
            .configured_hostname
            .is_some_and(|value| !valid_hostname(value))
        || update
            .listener_origin
            .is_some_and(|value| !valid_loopback_origin(value))
        || update
            .cloudflared_version
            .is_some_and(|value| !valid_version(value))
        || update
            .last_error_code
            .is_some_and(|value| !valid_error_code(value))
        || (update.phase == NativeTunnelPhase::Ready
            && (update.configured_hostname.is_none()
                || update.listener_origin.is_none()
                || !update.executable_verified
                || update.cloudflared_version.is_none()))
    {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_invalid",
            "native tunnel status update is invalid",
        ));
    }

    Ok(())
}

fn validate_status(
    status: &AuthenticatedNativeTunnelStatus,
) -> Result<(), NativeTunnelStatusError> {
    let update = NativeTunnelStatusUpdate {
        configured_hostname: status.configured_hostname.as_deref(),
        phase: status.phase,
        listener_origin: status.listener_origin.as_deref(),
        cloudflared_version: status.cloudflared_version.as_deref(),
        executable_verified: status.executable_verified,
        retry_count: status.retry_count,
        last_error_code: status.last_error_code.as_deref(),
        updated_at_unix_ms: status.updated_at_unix_ms,
    };
    if status.schema_version != STATUS_SCHEMA_VERSION
        || status.application_version.is_empty()
        || status.application_version.len() > 128
        || status.application_version.trim() != status.application_version
        || status
            .application_version
            .bytes()
            .any(|byte| byte.is_ascii_control())
        || status.sequence == 0
        || status.signature.len() != 64
        || !status
            .signature
            .bytes()
            .all(|byte| byte.is_ascii_digit() || matches!(byte, b'a'..=b'f'))
    {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_invalid",
            "native tunnel status envelope is invalid",
        ));
    }
    validate_update(&update)
}

fn valid_hostname(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 253
        && value == value.to_ascii_lowercase()
        && value.contains('.')
        && !value.ends_with(".localhost")
        && value != "localhost"
        && !value.ends_with(".trycloudflare.com")
        && value != "trycloudflare.com"
        && value.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && !label.starts_with('-')
                && !label.ends_with('-')
                && label
                    .bytes()
                    .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
        })
}

fn valid_loopback_origin(value: &str) -> bool {
    let Ok(url) = url::Url::parse(value) else {
        return false;
    };
    url.scheme() == "http"
        && url.host_str() == Some("127.0.0.1")
        && url.port().is_some_and(|port| port >= 1024)
        && url.username().is_empty()
        && url.password().is_none()
        && url.path() == "/"
        && url.query().is_none()
        && url.fragment().is_none()
        && value == format!("http://127.0.0.1:{}", url.port().unwrap_or_default())
}

fn valid_version(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value
            .bytes()
            .all(|byte| byte.is_ascii_digit() || byte == b'.' || byte == b'-')
}

fn valid_error_code(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 96
        && value
            .bytes()
            .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'_')
}

fn signature_payload(
    status: &AuthenticatedNativeTunnelStatus,
) -> Result<Vec<u8>, NativeTunnelStatusError> {
    let fields = [
        status.schema_version.to_string(),
        status.runtime_instance_id.to_string(),
        status.installation_id.to_string(),
        status.application_version.clone(),
        status.configured_hostname.clone().unwrap_or_default(),
        status.phase.as_str().to_owned(),
        status.listener_origin.clone().unwrap_or_default(),
        status.cloudflared_version.clone().unwrap_or_default(),
        if status.executable_verified { "1" } else { "0" }.to_owned(),
        status.retry_count.to_string(),
        status.last_error_code.clone().unwrap_or_default(),
        status.updated_at_unix_ms.to_string(),
        status.sequence.to_string(),
    ];
    let mut payload = Vec::with_capacity(512);
    append_field(&mut payload, SIGNATURE_DOMAIN)?;
    for field in fields {
        append_field(&mut payload, field.as_bytes())?;
    }
    Ok(payload)
}

fn append_field(payload: &mut Vec<u8>, field: &[u8]) -> Result<(), NativeTunnelStatusError> {
    let length = u32::try_from(field.len()).map_err(|_| {
        NativeTunnelStatusError::new(
            "native_tunnel_status_invalid",
            "native tunnel status field is too large",
        )
    })?;
    payload.extend_from_slice(&length.to_be_bytes());
    payload.extend_from_slice(field);
    Ok(())
}

fn hmac_sha256_hex(key: &[u8], message: &[u8]) -> String {
    const BLOCK_BYTES: usize = 64;
    let normalized = if key.len() > BLOCK_BYTES {
        Sha256::digest(key).to_vec()
    } else {
        key.to_vec()
    };
    let mut key_block = Zeroizing::new([0_u8; BLOCK_BYTES]);
    key_block[..normalized.len()].copy_from_slice(&normalized);
    let mut inner_pad = Zeroizing::new([0x36_u8; BLOCK_BYTES]);
    let mut outer_pad = Zeroizing::new([0x5c_u8; BLOCK_BYTES]);
    for index in 0..BLOCK_BYTES {
        inner_pad[index] ^= key_block[index];
        outer_pad[index] ^= key_block[index];
    }
    let mut inner = Sha256::new();
    inner.update(inner_pad.as_slice());
    inner.update(message);
    let inner_digest = inner.finalize();
    let mut outer = Sha256::new();
    outer.update(outer_pad.as_slice());
    outer.update(inner_digest);
    outer
        .finalize()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn constant_time_equal(left: &[u8], right: &[u8]) -> bool {
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

pub(crate) fn atomic_replace_private(
    path: &Path,
    contents: &[u8],
) -> Result<(), NativeTunnelStatusError> {
    if contents.is_empty() || contents.len() as u64 > MAX_STATUS_BYTES {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_write_failed",
            "managed native tunnel file has an invalid size",
        ));
    }
    let parent = path.parent().ok_or_else(|| {
        NativeTunnelStatusError::new(
            "native_tunnel_status_write_failed",
            "managed native tunnel path has no parent",
        )
    })?;
    ensure_directory(parent)?;
    match fs::symlink_metadata(path) {
        Ok(metadata) if metadata.file_type().is_symlink() || !metadata.is_file() => {
            return Err(NativeTunnelStatusError::new(
                "native_tunnel_status_write_failed",
                "managed native tunnel target is not a regular file",
            ));
        }
        Ok(_) => {}
        Err(error) if error.kind() == io::ErrorKind::NotFound => {}
        Err(error) => {
            return Err(NativeTunnelStatusError::new(
                "native_tunnel_status_write_failed",
                format!("inspect managed native tunnel target: {error}"),
            ));
        }
    }

    let temporary = temporary_path_for(path);
    let mut options = OpenOptions::new();
    options.write(true).create_new(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }
    let result = (|| {
        let mut file = options.open(&temporary)?;
        file.write_all(contents)?;
        file.sync_all()?;
        drop(file);
        publish_replacement(&temporary, path)
    })();
    if let Err(error) = result {
        let _ = fs::remove_file(&temporary);
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_write_failed",
            format!("publish managed native tunnel file: {error}"),
        ));
    }
    Ok(())
}

fn ensure_directory(path: &Path) -> Result<(), NativeTunnelStatusError> {
    let metadata = fs::symlink_metadata(path).map_err(|error| {
        NativeTunnelStatusError::new(
            "native_tunnel_status_configuration_invalid",
            format!("inspect native tunnel status directory: {error}"),
        )
    })?;
    if metadata.file_type().is_symlink() || !metadata.is_dir() {
        return Err(NativeTunnelStatusError::new(
            "native_tunnel_status_configuration_invalid",
            "native tunnel status parent is not a regular directory",
        ));
    }
    Ok(())
}

fn ensure_regular_file(path: &Path, code: &'static str) -> Result<(), NativeTunnelStatusError> {
    let metadata = fs::symlink_metadata(path)
        .map_err(|error| NativeTunnelStatusError::new(code, format!("inspect file: {error}")))?;
    if metadata.file_type().is_symlink() || !metadata.is_file() {
        return Err(NativeTunnelStatusError::new(
            code,
            "required path is not a regular file",
        ));
    }
    Ok(())
}

fn temporary_path_for(path: &Path) -> PathBuf {
    let mut suffix = [0_u8; 8];
    rand::rng().fill_bytes(&mut suffix);
    let suffix = suffix
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<String>();
    path.with_extension(format!("tmp-{suffix}"))
}

#[cfg(not(windows))]
fn publish_replacement(temporary: &Path, target: &Path) -> io::Result<()> {
    fs::rename(temporary, target)?;
    if let Some(parent) = target.parent() {
        File::open(parent)?.sync_all()?;
    }
    Ok(())
}

#[cfg(windows)]
fn publish_replacement(temporary: &Path, target: &Path) -> io::Result<()> {
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
        Err(io::Error::last_os_error())
    } else {
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const KEY: &str = "health-key-used-only-for-native-status-tests-2026";

    fn directory() -> PathBuf {
        let path = std::env::temp_dir().join(format!("native-tunnel-status-{}", Uuid::new_v4()));
        fs::create_dir_all(&path).unwrap();
        path
    }

    fn ready_update(now: u64) -> NativeTunnelStatusUpdate<'static> {
        NativeTunnelStatusUpdate {
            configured_hostname: Some("upload.example.test"),
            phase: NativeTunnelPhase::Ready,
            listener_origin: Some("http://127.0.0.1:43125"),
            cloudflared_version: Some("2026.8.0"),
            executable_verified: true,
            retry_count: 0,
            last_error_code: None,
            updated_at_unix_ms: now,
        }
    }

    #[test]
    fn published_status_authenticates_and_contains_no_connector_material() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");
        let installation = Uuid::new_v4();
        let mut publisher =
            NativeTunnelStatusPublisher::new(path.clone(), KEY, installation, "2.1.0".to_owned())
                .unwrap();
        let published = publisher.publish(ready_update(1_786_000_000_000)).unwrap();
        let verified = read_authenticated_native_tunnel_status(&path, KEY).unwrap();

        assert_eq!(published, verified);
        assert_eq!(verified.installation_id, installation);
        let raw = fs::read_to_string(&path).unwrap();
        assert!(!raw.contains("token"));
        assert!(!raw.contains("credential"));
        assert!(!raw.contains(KEY));
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn tampering_and_a_different_runtime_key_fail_authentication() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");
        let mut publisher =
            NativeTunnelStatusPublisher::new(path.clone(), KEY, Uuid::new_v4(), "2.1.0".to_owned())
                .unwrap();
        publisher.publish(ready_update(1_786_000_000_000)).unwrap();

        let raw = fs::read_to_string(&path).unwrap();
        fs::write(&path, raw.replace("\"ready\"", "\"stopped\"")).unwrap();
        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_authentication_failed"
        );
        assert!(read_authenticated_native_tunnel_status(
            &path,
            "another-health-key-used-only-for-native-tests"
        )
        .is_err());
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn ready_requires_exact_hostname_origin_and_verified_executable() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");
        let mut publisher =
            NativeTunnelStatusPublisher::new(path, KEY, Uuid::new_v4(), "2.1.0".to_owned())
                .unwrap();

        let mut invalid = ready_update(1_786_000_000_000);
        invalid.listener_origin = Some("http://localhost:43125");
        assert_eq!(
            publisher.publish(invalid).unwrap_err().code(),
            "native_tunnel_status_invalid"
        );
        let mut invalid = ready_update(1_786_000_000_000);
        invalid.configured_hostname = Some("random.trycloudflare.com");
        assert!(publisher.publish(invalid).is_err());
        let mut invalid = ready_update(1_786_000_000_000);
        invalid.executable_verified = false;
        assert!(publisher.publish(invalid).is_err());
        fs::remove_dir_all(root).unwrap();
    }
}
