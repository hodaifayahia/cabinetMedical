use std::{
    fmt,
    fs::{self, File},
    io::{self, BufReader, Read},
    net::IpAddr,
    path::{Path, PathBuf},
    process::{Child, Command, Stdio},
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Mutex,
    },
    thread,
    time::{Duration, Instant},
};

use reqwest::{blocking::Client, redirect::Policy, StatusCode};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use url::Url;
use uuid::Uuid;

use crate::{
    allocate_loopback_port,
    native_tunnel_status::{
        NativeTunnelPhase, NativeTunnelStatusPublisher, NativeTunnelStatusUpdate,
    },
    protected_secret::ProtectedSecret,
    supervisor::{configure_platform_process, pump_child_output, request_graceful_termination},
    RuntimeLogger,
};

const SETTINGS_SCHEMA_VERSION: u8 = 1;
const EXECUTABLE_MANIFEST_SCHEMA_VERSION: u8 = 1;
const MAX_SETTINGS_BYTES: u64 = 32 * 1024;
const MAX_MANIFEST_BYTES: u64 = 8 * 1024;
const MAX_VERSION_OUTPUT_BYTES: usize = 8 * 1024;
const MAX_READINESS_RESPONSE_BYTES: u64 = 64 * 1024;
const VERSION_PROBE_TIMEOUT: Duration = Duration::from_secs(5);
const POLL_INTERVAL: Duration = Duration::from_millis(200);
// Keep authenticated Ready evidence comfortably inside Laravel's 15-second
// freshness window, including ordinary scheduler jitter.
const RUNTIME_READINESS_INTERVAL: Duration = Duration::from_secs(5);
const ORIGIN_ATTESTATION_UNAVAILABLE: &str = "tunnel_origin_attestation_unavailable";

#[derive(Debug)]
pub struct TunnelError {
    code: &'static str,
    detail: String,
}

impl TunnelError {
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

impl fmt::Display for TunnelError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}: {}", self.code, self.detail)
    }
}

impl std::error::Error for TunnelError {}

#[derive(Clone, Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TunnelSettings {
    schema_version: u8,
    enabled: bool,
    provider: Option<String>,
    management: Option<String>,
    tunnel_id: Option<Uuid>,
    upload_hostname: Option<String>,
}

impl TunnelSettings {
    pub fn enabled(&self) -> bool {
        self.enabled
    }

    pub fn upload_hostname(&self) -> Option<&str> {
        self.upload_hostname.as_deref()
    }

    fn validate(&self) -> Result<(), TunnelError> {
        if self.schema_version != SETTINGS_SCHEMA_VERSION {
            return Err(TunnelError::new(
                "tunnel_configuration_invalid",
                "unsupported tunnel settings schema",
            ));
        }
        if !self.enabled {
            return Ok(());
        }
        if self.provider.as_deref() != Some("cloudflare")
            || self.management.as_deref() != Some("remote")
        {
            return Err(TunnelError::new(
                "tunnel_configuration_invalid",
                "only a remotely managed Cloudflare Tunnel is supported",
            ));
        }
        if self.tunnel_id.is_none_or(|tunnel_id| tunnel_id.is_nil()) {
            return Err(TunnelError::new(
                "tunnel_configuration_invalid",
                "a non-nil named tunnel ID is required",
            ));
        }
        validate_upload_hostname(self.upload_hostname.as_deref().unwrap_or_default())?;
        Ok(())
    }
}

pub fn load_tunnel_settings(path: &Path) -> Result<TunnelSettings, TunnelError> {
    ensure_regular_file(path, "tunnel_configuration_invalid")?;
    let metadata = fs::metadata(path).map_err(|error| {
        TunnelError::new(
            "tunnel_configuration_invalid",
            format!("read tunnel settings metadata: {error}"),
        )
    })?;
    if metadata.len() == 0 || metadata.len() > MAX_SETTINGS_BYTES {
        return Err(TunnelError::new(
            "tunnel_configuration_invalid",
            "tunnel settings have an invalid size",
        ));
    }

    let bytes = fs::read(path).map_err(|error| {
        TunnelError::new(
            "tunnel_configuration_invalid",
            format!("read tunnel settings: {error}"),
        )
    })?;
    let settings: TunnelSettings = serde_json::from_slice(&bytes).map_err(|error| {
        TunnelError::new(
            "tunnel_configuration_invalid",
            format!("parse tunnel settings: {error}"),
        )
    })?;
    settings.validate()?;
    Ok(settings)
}

fn validate_upload_hostname(hostname: &str) -> Result<(), TunnelError> {
    if hostname.is_empty()
        || hostname.len() > 253
        || hostname != hostname.to_ascii_lowercase()
        || hostname.parse::<IpAddr>().is_ok()
        || !hostname.contains('.')
        || hostname == "localhost"
        || hostname.ends_with(".localhost")
        || hostname == "trycloudflare.com"
        || hostname.ends_with(".trycloudflare.com")
    {
        return Err(TunnelError::new(
            "tunnel_hostname_invalid",
            "upload hostname must be an exact lowercase public DNS hostname",
        ));
    }

    let valid = hostname.split('.').all(|label| {
        !label.is_empty()
            && label.len() <= 63
            && !label.starts_with('-')
            && !label.ends_with('-')
            && label
                .bytes()
                .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
    });
    if !valid {
        return Err(TunnelError::new(
            "tunnel_hostname_invalid",
            "upload hostname contains an invalid DNS label",
        ));
    }

    Ok(())
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct CloudflaredManifest {
    schema_version: u8,
    version: String,
    sha256: String,
}

#[derive(Clone, Debug)]
pub struct CloudflaredExecutable {
    path: PathBuf,
    version: String,
}

impl CloudflaredExecutable {
    pub fn path(&self) -> &Path {
        &self.path
    }

    pub fn version(&self) -> &str {
        &self.version
    }
}

pub fn verify_cloudflared_executable(
    executable_path: &Path,
    manifest_path: &Path,
) -> Result<CloudflaredExecutable, TunnelError> {
    ensure_regular_file(executable_path, "tunnel_executable_unverified")?;
    ensure_regular_file(manifest_path, "tunnel_executable_unverified")?;

    let manifest_metadata = fs::metadata(manifest_path).map_err(|error| {
        TunnelError::new(
            "tunnel_executable_unverified",
            format!("read cloudflared manifest metadata: {error}"),
        )
    })?;
    if manifest_metadata.len() == 0 || manifest_metadata.len() > MAX_MANIFEST_BYTES {
        return Err(TunnelError::new(
            "tunnel_executable_unverified",
            "cloudflared manifest has an invalid size",
        ));
    }

    let manifest: CloudflaredManifest =
        serde_json::from_slice(&fs::read(manifest_path).map_err(|error| {
            TunnelError::new(
                "tunnel_executable_unverified",
                format!("read cloudflared manifest: {error}"),
            )
        })?)
        .map_err(|error| {
            TunnelError::new(
                "tunnel_executable_unverified",
                format!("parse cloudflared manifest: {error}"),
            )
        })?;

    if manifest.schema_version != EXECUTABLE_MANIFEST_SCHEMA_VERSION
        || !valid_cloudflared_version(&manifest.version)
        || manifest.sha256.len() != 64
        || !manifest
            .sha256
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
    {
        return Err(TunnelError::new(
            "tunnel_executable_unverified",
            "cloudflared manifest is invalid",
        ));
    }

    let digest = sha256_file(executable_path)?;
    if digest != manifest.sha256 {
        return Err(TunnelError::new(
            "tunnel_executable_unverified",
            "cloudflared digest does not match the approved manifest",
        ));
    }

    verify_cloudflared_version(executable_path, &manifest.version)?;

    Ok(CloudflaredExecutable {
        path: executable_path.to_path_buf(),
        version: manifest.version,
    })
}

fn valid_cloudflared_version(version: &str) -> bool {
    let components = version.split('.').collect::<Vec<_>>();
    matches!(components.len(), 3 | 4)
        && components[0]
            .parse::<u16>()
            .is_ok_and(|year| (2025..=2100).contains(&year))
        && components[1]
            .parse::<u8>()
            .is_ok_and(|month| (1..=12).contains(&month))
        && components[2].parse::<u16>().is_ok()
        && components
            .get(3)
            .is_none_or(|component| component.parse::<u16>().is_ok())
}

fn sha256_file(path: &Path) -> Result<String, TunnelError> {
    let file = File::open(path).map_err(|error| {
        TunnelError::new(
            "tunnel_executable_unverified",
            format!("open cloudflared for hashing: {error}"),
        )
    })?;
    let mut reader = BufReader::new(file);
    let mut hasher = Sha256::new();
    let mut buffer = [0_u8; 64 * 1024];
    loop {
        let count = reader.read(&mut buffer).map_err(|error| {
            TunnelError::new(
                "tunnel_executable_unverified",
                format!("hash cloudflared: {error}"),
            )
        })?;
        if count == 0 {
            break;
        }
        hasher.update(&buffer[..count]);
    }
    Ok(format!("{:x}", hasher.finalize()))
}

fn verify_cloudflared_version(path: &Path, expected: &str) -> Result<(), TunnelError> {
    let mut command = Command::new(path);
    command
        .arg("--version")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());
    clear_cloudflared_secret_environment(&mut command);
    configure_platform_process(&mut command);

    let mut child = command.spawn().map_err(|error| {
        TunnelError::new(
            "tunnel_executable_unverified",
            format!("execute cloudflared version probe: {error}"),
        )
    })?;
    let stdout = child.stdout.take().ok_or_else(|| {
        TunnelError::new(
            "tunnel_executable_unverified",
            "cloudflared version stdout is unavailable",
        )
    })?;
    let stderr = child.stderr.take().ok_or_else(|| {
        TunnelError::new(
            "tunnel_executable_unverified",
            "cloudflared version stderr is unavailable",
        )
    })?;
    let stdout_reader = thread::spawn(move || read_bounded_and_drain(stdout));
    let stderr_reader = thread::spawn(move || read_bounded_and_drain(stderr));

    let deadline = Instant::now() + VERSION_PROBE_TIMEOUT;
    let status = loop {
        match child.try_wait() {
            Ok(Some(status)) => break status,
            Ok(None) if Instant::now() < deadline => thread::sleep(Duration::from_millis(25)),
            Ok(None) => {
                let _ = child.kill();
                let _ = child.wait();
                return Err(TunnelError::new(
                    "tunnel_executable_unverified",
                    "cloudflared version probe exceeded its deadline",
                ));
            }
            Err(error) => {
                let _ = child.kill();
                let _ = child.wait();
                return Err(TunnelError::new(
                    "tunnel_executable_unverified",
                    format!("inspect cloudflared version probe: {error}"),
                ));
            }
        }
    };

    let stdout = stdout_reader
        .join()
        .unwrap_or_else(|_| Err(io::Error::other("reader failed")));
    let stderr = stderr_reader
        .join()
        .unwrap_or_else(|_| Err(io::Error::other("reader failed")));
    let mut output = stdout.map_err(|error| {
        TunnelError::new(
            "tunnel_executable_unverified",
            format!("read cloudflared version output: {error}"),
        )
    })?;
    output.extend(stderr.map_err(|error| {
        TunnelError::new(
            "tunnel_executable_unverified",
            format!("read cloudflared version error output: {error}"),
        )
    })?);

    if !status.success() || output.len() > MAX_VERSION_OUTPUT_BYTES {
        return Err(TunnelError::new(
            "tunnel_executable_unverified",
            "cloudflared version probe failed",
        ));
    }
    let output = String::from_utf8_lossy(&output);
    let reported_version_matches = output.lines().any(|line| {
        line.trim()
            .strip_prefix("cloudflared version ")
            .and_then(|remainder| remainder.split_whitespace().next())
            == Some(expected)
    });
    if !reported_version_matches {
        return Err(TunnelError::new(
            "tunnel_executable_unverified",
            "cloudflared reported a version that differs from the approved manifest",
        ));
    }

    Ok(())
}

