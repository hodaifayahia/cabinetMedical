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

    type JsonMutation = Box<dyn Fn(&mut serde_json::Value)>;

    fn publisher_in(root: &Path) -> NativeTunnelStatusPublisher {
        NativeTunnelStatusPublisher::new(
            root.join("tunnel-public-status.json"),
            KEY,
            Uuid::new_v4(),
            "2.1.0".to_owned(),
        )
        .unwrap()
    }

    fn idle_update(phase: NativeTunnelPhase) -> NativeTunnelStatusUpdate<'static> {
        NativeTunnelStatusUpdate {
            configured_hostname: None,
            phase,
            listener_origin: None,
            cloudflared_version: None,
            executable_verified: false,
            retry_count: 3,
            last_error_code: Some("cloudflared_exited"),
            updated_at_unix_ms: 1,
        }
    }

    fn rewrite_status(path: &Path, change: impl FnOnce(&mut serde_json::Value)) {
        let mut value: serde_json::Value =
            serde_json::from_slice(&fs::read(path).unwrap()).unwrap();
        change(&mut value);
        fs::write(path, serde_json::to_vec(&value).unwrap()).unwrap();
    }

    fn resign(path: &Path, key: &str) {
        let mut status: AuthenticatedNativeTunnelStatus =
            serde_json::from_slice(&fs::read(path).unwrap()).unwrap();
        status.signature = hmac_sha256_hex(key.as_bytes(), &signature_payload(&status).unwrap());
        fs::write(path, serde_json::to_vec(&status).unwrap()).unwrap();
    }

    #[test]
    fn hmac_matches_rfc_4231_vectors() {
        assert_eq!(
            hmac_sha256_hex(&[0x0b; 20], b"Hi There"),
            "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7"
        );
        assert_eq!(
            hmac_sha256_hex(b"Jefe", b"what do ya want for nothing?"),
            "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843"
        );
        assert_eq!(
            hmac_sha256_hex(
                &[0xaa; 131],
                b"Test Using Larger Than Block-Size Key - Hash Key First"
            ),
            "60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54"
        );
    }

    #[test]
    fn constant_time_comparison_requires_equal_length_and_bytes() {
        assert!(constant_time_equal(b"", b""));
        assert!(constant_time_equal(b"abc", b"abc"));
        assert!(!constant_time_equal(b"abc", b"abd"));
        assert!(!constant_time_equal(b"abc", b"abcd"));
        assert!(!constant_time_equal(b"", b"a"));
    }

    #[test]
    fn hostname_validation_accepts_named_tunnels_only() {
        for valid in [
            "upload.example.test",
            "a.b",
            "x-1.clinic-2.example",
            &format!("{}.com", "a".repeat(63)),
        ] {
            assert!(valid_hostname(valid), "{valid}");
        }
        for invalid in [
            "",
            "localhost",
            "app.localhost",
            "trycloudflare.com",
            "abc.trycloudflare.com",
            "Upload.Example.test",
            "nodot",
            "a..b",
            ".example.com",
            "example.com.",
            "-bad.example.com",
            "bad-.example.com",
            "under_score.example.com",
            "space here.example.com",
            &format!("{}.com", "a".repeat(64)),
            &format!("{}.com", "a.".repeat(126)),
        ] {
            assert!(!valid_hostname(invalid), "{invalid}");
        }
    }

    #[test]
    fn loopback_origin_must_be_canonical_http_ipv4_with_unprivileged_port() {
        assert!(valid_loopback_origin("http://127.0.0.1:1024"));
        assert!(valid_loopback_origin("http://127.0.0.1:65535"));
        for invalid in [
            "http://127.0.0.1:1023",
            "http://127.0.0.1",
            "http://127.0.0.1:80",
            "http://127.0.0.1:43125/",
            "http://127.0.0.1:43125/path",
            "http://127.0.0.1:43125?x=1",
            "http://127.0.0.1:43125#frag",
            "https://127.0.0.1:43125",
            "http://user@127.0.0.1:43125",
            "http://user:pass@127.0.0.1:43125",
            "http://localhost:43125",
            "http://[::1]:43125",
            "http://127.0.0.2:43125",
            "HTTP://127.0.0.1:43125",
            "not a url",
            "",
        ] {
            assert!(!valid_loopback_origin(invalid), "{invalid}");
        }
    }

    #[test]
    fn version_and_error_code_validation_enforce_charset_and_length() {
        assert!(valid_version("2026.8.0"));
        assert!(valid_version("1-2"));
        assert!(valid_version(&"9".repeat(64)));
        assert!(!valid_version(&"9".repeat(65)));
        assert!(!valid_version(""));
        assert!(!valid_version("v2026.8.0"));
        assert!(!valid_version("2026.8.0 (build)"));

        assert!(valid_error_code("cloudflared_exited"));
        assert!(valid_error_code("e2"));
        assert!(valid_error_code(&"a".repeat(96)));
        assert!(!valid_error_code(&"a".repeat(97)));
        assert!(!valid_error_code(""));
        assert!(!valid_error_code("Upper_case"));
        assert!(!valid_error_code("with-dash"));
        assert!(!valid_error_code("with space"));
    }

    #[test]
    fn signature_payload_is_length_prefixed_and_unambiguous() {
        let root = directory();
        let mut publisher = publisher_in(&root);
        let mut first = publisher
            .publish(idle_update(NativeTunnelPhase::Stopped))
            .unwrap();
        let payload = signature_payload(&first).unwrap();

        assert_eq!(
            &payload[..4],
            &(SIGNATURE_DOMAIN.len() as u32).to_be_bytes()
        );
        assert_eq!(&payload[4..4 + SIGNATURE_DOMAIN.len()], SIGNATURE_DOMAIN);

        first.configured_hostname = None;
        first.listener_origin = Some("x".to_owned());
        let mut shifted = first.clone();
        shifted.configured_hostname = Some("x".to_owned());
        shifted.listener_origin = None;
        assert_ne!(
            signature_payload(&first).unwrap(),
            signature_payload(&shifted).unwrap()
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn publisher_rejects_invalid_keys_versions_and_paths() {
        let root = directory();
        let good_path = root.join("tunnel-public-status.json");
        let attempt = |path: PathBuf, key: &str, version: &str| {
            NativeTunnelStatusPublisher::new(path, key, Uuid::new_v4(), version.to_owned())
                .err()
                .map(|error| error.code())
        };
        let invalid = Some("native_tunnel_status_configuration_invalid");

        assert_eq!(
            attempt(good_path.clone(), &"k".repeat(31), "2.1.0"),
            invalid
        );
        assert_eq!(
            attempt(good_path.clone(), &"k".repeat(1025), "2.1.0"),
            invalid
        );
        assert_eq!(
            attempt(good_path.clone(), &format!("{}\0", "k".repeat(40)), "2.1.0"),
            invalid
        );
        assert_eq!(attempt(good_path.clone(), KEY, ""), invalid);
        assert_eq!(attempt(good_path.clone(), KEY, " 2.1.0"), invalid);
        assert_eq!(attempt(good_path.clone(), KEY, "2.1\t0"), invalid);
        assert_eq!(attempt(good_path.clone(), KEY, &"1".repeat(129)), invalid);
        assert_eq!(attempt(root.join("other-name.json"), KEY, "2.1.0"), invalid);
        assert_eq!(
            attempt(
                root.join("missing").join("tunnel-public-status.json"),
                KEY,
                "2.1.0"
            ),
            invalid
        );

        assert_eq!(attempt(good_path.clone(), &"k".repeat(32), "2.1.0"), None);
        assert_eq!(
            attempt(good_path, &"k".repeat(1024), &"1".repeat(128)),
            None
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn publisher_rejects_symlinked_parent_directory() {
        use std::os::unix::fs::symlink;

        let root = directory();
        let real = root.join("real");
        fs::create_dir(&real).unwrap();
        symlink(&real, root.join("link")).unwrap();

        let error = NativeTunnelStatusPublisher::new(
            root.join("link").join("tunnel-public-status.json"),
            KEY,
            Uuid::new_v4(),
            "2.1.0".to_owned(),
        )
        .err()
        .unwrap();

        assert_eq!(error.code(), "native_tunnel_status_configuration_invalid");
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn sequence_increments_and_latest_tracks_last_publication() {
        let root = directory();
        let mut publisher = publisher_in(&root);
        assert!(publisher.latest().is_none());

        let first = publisher
            .publish(idle_update(NativeTunnelPhase::Starting))
            .unwrap();
        let second = publisher.publish(ready_update(2)).unwrap();

        assert_eq!(first.sequence, 1);
        assert_eq!(second.sequence, 2);
        assert_eq!(first.runtime_instance_id, second.runtime_instance_id);
        assert_eq!(publisher.latest(), Some(second.clone()));
        assert_eq!(
            read_authenticated_native_tunnel_status(&root.join("tunnel-public-status.json"), KEY)
                .unwrap(),
            second
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn each_publisher_gets_a_fresh_runtime_instance_id() {
        let root = directory();
        let first = publisher_in(&root)
            .publish(idle_update(NativeTunnelPhase::Stopped))
            .unwrap();
        let second = publisher_in(&root)
            .publish(idle_update(NativeTunnelPhase::Stopped))
            .unwrap();

        assert_ne!(first.runtime_instance_id, second.runtime_instance_id);
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn rejected_updates_do_not_consume_a_sequence_number_or_overwrite_the_file() {
        let root = directory();
        let mut publisher = publisher_in(&root);
        publisher
            .publish(idle_update(NativeTunnelPhase::Stopped))
            .unwrap();
        let before = fs::read(root.join("tunnel-public-status.json")).unwrap();

        let mut invalid = idle_update(NativeTunnelPhase::Retrying);
        invalid.updated_at_unix_ms = 0;
        assert!(publisher.publish(invalid).is_err());
        let next = publisher
            .publish(idle_update(NativeTunnelPhase::Retrying))
            .unwrap();

        assert_eq!(next.sequence, 2);
        assert_ne!(
            before,
            fs::read(root.join("tunnel-public-status.json")).unwrap()
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn update_validation_covers_each_optional_field() {
        let root = directory();
        let mut publisher = publisher_in(&root);

        for phase in [
            NativeTunnelPhase::Starting,
            NativeTunnelPhase::Retrying,
            NativeTunnelPhase::Unavailable,
            NativeTunnelPhase::Stopping,
            NativeTunnelPhase::Stopped,
        ] {
            assert!(publisher.publish(idle_update(phase)).is_ok(), "{phase:?}");
        }

        let mut invalid = ready_update(1);
        invalid.cloudflared_version = None;
        assert!(publisher.publish(invalid).is_err());
        let mut invalid = ready_update(1);
        invalid.configured_hostname = None;
        assert!(publisher.publish(invalid).is_err());
        let mut invalid = ready_update(1);
        invalid.listener_origin = None;
        assert!(publisher.publish(invalid).is_err());
        let mut invalid = idle_update(NativeTunnelPhase::Retrying);
        invalid.last_error_code = Some("Bad Code");
        assert!(publisher.publish(invalid).is_err());
        let mut invalid = idle_update(NativeTunnelPhase::Retrying);
        invalid.cloudflared_version = Some("latest");
        assert!(publisher.publish(invalid).is_err());
        let mut invalid = idle_update(NativeTunnelPhase::Retrying);
        invalid.listener_origin = Some("http://0.0.0.0:43125");
        assert!(publisher.publish(invalid).is_err());
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn phases_serialize_as_snake_case_matching_signature_names() {
        for phase in [
            NativeTunnelPhase::Starting,
            NativeTunnelPhase::Ready,
            NativeTunnelPhase::Retrying,
            NativeTunnelPhase::Unavailable,
            NativeTunnelPhase::Stopping,
            NativeTunnelPhase::Stopped,
        ] {
            assert_eq!(
                serde_json::to_string(&phase).unwrap(),
                format!("\"{}\"", phase.as_str())
            );
        }
    }

    #[test]
    fn reader_rejects_invalid_authentication_keys_before_touching_disk() {
        let missing = Path::new("/definitely/not/here/tunnel-public-status.json");

        for key in [
            "k".repeat(31),
            "k".repeat(1025),
            format!("{}\0", "k".repeat(40)),
        ] {
            assert_eq!(
                read_authenticated_native_tunnel_status(missing, &key)
                    .unwrap_err()
                    .code(),
                "native_tunnel_status_authentication_failed"
            );
        }
        assert_eq!(
            read_authenticated_native_tunnel_status(missing, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_invalid"
        );
    }

    #[test]
    fn reader_rejects_empty_oversized_and_malformed_files() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");

        fs::write(&path, b"").unwrap();
        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_invalid"
        );
        fs::write(&path, vec![b' '; MAX_STATUS_BYTES as usize + 1]).unwrap();
        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_invalid"
        );
        fs::write(&path, b"{not json").unwrap();
        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_invalid"
        );
        fs::remove_file(&path).unwrap();
        fs::create_dir(&path).unwrap();
        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_invalid"
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn reader_rejects_unknown_fields_even_when_otherwise_signed() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");
        publisher_in(&root).publish(ready_update(5)).unwrap();
        rewrite_status(&path, |value| {
            value["token"] = serde_json::json!("leaked");
        });

        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_invalid"
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn reader_rejects_correctly_signed_but_invalid_envelopes() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");
        let mutations: Vec<JsonMutation> = vec![
            Box::new(|value| value["schema_version"] = serde_json::json!(2)),
            Box::new(|value| value["sequence"] = serde_json::json!(0)),
            Box::new(|value| value["application_version"] = serde_json::json!("")),
            Box::new(|value| value["updated_at_unix_ms"] = serde_json::json!(0)),
            Box::new(|value| {
                value["configured_hostname"] = serde_json::json!("x.trycloudflare.com")
            }),
            Box::new(|value| value["executable_verified"] = serde_json::json!(false)),
        ];

        for (index, mutation) in mutations.iter().enumerate() {
            publisher_in(&root).publish(ready_update(5)).unwrap();
            rewrite_status(&path, |value| mutation(value));
            resign(&path, KEY);
            assert_eq!(
                read_authenticated_native_tunnel_status(&path, KEY)
                    .unwrap_err()
                    .code(),
                "native_tunnel_status_invalid",
                "mutation {index}"
            );
        }
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn reader_rejects_signatures_that_are_not_lowercase_hex() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");
        publisher_in(&root).publish(ready_update(5)).unwrap();
        rewrite_status(&path, |value| {
            let upper = value["signature"].as_str().unwrap().to_ascii_uppercase();
            value["signature"] = serde_json::json!(upper);
        });

        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_invalid"
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn resigning_with_the_wrong_key_fails_authentication() {
        let root = directory();
        let path = root.join("tunnel-public-status.json");
        publisher_in(&root).publish(ready_update(5)).unwrap();
        rewrite_status(&path, |value| value["retry_count"] = serde_json::json!(9));
        resign(&path, "attacker-controlled-key-that-is-long-enough");

        assert_eq!(
            read_authenticated_native_tunnel_status(&path, KEY)
                .unwrap_err()
                .code(),
            "native_tunnel_status_authentication_failed"
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn atomic_replace_rejects_empty_and_oversized_contents() {
        let root = directory();
        let path = root.join("file.json");

        assert_eq!(
            atomic_replace_private(&path, b"").unwrap_err().code(),
            "native_tunnel_status_write_failed"
        );
        assert_eq!(
            atomic_replace_private(&path, &vec![b'a'; MAX_STATUS_BYTES as usize + 1])
                .unwrap_err()
                .code(),
            "native_tunnel_status_write_failed"
        );
        assert!(!path.exists());
        atomic_replace_private(&path, &vec![b'a'; MAX_STATUS_BYTES as usize]).unwrap();
        assert_eq!(fs::metadata(&path).unwrap().len(), MAX_STATUS_BYTES);
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn atomic_replace_overwrites_files_but_refuses_directories() {
        let root = directory();
        let path = root.join("file.json");
        atomic_replace_private(&path, b"one").unwrap();
        atomic_replace_private(&path, b"two").unwrap();
        assert_eq!(fs::read(&path).unwrap(), b"two");
        assert_eq!(fs::read_dir(&root).unwrap().count(), 1);

        let directory_target = root.join("dir.json");
        fs::create_dir(&directory_target).unwrap();
        assert_eq!(
            atomic_replace_private(&directory_target, b"x")
                .unwrap_err()
                .code(),
            "native_tunnel_status_write_failed"
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn atomic_replace_refuses_symlink_targets_and_writes_user_only_files() {
        use std::os::unix::fs::{symlink, PermissionsExt};

        let root = directory();
        let external = root.join("external.json");
        fs::write(&external, b"keep").unwrap();
        symlink(&external, root.join("link.json")).unwrap();

        assert!(atomic_replace_private(&root.join("link.json"), b"x").is_err());
        assert_eq!(fs::read(&external).unwrap(), b"keep");

        let path = root.join("private.json");
        atomic_replace_private(&path, b"x").unwrap();
        assert_eq!(
            fs::metadata(&path).unwrap().permissions().mode() & 0o777,
            0o600
        );
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn error_display_includes_code_and_detail() {
        let error = NativeTunnelStatusError::new("some_code", "some detail");

        assert_eq!(error.to_string(), "some_code: some detail");
        assert_eq!(error.code(), "some_code");
    }
}