fn read_bounded_and_drain<R: Read>(mut reader: R) -> io::Result<Vec<u8>> {
    let mut retained = Vec::with_capacity(512);
    let mut buffer = [0_u8; 4096];
    loop {
        let count = reader.read(&mut buffer)?;
        if count == 0 {
            break;
        }
        let remaining = (MAX_VERSION_OUTPUT_BYTES + 1).saturating_sub(retained.len());
        retained.extend_from_slice(&buffer[..count.min(remaining)]);
    }
    Ok(retained)
}

fn ensure_regular_file(path: &Path, code: &'static str) -> Result<(), TunnelError> {
    let metadata = fs::symlink_metadata(path)
        .map_err(|error| TunnelError::new(code, format!("inspect required file: {error}")))?;
    let file_type = metadata.file_type();
    if file_type.is_symlink() || !file_type.is_file() {
        return Err(TunnelError::new(
            code,
            "required path is not a regular file",
        ));
    }
    Ok(())
}

const REMOTE_UPLOAD_BOUNDARY_SCHEMA_VERSION: u8 = 1;
const REMOTE_UPLOAD_BOUNDARY_ROUTE_SET: &str = "public_upload_v1";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct RemoteUploadBoundaryAttestation {
    schema_version: u8,
    status: String,
    hostname: String,
    listener_origin: String,
    route_set: String,
    upload_routes_only: bool,
    exact_host_enforced: bool,
    trusted_proxy_enforced: bool,
    forwarded_https_enforced: bool,
    local_tokens_rejected_on_remote_host: bool,
}

#[derive(Debug, Deserialize)]
struct RemoteUploadBoundaryHealthEnvelope {
    remote_upload_boundary: RemoteUploadBoundaryAttestation,
}

/// Runtime-only proof that the authenticated loopback health response
/// described the exact remote upload boundary required for one PHP listener.
///
/// The fields and constructor are intentionally private. Callers can only
/// receive this capability from the supervised, keyed health-check path and
/// must consume it when preparing a tunnel start.
pub struct VerifiedRemoteUploadBoundary {
    hostname: String,
    listener_origin: String,
}

impl fmt::Debug for VerifiedRemoteUploadBoundary {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter
            .debug_struct("VerifiedRemoteUploadBoundary")
            .finish_non_exhaustive()
    }
}

impl VerifiedRemoteUploadBoundary {
    pub(crate) fn from_health_response(
        body: &[u8],
        expected_hostname: &str,
        expected_listener_origin: &str,
    ) -> Option<Self> {
        validate_upload_hostname(expected_hostname).ok()?;
        let canonical_origin = LoopbackOrigin::parse(expected_listener_origin).ok()?;
        if canonical_origin.url != expected_listener_origin {
            return None;
        }

        let envelope = serde_json::from_slice::<RemoteUploadBoundaryHealthEnvelope>(body).ok()?;
        let attestation = envelope.remote_upload_boundary;
        if attestation.schema_version != REMOTE_UPLOAD_BOUNDARY_SCHEMA_VERSION
            || attestation.status != "ready"
            || attestation.hostname != expected_hostname
            || attestation.listener_origin != expected_listener_origin
            || attestation.route_set != REMOTE_UPLOAD_BOUNDARY_ROUTE_SET
            || !attestation.upload_routes_only
            || !attestation.exact_host_enforced
            || !attestation.trusted_proxy_enforced
            || !attestation.forwarded_https_enforced
            || !attestation.local_tokens_rejected_on_remote_host
        {
            return None;
        }

        Some(Self {
            hostname: attestation.hostname,
            listener_origin: attestation.listener_origin,
        })
    }

    fn authorizes(&self, hostname: &str, listener_origin: &str) -> bool {
        self.hostname == hostname && self.listener_origin == listener_origin
    }
}

pub struct TunnelConfiguration {
    executable: CloudflaredExecutable,
    token: ProtectedSecret,
    tunnel_id: Uuid,
    upload_hostname: String,
    application_version: String,
    readiness_timeout: Duration,
    shutdown_timeout: Duration,
    retry_limit: u8,
    retry_delay: Duration,
}

impl TunnelConfiguration {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        settings: TunnelSettings,
        executable: CloudflaredExecutable,
        token: ProtectedSecret,
        application_version: String,
        readiness_timeout: Duration,
        shutdown_timeout: Duration,
        retry_limit: u8,
        retry_delay: Duration,
    ) -> Result<Self, TunnelError> {
        settings.validate()?;
        if !settings.enabled {
            return Err(TunnelError::new(
                "tunnel_disabled",
                "tunnel settings are disabled",
            ));
        }
        validate_connector_token(token.expose())?;
        if application_version.is_empty()
            || readiness_timeout.is_zero()
            || readiness_timeout > Duration::from_secs(120)
            || shutdown_timeout.is_zero()
            || shutdown_timeout > Duration::from_secs(30)
            || retry_limit > 5
            || retry_delay > Duration::from_secs(30)
        {
            return Err(TunnelError::new(
                "tunnel_configuration_invalid",
                "tunnel lifecycle bounds are invalid",
            ));
        }

        Ok(Self {
            executable,
            token,
            tunnel_id: settings.tunnel_id.expect("validated tunnel ID is present"),
            upload_hostname: settings
                .upload_hostname
                .expect("validated upload hostname is present"),
            application_version,
            readiness_timeout,
            shutdown_timeout,
            retry_limit,
            retry_delay,
        })
    }

    pub fn upload_hostname(&self) -> &str {
        &self.upload_hostname
    }
}

fn validate_connector_token(token: &str) -> Result<(), TunnelError> {
    if !(32..=16 * 1024).contains(&token.len())
        || !token.bytes().all(|byte| {
            byte.is_ascii_alphanumeric() || matches!(byte, b'+' | b'/' | b'_' | b'-' | b'=' | b'.')
        })
    {
        return Err(TunnelError::new(
            "tunnel_credentials_unavailable",
            "connector token has an invalid format",
        ));
    }
    Ok(())
}

#[derive(Clone, Copy, Debug, Deserialize, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
enum TunnelPhase {
    Starting,
    Ready,
    Retrying,
    Unavailable,
    Stopping,
    Stopped,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
struct TunnelSnapshot {
    schema_version: u8,
    phase: TunnelPhase,
    upload_hostname: Option<String>,
    local_origin_port: Option<u16>,
    metrics_port: Option<u16>,
    process_id: Option<u32>,
    retry_count: u8,
    last_error_code: Option<String>,
    updated_at_unix_ms: u128,
}

impl TunnelSnapshot {
    fn new(
        phase: TunnelPhase,
        hostname: Option<&str>,
        retry_count: u8,
        code: Option<&'static str>,
    ) -> Self {
        Self {
            schema_version: 1,
            phase,
            upload_hostname: hostname.map(str::to_owned),
            local_origin_port: None,
            metrics_port: None,
            process_id: None,
            retry_count,
            last_error_code: code.map(str::to_owned),
            updated_at_unix_ms: crate::diagnostics::now_unix_ms(),
        }
    }
}

#[derive(Clone, Debug)]
struct LoopbackOrigin {
    url: String,
    port: u16,
}

impl LoopbackOrigin {
    fn parse(value: &str) -> Result<Self, TunnelError> {
        let parsed = Url::parse(value).map_err(|_| {
            TunnelError::new("tunnel_origin_invalid", "Laravel origin is not a valid URL")
        })?;
        let port = parsed.port().ok_or_else(|| {
            TunnelError::new(
                "tunnel_origin_invalid",
                "Laravel origin must use an explicit dynamic port",
            )
        })?;
        if parsed.scheme() != "http"
            || parsed.host_str() != Some("127.0.0.1")
            || parsed.username() != ""
            || parsed.password().is_some()
            || parsed.path() != "/"
            || parsed.query().is_some()
            || parsed.fragment().is_some()
        {
            return Err(TunnelError::new(
                "tunnel_origin_invalid",
                "Laravel origin must be the selected HTTP 127.0.0.1 origin",
            ));
        }
        Ok(Self {
            url: format!("http://127.0.0.1:{port}"),
            port,
        })
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum ProbeFailure {
    Cancelled,
    LocalTimeout,
    IdentityMismatch,
    RouteMismatch,
    PublicTimeout,
}

impl ProbeFailure {
    fn code(self) -> &'static str {
        match self {
            Self::Cancelled => "tunnel_cancelled",
            Self::LocalTimeout => "tunnel_local_readiness_timeout",
            Self::IdentityMismatch => "tunnel_identity_mismatch",
            Self::RouteMismatch => "tunnel_effective_route_mismatch",
            Self::PublicTimeout => "tunnel_public_readiness_timeout",
        }
    }
}

trait TunnelReadinessProbe: Send + Sync {
    fn wait_until_ready(
        &self,
        context: &ReadinessContext,
        should_abort: &dyn Fn() -> bool,
    ) -> Result<(), ProbeFailure>;

    fn verify_runtime(&self, context: &ReadinessContext) -> Result<(), ProbeFailure>;
}

struct ReadinessContext {
    metrics_port: u16,
    tunnel_id: Uuid,
    origin_url: String,
    upload_hostname: String,
    application_version: String,
    retry_count: u8,
    timeout: Duration,
}

struct HttpTunnelReadinessProbe;

impl TunnelReadinessProbe for HttpTunnelReadinessProbe {
    fn wait_until_ready(
        &self,
        context: &ReadinessContext,
        should_abort: &dyn Fn() -> bool,
    ) -> Result<(), ProbeFailure> {
        let deadline = Instant::now() + context.timeout;
        let local_client = Client::builder()
            .connect_timeout(Duration::from_secs(1))
            .timeout(Duration::from_secs(2))
            .redirect(Policy::none())
            .no_proxy()
            .build()
            .map_err(|_| ProbeFailure::LocalTimeout)?;
        let local_url = format!("http://127.0.0.1:{}/ready", context.metrics_port);

        while Instant::now() < deadline {
            if should_abort() {
                return Err(ProbeFailure::Cancelled);
            }
            if local_client
                .get(&local_url)
                .send()
                .is_ok_and(|response| response.status() == StatusCode::OK)
            {
                break;
            }
            thread::sleep(POLL_INTERVAL);
        }
        if Instant::now() >= deadline {
            return Err(ProbeFailure::LocalTimeout);
        }

        let tunnel_state_url = format!("http://127.0.0.1:{}/diag/tunnel", context.metrics_port);
        loop {
            if should_abort() {
                return Err(ProbeFailure::Cancelled);
            }
            match read_tunnel_id(&local_client, &tunnel_state_url) {
                Some(tunnel_id) if tunnel_id == context.tunnel_id => break,
                Some(_) => return Err(ProbeFailure::IdentityMismatch),
                None if Instant::now() < deadline => thread::sleep(POLL_INTERVAL),
                None => return Err(ProbeFailure::LocalTimeout),
            }
        }

        let effective_config_url = format!("http://127.0.0.1:{}/config", context.metrics_port);
        while Instant::now() < deadline {
            if should_abort() {
                return Err(ProbeFailure::Cancelled);
            }
            if effective_route_matches(
                &local_client,
                &effective_config_url,
                &context.upload_hostname,
                &context.origin_url,
            ) {
                break;
            }
            thread::sleep(POLL_INTERVAL);
        }
        if Instant::now() >= deadline {
            return Err(ProbeFailure::RouteMismatch);
        }

        let public_url = format!("https://{}/health", context.upload_hostname);
        let public_client = Client::builder()
            .connect_timeout(Duration::from_secs(2))
            .timeout(Duration::from_secs(4))
            .redirect(Policy::none())
            .https_only(true)
            .build()
            .map_err(|_| ProbeFailure::PublicTimeout)?;
        while Instant::now() < deadline {
            if should_abort() {
                return Err(ProbeFailure::Cancelled);
            }
            if public_health_is_ready(&public_client, &public_url, &context.application_version) {
                return Ok(());
            }
            thread::sleep(POLL_INTERVAL);
        }

        Err(ProbeFailure::PublicTimeout)
    }

    fn verify_runtime(&self, context: &ReadinessContext) -> Result<(), ProbeFailure> {
        let local_client = Client::builder()
            .connect_timeout(Duration::from_secs(1))
            .timeout(Duration::from_secs(2))
            .redirect(Policy::none())
            .no_proxy()
            .build()
            .map_err(|_| ProbeFailure::LocalTimeout)?;
        let ready_url = format!("http://127.0.0.1:{}/ready", context.metrics_port);
        if !local_client
            .get(&ready_url)
            .send()
            .is_ok_and(|response| response.status() == StatusCode::OK)
        {
            return Err(ProbeFailure::LocalTimeout);
        }
        let tunnel_state_url = format!("http://127.0.0.1:{}/diag/tunnel", context.metrics_port);
        if read_tunnel_id(&local_client, &tunnel_state_url) != Some(context.tunnel_id) {
            return Err(ProbeFailure::IdentityMismatch);
        }
        let effective_config_url = format!("http://127.0.0.1:{}/config", context.metrics_port);
        if !effective_route_matches(
            &local_client,
            &effective_config_url,
            &context.upload_hostname,
            &context.origin_url,
        ) {
            return Err(ProbeFailure::RouteMismatch);
        }

        let public_client = Client::builder()
            .connect_timeout(Duration::from_secs(2))
            .timeout(Duration::from_secs(4))
            .redirect(Policy::none())
            .https_only(true)
            .build()
            .map_err(|_| ProbeFailure::PublicTimeout)?;
        let public_url = format!("https://{}/health", context.upload_hostname);
        if !public_health_is_ready(&public_client, &public_url, &context.application_version) {
            return Err(ProbeFailure::PublicTimeout);
        }

        Ok(())
    }
}

#[derive(Debug, Deserialize)]
struct TunnelDiagnostic {
    #[serde(rename = "tunnelID")]
    tunnel_id: Uuid,
}

fn read_tunnel_id(client: &Client, url: &str) -> Option<Uuid> {
    read_bounded_json::<TunnelDiagnostic>(client, url).map(|state| state.tunnel_id)
}

#[derive(Debug, Deserialize)]
struct EffectiveTunnelConfiguration {
    #[serde(default)]
    ingress: Vec<EffectiveIngressRule>,
    #[serde(default, rename = "warp-routing")]
    warp_routing: EffectiveWarpRouting,
}

#[derive(Debug, Deserialize)]
struct EffectiveIngressRule {
    #[serde(default)]
    hostname: String,
    #[serde(default)]
    path: String,
    service: String,
}

#[derive(Debug, Default, Deserialize)]
struct EffectiveWarpRouting {
    #[serde(default)]
    enabled: bool,
}

fn effective_route_matches(
    client: &Client,
    url: &str,
    upload_hostname: &str,
    origin_url: &str,
) -> bool {
    let Some(configuration) = read_bounded_json::<EffectiveTunnelConfiguration>(client, url) else {
        return false;
    };
    effective_configuration_matches(&configuration, upload_hostname, origin_url)
}

fn effective_configuration_matches(
    configuration: &EffectiveTunnelConfiguration,
    upload_hostname: &str,
    origin_url: &str,
) -> bool {
    if configuration.warp_routing.enabled || configuration.ingress.len() != 2 {
        return false;
    }
    let upload_rule = &configuration.ingress[0];
    let fallback_rule = &configuration.ingress[1];
    let service_matches =
        LoopbackOrigin::parse(&upload_rule.service).is_ok_and(|origin| origin.url == origin_url);

    upload_rule.hostname == upload_hostname
        && upload_rule.path.is_empty()
        && service_matches
        && fallback_rule.hostname.is_empty()
        && fallback_rule.path.is_empty()
        && fallback_rule.service == "http_status:404"
}

fn read_bounded_json<T: for<'de> Deserialize<'de>>(client: &Client, url: &str) -> Option<T> {
    let mut response = client.get(url).send().ok()?;
    if response.status() != StatusCode::OK {
        return None;
    }
    let mut body = Vec::with_capacity(2048);
    response
        .by_ref()
        .take(MAX_READINESS_RESPONSE_BYTES + 1)
        .read_to_end(&mut body)
        .ok()?;
    if body.len() > MAX_READINESS_RESPONSE_BYTES as usize {
        return None;
    }
    serde_json::from_slice(&body).ok()
}

#[derive(Debug, Deserialize)]
struct PublicHealth {
    status: String,
    application: PublicHealthApplication,
}

#[derive(Debug, Deserialize)]
struct PublicHealthApplication {
    version: String,
}

fn public_health_is_ready(client: &Client, url: &str, application_version: &str) -> bool {
    let Ok(mut response) = client.get(url).send() else {
        return false;
    };
    if response.status() != StatusCode::OK {
        return false;
    }
    let mut body = Vec::with_capacity(2048);
    response
        .by_ref()
        .take(MAX_READINESS_RESPONSE_BYTES + 1)
        .read_to_end(&mut body)
        .is_ok()
        && body.len() <= MAX_READINESS_RESPONSE_BYTES as usize
        && serde_json::from_slice::<PublicHealth>(&body).is_ok_and(|payload| {
            payload.status == "healthy" && payload.application.version == application_version
        })
}

enum TunnelMode {
    Inactive {
        phase: TunnelPhase,
        hostname: Option<String>,
        code: &'static str,
    },
    Active(TunnelConfiguration),
}

struct ActiveChild {
    generation: u64,
    child: Child,
}

#[derive(Default)]
struct Lifecycle {
    generation: u64,
    child: Option<ActiveChild>,
    worker: Option<thread::JoinHandle<()>>,
}

pub struct TunnelSupervisor {
    mode: TunnelMode,
    logger: Arc<RuntimeLogger>,
    transition: Mutex<()>,
    lifecycle: Mutex<Lifecycle>,
    shutting_down: AtomicBool,
    snapshot_path: PathBuf,
    native_status: Option<Mutex<NativeTunnelStatusPublisher>>,
    readiness_probe: Arc<dyn TunnelReadinessProbe>,
}

impl TunnelSupervisor {
    pub fn disabled(
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
    ) -> Result<Self, TunnelError> {
        Self::inactive(
            runtime_directory,
            logger,
            TunnelPhase::Stopped,
            None,
            "tunnel_disabled",
        )
    }

    pub fn unavailable(
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
        hostname: Option<String>,
        code: &'static str,
    ) -> Result<Self, TunnelError> {
        Self::inactive(
            runtime_directory,
            logger,
            TunnelPhase::Unavailable,
            hostname,
            code,
        )
    }

    fn inactive(
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
        phase: TunnelPhase,
        hostname: Option<String>,
        code: &'static str,
    ) -> Result<Self, TunnelError> {
        fs::create_dir_all(runtime_directory).map_err(|error| {
            TunnelError::new(
                "tunnel_runtime_io_failed",
                format!("create tunnel runtime directory: {error}"),
            )
        })?;
        let supervisor = Self {
            mode: TunnelMode::Inactive {
                phase,
                hostname,
                code,
            },
            logger,
            transition: Mutex::new(()),
            lifecycle: Mutex::new(Lifecycle::default()),
            shutting_down: AtomicBool::new(false),
            snapshot_path: runtime_directory.join("tunnel-state.json"),
            native_status: None,
            readiness_probe: Arc::new(HttpTunnelReadinessProbe),
        };
        supervisor.write_inactive_snapshot();
        Ok(supervisor)
    }

    pub fn new(
        configuration: TunnelConfiguration,
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
    ) -> Result<Self, TunnelError> {
        Self::new_with_readiness_probe(
            configuration,
            runtime_directory,
            logger,
            Arc::new(HttpTunnelReadinessProbe),
        )
    }

    fn new_with_readiness_probe(
        configuration: TunnelConfiguration,
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
        readiness_probe: Arc<dyn TunnelReadinessProbe>,
    ) -> Result<Self, TunnelError> {
        fs::create_dir_all(runtime_directory).map_err(|error| {
            TunnelError::new(
                "tunnel_runtime_io_failed",
                format!("create tunnel runtime directory: {error}"),
            )
        })?;
        let hostname = configuration.upload_hostname.clone();
        let supervisor = Self {
            mode: TunnelMode::Active(configuration),
            logger,
            transition: Mutex::new(()),
            lifecycle: Mutex::new(Lifecycle::default()),
            shutting_down: AtomicBool::new(false),
            snapshot_path: runtime_directory.join("tunnel-state.json"),
            native_status: None,
            readiness_probe,
        };
        supervisor.write_snapshot(&TunnelSnapshot::new(
            TunnelPhase::Stopped,
            Some(&hostname),
            0,
            Some("tunnel_stopped"),
        ));
        Ok(supervisor)
    }

    /// Attach the authenticated native-to-Laravel status contract. Existing
    /// constructors remain useful for isolated tests, while the desktop shell
    /// must call this before sharing the supervisor or starting a tunnel.
    pub fn with_authenticated_native_status(
        mut self,
        authentication_key: &str,
        installation_id: &str,
        application_version: &str,
    ) -> Result<Self, TunnelError> {
        let installation_id = Uuid::parse_str(installation_id).map_err(|_| {
            TunnelError::new(
                "tunnel_status_configuration_invalid",
                "native tunnel status installation identity is invalid",
            )
        })?;
        if installation_id.is_nil() {
            return Err(TunnelError::new(
                "tunnel_status_configuration_invalid",
                "native tunnel status installation identity is nil",
            ));
        }
        let status_path = self
            .snapshot_path
            .with_file_name("tunnel-public-status.json");
        self.native_status = Some(Mutex::new(
            NativeTunnelStatusPublisher::new(
                status_path,
                authentication_key,
                installation_id,
                application_version.to_owned(),
            )
            .map_err(|error| TunnelError::new(error.code(), error.to_string()))?,
        ));

        let initial = match &self.mode {
            TunnelMode::Inactive {
                phase,
                hostname,
                code,
            } => TunnelSnapshot::new(*phase, hostname.as_deref(), 0, Some(code)),
            TunnelMode::Active(configuration) => TunnelSnapshot::new(
                TunnelPhase::Stopped,
                Some(configuration.upload_hostname()),
                0,
                Some("tunnel_stopped"),
            ),
        };
        self.write_snapshot(&initial);

        Ok(self)
    }

    pub fn required_attestation_hostname(&self) -> Option<&str> {
        match &self.mode {
            TunnelMode::Active(configuration) => Some(configuration.upload_hostname()),
            TunnelMode::Inactive { .. } => None,
        }
    }

    pub fn start_for_origin(
        self: &Arc<Self>,
        local_origin: &str,
        verified_boundary: VerifiedRemoteUploadBoundary,
    ) {
        let TunnelMode::Active(configuration) = &self.mode else {
            self.write_inactive_snapshot();
            return;
        };
        if self.shutting_down.load(Ordering::SeqCst) {
            return;
        }
        if !verified_boundary.authorizes(configuration.upload_hostname(), local_origin) {
            self.deny_unverified_origin();
            return;
        }
        let origin = match LoopbackOrigin::parse(local_origin) {
            Ok(origin) => origin,
            Err(error) => {
                self.logger.warn(error.code());
                self.write_snapshot(&TunnelSnapshot::new(
                    TunnelPhase::Unavailable,
                    Some(configuration.upload_hostname()),
                    0,
                    Some(error.code()),
                ));
                return;
            }
        };
        let _transition = self
            .transition
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        if self.shutting_down.load(Ordering::SeqCst) {
            return;
        }

        let (generation, previous_child, previous_worker) = match self.lifecycle.lock() {
            Ok(mut lifecycle) => {
                lifecycle.generation = lifecycle.generation.wrapping_add(1);
                let generation = lifecycle.generation;
                (generation, lifecycle.child.take(), lifecycle.worker.take())
            }
            Err(_) => {
                self.write_snapshot(&TunnelSnapshot::new(
                    TunnelPhase::Unavailable,
                    Some(configuration.upload_hostname()),
                    0,
                    Some("tunnel_runtime_io_failed"),
                ));
                return;
            }
        };
        self.terminate_child_and_join_worker(previous_child, previous_worker);

        let supervisor = Arc::clone(self);
        let mut lifecycle = self
            .lifecycle
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        if lifecycle.generation != generation || self.shutting_down.load(Ordering::SeqCst) {
            return;
        }
        match thread::Builder::new()
            .name("medismart-cloudflared".to_owned())
            .spawn(move || supervisor.run_generation(generation, origin))
        {
            Ok(worker) => lifecycle.worker = Some(worker),
            Err(_) => {
                drop(lifecycle);
                self.logger
                    .warn("Cloudflare Tunnel supervisor failed to start");
                self.write_snapshot(&TunnelSnapshot::new(
                    TunnelPhase::Unavailable,
                    Some(configuration.upload_hostname()),
                    0,
                    Some("tunnel_runtime_io_failed"),
                ));
            }
        }
    }

    pub fn deny_unverified_origin(&self) {
        if let TunnelMode::Inactive { .. } = &self.mode {
            self.write_inactive_snapshot();
            return;
        }
        self.logger.warn(ORIGIN_ATTESTATION_UNAVAILABLE);
        let _transition = self
            .transition
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        let mut lifecycle = self
            .lifecycle
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        let (child, worker) = {
            lifecycle.generation = lifecycle.generation.wrapping_add(1);
            (lifecycle.child.take(), lifecycle.worker.take())
        };
        drop(lifecycle);
        self.terminate_child_and_join_worker(child, worker);
        self.write_snapshot(&TunnelSnapshot::new(
            TunnelPhase::Unavailable,
            self.hostname(),
            0,
            Some(ORIGIN_ATTESTATION_UNAVAILABLE),
        ));
    }

    pub fn stop_active(&self) {
        if let TunnelMode::Inactive { .. } = &self.mode {
            self.write_inactive_snapshot();
            return;
        }
        let _transition = self
            .transition
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        let (child, worker) = {
            let mut lifecycle = self
                .lifecycle
                .lock()
                .unwrap_or_else(|poisoned| poisoned.into_inner());
            lifecycle.generation = lifecycle.generation.wrapping_add(1);
            (lifecycle.child.take(), lifecycle.worker.take())
        };
        let hostname = self.hostname();
        self.write_snapshot(&TunnelSnapshot::new(
            TunnelPhase::Stopping,
            hostname,
            0,
            None,
        ));
        self.terminate_child_and_join_worker(child, worker);
        self.write_snapshot(&TunnelSnapshot::new(
            TunnelPhase::Stopped,
            hostname,
            0,
            Some("tunnel_stopped"),
        ));
    }

    pub fn shutdown(&self) {
        if self.shutting_down.swap(true, Ordering::SeqCst) {
            return;
        }
        self.stop_active();
    }

    fn run_generation(self: Arc<Self>, generation: u64, origin: LoopbackOrigin) {
        let TunnelMode::Active(configuration) = &self.mode else {
            return;
        };
        for retry_count in 0..=configuration.retry_limit {
            if !self.is_current(generation) {
                return;
            }
            let phase = if retry_count == 0 {
                TunnelPhase::Starting
            } else {
                TunnelPhase::Retrying
            };
            self.write_snapshot(&TunnelSnapshot::new(
                phase,
                Some(configuration.upload_hostname()),
                retry_count,
                None,
            ));

            let attempt = self.launch_attempt(generation, retry_count, &origin);
            let error_code = match attempt {
                Ok(context) => match self.monitor_child(generation, &context) {
                    MonitorResult::Cancelled => return,
                    MonitorResult::Exited => "tunnel_exited",
                    MonitorResult::Unhealthy(code) => code,
                },
                Err("tunnel_cancelled") => return,
                Err(error_code) => error_code,
            };
            self.stop_generation_child(generation);
            if !self.is_current(generation) {
                return;
            }
            if retry_count >= configuration.retry_limit {
                self.logger.warn("Cloudflare Tunnel retries exhausted");
                self.write_snapshot(&TunnelSnapshot::new(
                    TunnelPhase::Unavailable,
                    Some(configuration.upload_hostname()),
                    retry_count,
                    Some("tunnel_retries_exhausted"),
                ));
                return;
            }

            self.logger.warn(error_code);
            let mut retry_snapshot = TunnelSnapshot::new(
                TunnelPhase::Retrying,
                Some(configuration.upload_hostname()),
                retry_count + 1,
                Some(error_code),
            );
            retry_snapshot.local_origin_port = Some(origin.port);
            self.write_snapshot(&retry_snapshot);
            let delay = configuration
                .retry_delay
                .saturating_mul(u32::from(retry_count + 1));
            if !self.wait_while_current(generation, delay) {
                return;
            }
        }
    }

    fn launch_attempt(
        &self,
        generation: u64,
        retry_count: u8,
        origin: &LoopbackOrigin,
    ) -> Result<ReadinessContext, &'static str> {
        let TunnelMode::Active(configuration) = &self.mode else {
            return Err("tunnel_cancelled");
        };
        let metrics_port = allocate_loopback_port().map_err(|_| "tunnel_metrics_unavailable")?;
        let mut command = build_cloudflared_command(configuration, origin, metrics_port);
        let mut child = command.spawn().map_err(|_| "tunnel_spawn_failed")?;
        let process_id = child.id();
        if let Some(stdout) = child.stdout.take() {
            pump_child_output(stdout, "TUNNEL-OUT", Arc::clone(&self.logger));
        }
        if let Some(stderr) = child.stderr.take() {
            pump_child_output(stderr, "TUNNEL-ERR", Arc::clone(&self.logger));
        }

        let mut lifecycle = self
            .lifecycle
            .lock()
            .map_err(|_| "tunnel_runtime_io_failed")?;
        if lifecycle.generation != generation || self.shutting_down.load(Ordering::SeqCst) {
            drop(lifecycle);
            self.terminate_child(child);
            return Err("tunnel_cancelled");
        }
        lifecycle.child = Some(ActiveChild { generation, child });
        drop(lifecycle);

        let mut snapshot = TunnelSnapshot::new(
            TunnelPhase::Starting,
            Some(configuration.upload_hostname()),
            retry_count,
            None,
        );
        snapshot.local_origin_port = Some(origin.port);
        snapshot.metrics_port = Some(metrics_port);
        snapshot.process_id = Some(process_id);
        self.write_snapshot(&snapshot);

        let context = ReadinessContext {
            metrics_port,
            tunnel_id: configuration.tunnel_id,
            origin_url: origin.url.clone(),
            upload_hostname: configuration.upload_hostname.clone(),
            application_version: configuration.application_version.clone(),
            retry_count,
            timeout: configuration.readiness_timeout,
        };
        let readiness = self
            .readiness_probe
            .wait_until_ready(&context, &|| self.should_abort(generation));
        match readiness {
            Ok(()) => {
                snapshot.phase = TunnelPhase::Ready;
                snapshot.updated_at_unix_ms = crate::diagnostics::now_unix_ms();
                self.write_snapshot(&snapshot);
                self.logger.info(&format!(
                    "Named Cloudflare Tunnel is ready for https://{}",
                    configuration.upload_hostname
                ));
                Ok(context)
            }
            Err(ProbeFailure::Cancelled) if self.is_current(generation) => Err("tunnel_exited"),
            Err(ProbeFailure::Cancelled) => Err("tunnel_cancelled"),
            Err(error) => Err(error.code()),
        }
    }

    fn should_abort(&self, generation: u64) -> bool {
        if !self.is_current(generation) {
            return true;
        }
        let Ok(mut lifecycle) = self.lifecycle.lock() else {
            return true;
        };
        let Some(active) = lifecycle.child.as_mut() else {
            return true;
        };
        if active.generation != generation {
            return true;
        }
        active
            .child
            .try_wait()
            .map_or(true, |status| status.is_some())
    }

    fn monitor_child(
        &self,
        generation: u64,
        readiness_context: &ReadinessContext,
    ) -> MonitorResult {
        let mut next_readiness_check = Instant::now() + RUNTIME_READINESS_INTERVAL;
        loop {
            if !self.is_current(generation) {
                return MonitorResult::Cancelled;
            }
            let status = {
                let Ok(mut lifecycle) = self.lifecycle.lock() else {
                    return MonitorResult::Exited;
                };
                let Some(active) = lifecycle.child.as_mut() else {
                    return MonitorResult::Cancelled;
                };
                if active.generation != generation {
                    return MonitorResult::Cancelled;
                }
                active.child.try_wait()
            };
            match status {
                Ok(Some(_)) | Err(_) => return MonitorResult::Exited,
                Ok(None) => {
                    if Instant::now() >= next_readiness_check {
                        if let Err(error) = self.readiness_probe.verify_runtime(readiness_context) {
                            return MonitorResult::Unhealthy(error.code());
                        }
                        self.write_ready_heartbeat(generation, readiness_context);
                        next_readiness_check = Instant::now() + RUNTIME_READINESS_INTERVAL;
                    }
                    thread::sleep(POLL_INTERVAL);
                }
            }
        }
    }

    fn stop_generation_child(&self, generation: u64) {
        let child = self.lifecycle.lock().ok().and_then(|mut lifecycle| {
            if lifecycle
                .child
                .as_ref()
                .is_some_and(|active| active.generation == generation)
            {
                lifecycle.child.take()
            } else {
                None
            }
        });
        if let Some(active) = child {
            self.terminate_child(active.child);
        }
    }

    fn terminate_child(&self, mut child: Child) {
        request_graceful_termination(child.id());
        let shutdown_timeout = match &self.mode {
            TunnelMode::Active(configuration) => configuration.shutdown_timeout,
            TunnelMode::Inactive { .. } => Duration::from_secs(1),
        };
        let deadline = Instant::now() + shutdown_timeout;
        while Instant::now() < deadline {
            match child.try_wait() {
                Ok(Some(_)) => {
                    self.logger.info("Cloudflare Tunnel stopped cleanly");
                    return;
                }
                Ok(None) => thread::sleep(Duration::from_millis(50)),
                Err(_) => break,
            }
        }
        self.logger
            .warn("Cloudflare Tunnel exceeded its shutdown deadline; forcing termination");
        let _ = child.kill();
        let _ = child.wait();
    }

    fn terminate_child_and_join_worker(
        &self,
        child: Option<ActiveChild>,
        worker: Option<thread::JoinHandle<()>>,
    ) {
        if let Some(active) = child {
            self.terminate_child(active.child);
        }
        if let Some(worker) = worker {
            if worker.thread().id() == thread::current().id() {
                self.logger
                    .warn("Cloudflare Tunnel worker cannot join itself during teardown");
                return;
            }
            if worker.join().is_err() {
                self.logger
                    .warn("Cloudflare Tunnel supervisor stopped after an internal failure");
            }
        }
    }

    fn is_current(&self, generation: u64) -> bool {
        !self.shutting_down.load(Ordering::SeqCst)
            && self
                .lifecycle
                .lock()
                .is_ok_and(|lifecycle| lifecycle.generation == generation)
    }

    fn wait_while_current(&self, generation: u64, duration: Duration) -> bool {
        let deadline = Instant::now() + duration;
        while Instant::now() < deadline {
            if !self.is_current(generation) {
                return false;
            }
            thread::sleep(Duration::from_millis(50));
        }
        self.is_current(generation)
    }

    fn hostname(&self) -> Option<&str> {
        match &self.mode {
            TunnelMode::Inactive { hostname, .. } => hostname.as_deref(),
            TunnelMode::Active(configuration) => Some(configuration.upload_hostname()),
        }
    }

    fn write_ready_heartbeat(&self, generation: u64, context: &ReadinessContext) {
        if !self.is_current(generation) {
            return;
        }
        let process_id = self.lifecycle.lock().ok().and_then(|lifecycle| {
            lifecycle
                .child
                .as_ref()
                .and_then(|active| (active.generation == generation).then_some(active.child.id()))
        });
        let mut snapshot = TunnelSnapshot::new(
            TunnelPhase::Ready,
            Some(&context.upload_hostname),
            context.retry_count,
            None,
        );
        snapshot.local_origin_port = Url::parse(&context.origin_url)
            .ok()
            .and_then(|origin| origin.port());
        snapshot.metrics_port = Some(context.metrics_port);
        snapshot.process_id = process_id;
        self.write_snapshot(&snapshot);
    }

    fn write_inactive_snapshot(&self) {
        if let TunnelMode::Inactive {
            phase,
            hostname,
            code,
        } = &self.mode
        {
            self.write_snapshot(&TunnelSnapshot::new(
                *phase,
                hostname.as_deref(),
                0,
                Some(code),
            ));
        }
    }

    fn write_snapshot(&self, snapshot: &TunnelSnapshot) {
        self.publish_authenticated_snapshot(snapshot);
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

    fn publish_authenticated_snapshot(&self, snapshot: &TunnelSnapshot) {
        let Some(publisher) = &self.native_status else {
            return;
        };
        let updated_at_unix_ms = match u64::try_from(snapshot.updated_at_unix_ms) {
            Ok(value) => value,
            Err(_) => {
                self.logger.warn("native_tunnel_status_invalid");
                return;
            }
        };
        let listener_origin = snapshot
            .local_origin_port
            .map(|port| format!("http://127.0.0.1:{port}"));
        let (cloudflared_version, executable_verified) = match &self.mode {
            TunnelMode::Active(configuration) => (Some(configuration.executable.version()), true),
            TunnelMode::Inactive { .. } => (None, false),
        };
        let phase = match snapshot.phase {
            TunnelPhase::Starting => NativeTunnelPhase::Starting,
            TunnelPhase::Ready => NativeTunnelPhase::Ready,
            TunnelPhase::Retrying => NativeTunnelPhase::Retrying,
            TunnelPhase::Unavailable => NativeTunnelPhase::Unavailable,
            TunnelPhase::Stopping => NativeTunnelPhase::Stopping,
            TunnelPhase::Stopped => NativeTunnelPhase::Stopped,
        };
        let update = NativeTunnelStatusUpdate {
            configured_hostname: snapshot.upload_hostname.as_deref(),
            phase,
            listener_origin: listener_origin.as_deref(),
            cloudflared_version,
            executable_verified,
            retry_count: snapshot.retry_count,
            last_error_code: snapshot.last_error_code.as_deref(),
            updated_at_unix_ms,
        };
        match publisher.lock() {
            Ok(mut publisher) => {
                if let Err(error) = publisher.publish(update) {
                    self.logger.warn(error.code());
                }
            }
            Err(_) => self.logger.warn("native_tunnel_status_write_failed"),
        }
    }
}

impl Drop for TunnelSupervisor {
    fn drop(&mut self) {
        self.shutting_down.store(true, Ordering::SeqCst);
        let (child, worker) = self.lifecycle.get_mut().map_or((None, None), |lifecycle| {
            (lifecycle.child.take(), lifecycle.worker.take())
        });
        self.terminate_child_and_join_worker(child, worker);
    }
}

enum MonitorResult {
    Cancelled,
    Exited,
    Unhealthy(&'static str),
}

fn build_cloudflared_command(
    configuration: &TunnelConfiguration,
    origin: &LoopbackOrigin,
    metrics_port: u16,
) -> Command {
    let metrics_address = format!("127.0.0.1:{metrics_port}");
    let cloudflared_grace_seconds = configuration
        .shutdown_timeout
        .as_secs()
        .saturating_sub(1)
        .clamp(1, 3);
    let mut command = Command::new(configuration.executable.path());
    clear_cloudflared_secret_environment(&mut command);
    command
        .arg("tunnel")
        .arg("--config")
        .arg(null_configuration_path())
        .arg("--no-autoupdate")
        .arg("--metrics")
        .arg(&metrics_address)
        .arg("--loglevel")
        .arg("warn")
        .arg("--grace-period")
        .arg(format!("{cloudflared_grace_seconds}s"))
        .arg("--retries")
        .arg("2")
        .arg("run")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .env("TUNNEL_TOKEN", configuration.token.expose())
        .env("TUNNEL_URL", &origin.url)
        .env("TUNNEL_METRICS", &metrics_address)
        .env("TUNNEL_LOGLEVEL", "warn")
        .env("TUNNEL_TRANSPORT_LOGLEVEL", "warn")
        .env("TUNNEL_RETRIES", "2")
        .env(
            "TUNNEL_GRACE_PERIOD",
            format!("{cloudflared_grace_seconds}s"),
        )
        .env("TUNNEL_HA_CONNECTIONS", "1")
        .env("NO_AUTOUPDATE", "true");
    configure_platform_process(&mut command);
    command
}

fn clear_cloudflared_secret_environment(command: &mut Command) {
    for name in [
        "TUNNEL_TOKEN",
        "TUNNEL_TOKEN_FILE",
        "TUNNEL_CRED_FILE",
        "TUNNEL_CREDENTIALS_FILE",
        "TUNNEL_ORIGIN_CERT",
        "TUNNEL_CONFIG",
        "TUNNEL_URL",
        "TUNNEL_HOSTNAME",
        "TUNNEL_METRICS",
        "TUNNEL_LOGFILE",
        "TUNNEL_LOGDIRECTORY",
        "TUNNEL_TRACE_OUTPUT",
        "TUNNEL_QUICK_SERVICE",
    ] {
        command.env_remove(name);
    }
}

#[cfg(windows)]
fn null_configuration_path() -> &'static str {
    "NUL"
}

#[cfg(not(windows))]
fn null_configuration_path() -> &'static str {
    "/dev/null"
}

#[cfg(test)]
mod tests {
    use std::{ffi::OsStr, fs, path::PathBuf};

    use crate::{
        read_authenticated_native_tunnel_status, read_protected_secret, write_new_protected_secret,
    };

    use super::*;

    const TEST_TOKEN: &str = "eyJhIjoiY2xpbmljLXR1bm5lbCIsInQiOiJjcmVkZW50aWFsIn0=";
    const TEST_STATUS_KEY: &str = "native-tunnel-status-test-key-with-more-than-32-bytes";
    const TEST_INSTALLATION_ID: &str = "bfe152e6-74b4-46de-bf9e-f07cf3eff0c4";

    struct ImmediateReadiness;

    impl TunnelReadinessProbe for ImmediateReadiness {
        fn wait_until_ready(
            &self,
            _context: &ReadinessContext,
            should_abort: &dyn Fn() -> bool,
        ) -> Result<(), ProbeFailure> {
            if should_abort() {
                Err(ProbeFailure::Cancelled)
            } else {
                Ok(())
            }
        }

        fn verify_runtime(&self, _context: &ReadinessContext) -> Result<(), ProbeFailure> {
            Ok(())
        }
    }

    struct CancellationRecordingReadiness {
        entered: Arc<AtomicBool>,
        exited: Arc<AtomicBool>,
    }

    impl TunnelReadinessProbe for CancellationRecordingReadiness {
        fn wait_until_ready(
            &self,
            _context: &ReadinessContext,
            should_abort: &dyn Fn() -> bool,
        ) -> Result<(), ProbeFailure> {
            self.entered.store(true, Ordering::SeqCst);
            while !should_abort() {
                thread::sleep(Duration::from_millis(5));
            }
            thread::sleep(Duration::from_millis(50));
            self.exited.store(true, Ordering::SeqCst);
            Err(ProbeFailure::Cancelled)
        }

        fn verify_runtime(&self, _context: &ReadinessContext) -> Result<(), ProbeFailure> {
            Ok(())
        }
    }

    fn temporary_directory() -> PathBuf {
        let directory = std::env::temp_dir().join(format!("medismart-tunnel-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        directory
    }

    fn enabled_settings() -> TunnelSettings {
        serde_json::from_str(&format!(
            r#"{{
                "schema_version": 1,
                "enabled": true,
                "provider": "cloudflare",
                "management": "remote",
                "tunnel_id": "{}",
                "upload_hostname": "uploads.clinic.example"
            }}"#,
            Uuid::new_v4()
        ))
        .unwrap()
    }

    fn valid_remote_upload_boundary(listener_origin: &str) -> serde_json::Value {
        serde_json::json!({
            "schema_version": 1,
            "status": "ready",
            "hostname": "uploads.clinic.example",
            "listener_origin": listener_origin,
            "route_set": "public_upload_v1",
            "upload_routes_only": true,
            "exact_host_enforced": true,
            "trusted_proxy_enforced": true,
            "forwarded_https_enforced": true,
            "local_tokens_rejected_on_remote_host": true
        })
    }

    fn health_response_with_boundary(boundary: &serde_json::Value) -> Vec<u8> {
        serde_json::to_vec(&serde_json::json!({
            "remote_upload_boundary": boundary,
        }))
        .unwrap()
    }

    fn verify_boundary_value(
        boundary: &serde_json::Value,
        expected_hostname: &str,
        expected_listener_origin: &str,
    ) -> Option<VerifiedRemoteUploadBoundary> {
        VerifiedRemoteUploadBoundary::from_health_response(
            &health_response_with_boundary(boundary),
            expected_hostname,
            expected_listener_origin,
        )
    }

    fn verified_boundary(listener_origin: &str) -> VerifiedRemoteUploadBoundary {
        verify_boundary_value(
            &valid_remote_upload_boundary(listener_origin),
            "uploads.clinic.example",
            listener_origin,
        )
        .unwrap()
    }

    fn test_token(directory: &Path) -> ProtectedSecret {
        let path = directory.join("cloudflared.token");
        write_new_protected_secret(&path, TEST_TOKEN).unwrap();
        read_protected_secret(&path).unwrap()
    }

    fn test_logger(directory: &Path) -> Arc<RuntimeLogger> {
        Arc::new(
            RuntimeLogger::open_named(
                &directory.join("logs"),
                "cloudflared-supervisor.log",
                &[TEST_TOKEN.to_owned()],
                &[directory.to_path_buf()],
            )
            .unwrap(),
        )
    }

    fn test_configuration(
        directory: &Path,
        executable: CloudflaredExecutable,
        retry_limit: u8,
    ) -> TunnelConfiguration {
        TunnelConfiguration::new(
            enabled_settings(),
            executable,
            test_token(directory),
            "0.1.0".to_owned(),
            Duration::from_secs(2),
            Duration::from_secs(2),
            retry_limit,
            Duration::from_millis(20),
        )
        .unwrap()
    }

    #[cfg(unix)]
    fn write_executable(directory: &Path, body: &str) -> (PathBuf, PathBuf) {
        use std::os::unix::fs::PermissionsExt;

        let executable = directory.join("cloudflared");
        fs::write(&executable, body).unwrap();
        fs::set_permissions(&executable, fs::Permissions::from_mode(0o700)).unwrap();
        let digest = sha256_file(&executable).unwrap();
        let manifest = directory.join("cloudflared.manifest.json");
        fs::write(
            &manifest,
            format!(r#"{{"schema_version":1,"version":"2026.8.0","sha256":"{digest}"}}"#),
        )
        .unwrap();
        (executable, manifest)
    }

    fn wait_for_snapshot(
        path: &Path,
        timeout: Duration,
        predicate: impl Fn(&TunnelSnapshot) -> bool,
    ) -> TunnelSnapshot {
        let deadline = Instant::now() + timeout;
        while Instant::now() < deadline {
            if let Ok(bytes) = fs::read(path) {
                if let Ok(snapshot) = serde_json::from_slice::<TunnelSnapshot>(&bytes) {
                    if predicate(&snapshot) {
                        return snapshot;
                    }
                }
            }
            thread::sleep(Duration::from_millis(20));
        }
        panic!("tunnel snapshot did not reach the expected state");
    }

    #[test]
    fn disabled_settings_need_no_credentials_and_enabled_settings_are_strict() {
        let directory = temporary_directory();
        let disabled_path = directory.join("disabled.json");
        fs::write(&disabled_path, r#"{"schema_version":1,"enabled":false}"#).unwrap();
        assert!(!load_tunnel_settings(&disabled_path).unwrap().enabled());

        let quick_path = directory.join("quick.json");
        fs::write(
            &quick_path,
            format!(
                r#"{{"schema_version":1,"enabled":true,"provider":"cloudflare","management":"remote","tunnel_id":"{}","upload_hostname":"random.trycloudflare.com"}}"#,
                Uuid::new_v4()
            ),
        )
        .unwrap();
        assert_eq!(
            load_tunnel_settings(&quick_path).unwrap_err().code(),
            "tunnel_hostname_invalid"
        );

        let unknown_path = directory.join("unknown.json");
        fs::write(
            &unknown_path,
            r#"{"schema_version":1,"enabled":false,"quick_tunnel":true}"#,
        )
        .unwrap();
        assert_eq!(
            load_tunnel_settings(&unknown_path).unwrap_err().code(),
            "tunnel_configuration_invalid"
        );
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn exact_remote_upload_attestation_mints_an_origin_bound_capability() {
        let listener_origin = "http://127.0.0.1:49152";
        let capability = verify_boundary_value(
            &valid_remote_upload_boundary(listener_origin),
            "uploads.clinic.example",
            listener_origin,
        )
        .unwrap();

        assert!(capability.authorizes("uploads.clinic.example", listener_origin));
        assert!(!capability.authorizes("uploads.clinic.example", "http://127.0.0.1:49153"));
        assert!(!capability.authorizes("other.clinic.example", listener_origin));
    }

    #[test]
    fn incomplete_invalid_mismatched_and_stale_attestations_are_rejected() {
        let listener_origin = "http://127.0.0.1:49152";
        let valid = valid_remote_upload_boundary(listener_origin);
        let mut invalid = Vec::new();

        let mut missing = valid.clone();
        missing
            .as_object_mut()
            .unwrap()
            .remove("trusted_proxy_enforced");
        invalid.push(missing);

        let mut extra = valid.clone();
        extra
            .as_object_mut()
            .unwrap()
            .insert("unexpected".to_owned(), serde_json::json!(true));
        invalid.push(extra);

        for (field, value) in [
            ("schema_version", serde_json::json!(2)),
            ("schema_version", serde_json::json!("1")),
            ("status", serde_json::json!("starting")),
            ("hostname", serde_json::json!("Uploads.clinic.example")),
            (
                "listener_origin",
                serde_json::json!("http://127.0.0.1:49151"),
            ),
            ("route_set", serde_json::json!("public_upload_v2")),
        ] {
            let mut payload = valid.clone();
            payload
                .as_object_mut()
                .unwrap()
                .insert(field.to_owned(), value);
            invalid.push(payload);
        }

        for field in [
            "upload_routes_only",
            "exact_host_enforced",
            "trusted_proxy_enforced",
            "forwarded_https_enforced",
            "local_tokens_rejected_on_remote_host",
        ] {
            let mut payload = valid.clone();
            payload
                .as_object_mut()
                .unwrap()
                .insert(field.to_owned(), serde_json::json!(false));
            invalid.push(payload);
        }

        invalid.push(serde_json::Value::Null);
        for payload in invalid {
            assert!(
                verify_boundary_value(&payload, "uploads.clinic.example", listener_origin,)
                    .is_none()
            );
        }
        assert!(
            verify_boundary_value(&valid, "Uploads.clinic.example", listener_origin,).is_none()
        );
        assert!(
            verify_boundary_value(&valid, "uploads.clinic.example", "http://127.0.0.1:49153",)
                .is_none()
        );
        assert!(VerifiedRemoteUploadBoundary::from_health_response(
            br#"{"status":"healthy"}"#,
            "uploads.clinic.example",
            listener_origin,
        )
        .is_none());

        let valid_response = String::from_utf8(health_response_with_boundary(&valid)).unwrap();
        let duplicate_field = valid_response.replacen(
            r#""status":"ready""#,
            r#""status":"ready","status":"ready""#,
            1,
        );
        assert!(VerifiedRemoteUploadBoundary::from_health_response(
            duplicate_field.as_bytes(),
            "uploads.clinic.example",
            listener_origin,
        )
        .is_none());
    }

    #[test]
    fn loopback_origin_is_exact_and_fail_closed() {
        assert_eq!(
            LoopbackOrigin::parse("http://localhost:49152")
                .unwrap_err()
                .code(),
            "tunnel_origin_invalid"
        );
        assert_eq!(
            LoopbackOrigin::parse("https://127.0.0.1:49152")
                .unwrap_err()
                .code(),
            "tunnel_origin_invalid"
        );
        assert_eq!(
            LoopbackOrigin::parse("http://127.0.0.1:49152/admin")
                .unwrap_err()
                .code(),
            "tunnel_origin_invalid"
        );
        assert_eq!(
            LoopbackOrigin::parse("http://127.0.0.1:49152").unwrap().url,
            "http://127.0.0.1:49152"
        );
    }

    #[test]
    fn effective_remote_config_must_have_one_exact_origin_and_a_deny_fallback() {
        let valid: EffectiveTunnelConfiguration = serde_json::from_str(
            r#"{
                "ingress": [
                    {
                        "hostname": "uploads.clinic.example",
                        "service": "http://127.0.0.1:49152"
                    },
                    {"service": "http_status:404"}
                ],
                "warp-routing": {"enabled": false}
            }"#,
        )
        .unwrap();
        assert!(effective_configuration_matches(
            &valid,
            "uploads.clinic.example",
            "http://127.0.0.1:49152"
        ));
        assert!(!effective_configuration_matches(
            &valid,
            "uploads.clinic.example",
            "http://127.0.0.1:49153"
        ));

        let extra_route: EffectiveTunnelConfiguration = serde_json::from_str(
            r#"{
                "ingress": [
                    {"hostname":"uploads.clinic.example","service":"http://127.0.0.1:49152"},
                    {"hostname":"admin.clinic.example","service":"http://127.0.0.1:49152"},
                    {"service":"http_status:404"}
                ]
            }"#,
        )
        .unwrap();
        assert!(!effective_configuration_matches(
            &extra_route,
            "uploads.clinic.example",
            "http://127.0.0.1:49152"
        ));

        let warp_routing: EffectiveTunnelConfiguration = serde_json::from_str(
            r#"{
                "ingress": [
                    {"hostname":"uploads.clinic.example","service":"http://127.0.0.1:49152"},
                    {"service":"http_status:404"}
                ],
                "warp-routing":{"enabled":true}
            }"#,
        )
        .unwrap();
        assert!(!effective_configuration_matches(
            &warp_routing,
            "uploads.clinic.example",
            "http://127.0.0.1:49152"
        ));
    }

    #[test]
    fn command_uses_named_run_and_keeps_the_token_out_of_arguments() {
        let directory = temporary_directory();
        let executable = CloudflaredExecutable {
            path: directory.join("cloudflared"),
            version: "2026.8.0".to_owned(),
        };
        let configuration = test_configuration(&directory, executable, 0);
        let origin = LoopbackOrigin::parse("http://127.0.0.1:49152").unwrap();
        let command = build_cloudflared_command(&configuration, &origin, 49153);
        let arguments = command
            .get_args()
            .map(|argument| argument.to_string_lossy())
            .collect::<Vec<_>>()
            .join(" ");
        let environment = command
            .get_envs()
            .map(|(name, value)| {
                (
                    name.to_string_lossy().into_owned(),
                    value
                        .map(OsStr::to_string_lossy)
                        .map(|value| value.into_owned()),
                )
            })
            .collect::<std::collections::HashMap<_, _>>();

        assert!(arguments.contains("tunnel"));
        assert!(arguments.ends_with("run"));
        assert!(!arguments.contains("--url"));
        assert!(!arguments.contains(TEST_TOKEN));
        assert!(!arguments.contains("trycloudflare"));
        assert_eq!(
            environment.get("TUNNEL_TOKEN").and_then(Option::as_deref),
            Some(TEST_TOKEN)
        );
        assert_eq!(
            environment.get("TUNNEL_URL").and_then(Option::as_deref),
            Some("http://127.0.0.1:49152")
        );
        assert_eq!(environment.get("TUNNEL_TOKEN_FILE"), Some(&None));
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn executable_manifest_hash_and_reported_version_are_both_verified() {
        let directory = temporary_directory();
        let (executable, manifest) = write_executable(
            &directory,
            "#!/bin/sh\necho 'cloudflared version 2026.8.0 (built test)'\n",
        );
        let verified = verify_cloudflared_executable(&executable, &manifest).unwrap();
        assert_eq!(verified.version(), "2026.8.0");

        fs::write(
            &executable,
            "#!/bin/sh\necho 'cloudflared version 2026.8.0 (tampered)'\n",
        )
        .unwrap();
        assert_eq!(
            verify_cloudflared_executable(&executable, &manifest)
                .unwrap_err()
                .code(),
            "tunnel_executable_unverified"
        );
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn missing_or_stale_capability_keeps_an_enabled_tunnel_unavailable() {
        let directory = temporary_directory();
        let executable = CloudflaredExecutable {
            path: directory.join("must-not-run-cloudflared"),
            version: "2026.8.0".to_owned(),
        };
        let configuration = test_configuration(&directory, executable, 0);
        let supervisor = Arc::new(
            TunnelSupervisor::new_with_readiness_probe(
                configuration,
                &directory,
                test_logger(&directory),
                Arc::new(ImmediateReadiness),
            )
            .unwrap(),
        );
        assert_eq!(
            supervisor.required_attestation_hostname(),
            Some("uploads.clinic.example")
        );

        supervisor.deny_unverified_origin();
        let missing: TunnelSnapshot =
            serde_json::from_slice(&fs::read(directory.join("tunnel-state.json")).unwrap())
                .unwrap();
        assert_eq!(missing.phase, TunnelPhase::Unavailable);
        assert_eq!(
            missing.last_error_code.as_deref(),
            Some(ORIGIN_ATTESTATION_UNAVAILABLE)
        );

        supervisor.start_for_origin(
            "http://127.0.0.1:49152",
            verified_boundary("http://127.0.0.1:49153"),
        );
        let stale: TunnelSnapshot =
            serde_json::from_slice(&fs::read(directory.join("tunnel-state.json")).unwrap())
                .unwrap();
        assert_eq!(stale.phase, TunnelPhase::Unavailable);
        assert_eq!(
            stale.last_error_code.as_deref(),
            Some(ORIGIN_ATTESTATION_UNAVAILABLE)
        );
        assert!(supervisor.lifecycle.lock().unwrap().child.is_none());

        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn inactive_snapshot_contains_only_nonsecret_stable_state() {
        let directory = temporary_directory();
        let supervisor = TunnelSupervisor::unavailable(
            &directory,
            test_logger(&directory),
            Some("uploads.clinic.example".to_owned()),
            ORIGIN_ATTESTATION_UNAVAILABLE,
        )
        .unwrap()
        .with_authenticated_native_status(TEST_STATUS_KEY, TEST_INSTALLATION_ID, "2.1.0")
        .unwrap();
        let authenticated = read_authenticated_native_tunnel_status(
            &directory.join("tunnel-public-status.json"),
            TEST_STATUS_KEY,
        )
        .unwrap();
        assert_eq!(authenticated.phase, NativeTunnelPhase::Unavailable);
        assert_eq!(
            authenticated.installation_id.to_string(),
            TEST_INSTALLATION_ID
        );
        assert_eq!(authenticated.application_version, "2.1.0");
        drop(supervisor);
        let state = fs::read_to_string(directory.join("tunnel-state.json")).unwrap();

        assert!(state.contains(ORIGIN_ATTESTATION_UNAVAILABLE));
        assert!(state.contains("uploads.clinic.example"));
        assert!(!state.contains(TEST_TOKEN));
        assert!(!state.contains("command"));
        assert!(!state.contains("argument"));
        assert!(!state.contains(directory.to_string_lossy().as_ref()));
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn active_sidecar_stops_gracefully_and_never_receives_token_as_an_argument() {
        let directory = temporary_directory();
        let marker = directory.join("stopped.marker");
        let arguments = directory.join("arguments.txt");
        let script = format!(
            "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'cloudflared version 2026.8.0'; exit 0; fi\nprintf '%s\\n' \"$@\" > '{}'\ntrap 'printf stopped > \"{}\"; exit 0' TERM\nwhile :; do sleep 1; done\n",
            arguments.display(),
            marker.display()
        );
        let (executable_path, manifest_path) = write_executable(&directory, &script);
        let executable = verify_cloudflared_executable(&executable_path, &manifest_path).unwrap();
        let configuration = test_configuration(&directory, executable, 0);
        let supervisor = Arc::new(
            TunnelSupervisor::new_with_readiness_probe(
                configuration,
                &directory,
                test_logger(&directory),
                Arc::new(ImmediateReadiness),
            )
            .unwrap()
            .with_authenticated_native_status(TEST_STATUS_KEY, TEST_INSTALLATION_ID, "2.1.0")
            .unwrap(),
        );

        supervisor.start_for_origin(
            "http://127.0.0.1:49152",
            verified_boundary("http://127.0.0.1:49152"),
        );
        wait_for_snapshot(
            &directory.join("tunnel-state.json"),
            Duration::from_secs(3),
            |snapshot| snapshot.phase == TunnelPhase::Ready,
        );
        let ready = read_authenticated_native_tunnel_status(
            &directory.join("tunnel-public-status.json"),
            TEST_STATUS_KEY,
        )
        .unwrap();
        assert_eq!(ready.phase, NativeTunnelPhase::Ready);
        assert_eq!(
            ready.configured_hostname.as_deref(),
            Some("uploads.clinic.example")
        );
        assert_eq!(
            ready.listener_origin.as_deref(),
            Some("http://127.0.0.1:49152")
        );
        assert_eq!(ready.cloudflared_version.as_deref(), Some("2026.8.0"));
        assert!(ready.executable_verified);
        supervisor.shutdown();

        assert!(marker.exists());
        let arguments = fs::read_to_string(arguments).unwrap();
        assert!(arguments.lines().any(|argument| argument == "run"));
        assert!(!arguments.contains(TEST_TOKEN));
        assert!(!arguments.contains("trycloudflare"));
        let snapshot: TunnelSnapshot =
            serde_json::from_slice(&fs::read(directory.join("tunnel-state.json")).unwrap())
                .unwrap();
        assert_eq!(snapshot.phase, TunnelPhase::Stopped);
        let stopped = read_authenticated_native_tunnel_status(
            &directory.join("tunnel-public-status.json"),
            TEST_STATUS_KEY,
        )
        .unwrap();
        assert_eq!(stopped.phase, NativeTunnelPhase::Stopped);
        assert!(stopped.sequence > ready.sequence);
        let lifecycle = supervisor.lifecycle.lock().unwrap();
        assert!(lifecycle.child.is_none());
        assert!(lifecycle.worker.is_none());
        drop(lifecycle);
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn shutdown_joins_the_tunnel_worker_before_returning() {
        let directory = temporary_directory();
        let script = "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'cloudflared version 2026.8.0'; exit 0; fi\ntrap 'exit 0' TERM\nwhile :; do sleep 1; done\n";
        let (executable_path, manifest_path) = write_executable(&directory, script);
        let executable = verify_cloudflared_executable(&executable_path, &manifest_path).unwrap();
        let configuration = test_configuration(&directory, executable, 0);
        let entered = Arc::new(AtomicBool::new(false));
        let exited = Arc::new(AtomicBool::new(false));
        let supervisor = Arc::new(
            TunnelSupervisor::new_with_readiness_probe(
                configuration,
                &directory,
                test_logger(&directory),
                Arc::new(CancellationRecordingReadiness {
                    entered: Arc::clone(&entered),
                    exited: Arc::clone(&exited),
                }),
            )
            .unwrap(),
        );

        supervisor.start_for_origin(
            "http://127.0.0.1:49152",
            verified_boundary("http://127.0.0.1:49152"),
        );
        let deadline = Instant::now() + Duration::from_secs(3);
        while !entered.load(Ordering::SeqCst) && Instant::now() < deadline {
            thread::sleep(Duration::from_millis(5));
        }
        assert!(entered.load(Ordering::SeqCst));

        supervisor.shutdown();

        assert!(exited.load(Ordering::SeqCst));
        let lifecycle = supervisor.lifecycle.lock().unwrap();
        assert!(lifecycle.child.is_none());
        assert!(lifecycle.worker.is_none());
        drop(lifecycle);
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn exited_sidecar_has_a_bounded_retry_budget() {
        let directory = temporary_directory();
        let counter = directory.join("attempts.txt");
        let script = format!(
            "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'cloudflared version 2026.8.0'; exit 0; fi\ncount=0\nif [ -f '{}' ]; then count=$(head -n 1 '{}'); fi\ncount=$((count + 1))\nprintf '%s' \"$count\" > '{}'\nexit 7\n",
            counter.display(),
            counter.display(),
            counter.display()
        );
        let (executable_path, manifest_path) = write_executable(&directory, &script);
        let executable = verify_cloudflared_executable(&executable_path, &manifest_path).unwrap();
        let configuration = test_configuration(&directory, executable, 1);
        let supervisor = Arc::new(
            TunnelSupervisor::new_with_readiness_probe(
                configuration,
                &directory,
                test_logger(&directory),
                Arc::new(ImmediateReadiness),
            )
            .unwrap(),
        );

        supervisor.start_for_origin(
            "http://127.0.0.1:49152",
            verified_boundary("http://127.0.0.1:49152"),
        );
        let snapshot = wait_for_snapshot(
            &directory.join("tunnel-state.json"),
            Duration::from_secs(4),
            |snapshot| {
                snapshot.phase == TunnelPhase::Unavailable
                    && snapshot.last_error_code.as_deref() == Some("tunnel_retries_exhausted")
            },
        );

        assert_eq!(snapshot.retry_count, 1);
        assert_eq!(fs::read_to_string(counter).unwrap(), "2");
        supervisor.shutdown();
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }
}
