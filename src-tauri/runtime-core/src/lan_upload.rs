use std::{
    fmt,
    fs::{self, File},
    io::{self, Read, Write},
    net::{IpAddr, Ipv4Addr, Shutdown, SocketAddr, TcpListener, TcpStream},
    path::{Path, PathBuf},
    str::FromStr,
    sync::{
        atomic::{AtomicBool, AtomicU64, AtomicU8, Ordering},
        Arc, Mutex,
    },
    thread,
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use network_interface::{Addr, NetworkInterface, NetworkInterfaceConfig};
use reqwest::{
    blocking::{Body, Client},
    header::{HeaderMap, HeaderName, HeaderValue, CONTENT_LENGTH, HOST, SET_COOKIE},
    redirect::Policy,
    StatusCode,
};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use url::Url;

use crate::RuntimeLogger;

const SETTINGS_SCHEMA_VERSION: u8 = 1;
const ATTESTATION_SCHEMA_VERSION: u8 = 1;
const ROUTE_SET: &str = "public_upload_v1";
const MAX_SETTINGS_BYTES: u64 = 16 * 1024;
const MAX_REQUEST_HEAD_BYTES: usize = 32 * 1024;
const MAX_REQUEST_HEADERS: usize = 64;
const MAX_UPLOAD_REQUEST_BYTES: u64 = 128 * 1024 * 1024;
const MAX_FORM_REQUEST_BYTES: u64 = 64 * 1024;
const MAX_RESPONSE_BYTES: u64 = 16 * 1024 * 1024;
const MAX_READINESS_RESPONSE_BYTES: u64 = 8 * 1024;
const ACCEPT_POLL_INTERVAL: Duration = Duration::from_millis(25);
const REQUEST_HEAD_TIMEOUT: Duration = Duration::from_secs(10);
const REQUEST_BODY_TIMEOUT: Duration = Duration::from_secs(120);
const RESPONSE_WRITE_TIMEOUT: Duration = Duration::from_secs(30);
const REQUEST_HEAD_ABSOLUTE_TIMEOUT: Duration = Duration::from_secs(12);
const REQUEST_BODY_ABSOLUTE_TIMEOUT: Duration = Duration::from_secs(120);
const CONNECTION_ABSOLUTE_TIMEOUT: Duration = Duration::from_secs(135);
const BACKEND_TIMEOUT: Duration = Duration::from_secs(125);
const BACKEND_FORM_TIMEOUT: Duration = Duration::from_secs(15);
const READINESS_TIMEOUT: Duration = Duration::from_secs(5);
const READINESS_REQUEST_TIMEOUT: Duration = Duration::from_secs(1);
const LISTENER_RETRY_LIMIT: u8 = 2;
const LISTENER_RETRY_DELAY: Duration = Duration::from_millis(500);
const ADAPTER_RECONCILIATION_INTERVAL: Duration = Duration::from_secs(2);
const MAX_ADAPTER_INVENTORY_BYTES: usize = 64 * 1024;
const MAX_CONCURRENT_CONNECTIONS: usize = 4;
const LISTENER_STATUS_ACTIVATING: u8 = 2;

#[derive(Debug)]
pub struct LanListenerError {
    code: &'static str,
    detail: String,
}

impl LanListenerError {
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
            "lan_adapter_selection_required" => {
                "Sélectionnez une carte réseau privée active avant d’activer la réception locale."
            }
            "lan_adapter_unavailable" => {
                "La carte réseau sélectionnée n’a plus d’adresse IPv4 privée active."
            }
            "lan_port_invalid" => "Le port LAN doit être compris entre 1024 et 65535.",
            "lan_bind_failed" => {
                "Drclick n’a pas pu réserver cette adresse et ce port. Choisissez un autre port ou vérifiez la carte réseau."
            }
            "lan_configuration_invalid" => {
                "La configuration LAN native est invalide et le listener reste fermé."
            }
            "lan_configuration_write_unavailable" => {
                "La configuration LAN native ne peut pas être enregistrée sur cette installation."
            }
            "lan_adapter_discovery_failed" => {
                "Windows n’a pas permis d’inspecter les cartes réseau actives."
            }
            "lan_runtime_stopping" => "Le runtime local est en cours d’arrêt.",
            _ => "Le listener LAN reste fermé car sa configuration native n’a pas pu être vérifiée.",
        }
    }
}

impl fmt::Display for LanListenerError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}: {}", self.code, self.detail)
    }
}

impl std::error::Error for LanListenerError {}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct LanListenerSettings {
    schema_version: u8,
    enabled: bool,
    selected_adapter_id: Option<String>,
    preferred_port: Option<u16>,
    #[serde(default = "default_firewall_diagnostics_enabled")]
    firewall_diagnostics_enabled: bool,
}

impl LanListenerSettings {
    pub fn disabled_defaults() -> Self {
        Self {
            schema_version: SETTINGS_SCHEMA_VERSION,
            enabled: false,
            selected_adapter_id: None,
            preferred_port: None,
            firewall_diagnostics_enabled: true,
        }
    }

    pub fn enabled(&self) -> bool {
        self.enabled
    }

    fn validate(&self) -> Result<(), LanListenerError> {
        if self.schema_version != SETTINGS_SCHEMA_VERSION {
            return Err(LanListenerError::new(
                "lan_configuration_invalid",
                "unsupported LAN listener settings schema",
            ));
        }
        if self
            .selected_adapter_id
            .as_deref()
            .is_some_and(|adapter_id| !valid_stable_adapter_id(adapter_id))
        {
            return Err(LanListenerError::new(
                "lan_configuration_invalid",
                "the selected adapter identifier is not a stable native identifier",
            ));
        }
        if !self.enabled {
            return Ok(());
        }

        let adapter_id = self.selected_adapter_id.as_deref().unwrap_or_default();
        if adapter_id.is_empty() {
            return Err(LanListenerError::new(
                "lan_adapter_selection_required",
                "an exact native adapter identifier is required when LAN uploads are enabled",
            ));
        }
        if self.preferred_port.is_some_and(|port| port < 1024) {
            return Err(LanListenerError::new(
                "lan_port_invalid",
                "the preferred LAN port must be an explicit high port",
            ));
        }

        Ok(())
    }
}

fn default_firewall_diagnostics_enabled() -> bool {
    true
}

fn valid_stable_adapter_id(identifier: &str) -> bool {
    identifier
        .strip_prefix("adapter-v1:")
        .is_some_and(|digest| {
            digest.len() == 64
                && digest
                    .bytes()
                    .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
        })
}

pub fn load_lan_listener_settings(path: &Path) -> Result<LanListenerSettings, LanListenerError> {
    ensure_regular_file(path)?;
    let metadata = fs::metadata(path).map_err(|error| {
        LanListenerError::new(
            "lan_configuration_invalid",
            format!("read LAN settings metadata: {error}"),
        )
    })?;
    if metadata.len() == 0 || metadata.len() > MAX_SETTINGS_BYTES {
        return Err(LanListenerError::new(
            "lan_configuration_invalid",
            "LAN settings have an invalid size",
        ));
    }

    let settings =
        serde_json::from_slice::<LanListenerSettings>(&fs::read(path).map_err(|error| {
            LanListenerError::new(
                "lan_configuration_invalid",
                format!("read LAN settings: {error}"),
            )
        })?)
        .map_err(|error| {
            LanListenerError::new(
                "lan_configuration_invalid",
                format!("parse LAN settings: {error}"),
            )
        })?;
    settings.validate()?;
    Ok(settings)
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct LanAdapterCandidate {
    pub id: String,
    pub label: String,
    pub address: Ipv4Addr,
    pub index: u32,
}

pub fn discover_lan_adapter_candidates() -> Result<Vec<LanAdapterCandidate>, LanListenerError> {
    let interfaces = NetworkInterface::show().map_err(|error| {
        LanListenerError::new(
            "lan_adapter_discovery_failed",
            format!("enumerate native network adapters: {error}"),
        )
    })?;
    let mut candidates = Vec::new();

    for interface in interfaces {
        if interface.internal
            || invalid_adapter_identifier(&interface.name)
            || looks_like_tunnel_or_virtual_adapter(&interface.name)
        {
            continue;
        }
        let Some(adapter_id) = stable_adapter_id(interface.mac_addr.as_deref()) else {
            continue;
        };
        for address in interface.addr {
            let Addr::V4(address) = address else {
                continue;
            };
            if !is_private_non_loopback_ipv4(address.ip) {
                continue;
            }
            candidates.push(LanAdapterCandidate {
                id: adapter_id.clone(),
                label: interface.name.clone(),
                address: address.ip,
                index: interface.index,
            });
        }
    }

    candidates.sort_by(|left, right| {
        (&left.id, left.address.octets(), left.index).cmp(&(
            &right.id,
            right.address.octets(),
            right.index,
        ))
    });
    candidates.dedup_by(|left, right| left.id == right.id && left.address == right.address);
    Ok(candidates)
}

fn stable_adapter_id(mac_address: Option<&str>) -> Option<String> {
    let normalized = mac_address?
        .bytes()
        .filter(|byte| byte.is_ascii_hexdigit())
        .map(|byte| byte.to_ascii_lowercase())
        .collect::<Vec<_>>();
    if normalized.len() < 12
        || normalized.len() % 2 != 0
        || normalized.iter().all(|byte| *byte == b'0')
    {
        return None;
    }
    let mut digest = Sha256::new();
    digest.update(b"medismart-lan-adapter-v1\0");
    digest.update(&normalized);
    Some(format!("adapter-v1:{:x}", digest.finalize()))
}

fn invalid_adapter_identifier(identifier: &str) -> bool {
    identifier.is_empty()
        || identifier.len() > 255
        || identifier.trim() != identifier
        || identifier.chars().any(char::is_control)
}

fn looks_like_tunnel_or_virtual_adapter(identifier: &str) -> bool {
    let normalized = identifier.to_ascii_lowercase();
    [
        "loopback",
        "tunnel",
        "wireguard",
        "tailscale",
        "zerotier",
        "openvpn",
        "hyper-v",
        "vmware",
        "virtualbox",
        "vbox",
        "docker",
        "vethernet",
        "wsl",
    ]
    .iter()
    .any(|marker| normalized.contains(marker))
        || normalized == "tun"
        || normalized.starts_with("tun")
        || normalized == "tap"
        || normalized.starts_with("tap")
        || normalized == "wg"
        || normalized.starts_with("wg-")
}

#[derive(Debug)]
pub struct LanListenerConfiguration {
    adapter_id: String,
    bind_address: Ipv4Addr,
    preferred_port: Option<u16>,
}

impl LanListenerConfiguration {
    pub fn resolve(
        settings: &LanListenerSettings,
        candidates: &[LanAdapterCandidate],
    ) -> Result<Option<Self>, LanListenerError> {
        settings.validate()?;
        if !settings.enabled {
            return Ok(None);
        }

        let selected_id = settings.selected_adapter_id.as_deref().ok_or_else(|| {
            LanListenerError::new(
                "lan_adapter_selection_required",
                "an exact native adapter identifier is required",
            )
        })?;
        let mut matching = candidates
            .iter()
            .filter(|candidate| candidate.id == selected_id)
            .collect::<Vec<_>>();
        matching.sort_by_key(|candidate| candidate.address.octets());
        let selected = matching.first().ok_or_else(|| {
            LanListenerError::new(
                "lan_adapter_unavailable",
                "the selected adapter has no active private non-loopback IPv4 address",
            )
        })?;
        if !is_private_non_loopback_ipv4(selected.address) {
            return Err(LanListenerError::new(
                "lan_adapter_unavailable",
                "the selected adapter address is not private and non-loopback",
            ));
        }

        Ok(Some(Self {
            adapter_id: selected.id.clone(),
            bind_address: selected.address,
            preferred_port: settings.preferred_port,
        }))
    }

    #[cfg(test)]
    fn for_test(bind_address: Ipv4Addr, preferred_port: Option<u16>) -> Self {
        Self {
            adapter_id: "test-adapter".to_owned(),
            bind_address,
            preferred_port,
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum LanListenerStatus {
    Active = 1,
    Stopped = 0,
}

impl LanListenerStatus {
    pub fn as_env_value(self) -> &'static str {
        match self {
            Self::Active => "active",
            Self::Stopped => "stopped",
        }
    }

    fn from_u8(value: u8) -> Self {
        if value == Self::Active as u8 {
            Self::Active
        } else {
            Self::Stopped
        }
    }
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct LanUploadBoundaryAttestation {
    schema_version: u8,
    status: String,
    origin: String,
    route_set: String,
    upload_routes_only: bool,
    exact_origin_enforced: bool,
    explicit_high_port_enforced: bool,
    direct_private_peer_enforced: bool,
    forwarding_headers_rejected: bool,
    local_tokens_bound_to_lan_origin: bool,
}

#[derive(Debug, Deserialize)]
struct LanUploadBoundaryHealthEnvelope {
    lan_upload_boundary: LanUploadBoundaryAttestation,
}

/// Runtime-only proof minted from Laravel's authenticated loopback health response.
/// Its private fields prevent callers from manufacturing authority to expose PHP.
pub struct VerifiedLanUploadBoundary {
    origin: String,
}

impl fmt::Debug for VerifiedLanUploadBoundary {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter
            .debug_struct("VerifiedLanUploadBoundary")
            .finish_non_exhaustive()
    }
}

impl VerifiedLanUploadBoundary {
    pub(crate) fn from_health_response(body: &[u8], expected_origin: &str) -> Option<Self> {
        let origin = LanOrigin::parse(expected_origin).ok()?;
        if origin.url != expected_origin {
            return None;
        }
        let envelope = serde_json::from_slice::<LanUploadBoundaryHealthEnvelope>(body).ok()?;
        let attestation = envelope.lan_upload_boundary;
        if attestation.schema_version != ATTESTATION_SCHEMA_VERSION
            || attestation.status != "ready"
            || attestation.origin != expected_origin
            || attestation.route_set != ROUTE_SET
            || !attestation.upload_routes_only
            || !attestation.exact_origin_enforced
            || !attestation.explicit_high_port_enforced
            || !attestation.direct_private_peer_enforced
            || !attestation.forwarding_headers_rejected
            || !attestation.local_tokens_bound_to_lan_origin
        {
            return None;
        }
        Some(Self {
            origin: attestation.origin,
        })
    }

    fn authorizes(&self, origin: &str) -> bool {
        self.origin == origin
    }
}

#[derive(Clone)]
struct LanOrigin {
    url: String,
    authority: String,
    address: Ipv4Addr,
    port: u16,
}

impl LanOrigin {
    fn parse(value: &str) -> Result<Self, LanListenerError> {
        let url = Url::parse(value).map_err(|_| {
            LanListenerError::new("lan_origin_invalid", "LAN origin is not a valid URL")
        })?;
        if url.scheme() != "http"
            || !url.username().is_empty()
            || url.password().is_some()
            || url.query().is_some()
            || url.fragment().is_some()
            || url.path() != "/"
        {
            return Err(LanListenerError::new(
                "lan_origin_invalid",
                "LAN origin must be canonical plain HTTP with no path or credentials",
            ));
        }
        let address = url
            .host_str()
            .and_then(|host| Ipv4Addr::from_str(host).ok())
            .filter(|address| is_private_non_loopback_ipv4(*address))
            .ok_or_else(|| {
                LanListenerError::new(
                    "lan_origin_invalid",
                    "LAN origin must use a private non-loopback IPv4 address",
                )
            })?;
        let port = url.port().filter(|port| *port >= 1024).ok_or_else(|| {
            LanListenerError::new(
                "lan_origin_invalid",
                "LAN origin must contain an explicit high port",
            )
        })?;
        let canonical = format!("http://{address}:{port}");
        if canonical != value {
            return Err(LanListenerError::new(
                "lan_origin_invalid",
                "LAN origin is not canonical",
            ));
        }
        Ok(Self {
            url: canonical,
            authority: format!("{address}:{port}"),
            address,
            port,
        })
    }
}

#[derive(Clone)]
struct LoopbackOrigin {
    url: String,
}

impl LoopbackOrigin {
    fn parse(value: &str) -> Result<Self, LanListenerError> {
        let url = Url::parse(value).map_err(|_| {
            LanListenerError::new("lan_backend_invalid", "PHP origin is not a valid URL")
        })?;
        if url.scheme() != "http"
            || url.host_str() != Some("127.0.0.1")
            || url.port().is_none_or(|port| port < 1024)
            || !url.username().is_empty()
            || url.password().is_some()
            || url.query().is_some()
            || url.fragment().is_some()
            || url.path() != "/"
        {
            return Err(LanListenerError::new(
                "lan_backend_invalid",
                "PHP backend must be an exact loopback HTTP origin with an explicit high port",
            ));
        }
        let canonical = format!("http://127.0.0.1:{}", url.port().unwrap_or_default());
        if canonical != value {
            return Err(LanListenerError::new(
                "lan_backend_invalid",
                "PHP backend origin is not canonical",
            ));
        }
        Ok(Self { url: canonical })
    }
}

#[derive(Clone)]
enum LanMode {
    Inactive {
        code: &'static str,
    },
    Active {
        adapter_id: String,
        origin: LanOrigin,
    },
}

#[derive(Default)]
struct ListenerLifecycle {
    listener: Option<TcpListener>,
    worker: Option<thread::JoinHandle<()>>,
    connections: Vec<ConnectionWorker>,
    worker_stop: Option<Arc<AtomicBool>>,
}

struct ConnectionWorker {
    client: TcpStream,
    worker: thread::JoinHandle<()>,
}

#[derive(Clone, Debug, Serialize)]
pub struct LanProvisioningState {
    pub schema_version: u8,
    pub requested_enabled: bool,
    pub requested_adapter_id: Option<String>,
    pub requested_preferred_port: Option<u16>,
    pub diagnostics_requested: bool,
    pub phase: &'static str,
    pub verified: bool,
    pub verified_origin: Option<String>,
    pub verified_adapter_id: Option<String>,
    pub local_reachability: &'static str,
    pub firewall_assessment: &'static str,
    pub firewall_rules_modified: bool,
    pub error_code: Option<&'static str>,
    pub adapters: Vec<LanAdapterCandidate>,
}

pub struct LanUploadSupervisor {
    mode: Mutex<LanMode>,
    desired_settings: Mutex<LanListenerSettings>,
    configuration_path: Option<PathBuf>,
    logger: Arc<RuntimeLogger>,
    lifecycle: Mutex<ListenerLifecycle>,
    backend: Mutex<Option<LoopbackOrigin>>,
    transition: Mutex<()>,
    stopping: AtomicBool,
    status: AtomicU8,
    contract_generation: AtomicU64,
    last_reconciliation: Mutex<Instant>,
    phase: Mutex<&'static str>,
    last_error_code: Mutex<Option<&'static str>>,
    local_reachability: Mutex<&'static str>,
    snapshot_path: PathBuf,
    adapter_inventory_path: PathBuf,
    client: Client,
}

impl LanUploadSupervisor {
    pub fn disabled(
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
    ) -> Result<Self, LanListenerError> {
        Self::inactive(runtime_directory, logger, "lan_disabled")
    }

    pub fn unavailable(
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
        code: &'static str,
    ) -> Result<Self, LanListenerError> {
        Self::inactive(runtime_directory, logger, code)
    }

    fn inactive(
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
        code: &'static str,
    ) -> Result<Self, LanListenerError> {
        Self::inactive_with_configuration_path(runtime_directory, None, logger, code)
    }

    fn inactive_with_configuration_path(
        runtime_directory: &Path,
        configuration_path: Option<PathBuf>,
        logger: Arc<RuntimeLogger>,
        code: &'static str,
    ) -> Result<Self, LanListenerError> {
        fs::create_dir_all(runtime_directory).map_err(|error| {
            LanListenerError::new(
                "lan_runtime_io_failed",
                format!("create LAN runtime directory: {error}"),
            )
        })?;
        let supervisor = Self {
            mode: Mutex::new(LanMode::Inactive { code }),
            desired_settings: Mutex::new(LanListenerSettings::disabled_defaults()),
            configuration_path,
            logger,
            lifecycle: Mutex::new(ListenerLifecycle::default()),
            backend: Mutex::new(None),
            transition: Mutex::new(()),
            stopping: AtomicBool::new(false),
            status: AtomicU8::new(LanListenerStatus::Stopped as u8),
            contract_generation: AtomicU64::new(0),
            last_reconciliation: Mutex::new(Instant::now()),
            phase: Mutex::new("unavailable"),
            last_error_code: Mutex::new(Some(code)),
            local_reachability: Mutex::new("not_run"),
            snapshot_path: runtime_directory.join("lan-listener-state.json"),
            adapter_inventory_path: runtime_directory.join("lan-adapters.json"),
            client: build_backend_client()?,
        };
        supervisor.write_snapshot("unavailable", Some(code));
        Ok(supervisor)
    }

    pub fn managed(
        settings: LanListenerSettings,
        configuration_path: &Path,
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
    ) -> Result<Self, LanListenerError> {
        settings.validate()?;
        let supervisor = Self::inactive_with_configuration_path(
            runtime_directory,
            Some(configuration_path.to_path_buf()),
            logger,
            "lan_disabled",
        )?;
        if let Err(error) = supervisor.install_desired_configuration(settings) {
            supervisor.logger.warn(error.code());
        }
        Ok(supervisor)
    }

    pub fn recoverable_unavailable(
        configuration_path: &Path,
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
        code: &'static str,
    ) -> Result<Self, LanListenerError> {
        Self::inactive_with_configuration_path(
            runtime_directory,
            Some(configuration_path.to_path_buf()),
            logger,
            code,
        )
    }

    pub fn new(
        configuration: LanListenerConfiguration,
        runtime_directory: &Path,
        logger: Arc<RuntimeLogger>,
    ) -> Result<Self, LanListenerError> {
        fs::create_dir_all(runtime_directory).map_err(|error| {
            LanListenerError::new(
                "lan_runtime_io_failed",
                format!("create LAN runtime directory: {error}"),
            )
        })?;
        let requested_port = configuration.preferred_port.unwrap_or(0);
        let listener =
            TcpListener::bind((configuration.bind_address, requested_port)).map_err(|error| {
                LanListenerError::new(
                    "lan_bind_failed",
                    format!("bind the selected private adapter and managed port: {error}"),
                )
            })?;
        listener.set_nonblocking(true).map_err(|error| {
            LanListenerError::new(
                "lan_bind_failed",
                format!("configure the LAN listener: {error}"),
            )
        })?;
        let local = listener.local_addr().map_err(|error| {
            LanListenerError::new(
                "lan_bind_failed",
                format!("inspect the LAN listener address: {error}"),
            )
        })?;
        if local.ip() != IpAddr::V4(configuration.bind_address) || local.port() < 1024 {
            return Err(LanListenerError::new(
                "lan_bind_failed",
                "the socket did not bind the exact selected private IPv4 and a high port",
            ));
        }
        let origin = LanOrigin::parse(&format!(
            "http://{}:{}",
            configuration.bind_address,
            local.port()
        ))?;
        let selected_adapter_id = configuration.adapter_id.clone();
        let supervisor = Self {
            mode: Mutex::new(LanMode::Active {
                adapter_id: configuration.adapter_id,
                origin,
            }),
            desired_settings: Mutex::new(LanListenerSettings {
                schema_version: SETTINGS_SCHEMA_VERSION,
                enabled: true,
                selected_adapter_id: Some(selected_adapter_id),
                preferred_port: configuration.preferred_port,
                firewall_diagnostics_enabled: true,
            }),
            configuration_path: None,
            logger,
            lifecycle: Mutex::new(ListenerLifecycle {
                listener: Some(listener),
                worker: None,
                connections: Vec::new(),
                worker_stop: None,
            }),
            backend: Mutex::new(None),
            transition: Mutex::new(()),
            stopping: AtomicBool::new(false),
            status: AtomicU8::new(LanListenerStatus::Stopped as u8),
            contract_generation: AtomicU64::new(0),
            last_reconciliation: Mutex::new(Instant::now()),
            phase: Mutex::new("stopped"),
            last_error_code: Mutex::new(Some("lan_boundary_not_attested")),
            local_reachability: Mutex::new("not_run"),
            snapshot_path: runtime_directory.join("lan-listener-state.json"),
            adapter_inventory_path: runtime_directory.join("lan-adapters.json"),
            client: build_backend_client()?,
        };
        supervisor.write_snapshot("stopped", Some("lan_boundary_not_attested"));
        Ok(supervisor)
    }

    pub fn required_attestation_origin(&self) -> Option<String> {
        self.mode.lock().ok().and_then(|mode| match &*mode {
            LanMode::Active { origin, .. } => Some(origin.url.clone()),
            LanMode::Inactive { .. } => None,
        })
    }

    pub fn status_for_php(&self) -> LanListenerStatus {
        LanListenerStatus::from_u8(self.status.load(Ordering::SeqCst))
    }

    pub fn contract_generation(&self) -> u64 {
        self.contract_generation.load(Ordering::SeqCst)
    }

    pub fn adapter_inventory_path(&self) -> &Path {
        &self.adapter_inventory_path
    }

    pub fn activate_for_backend(
        self: &Arc<Self>,
        backend_origin: &str,
        verified_boundary: VerifiedLanUploadBoundary,
    ) {
        let _transition = self
            .transition
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        if self.stopping.load(Ordering::SeqCst) {
            return;
        }
        let backend = match LoopbackOrigin::parse(backend_origin) {
            Ok(backend) => backend,
            Err(error) => {
                self.record_fail_closed(error.code());
                return;
            }
        };
        let Some(origin) = self.active_origin() else {
            self.write_inactive_snapshot();
            return;
        };
        if !verified_boundary.authorizes(&origin.url) {
            self.record_fail_closed("lan_origin_attestation_unavailable");
            return;
        }
        self.status
            .store(LISTENER_STATUS_ACTIVATING, Ordering::SeqCst);
        if self.ensure_worker_started().is_err() {
            self.record_fail_closed("lan_listener_start_failed");
            return;
        }
        if let Ok(mut current) = self.backend.lock() {
            *current = Some(backend);
        } else {
            self.record_fail_closed("lan_runtime_io_failed");
            return;
        }
        if self.status.load(Ordering::SeqCst) != LISTENER_STATUS_ACTIVATING {
            if let Ok(mut current) = self.backend.lock() {
                current.take();
            }
            return;
        }
        self.write_snapshot("starting", None);
        if self.probe_readiness(&origin).is_err() {
            self.record_fail_closed("lan_readiness_failed");
            return;
        }

        if !self.commit_activation() {
            if let Ok(mut current) = self.backend.lock() {
                current.take();
            }
            return;
        }
        self.update_runtime_observation("active", None, "passed");
        self.write_snapshot("active", None);
        self.logger
            .info("Dedicated LAN upload listener passed its restricted health probe");
    }

    fn commit_activation(&self) -> bool {
        self.status
            .compare_exchange(
                LISTENER_STATUS_ACTIVATING,
                LanListenerStatus::Active as u8,
                Ordering::SeqCst,
                Ordering::SeqCst,
            )
            .is_ok()
    }

    pub fn suspend_backend(&self) {
        let _transition = self
            .transition
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        if let Ok(mut backend) = self.backend.lock() {
            backend.take();
        }
        if self.status_for_php() == LanListenerStatus::Active {
            self.write_snapshot("starting", Some("laravel_backend_refreshing"));
        }
    }

    pub fn deny_unverified_origin(&self) {
        self.fail_closed("lan_origin_attestation_unavailable");
    }

    fn fail_closed(&self, code: &'static str) {
        let _transition = self
            .transition
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        self.record_fail_closed(code);
    }

    /// Publishes a fail-closed observation without acquiring `transition`.
    /// The listener worker must use this path because transition holders join
    /// that worker during reconfiguration and shutdown. Activation commits
    /// with an atomic ACTIVATING -> Active compare-exchange so this worker-safe
    /// publication cannot be overwritten by a stale readiness result.
    fn record_fail_closed(&self, code: &'static str) {
        if let Ok(mut backend) = self.backend.lock() {
            backend.take();
        }
        self.status
            .store(LanListenerStatus::Stopped as u8, Ordering::SeqCst);
        self.update_runtime_observation("unavailable", Some(code), "failed");
        self.write_snapshot("unavailable", Some(code));
        self.logger.warn(code);
    }

    fn ensure_worker_started(self: &Arc<Self>) -> Result<(), LanListenerError> {
        let mut lifecycle = self.lifecycle.lock().map_err(|_| {
            LanListenerError::new("lan_runtime_io_failed", "LAN lifecycle lock is poisoned")
        })?;
        if lifecycle
            .worker
            .as_ref()
            .is_some_and(|worker| worker.is_finished())
        {
            if let Some(worker) = lifecycle.worker.take() {
                let _ = worker.join();
            }
            lifecycle.worker_stop.take();
            return Err(LanListenerError::new(
                "lan_listener_retries_exhausted",
                "LAN listener worker exhausted its bounded restart attempts",
            ));
        }
        if lifecycle.worker.is_some() {
            return Ok(());
        }
        let origin = self.active_origin().ok_or_else(|| {
            LanListenerError::new(
                "lan_listener_start_failed",
                "the configured LAN origin is unavailable",
            )
        })?;
        let listener = lifecycle.listener.take().ok_or_else(|| {
            LanListenerError::new(
                "lan_listener_start_failed",
                "reserved listener is unavailable",
            )
        })?;
        let worker_stop = Arc::new(AtomicBool::new(false));
        let supervisor = Arc::clone(self);
        let worker_stop_for_thread = Arc::clone(&worker_stop);
        let worker = thread::Builder::new()
            .name("medismart-lan-upload".to_owned())
            .spawn(move || supervisor.run_listener(listener, origin, worker_stop_for_thread))
            .map_err(|error| {
                LanListenerError::new(
                    "lan_listener_start_failed",
                    format!("spawn LAN listener worker: {error}"),
                )
            })?;
        lifecycle.worker = Some(worker);
        lifecycle.worker_stop = Some(worker_stop);
        Ok(())
    }

    fn run_listener(
        self: Arc<Self>,
        listener: TcpListener,
        origin: LanOrigin,
        worker_stop: Arc<AtomicBool>,
    ) {
        let mut listener = Some(listener);
        let mut retry_count = 0_u8;
        while !self.stopping.load(Ordering::SeqCst) && !worker_stop.load(Ordering::SeqCst) {
            self.reap_finished_connections();
            let Some(active_listener) = listener.as_ref() else {
                break;
            };
            match active_listener.accept() {
                Ok((stream, peer)) => {
                    if !peer_is_private_ipv4(peer) {
                        let mut stream = stream;
                        let _ = write_simple_response(&mut stream, 404, b"");
                        continue;
                    }
                    if self.active_connection_count() >= MAX_CONCURRENT_CONNECTIONS {
                        let mut stream = stream;
                        let _ = stream.set_write_timeout(Some(Duration::from_secs(1)));
                        let _ = write_simple_response(&mut stream, 503, b"");
                        continue;
                    }
                    if let Err(error) = self.spawn_connection_worker(stream, origin.clone()) {
                        self.logger.warn(error.code());
                    }
                }
                Err(error) if error.kind() == io::ErrorKind::WouldBlock => {
                    thread::sleep(ACCEPT_POLL_INTERVAL);
                }
                Err(error) => {
                    self.logger
                        .warn(&format!("LAN listener accept failed: {error}"));
                    self.record_fail_closed("lan_listener_accept_failed");
                    // Release the failed socket before attempting to reserve
                    // the same exact adapter and port again.
                    listener.take();
                    while retry_count < LISTENER_RETRY_LIMIT
                        && !self.stopping.load(Ordering::SeqCst)
                        && !worker_stop.load(Ordering::SeqCst)
                    {
                        retry_count += 1;
                        if !self.wait_for_listener_retry(retry_count, &worker_stop) {
                            break;
                        }
                        match TcpListener::bind((origin.address, origin.port)).and_then(
                            |replacement| {
                                replacement.set_nonblocking(true)?;
                                Ok(replacement)
                            },
                        ) {
                            Ok(replacement) => {
                                listener = Some(replacement);
                                self.write_snapshot("starting", Some("lan_listener_restarted"));
                                break;
                            }
                            Err(error) => {
                                self.logger.warn(&format!(
                                    "LAN listener restart attempt {retry_count} failed: {error}"
                                ));
                            }
                        }
                    }
                    if listener.is_none() {
                        self.write_snapshot("failed", Some("lan_listener_retries_exhausted"));
                        break;
                    }
                }
            }
        }
        self.reap_finished_connections();
    }

    fn active_connection_count(&self) -> usize {
        self.lifecycle
            .lock()
            .map_or(MAX_CONCURRENT_CONNECTIONS, |lifecycle| {
                lifecycle.connections.len()
            })
    }

    fn spawn_connection_worker(
        self: &Arc<Self>,
        stream: TcpStream,
        origin: LanOrigin,
    ) -> Result<(), LanListenerError> {
        let client = stream.try_clone().map_err(http_io_error)?;
        let supervisor = Arc::clone(self);
        let worker = thread::Builder::new()
            .name("medismart-lan-connection".to_owned())
            .spawn(move || {
                if let Err(error) = supervisor.handle_connection(stream, &origin) {
                    supervisor.logger.warn(error.code());
                }
            })
            .map_err(|error| {
                let _ = client.shutdown(Shutdown::Both);
                LanListenerError::new(
                    "lan_listener_start_failed",
                    format!("spawn bounded LAN connection worker: {error}"),
                )
            })?;
        match self.lifecycle.lock() {
            Ok(mut lifecycle) if lifecycle.connections.len() < MAX_CONCURRENT_CONNECTIONS => {
                lifecycle
                    .connections
                    .push(ConnectionWorker { client, worker });
                Ok(())
            }
            _ => {
                let _ = client.shutdown(Shutdown::Both);
                let _ = worker.join();
                Err(LanListenerError::new(
                    "lan_listener_saturated",
                    "the bounded LAN connection pool is full",
                ))
            }
        }
    }

    fn reap_finished_connections(&self) {
        let finished = self
            .lifecycle
            .lock()
            .map(|mut lifecycle| {
                let mut finished = Vec::new();
                let mut index = 0;
                while index < lifecycle.connections.len() {
                    if lifecycle.connections[index].worker.is_finished() {
                        finished.push(lifecycle.connections.swap_remove(index));
                    } else {
                        index += 1;
                    }
                }
                finished
            })
            .unwrap_or_default();
        for connection in finished {
            let _ = connection.worker.join();
        }
    }

    fn active_origin(&self) -> Option<LanOrigin> {
        self.mode.lock().ok().and_then(|mode| match &*mode {
            LanMode::Active { origin, .. } => Some(origin.clone()),
            LanMode::Inactive { .. } => None,
        })
    }

    fn wait_for_listener_retry(&self, retry_count: u8, worker_stop: &AtomicBool) -> bool {
        let delay = LISTENER_RETRY_DELAY.saturating_mul(u32::from(retry_count));
        let deadline = Instant::now() + delay;
        while Instant::now() < deadline {
            if self.stopping.load(Ordering::SeqCst) || worker_stop.load(Ordering::SeqCst) {
                return false;
            }
            thread::sleep(Duration::from_millis(25));
        }
        true
    }

    fn handle_connection(
        &self,
        mut stream: TcpStream,
        origin: &LanOrigin,
    ) -> Result<(), LanListenerError> {
        let connection_deadline = Instant::now() + CONNECTION_ABSOLUTE_TIMEOUT;
        stream
            .set_read_timeout(Some(REQUEST_HEAD_TIMEOUT))
            .map_err(http_io_error)?;
        stream
            .set_write_timeout(Some(RESPONSE_WRITE_TIMEOUT))
            .map_err(http_io_error)?;
        if self
            .active_origin()
            .is_none_or(|current| current.url != origin.url)
        {
            write_simple_response(&mut stream, 503, b"")?;
            return Ok(());
        }
        let backend = self.backend.lock().ok().and_then(|backend| backend.clone());
        let Some(backend) = backend else {
            write_simple_response(&mut stream, 503, b"")?;
            return Ok(());
        };
        let head_deadline = std::cmp::min(
            Instant::now() + REQUEST_HEAD_ABSOLUTE_TIMEOUT,
            connection_deadline,
        );
        let request = match parse_request(&mut stream, origin, head_deadline) {
            Ok(request) => request,
            Err(rejection) => {
                write_simple_response(&mut stream, rejection.status, b"")?;
                return Ok(());
            }
        };
        stream
            .set_read_timeout(Some(REQUEST_BODY_TIMEOUT))
            .map_err(http_io_error)?;
        let body_deadline = std::cmp::min(
            Instant::now() + REQUEST_BODY_ABSOLUTE_TIMEOUT,
            connection_deadline,
        );
        self.proxy_request(
            stream,
            request,
            origin,
            &backend,
            body_deadline,
            connection_deadline,
        )
    }

    fn proxy_request(
        &self,
        mut downstream: TcpStream,
        request: ParsedRequest,
        origin: &LanOrigin,
        backend: &LoopbackOrigin,
        body_deadline: Instant,
        connection_deadline: Instant,
    ) -> Result<(), LanListenerError> {
        let url = format!("{}{}", backend.url, request.path);
        let method = reqwest::Method::from_bytes(request.method.as_bytes())
            .map_err(|_| LanListenerError::new("lan_request_rejected", "invalid request method"))?;
        let mut headers = request.forwarded_headers;
        headers.insert(
            HOST,
            HeaderValue::from_str(&origin.authority).map_err(|_| {
                LanListenerError::new("lan_runtime_io_failed", "invalid configured LAN authority")
            })?,
        );
        headers.insert(
            HeaderName::from_static("connection"),
            HeaderValue::from_static("close"),
        );

        let backend_timeout = if request.method == "POST" && request.path.ends_with("/files") {
            BACKEND_TIMEOUT
        } else {
            BACKEND_FORM_TIMEOUT
        };
        let remaining = connection_deadline.saturating_duration_since(Instant::now());
        if remaining.is_zero() {
            write_simple_response(&mut downstream, 408, b"")?;
            return Ok(());
        }
        let mut builder = self
            .client
            .request(method, url)
            .headers(headers)
            .timeout(std::cmp::min(backend_timeout, remaining));
        if request.content_length > 0 {
            let body_stream = downstream.try_clone().map_err(http_io_error)?;
            let body = FixedBodyReader::new(
                request.body_prefix,
                body_stream,
                request.content_length,
                body_deadline,
            )?;
            builder = builder.body(Body::new(body));
        }
        let mut response = match builder.send() {
            Ok(response) => response,
            Err(_) => {
                write_simple_response(&mut downstream, 502, b"")?;
                return Ok(());
            }
        };

        if !response_location_allowed(&response, origin) {
            write_simple_response(&mut downstream, 502, b"")?;
            return Ok(());
        }
        if response
            .content_length()
            .is_some_and(|length| length > MAX_RESPONSE_BYTES)
        {
            write_simple_response(&mut downstream, 502, b"")?;
            return Ok(());
        }
        let mut response_body = Vec::new();
        response
            .by_ref()
            .take(MAX_RESPONSE_BYTES + 1)
            .read_to_end(&mut response_body)
            .map_err(http_io_error)?;
        if response_body.len() as u64 > MAX_RESPONSE_BYTES {
            write_simple_response(&mut downstream, 502, b"")?;
            return Ok(());
        }
        write_backend_response_head(
            &mut downstream,
            &response,
            response_body.len(),
            connection_deadline,
        )?;
        write_all_until(&mut downstream, &response_body, connection_deadline)?;
        downstream.flush().map_err(http_io_error)?;
        Ok(())
    }

    fn probe_readiness(&self, origin: &LanOrigin) -> Result<(), LanListenerError> {
        let deadline = Instant::now() + READINESS_TIMEOUT;
        let url = format!("{}/health", origin.url);
        let client = Client::builder()
            .connect_timeout(READINESS_REQUEST_TIMEOUT)
            .timeout(READINESS_REQUEST_TIMEOUT)
            .redirect(Policy::none())
            .no_proxy()
            .build()
            .map_err(|error| {
                LanListenerError::new(
                    "lan_runtime_io_failed",
                    format!("build LAN readiness client: {error}"),
                )
            })?;
        while Instant::now() < deadline {
            let response = client.get(&url).send();
            if let Ok(mut response) = response {
                if response.status() == StatusCode::OK {
                    let mut body = Vec::with_capacity(256);
                    if response
                        .by_ref()
                        .take(MAX_READINESS_RESPONSE_BYTES + 1)
                        .read_to_end(&mut body)
                        .is_ok()
                        && body.len() <= MAX_READINESS_RESPONSE_BYTES as usize
                        && serde_json::from_slice::<PublicHealth>(&body)
                            .is_ok_and(|health| health.status == "healthy")
                    {
                        return Ok(());
                    }
                }
            }
            thread::sleep(Duration::from_millis(100));
        }
        Err(LanListenerError::new(
            "lan_readiness_failed",
            "restricted LAN health probe did not pass",
        ))
    }

    pub fn apply_configuration(
        &self,
        settings: LanListenerSettings,
    ) -> Result<LanProvisioningState, LanListenerError> {
        settings.validate()?;
        {
            let _transition = self.transition.lock().map_err(|_| {
                LanListenerError::new("lan_runtime_io_failed", "LAN transition lock is poisoned")
            })?;
            if self.stopping.load(Ordering::SeqCst) {
                return Err(LanListenerError::new(
                    "lan_runtime_stopping",
                    "the LAN runtime is stopping",
                ));
            }
            self.persist_settings(&settings)?;
            self.install_desired_configuration_locked(settings)?;
        }
        Ok(self.provisioning_state())
    }

    pub fn reconcile_network_if_due(&self) {
        if self.stopping.load(Ordering::SeqCst) {
            return;
        }
        let should_reconcile = self
            .last_reconciliation
            .lock()
            .map(|mut last| {
                if last.elapsed() < ADAPTER_RECONCILIATION_INTERVAL {
                    false
                } else {
                    *last = Instant::now();
                    true
                }
            })
            .unwrap_or(false);
        if !should_reconcile {
            return;
        }
        let result = self
            .transition
            .lock()
            .map_err(|_| {
                LanListenerError::new("lan_runtime_io_failed", "LAN transition lock is poisoned")
            })
            .and_then(|_transition| {
                if self.stopping.load(Ordering::SeqCst) {
                    return Ok(());
                }
                let settings = {
                    let desired = self.desired_settings.lock().map_err(|_| {
                        LanListenerError::new(
                            "lan_runtime_io_failed",
                            "LAN desired-settings lock is poisoned",
                        )
                    })?;
                    desired.clone()
                };
                self.install_desired_configuration_locked(settings)
            });
        if let Err(error) = result {
            self.logger.warn(error.code());
        }
    }

    pub fn provisioning_state(&self) -> LanProvisioningState {
        let settings = self
            .desired_settings
            .lock()
            .map(|settings| settings.clone())
            .unwrap_or_else(|_| LanListenerSettings::disabled_defaults());
        let (reserved_origin, reserved_adapter_id) = self
            .mode
            .lock()
            .map(|mode| match &*mode {
                LanMode::Active { adapter_id, origin } => {
                    (Some(origin.url.clone()), Some(adapter_id.clone()))
                }
                LanMode::Inactive { .. } => (None, None),
            })
            .unwrap_or((None, None));
        let phase = self.phase.lock().map_or("unavailable", |phase| *phase);
        let error_code = self
            .last_error_code
            .lock()
            .map_or(Some("lan_runtime_io_failed"), |error_code| *error_code);
        let local_reachability = self
            .local_reachability
            .lock()
            .map_or("failed", |state| *state);
        let adapters = discover_lan_adapter_candidates().unwrap_or_default();

        let verified = self.status_for_php() == LanListenerStatus::Active;
        let (verified_origin, verified_adapter_id) = if verified {
            (reserved_origin, reserved_adapter_id)
        } else {
            (None, None)
        };
        LanProvisioningState {
            schema_version: SETTINGS_SCHEMA_VERSION,
            requested_enabled: settings.enabled,
            requested_adapter_id: settings.selected_adapter_id,
            requested_preferred_port: settings.preferred_port,
            diagnostics_requested: settings.firewall_diagnostics_enabled,
            phase,
            verified,
            verified_origin,
            verified_adapter_id,
            local_reachability,
            firewall_assessment: "not_determined",
            firewall_rules_modified: false,
            error_code,
            adapters,
        }
    }

    fn install_desired_configuration(
        &self,
        settings: LanListenerSettings,
    ) -> Result<(), LanListenerError> {
        let _transition = self.transition.lock().map_err(|_| {
            LanListenerError::new("lan_runtime_io_failed", "LAN transition lock is poisoned")
        })?;
        self.install_desired_configuration_locked(settings)
    }

    fn install_desired_configuration_locked(
        &self,
        settings: LanListenerSettings,
    ) -> Result<(), LanListenerError> {
        settings.validate()?;
        if self.stopping.load(Ordering::SeqCst) {
            return Err(LanListenerError::new(
                "lan_runtime_stopping",
                "the LAN runtime is stopping",
            ));
        }

        if let Ok(mut desired) = self.desired_settings.lock() {
            *desired = settings.clone();
        } else {
            return Err(LanListenerError::new(
                "lan_runtime_io_failed",
                "LAN desired-settings lock is poisoned",
            ));
        }

        let candidates = match discover_lan_adapter_candidates() {
            Ok(candidates) => candidates,
            Err(error) => {
                self.enter_unavailable_locked(error.code());
                return Err(error);
            }
        };
        if let Err(error) = write_adapter_inventory(&self.adapter_inventory_path, &candidates) {
            self.logger.warn(error.code());
        }

        let configuration = match LanListenerConfiguration::resolve(&settings, &candidates) {
            Ok(configuration) => configuration,
            Err(error) => {
                self.enter_unavailable_locked(error.code());
                return Err(error);
            }
        };
        let Some(configuration) = configuration else {
            let changed = !self.mode_is_inactive("lan_disabled")
                || self.status_for_php() != LanListenerStatus::Stopped;
            self.stop_listener_locked();
            if let Ok(mut mode) = self.mode.lock() {
                *mode = LanMode::Inactive {
                    code: "lan_disabled",
                };
            }
            self.update_runtime_observation("disabled", None, "not_run");
            if changed {
                self.contract_generation.fetch_add(1, Ordering::SeqCst);
            }
            self.write_snapshot("disabled", None);
            return Ok(());
        };

        if self.configuration_matches(&configuration) {
            return Ok(());
        }

        self.stop_listener_locked();
        match reserve_listener(&configuration) {
            Ok((listener, origin)) => {
                if let Ok(mut lifecycle) = self.lifecycle.lock() {
                    lifecycle.listener = Some(listener);
                } else {
                    self.enter_unavailable_locked("lan_runtime_io_failed");
                    return Err(LanListenerError::new(
                        "lan_runtime_io_failed",
                        "LAN lifecycle lock is poisoned",
                    ));
                }
                if let Ok(mut mode) = self.mode.lock() {
                    *mode = LanMode::Active {
                        adapter_id: configuration.adapter_id,
                        origin,
                    };
                } else {
                    self.enter_unavailable_locked("lan_runtime_io_failed");
                    return Err(LanListenerError::new(
                        "lan_runtime_io_failed",
                        "LAN mode lock is poisoned",
                    ));
                }
                self.update_runtime_observation(
                    "pending_attestation",
                    Some("lan_boundary_not_attested"),
                    "pending",
                );
                self.contract_generation.fetch_add(1, Ordering::SeqCst);
                self.write_snapshot("stopped", Some("lan_boundary_not_attested"));
                Ok(())
            }
            Err(error) => {
                self.enter_unavailable_locked(error.code());
                Err(error)
            }
        }
    }

    fn configuration_matches(&self, configuration: &LanListenerConfiguration) -> bool {
        let listener_available = self.lifecycle.lock().is_ok_and(|lifecycle| {
            lifecycle.listener.is_some()
                || lifecycle
                    .worker
                    .as_ref()
                    .is_some_and(|worker| !worker.is_finished())
        });
        listener_available
            && self.mode.lock().is_ok_and(|mode| match &*mode {
                LanMode::Active { adapter_id, origin } => {
                    adapter_id == &configuration.adapter_id
                        && origin.address == configuration.bind_address
                        && configuration
                            .preferred_port
                            .is_none_or(|preferred| preferred == origin.port)
                }
                LanMode::Inactive { .. } => false,
            })
    }

    fn mode_is_inactive(&self, expected_code: &'static str) -> bool {
        self.mode.lock().is_ok_and(
            |mode| matches!(&*mode, LanMode::Inactive { code } if *code == expected_code),
        )
    }

    fn enter_unavailable_locked(&self, code: &'static str) {
        let changed =
            !self.mode_is_inactive(code) || self.status_for_php() != LanListenerStatus::Stopped;
        self.stop_listener_locked();
        if let Ok(mut mode) = self.mode.lock() {
            *mode = LanMode::Inactive { code };
        }
        self.update_runtime_observation("unavailable", Some(code), "failed");
        if changed {
            self.contract_generation.fetch_add(1, Ordering::SeqCst);
        }
        self.write_snapshot("unavailable", Some(code));
    }

    fn stop_listener_locked(&self) {
        self.status
            .store(LanListenerStatus::Stopped as u8, Ordering::SeqCst);
        if let Ok(mut backend) = self.backend.lock() {
            backend.take();
        }
        let (reserved_listener, worker, worker_stop) = self
            .lifecycle
            .lock()
            .map(|mut lifecycle| {
                (
                    lifecycle.listener.take(),
                    lifecycle.worker.take(),
                    lifecycle.worker_stop.take(),
                )
            })
            .unwrap_or((None, None, None));
        if let Some(stop) = worker_stop {
            stop.store(true, Ordering::SeqCst);
        }
        drop(reserved_listener);
        if let Some(worker) = worker {
            let _ = worker.join();
        }
        let connections = self
            .lifecycle
            .lock()
            .map(|mut lifecycle| std::mem::take(&mut lifecycle.connections))
            .unwrap_or_default();
        for connection in &connections {
            let _ = connection.client.shutdown(Shutdown::Both);
        }
        for connection in connections {
            let _ = connection.worker.join();
        }
    }

    fn update_runtime_observation(
        &self,
        phase: &'static str,
        error_code: Option<&'static str>,
        local_reachability: &'static str,
    ) {
        if let Ok(mut current) = self.phase.lock() {
            *current = phase;
        }
        if let Ok(mut current) = self.last_error_code.lock() {
            *current = error_code;
        }
        if let Ok(mut current) = self.local_reachability.lock() {
            *current = local_reachability;
        }
    }

    fn persist_settings(&self, settings: &LanListenerSettings) -> Result<(), LanListenerError> {
        let path = self.configuration_path.as_deref().ok_or_else(|| {
            LanListenerError::new(
                "lan_configuration_write_unavailable",
                "the managed LAN settings path is unavailable",
            )
        })?;
        write_settings_atomically(path, settings)
    }

    pub fn shutdown(&self) {
        if self.stopping.swap(true, Ordering::SeqCst) {
            return;
        }
        if let Ok(_transition) = self.transition.lock() {
            self.stop_listener_locked();
        }
        self.update_runtime_observation("stopped", Some("lan_stopped"), "not_run");
        self.write_snapshot("stopped", Some("lan_stopped"));
    }

    fn write_inactive_snapshot(&self) {
        if let Some(code) = self.mode.lock().ok().and_then(|mode| match &*mode {
            LanMode::Inactive { code } => Some(*code),
            LanMode::Active { .. } => None,
        }) {
            self.write_snapshot("unavailable", Some(code));
        }
    }

    fn write_snapshot(&self, phase: &'static str, error_code: Option<&'static str>) {
        let (origin, adapter_id) = self
            .mode
            .lock()
            .map(|mode| match &*mode {
                LanMode::Active { origin, adapter_id } => {
                    (Some(origin.url.clone()), Some(adapter_id.clone()))
                }
                LanMode::Inactive { .. } => (None, None),
            })
            .unwrap_or((None, None));
        let snapshot = LanListenerSnapshot {
            schema_version: 1,
            phase,
            origin,
            adapter_id,
            error_code,
            updated_at_unix: SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .map_or(0, |duration| duration.as_secs()),
        };
        let Ok(bytes) = serde_json::to_vec_pretty(&snapshot) else {
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

impl Drop for LanUploadSupervisor {
    fn drop(&mut self) {
        self.shutdown();
    }
}

#[derive(Serialize)]
struct LanListenerSnapshot {
    schema_version: u8,
    phase: &'static str,
    origin: Option<String>,
    adapter_id: Option<String>,
    error_code: Option<&'static str>,
    updated_at_unix: u64,
}

#[derive(Deserialize)]
struct PublicHealth {
    status: String,
}

fn build_backend_client() -> Result<Client, LanListenerError> {
    Client::builder()
        .connect_timeout(Duration::from_secs(1))
        .timeout(BACKEND_TIMEOUT)
        .redirect(Policy::none())
        .no_proxy()
        .build()
        .map_err(|error| {
            LanListenerError::new(
                "lan_runtime_io_failed",
                format!("build restricted LAN proxy client: {error}"),
            )
        })
}

fn reserve_listener(
    configuration: &LanListenerConfiguration,
) -> Result<(TcpListener, LanOrigin), LanListenerError> {
    let requested_port = configuration.preferred_port.unwrap_or(0);
    let listener =
        TcpListener::bind((configuration.bind_address, requested_port)).map_err(|error| {
            LanListenerError::new(
                "lan_bind_failed",
                format!("bind the selected private adapter and managed port: {error}"),
            )
        })?;
    listener.set_nonblocking(true).map_err(|error| {
        LanListenerError::new(
            "lan_bind_failed",
            format!("configure the LAN listener: {error}"),
        )
    })?;
    let local = listener.local_addr().map_err(|error| {
        LanListenerError::new(
            "lan_bind_failed",
            format!("inspect the LAN listener address: {error}"),
        )
    })?;
    if local.ip() != IpAddr::V4(configuration.bind_address) || local.port() < 1024 {
        return Err(LanListenerError::new(
            "lan_bind_failed",
            "the socket did not bind the exact selected private IPv4 and a high port",
        ));
    }
    let origin = LanOrigin::parse(&format!(
        "http://{}:{}",
        configuration.bind_address,
        local.port()
    ))?;
    Ok((listener, origin))
}

#[derive(Serialize)]
struct LanAdapterInventory<'a> {
    schema_version: u8,
    adapters: &'a [LanAdapterCandidate],
}

fn write_adapter_inventory(
    path: &Path,
    candidates: &[LanAdapterCandidate],
) -> Result<(), LanListenerError> {
    let bytes = serde_json::to_vec_pretty(&LanAdapterInventory {
        schema_version: SETTINGS_SCHEMA_VERSION,
        adapters: candidates,
    })
    .map_err(|error| {
        LanListenerError::new(
            "lan_runtime_io_failed",
            format!("serialize LAN adapter inventory: {error}"),
        )
    })?;
    if bytes.len() > MAX_ADAPTER_INVENTORY_BYTES {
        return Err(LanListenerError::new(
            "lan_runtime_io_failed",
            "LAN adapter inventory exceeds its bounded size",
        ));
    }
    write_bytes_atomically(path, &bytes, "lan_runtime_io_failed")
}

fn write_settings_atomically(
    path: &Path,
    settings: &LanListenerSettings,
) -> Result<(), LanListenerError> {
    let bytes = serde_json::to_vec_pretty(settings).map_err(|error| {
        LanListenerError::new(
            "lan_configuration_invalid",
            format!("serialize LAN listener settings: {error}"),
        )
    })?;
    if bytes.is_empty() || bytes.len() as u64 > MAX_SETTINGS_BYTES {
        return Err(LanListenerError::new(
            "lan_configuration_invalid",
            "LAN listener settings exceed their bounded size",
        ));
    }
    write_bytes_atomically(path, &bytes, "lan_configuration_invalid")
}

fn write_bytes_atomically(
    path: &Path,
    bytes: &[u8],
    error_code: &'static str,
) -> Result<(), LanListenerError> {
    let parent = path.parent().ok_or_else(|| {
        LanListenerError::new(error_code, "managed runtime path has no parent directory")
    })?;
    fs::create_dir_all(parent).map_err(|error| {
        LanListenerError::new(error_code, format!("create managed directory: {error}"))
    })?;
    let parent_metadata = fs::symlink_metadata(parent).map_err(|error| {
        LanListenerError::new(error_code, format!("inspect managed directory: {error}"))
    })?;
    if parent_metadata.file_type().is_symlink() || !parent_metadata.is_dir() {
        return Err(LanListenerError::new(
            error_code,
            "managed runtime parent is not a non-symlink directory",
        ));
    }
    match fs::symlink_metadata(path) {
        Ok(metadata) if metadata.file_type().is_symlink() || !metadata.is_file() => {
            return Err(LanListenerError::new(
                error_code,
                "managed runtime target is not a regular file",
            ));
        }
        Ok(_) => {}
        Err(error) if error.kind() == io::ErrorKind::NotFound => {}
        Err(error) => {
            return Err(LanListenerError::new(
                error_code,
                format!("inspect managed runtime target: {error}"),
            ));
        }
    }
    let temporary = path.with_extension("json.tmp");
    match fs::symlink_metadata(&temporary) {
        Ok(metadata) if metadata.file_type().is_symlink() || !metadata.is_file() => {
            return Err(LanListenerError::new(
                error_code,
                "managed runtime temporary target is not a regular file",
            ));
        }
        Ok(_) => fs::remove_file(&temporary).map_err(|error| {
            LanListenerError::new(
                error_code,
                format!("replace managed temporary file: {error}"),
            )
        })?,
        Err(error) if error.kind() == io::ErrorKind::NotFound => {}
        Err(error) => {
            return Err(LanListenerError::new(
                error_code,
                format!("inspect managed temporary file: {error}"),
            ));
        }
    }
    let mut temporary_file = fs::OpenOptions::new()
        .create_new(true)
        .write(true)
        .open(&temporary)
        .map_err(|error| {
            LanListenerError::new(
                error_code,
                format!("create managed temporary file: {error}"),
            )
        })?;
    temporary_file.write_all(bytes).map_err(|error| {
        LanListenerError::new(error_code, format!("write managed temporary file: {error}"))
    })?;
    temporary_file.sync_all().map_err(|error| {
        LanListenerError::new(error_code, format!("sync managed temporary file: {error}"))
    })?;
    drop(temporary_file);
    publish_managed_replacement(&temporary, path).map_err(|error| {
        LanListenerError::new(error_code, format!("publish managed target: {error}"))
    })
}

#[cfg(not(windows))]
fn publish_managed_replacement(temporary: &Path, target: &Path) -> io::Result<()> {
    fs::rename(temporary, target)?;
    if let Some(parent) = target.parent() {
        File::open(parent)?.sync_all()?;
    }
    Ok(())
}

#[cfg(windows)]
fn publish_managed_replacement(temporary: &Path, target: &Path) -> io::Result<()> {
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

#[derive(Debug)]
struct ParsedRequest {
    method: String,
    path: String,
    content_length: u64,
    body_prefix: Vec<u8>,
    forwarded_headers: HeaderMap,
}

#[derive(Debug)]
struct RequestRejection {
    status: u16,
}

fn parse_request(
    stream: &mut TcpStream,
    origin: &LanOrigin,
    deadline: Instant,
) -> Result<ParsedRequest, RequestRejection> {
    let mut bytes = Vec::with_capacity(2048);
    let head_end = loop {
        if let Some(position) = find_header_end(&bytes) {
            let head_end = position + 4;
            if head_end > MAX_REQUEST_HEAD_BYTES {
                return Err(RequestRejection { status: 431 });
            }
            break head_end;
        }
        if bytes.len() >= MAX_REQUEST_HEAD_BYTES {
            return Err(RequestRejection { status: 431 });
        }
        let remaining = deadline.saturating_duration_since(Instant::now());
        if remaining.is_zero() {
            return Err(RequestRejection { status: 408 });
        }
        stream
            .set_read_timeout(Some(std::cmp::min(REQUEST_HEAD_TIMEOUT, remaining)))
            .map_err(|_| RequestRejection { status: 400 })?;
        let mut chunk = [0_u8; 2048];
        let read = stream.read(&mut chunk).map_err(|error| RequestRejection {
            status: if matches!(
                error.kind(),
                io::ErrorKind::TimedOut | io::ErrorKind::WouldBlock
            ) {
                408
            } else {
                400
            },
        })?;
        if read == 0 {
            return Err(RequestRejection { status: 400 });
        }
        bytes.extend_from_slice(&chunk[..read]);
        if bytes.len() > MAX_REQUEST_HEAD_BYTES + MAX_UPLOAD_REQUEST_BYTES as usize {
            return Err(RequestRejection { status: 413 });
        }
    };

    let mut headers = [httparse::EMPTY_HEADER; MAX_REQUEST_HEADERS];
    let mut parsed = httparse::Request::new(&mut headers);
    let status = parsed
        .parse(&bytes[..head_end])
        .map_err(|_| RequestRejection { status: 400 })?;
    if !status.is_complete() || parsed.version != Some(1) {
        return Err(RequestRejection { status: 400 });
    }
    let method = parsed.method.ok_or(RequestRejection { status: 400 })?;
    let path = parsed.path.ok_or(RequestRejection { status: 400 })?;
    let route = allowed_route(method, path).ok_or(RequestRejection { status: 404 })?;

    let mut host = None;
    let mut content_length = None;
    let mut content_type = None;
    let mut forwarded_headers = HeaderMap::new();
    for header in parsed.headers.iter() {
        let name = header.name.to_ascii_lowercase();
        if forbidden_request_header(&name) {
            return Err(RequestRejection { status: 400 });
        }
        if name == "host" {
            if host.is_some() {
                return Err(RequestRejection { status: 400 });
            }
            host = Some(header.value);
            continue;
        }
        if name == "content-length" {
            if content_length.is_some() {
                return Err(RequestRejection { status: 400 });
            }
            content_length = Some(parse_content_length(header.value)?);
            continue;
        }
        if name == "content-type" {
            if content_type.is_some() {
                return Err(RequestRejection { status: 400 });
            }
            content_type = Some(header.value);
        }
        if name == "origin" && header.value != origin.url.as_bytes() {
            return Err(RequestRejection { status: 403 });
        }
        if name == "referer" {
            let value =
                std::str::from_utf8(header.value).map_err(|_| RequestRejection { status: 400 })?;
            if !value.starts_with(&format!("{}/upload/", origin.url)) {
                return Err(RequestRejection { status: 403 });
            }
        }
        if forwarded_request_header(&name) {
            let header_name = HeaderName::from_bytes(name.as_bytes())
                .map_err(|_| RequestRejection { status: 400 })?;
            let header_value = HeaderValue::from_bytes(header.value)
                .map_err(|_| RequestRejection { status: 400 })?;
            forwarded_headers.append(header_name, header_value);
        }
    }
    if host != Some(origin.authority.as_bytes()) {
        return Err(RequestRejection { status: 404 });
    }

    let content_length = content_length.unwrap_or(0);
    let maximum = match route {
        AllowedRoute::Health | AllowedRoute::Show => 0,
        AllowedRoute::Authorize | AllowedRoute::Complete => MAX_FORM_REQUEST_BYTES,
        AllowedRoute::Files => MAX_UPLOAD_REQUEST_BYTES,
    };
    if content_length > maximum {
        return Err(RequestRejection { status: 413 });
    }
    if matches!(
        route,
        AllowedRoute::Authorize | AllowedRoute::Files | AllowedRoute::Complete
    ) && content_length == 0
    {
        return Err(RequestRejection { status: 411 });
    }
    if matches!(route, AllowedRoute::Files) && !content_type.is_some_and(is_multipart_content_type)
    {
        return Err(RequestRejection { status: 415 });
    }
    if matches!(route, AllowedRoute::Authorize | AllowedRoute::Complete)
        && !content_type.is_some_and(is_form_content_type)
    {
        return Err(RequestRejection { status: 415 });
    }
    if content_length > 0 {
        forwarded_headers.insert(
            CONTENT_LENGTH,
            HeaderValue::from_str(&content_length.to_string())
                .map_err(|_| RequestRejection { status: 400 })?,
        );
    }

    let body_prefix = bytes[head_end..].to_vec();
    if body_prefix.len() as u64 > content_length {
        return Err(RequestRejection { status: 400 });
    }
    Ok(ParsedRequest {
        method: method.to_owned(),
        path: path.to_owned(),
        content_length,
        body_prefix,
        forwarded_headers,
    })
}

#[derive(Clone, Copy)]
enum AllowedRoute {
    Health,
    Show,
    Authorize,
    Files,
    Complete,
}

fn allowed_route(method: &str, target: &str) -> Option<AllowedRoute> {
    if target == "/health" {
        return (method == "GET").then_some(AllowedRoute::Health);
    }
    if !target.starts_with("/upload/")
        || target.contains('?')
        || target.contains('#')
        || target.contains('%')
    {
        return None;
    }
    let remainder = &target[8..];
    let mut parts = remainder.split('/');
    let selector = parts.next()?;
    if selector.len() != 22
        || !selector
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-'))
    {
        return None;
    }
    match (parts.next(), parts.next(), method) {
        (None, None, "GET") => Some(AllowedRoute::Show),
        (Some("authorize"), None, "POST") => Some(AllowedRoute::Authorize),
        (Some("files"), None, "POST") => Some(AllowedRoute::Files),
        (Some("complete"), None, "POST") => Some(AllowedRoute::Complete),
        _ => None,
    }
}

fn forbidden_request_header(name: &str) -> bool {
    matches!(
        name,
        "transfer-encoding"
            | "te"
            | "trailer"
            | "expect"
            | "upgrade"
            | "content-encoding"
            | "proxy-connection"
            | "proxy-authorization"
            | "proxy-authenticate"
            | "forwarded"
            | "x-forwarded-for"
            | "x-forwarded-host"
            | "x-forwarded-port"
            | "x-forwarded-proto"
            | "x-real-ip"
            | "x-medismart-health-key"
    )
}

fn forwarded_request_header(name: &str) -> bool {
    matches!(
        name,
        "accept"
            | "accept-language"
            | "content-type"
            | "cookie"
            | "origin"
            | "referer"
            | "user-agent"
            | "x-csrf-token"
            | "x-xsrf-token"
            | "x-inertia"
            | "x-inertia-version"
            | "x-requested-with"
    )
}

fn parse_content_length(value: &[u8]) -> Result<u64, RequestRejection> {
    if value.is_empty() || !value.iter().all(u8::is_ascii_digit) {
        return Err(RequestRejection { status: 400 });
    }
    let value = std::str::from_utf8(value).map_err(|_| RequestRejection { status: 400 })?;
    value
        .parse::<u64>()
        .map_err(|_| RequestRejection { status: 400 })
}

fn is_multipart_content_type(value: &[u8]) -> bool {
    std::str::from_utf8(value).is_ok_and(|value| {
        let value = value.to_ascii_lowercase();
        let Some(boundary) = value.strip_prefix("multipart/form-data; boundary=") else {
            return false;
        };
        !boundary.is_empty()
            && boundary.len() <= 70
            && boundary.bytes().all(|byte| {
                byte.is_ascii_alphanumeric()
                    || matches!(
                        byte,
                        b'\''
                            | b'('
                            | b')'
                            | b'+'
                            | b'_'
                            | b','
                            | b'-'
                            | b'.'
                            | b'/'
                            | b':'
                            | b'='
                            | b'?'
                    )
            })
    })
}

fn is_form_content_type(value: &[u8]) -> bool {
    std::str::from_utf8(value).is_ok_and(|value| {
        let value = value.to_ascii_lowercase();
        value == "application/json"
            || value.starts_with("application/json;")
            || value == "application/x-www-form-urlencoded"
            || value.starts_with("application/x-www-form-urlencoded;")
            || is_multipart_content_type(value.as_bytes())
    })
}

struct FixedBodyReader {
    prefix: io::Cursor<Vec<u8>>,
    stream: TcpStream,
    remaining: u64,
    deadline: Instant,
}

impl FixedBodyReader {
    fn new(
        prefix: Vec<u8>,
        stream: TcpStream,
        length: u64,
        deadline: Instant,
    ) -> Result<Self, LanListenerError> {
        if prefix.len() as u64 > length {
            return Err(LanListenerError::new(
                "lan_request_rejected",
                "request body exceeded its declared length",
            ));
        }
        Ok(Self {
            remaining: length,
            prefix: io::Cursor::new(prefix),
            stream,
            deadline,
        })
    }
}

impl Read for FixedBodyReader {
    fn read(&mut self, buffer: &mut [u8]) -> io::Result<usize> {
        if self.remaining == 0 || buffer.is_empty() {
            return Ok(0);
        }
        let maximum = usize::try_from(self.remaining)
            .unwrap_or(usize::MAX)
            .min(buffer.len());
        let read = if self.prefix.position() < self.prefix.get_ref().len() as u64 {
            self.prefix.read(&mut buffer[..maximum])?
        } else {
            let remaining_time = self.deadline.saturating_duration_since(Instant::now());
            if remaining_time.is_zero() {
                return Err(io::Error::new(
                    io::ErrorKind::TimedOut,
                    "absolute request body deadline exceeded",
                ));
            }
            self.stream
                .set_read_timeout(Some(std::cmp::min(REQUEST_BODY_TIMEOUT, remaining_time)))?;
            self.stream.read(&mut buffer[..maximum])?
        };
        if read == 0 {
            return Err(io::Error::new(
                io::ErrorKind::UnexpectedEof,
                "request body ended before Content-Length",
            ));
        }
        self.remaining -= read as u64;
        Ok(read)
    }
}

fn response_location_allowed(response: &reqwest::blocking::Response, origin: &LanOrigin) -> bool {
    response.headers().get_all("location").iter().all(|value| {
        let Ok(value) = value.to_str() else {
            return false;
        };
        let path = if value.starts_with('/') {
            value
        } else if let Some(path) = value.strip_prefix(&origin.url) {
            path
        } else {
            return false;
        };
        allowed_route("GET", path).is_some()
    })
}

fn write_backend_response_head(
    downstream: &mut TcpStream,
    response: &reqwest::blocking::Response,
    body_length: usize,
    deadline: Instant,
) -> Result<(), LanListenerError> {
    let status = response.status();
    let mut head = Vec::with_capacity(1024);
    write!(
        head,
        "HTTP/1.1 {} {}\r\nConnection: close\r\nContent-Length: {body_length}\r\n",
        status.as_u16(),
        status.canonical_reason().unwrap_or("Response")
    )
    .map_err(http_io_error)?;
    for name in [
        "content-type",
        "cache-control",
        "pragma",
        "expires",
        "location",
        "vary",
        "referrer-policy",
        "content-security-policy",
        "x-content-type-options",
        "x-frame-options",
        "x-inertia",
    ] {
        for value in response.headers().get_all(name) {
            let value = value.to_str().map_err(|_| {
                LanListenerError::new("lan_backend_invalid", "backend returned an invalid header")
            })?;
            write!(head, "{name}: {value}\r\n").map_err(http_io_error)?;
        }
    }
    for value in response.headers().get_all(SET_COOKIE) {
        let value = value.to_str().map_err(|_| {
            LanListenerError::new("lan_backend_invalid", "backend returned an invalid cookie")
        })?;
        write!(head, "set-cookie: {value}\r\n").map_err(http_io_error)?;
    }
    head.extend_from_slice(b"\r\n");
    if head.len() > MAX_REQUEST_HEAD_BYTES {
        return Err(LanListenerError::new(
            "lan_backend_invalid",
            "backend response headers exceed the LAN boundary limit",
        ));
    }
    write_all_until(downstream, &head, deadline)
}

fn write_all_until(
    stream: &mut TcpStream,
    mut bytes: &[u8],
    deadline: Instant,
) -> Result<(), LanListenerError> {
    while !bytes.is_empty() {
        let remaining = deadline.saturating_duration_since(Instant::now());
        if remaining.is_zero() {
            return Err(LanListenerError::new(
                "lan_connection_deadline_exceeded",
                "absolute LAN connection deadline exceeded",
            ));
        }
        stream
            .set_write_timeout(Some(std::cmp::min(RESPONSE_WRITE_TIMEOUT, remaining)))
            .map_err(http_io_error)?;
        let written = stream.write(bytes).map_err(http_io_error)?;
        if written == 0 {
            return Err(LanListenerError::new(
                "lan_connection_failed",
                "LAN response socket stopped accepting bytes",
            ));
        }
        bytes = &bytes[written..];
    }
    Ok(())
}

fn write_simple_response(
    stream: &mut TcpStream,
    status: u16,
    body: &[u8],
) -> Result<(), LanListenerError> {
    let reason = StatusCode::from_u16(status)
        .ok()
        .and_then(|status| status.canonical_reason())
        .unwrap_or("Response");
    write!(
        stream,
        "HTTP/1.1 {status} {reason}\r\nConnection: close\r\nContent-Length: {}\r\nCache-Control: no-store, private, max-age=0\r\nX-Content-Type-Options: nosniff\r\n\r\n",
        body.len()
    )
    .and_then(|_| stream.write_all(body))
    .map_err(http_io_error)
}

fn find_header_end(bytes: &[u8]) -> Option<usize> {
    bytes.windows(4).position(|window| window == b"\r\n\r\n")
}

fn http_io_error(error: io::Error) -> LanListenerError {
    LanListenerError::new(
        "lan_connection_failed",
        format!("LAN connection I/O: {error}"),
    )
}

fn peer_is_private_ipv4(peer: SocketAddr) -> bool {
    matches!(peer.ip(), IpAddr::V4(address) if is_private_non_loopback_ipv4(address))
}

fn is_private_non_loopback_ipv4(address: Ipv4Addr) -> bool {
    let [first, second, _, _] = address.octets();
    first == 10 || (first == 172 && (16..=31).contains(&second)) || (first == 192 && second == 168)
}

fn ensure_regular_file(path: &Path) -> Result<(), LanListenerError> {
    let metadata = fs::symlink_metadata(path).map_err(|error| {
        LanListenerError::new(
            "lan_configuration_invalid",
            format!("inspect LAN settings: {error}"),
        )
    })?;
    if !metadata.file_type().is_file() || metadata.file_type().is_symlink() {
        return Err(LanListenerError::new(
            "lan_configuration_invalid",
            "LAN settings must be a regular non-symlink file",
        ));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use uuid::Uuid;

    const SELECTED_ID: &str =
        "adapter-v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    fn parse_raw_request(raw: Vec<u8>) -> Result<ParsedRequest, RequestRejection> {
        let listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
        let address = listener.local_addr().unwrap();
        let writer = thread::spawn(move || {
            let mut stream = TcpStream::connect(address).unwrap();
            stream.write_all(&raw).unwrap();
            stream.shutdown(Shutdown::Write).unwrap();
        });
        let (mut stream, _) = listener.accept().unwrap();
        let result = parse_request(
            &mut stream,
            &LanOrigin::parse("http://192.168.1.40:43124").unwrap(),
            Instant::now() + Duration::from_secs(2),
        );
        writer.join().unwrap();
        result
    }

    fn test_logger(directory: &Path, name: &str) -> Arc<RuntimeLogger> {
        Arc::new(
            RuntimeLogger::open_named(
                directory,
                name,
                &[],
                std::slice::from_ref(&directory.to_path_buf()),
            )
            .unwrap(),
        )
    }

    #[test]
    fn listener_fault_publication_never_waits_on_its_joiners_transition_lock() {
        let directory = std::env::temp_dir().join(format!("medismart-lan-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let supervisor = Arc::new(
            LanUploadSupervisor::disabled(
                &directory,
                test_logger(&directory, "lan-fault-transition-test.log"),
            )
            .unwrap(),
        );
        supervisor
            .status
            .store(LISTENER_STATUS_ACTIVATING, Ordering::SeqCst);

        let transition = supervisor.transition.lock().unwrap();
        let worker_supervisor = Arc::clone(&supervisor);
        let (sent, received) = std::sync::mpsc::channel();
        let worker = thread::spawn(move || {
            worker_supervisor.record_fail_closed("lan_listener_accept_failed");
            sent.send(()).unwrap();
        });

        received
            .recv_timeout(Duration::from_secs(1))
            .expect("listener fault publication must not acquire transition");
        assert_eq!(supervisor.status_for_php(), LanListenerStatus::Stopped);
        assert!(!supervisor.commit_activation());
        drop(transition);
        worker.join().unwrap();
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    fn candidate(id: &str, address: Ipv4Addr) -> LanAdapterCandidate {
        LanAdapterCandidate {
            id: id.to_owned(),
            label: id.to_owned(),
            address,
            index: 7,
        }
    }

    #[test]
    fn settings_require_an_explicit_selected_adapter_when_enabled() {
        let settings: LanListenerSettings = serde_json::from_str(
            r#"{"schema_version":1,"enabled":true,"selected_adapter_id":null,"preferred_port":43124}"#,
        )
        .unwrap();

        assert_eq!(
            settings.validate().unwrap_err().code(),
            "lan_adapter_selection_required"
        );
    }

    #[test]
    fn settings_schema_rejects_unknown_native_authority_fields() {
        let with_path = serde_json::json!({
            "schema_version": 1,
            "enabled": false,
            "selected_adapter_id": null,
            "preferred_port": null,
            "firewall_diagnostics_enabled": true,
            "configuration_path": "C:/untrusted.json"
        });
        let with_firewall_claim = serde_json::json!({
            "schema_version": 1,
            "enabled": false,
            "selected_adapter_id": null,
            "preferred_port": null,
            "firewall_diagnostics_enabled": true,
            "firewall_rule_verified": true
        });

        assert!(serde_json::from_value::<LanListenerSettings>(with_path).is_err());
        assert!(serde_json::from_value::<LanListenerSettings>(with_firewall_claim).is_err());
        let raw_identity: LanListenerSettings = serde_json::from_value(serde_json::json!({
            "schema_version": 1,
            "enabled": false,
            "selected_adapter_id": "AA:BB:CC:DD:EE:FF",
            "preferred_port": null,
            "firewall_diagnostics_enabled": true
        }))
        .unwrap();
        assert_eq!(
            raw_identity.validate().unwrap_err().code(),
            "lan_configuration_invalid"
        );
    }

    #[test]
    fn resolution_never_falls_back_to_an_unselected_or_public_address() {
        let settings: LanListenerSettings = serde_json::from_str(
            &format!(r#"{{"schema_version":1,"enabled":true,"selected_adapter_id":"{SELECTED_ID}","preferred_port":43124}}"#),
        )
        .unwrap();
        let wrong = vec![candidate("other", Ipv4Addr::new(192, 168, 1, 10))];
        assert_eq!(
            LanListenerConfiguration::resolve(&settings, &wrong)
                .unwrap_err()
                .code(),
            "lan_adapter_unavailable"
        );

        let public = vec![candidate(SELECTED_ID, Ipv4Addr::new(203, 0, 113, 5))];
        assert_eq!(
            LanListenerConfiguration::resolve(&settings, &public)
                .unwrap_err()
                .code(),
            "lan_adapter_unavailable"
        );
    }

    #[test]
    fn resolution_uses_only_the_selected_private_adapter() {
        let settings: LanListenerSettings = serde_json::from_str(
            &format!(r#"{{"schema_version":1,"enabled":true,"selected_adapter_id":"{SELECTED_ID}","preferred_port":43124}}"#),
        )
        .unwrap();
        let candidates = vec![
            candidate("ethernet", Ipv4Addr::new(10, 0, 0, 8)),
            candidate(SELECTED_ID, Ipv4Addr::new(192, 168, 1, 44)),
        ];
        let configuration = LanListenerConfiguration::resolve(&settings, &candidates)
            .unwrap()
            .unwrap();

        assert_eq!(configuration.adapter_id, SELECTED_ID);
        assert_eq!(configuration.bind_address, Ipv4Addr::new(192, 168, 1, 44));
        assert_eq!(configuration.preferred_port, Some(43124));
    }

    #[test]
    fn route_set_is_exact_and_wrong_methods_or_near_matches_fail_closed() {
        let selector = "Abcdefghijklmnopqrstu_";
        assert_eq!(selector.len(), 22);
        assert!(matches!(
            allowed_route("GET", &format!("/upload/{selector}")),
            Some(AllowedRoute::Show)
        ));
        assert!(matches!(
            allowed_route("POST", &format!("/upload/{selector}/files")),
            Some(AllowedRoute::Files)
        ));
        for (method, path) in [
            ("HEAD", "/health".to_owned()),
            ("POST", format!("/upload/{selector}")),
            ("GET", format!("/upload/{selector}/files")),
            ("POST", format!("/upload/{selector}/files/extra")),
            ("GET", format!("/uploads/{selector}")),
            ("GET", format!("/upload/{selector}?x=1")),
            ("GET", format!("/upload/{selector}%2fcomplete")),
        ] {
            assert!(allowed_route(method, &path).is_none(), "{method} {path}");
        }
    }

    #[test]
    fn attestation_capability_requires_every_laravel_boundary_flag() {
        let origin = "http://192.168.1.40:43124";
        let exact = format!(
            r#"{{"lan_upload_boundary":{{"schema_version":1,"status":"ready","origin":"{origin}","route_set":"public_upload_v1","upload_routes_only":true,"exact_origin_enforced":true,"explicit_high_port_enforced":true,"direct_private_peer_enforced":true,"forwarding_headers_rejected":true,"local_tokens_bound_to_lan_origin":true}}}}"#
        );
        assert!(
            VerifiedLanUploadBoundary::from_health_response(exact.as_bytes(), origin).is_some()
        );

        let weakened = exact.replace(
            "\"forwarding_headers_rejected\":true",
            "\"forwarding_headers_rejected\":false",
        );
        assert!(
            VerifiedLanUploadBoundary::from_health_response(weakened.as_bytes(), origin).is_none()
        );
        assert!(VerifiedLanUploadBoundary::from_health_response(
            exact.as_bytes(),
            "http://192.168.1.41:43124"
        )
        .is_none());
    }

    #[test]
    fn listener_binds_the_exact_private_address_and_managed_high_port() {
        let directory = std::env::temp_dir().join(format!("medismart-lan-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let logger = test_logger(&directory, "lan-test.log");
        let supervisor = LanUploadSupervisor::new(
            LanListenerConfiguration::for_test(Ipv4Addr::new(127, 0, 0, 1), None),
            &directory,
            logger,
        );

        let error = match supervisor {
            Ok(_) => panic!("loopback must never become a LAN origin"),
            Err(error) => error,
        };
        assert_eq!(error.code(), "lan_origin_invalid");
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn managed_apply_persists_exact_settings_and_fails_closed_on_adapter_drift() {
        let Some(candidate) = discover_lan_adapter_candidates()
            .unwrap_or_default()
            .into_iter()
            .next()
        else {
            return;
        };
        let directory = std::env::temp_dir().join(format!("medismart-lan-{}", Uuid::new_v4()));
        let configuration_directory = directory.join("config");
        let runtime_directory = directory.join("runtime");
        fs::create_dir_all(&configuration_directory).unwrap();
        fs::create_dir_all(&runtime_directory).unwrap();
        let settings_path = configuration_directory.join("lan-listener.json");
        let supervisor = LanUploadSupervisor::managed(
            LanListenerSettings::disabled_defaults(),
            &settings_path,
            &runtime_directory,
            test_logger(&directory, "lan-managed-test.log"),
        )
        .unwrap();

        let requested = LanListenerSettings {
            schema_version: SETTINGS_SCHEMA_VERSION,
            enabled: true,
            selected_adapter_id: Some(candidate.id.clone()),
            preferred_port: None,
            firewall_diagnostics_enabled: true,
        };
        let state = supervisor.apply_configuration(requested.clone()).unwrap();
        assert_eq!(state.phase, "pending_attestation");
        assert!(!state.verified);
        assert_eq!(
            state.requested_adapter_id.as_deref(),
            Some(candidate.id.as_str())
        );
        assert!(state.verified_origin.is_none());
        assert!(settings_path.is_file());
        assert_eq!(
            load_lan_listener_settings(&settings_path).unwrap(),
            requested
        );
        let active_generation = supervisor.contract_generation();

        let missing_adapter = LanListenerSettings {
            selected_adapter_id: Some(format!("adapter-v1:{}", "b".repeat(64))),
            ..requested
        };
        let error = supervisor
            .apply_configuration(missing_adapter.clone())
            .unwrap_err();
        assert_eq!(error.code(), "lan_adapter_unavailable");
        let drift = supervisor.provisioning_state();
        assert_eq!(drift.phase, "unavailable");
        assert!(!drift.verified);
        assert!(drift.verified_origin.is_none());
        assert_eq!(drift.error_code, Some("lan_adapter_unavailable"));
        assert!(supervisor.contract_generation() > active_generation);
        assert_eq!(
            load_lan_listener_settings(&settings_path).unwrap(),
            missing_adapter
        );

        supervisor.shutdown();
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn managed_settings_writer_rejects_symlink_targets() {
        use std::os::unix::fs::symlink;

        let directory = std::env::temp_dir().join(format!("medismart-lan-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let outside = directory.join("outside.json");
        fs::write(&outside, b"unchanged").unwrap();
        let target = directory.join("lan-listener.json");
        symlink(&outside, &target).unwrap();

        let error = write_settings_atomically(&target, &LanListenerSettings::disabled_defaults())
            .unwrap_err();

        assert_eq!(error.code(), "lan_configuration_invalid");
        assert_eq!(fs::read(&outside).unwrap(), b"unchanged");
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn proxy_streams_the_body_with_exact_lan_host_and_preserves_session_cookie() {
        let directory = std::env::temp_dir().join(format!("medismart-lan-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let supervisor = LanUploadSupervisor::disabled(
            &directory,
            test_logger(&directory, "lan-proxy-test.log"),
        )
        .unwrap();

        let backend_listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
        let backend_address = backend_listener.local_addr().unwrap();
        let backend = thread::spawn(move || {
            let (mut stream, _) = backend_listener.accept().unwrap();
            stream
                .set_read_timeout(Some(Duration::from_secs(3)))
                .unwrap();
            let mut bytes = Vec::new();
            let head_end = loop {
                if let Some(position) = find_header_end(&bytes) {
                    break position + 4;
                }
                let mut chunk = [0_u8; 1024];
                let read = stream.read(&mut chunk).unwrap();
                assert!(read > 0);
                bytes.extend_from_slice(&chunk[..read]);
            };
            let head = String::from_utf8(bytes[..head_end].to_vec()).unwrap();
            let content_length = head
                .lines()
                .find_map(|line| {
                    line.to_ascii_lowercase()
                        .strip_prefix("content-length: ")
                        .and_then(|value| value.parse::<usize>().ok())
                })
                .unwrap();
            while bytes.len() - head_end < content_length {
                let mut chunk = [0_u8; 1024];
                let read = stream.read(&mut chunk).unwrap();
                assert!(read > 0);
                bytes.extend_from_slice(&chunk[..read]);
            }
            stream
                .write_all(
                    b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 11\r\nSet-Cookie: medismart_session=abc; Path=/; HttpOnly\r\n\r\n{\"ok\":true}",
                )
                .unwrap();
            bytes
        });

        let downstream_listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
        let downstream_address = downstream_listener.local_addr().unwrap();
        let phone = thread::spawn(move || {
            let mut stream = TcpStream::connect(downstream_address).unwrap();
            let mut response = Vec::new();
            stream.read_to_end(&mut response).unwrap();
            response
        });
        let (downstream, _) = downstream_listener.accept().unwrap();
        let body = br#"{"verifier":"abcdefghijklmnopqrstuvwxyz0123456789_-ABC"}"#.to_vec();
        let mut forwarded_headers = HeaderMap::new();
        forwarded_headers.insert(
            HeaderName::from_static("content-type"),
            HeaderValue::from_static("application/json"),
        );
        forwarded_headers.insert(
            CONTENT_LENGTH,
            HeaderValue::from_str(&body.len().to_string()).unwrap(),
        );
        supervisor
            .proxy_request(
                downstream,
                ParsedRequest {
                    method: "POST".to_owned(),
                    path: "/upload/Abcdefghijklmnopqrstu_/authorize".to_owned(),
                    content_length: body.len() as u64,
                    body_prefix: body.clone(),
                    forwarded_headers,
                },
                &LanOrigin::parse("http://192.168.1.40:43124").unwrap(),
                &LoopbackOrigin::parse(&format!("http://127.0.0.1:{}", backend_address.port()))
                    .unwrap(),
                Instant::now() + Duration::from_secs(2),
                Instant::now() + Duration::from_secs(3),
            )
            .unwrap();

        let backend_request = String::from_utf8(backend.join().unwrap()).unwrap();
        assert!(backend_request
            .starts_with("POST /upload/Abcdefghijklmnopqrstu_/authorize HTTP/1.1\r\n"));
        assert!(backend_request.contains("host: 192.168.1.40:43124\r\n"));
        assert!(backend_request.contains(&format!("content-length: {}\r\n", body.len())));
        assert!(!backend_request
            .to_ascii_lowercase()
            .contains("transfer-encoding"));
        assert!(backend_request.as_bytes().ends_with(&body));

        let phone_response = String::from_utf8(phone.join().unwrap()).unwrap();
        assert!(phone_response.starts_with("HTTP/1.1 200 OK\r\n"));
        assert!(phone_response.contains("set-cookie: medismart_session=abc; Path=/; HttpOnly\r\n"));
        assert!(phone_response.ends_with("{\"ok\":true}"));

        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn attested_listener_becomes_active_only_after_lan_health_and_joins_on_shutdown() {
        let Some(candidate) = discover_lan_adapter_candidates()
            .unwrap_or_default()
            .into_iter()
            .next()
        else {
            // Network-less CI still exercises all pure selection, request,
            // attestation, and proxy tests above.
            return;
        };
        let directory = std::env::temp_dir().join(format!("medismart-lan-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let supervisor = Arc::new(
            LanUploadSupervisor::new(
                LanListenerConfiguration {
                    adapter_id: candidate.id,
                    bind_address: candidate.address,
                    preferred_port: None,
                },
                &directory,
                test_logger(&directory, "lan-lifecycle-test.log"),
            )
            .unwrap(),
        );
        let origin = supervisor.required_attestation_origin().unwrap().to_owned();

        let backend_listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
        let backend_address = backend_listener.local_addr().unwrap();
        let backend = thread::spawn(move || {
            let (mut stream, _) = backend_listener.accept().unwrap();
            stream
                .set_read_timeout(Some(Duration::from_secs(3)))
                .unwrap();
            let mut request = Vec::new();
            while find_header_end(&request).is_none() {
                let mut chunk = [0_u8; 512];
                let read = stream.read(&mut chunk).unwrap();
                assert!(read > 0);
                request.extend_from_slice(&chunk[..read]);
            }
            let body = br#"{"status":"healthy"}"#;
            write!(
                stream,
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\r\n",
                body.len()
            )
            .unwrap();
            stream.write_all(body).unwrap();
            String::from_utf8(request).unwrap()
        });

        assert_eq!(supervisor.status_for_php(), LanListenerStatus::Stopped);
        supervisor.activate_for_backend(
            &format!("http://127.0.0.1:{}", backend_address.port()),
            VerifiedLanUploadBoundary {
                origin: origin.clone(),
            },
        );
        assert_eq!(supervisor.status_for_php(), LanListenerStatus::Active);
        let request = backend.join().unwrap();
        assert!(request.starts_with("GET /health HTTP/1.1\r\n"));
        assert!(request.to_ascii_lowercase().contains(&format!(
            "host: {}\r\n",
            origin.trim_start_matches("http://")
        )));

        supervisor.shutdown();
        assert_eq!(supervisor.status_for_php(), LanListenerStatus::Stopped);
        assert!(supervisor.lifecycle.lock().unwrap().worker.is_none());
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn forwarding_and_health_key_headers_are_never_accepted() {
        for name in [
            "forwarded",
            "x-forwarded-for",
            "x-forwarded-proto",
            "x-real-ip",
            "x-medismart-health-key",
            "transfer-encoding",
            "upgrade",
        ] {
            assert!(forbidden_request_header(name), "{name}");
        }
    }

    #[test]
    fn request_parser_requires_exact_host_and_preserves_only_browser_session_headers() {
        let selector = "Abcdefghijklmnopqrstu_";
        let request = format!(
            "GET /upload/{selector} HTTP/1.1\r\nHost: 192.168.1.40:43124\r\nCookie: session=abc\r\nAccept: text/html\r\nSec-Fetch-Site: same-origin\r\n\r\n"
        );
        let parsed = parse_raw_request(request.into_bytes()).unwrap();
        assert_eq!(parsed.method, "GET");
        assert_eq!(parsed.path, format!("/upload/{selector}"));
        assert_eq!(
            parsed
                .forwarded_headers
                .get("cookie")
                .and_then(|value| value.to_str().ok()),
            Some("session=abc")
        );
        assert!(parsed.forwarded_headers.get("sec-fetch-site").is_none());

        let wrong_host =
            format!("GET /upload/{selector} HTTP/1.1\r\nHost: 192.168.1.41:43124\r\n\r\n");
        assert_eq!(
            parse_raw_request(wrong_host.into_bytes())
                .unwrap_err()
                .status,
            404
        );
    }

    #[test]
    fn request_parser_rejects_forwarding_chunking_and_oversized_forms() {
        let selector = "Abcdefghijklmnopqrstu_";
        for header in [
            "X-Forwarded-For: 192.168.1.50",
            "X-MediSmart-Health-Key: secret",
            "Transfer-Encoding: chunked",
            "Expect: 100-continue",
        ] {
            let request = format!(
                "GET /upload/{selector} HTTP/1.1\r\nHost: 192.168.1.40:43124\r\n{header}\r\n\r\n"
            );
            assert_eq!(
                parse_raw_request(request.into_bytes()).unwrap_err().status,
                400,
                "{header}"
            );
        }

        let oversized = format!(
            "POST /upload/{selector}/authorize HTTP/1.1\r\nHost: 192.168.1.40:43124\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\r\n",
            MAX_FORM_REQUEST_BYTES + 1
        );
        assert_eq!(
            parse_raw_request(oversized.into_bytes())
                .unwrap_err()
                .status,
            413
        );
    }

    #[test]
    fn absolute_header_and_body_deadlines_stop_slow_clients() {
        let header_listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
        let header_address = header_listener.local_addr().unwrap();
        let header_writer = thread::spawn(move || {
            let mut stream = TcpStream::connect(header_address).unwrap();
            stream.write_all(b"G").unwrap();
            thread::sleep(Duration::from_millis(250));
        });
        let (mut header_stream, _) = header_listener.accept().unwrap();
        let started = Instant::now();
        let rejection = parse_request(
            &mut header_stream,
            &LanOrigin::parse("http://192.168.1.40:43124").unwrap(),
            Instant::now() + Duration::from_millis(60),
        )
        .unwrap_err();
        assert_eq!(rejection.status, 408);
        assert!(started.elapsed() < Duration::from_millis(500));
        header_writer.join().unwrap();

        let body_listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
        let body_address = body_listener.local_addr().unwrap();
        let body_writer = thread::spawn(move || {
            let _stream = TcpStream::connect(body_address).unwrap();
            thread::sleep(Duration::from_millis(250));
        });
        let (body_stream, _) = body_listener.accept().unwrap();
        let mut reader = FixedBodyReader::new(
            Vec::new(),
            body_stream,
            1,
            Instant::now() + Duration::from_millis(60),
        )
        .unwrap();
        let error = reader.read(&mut [0_u8; 1]).unwrap_err();
        assert!(matches!(
            error.kind(),
            io::ErrorKind::TimedOut | io::ErrorKind::WouldBlock
        ));
        body_writer.join().unwrap();
    }

    #[test]
    fn bounded_connection_pool_rejects_saturation_and_recovers() {
        let Some(candidate) = discover_lan_adapter_candidates()
            .unwrap_or_default()
            .into_iter()
            .next()
        else {
            return;
        };
        let directory = std::env::temp_dir().join(format!("medismart-lan-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let supervisor = Arc::new(
            LanUploadSupervisor::new(
                LanListenerConfiguration {
                    adapter_id: candidate.id,
                    bind_address: candidate.address,
                    preferred_port: None,
                },
                &directory,
                test_logger(&directory, "lan-saturation-test.log"),
            )
            .unwrap(),
        );
        let origin = supervisor.active_origin().unwrap();
        let backend_listener = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap();
        let backend_address = backend_listener.local_addr().unwrap();
        let backend = thread::spawn(move || {
            for _ in 0..2 {
                let (mut stream, _) = backend_listener.accept().unwrap();
                stream
                    .set_read_timeout(Some(Duration::from_secs(3)))
                    .unwrap();
                let mut request = Vec::new();
                while find_header_end(&request).is_none() {
                    let mut chunk = [0_u8; 512];
                    let read = stream.read(&mut chunk).unwrap();
                    assert!(read > 0);
                    request.extend_from_slice(&chunk[..read]);
                }
                let body = br#"{"status":"healthy"}"#;
                write!(
                    stream,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\r\n",
                    body.len()
                )
                .unwrap();
                stream.write_all(body).unwrap();
            }
        });
        supervisor.activate_for_backend(
            &format!("http://127.0.0.1:{}", backend_address.port()),
            VerifiedLanUploadBoundary {
                origin: origin.url.clone(),
            },
        );
        assert_eq!(supervisor.status_for_php(), LanListenerStatus::Active);

        let listener_address = SocketAddr::from((origin.address, origin.port));
        let mut slow_clients = Vec::new();
        for _ in 0..MAX_CONCURRENT_CONNECTIONS {
            let mut stream = TcpStream::connect(listener_address).unwrap();
            stream.write_all(b"G").unwrap();
            slow_clients.push(stream);
        }
        let saturation_deadline = Instant::now() + Duration::from_secs(2);
        while supervisor.active_connection_count() < MAX_CONCURRENT_CONNECTIONS
            && Instant::now() < saturation_deadline
        {
            thread::sleep(Duration::from_millis(10));
        }
        assert_eq!(
            supervisor.active_connection_count(),
            MAX_CONCURRENT_CONNECTIONS
        );

        let mut rejected = TcpStream::connect(listener_address).unwrap();
        rejected
            .set_read_timeout(Some(Duration::from_secs(2)))
            .unwrap();
        let mut rejected_response = Vec::new();
        rejected.read_to_end(&mut rejected_response).unwrap();
        assert!(rejected_response.starts_with(b"HTTP/1.1 503"));

        drop(slow_clients.swap_remove(0));
        let recovery_deadline = Instant::now() + Duration::from_secs(2);
        while supervisor.active_connection_count() >= MAX_CONCURRENT_CONNECTIONS
            && Instant::now() < recovery_deadline
        {
            thread::sleep(Duration::from_millis(10));
        }
        assert!(supervisor.active_connection_count() < MAX_CONCURRENT_CONNECTIONS);

        let mut recovered = TcpStream::connect(listener_address).unwrap();
        recovered
            .set_read_timeout(Some(Duration::from_secs(3)))
            .unwrap();
        write!(
            recovered,
            "GET /health HTTP/1.1\r\nHost: {}\r\n\r\n",
            origin.authority
        )
        .unwrap();
        let mut recovered_response = Vec::new();
        recovered.read_to_end(&mut recovered_response).unwrap();
        assert!(recovered_response.starts_with(b"HTTP/1.1 200"));

        drop(slow_clients);
        supervisor.shutdown();
        assert!(supervisor.lifecycle.lock().unwrap().connections.is_empty());
        backend.join().unwrap();
        drop(supervisor);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn tunnel_and_virtual_adapter_names_are_excluded_where_identifiable() {
        for name in [
            "Loopback Pseudo-Interface",
            "vEthernet (WSL)",
            "WireGuard Tunnel",
            "tailscale0",
            "docker0",
            "tap0",
        ] {
            assert!(looks_like_tunnel_or_virtual_adapter(name), "{name}");
        }
        assert!(!looks_like_tunnel_or_virtual_adapter("Wi-Fi"));
        assert!(!looks_like_tunnel_or_virtual_adapter("Ethernet"));
    }

    #[test]
    fn adapter_id_is_stable_across_friendly_name_and_index_changes() {
        let first = stable_adapter_id(Some("AA:BB:CC:DD:EE:FF")).unwrap();
        let second = stable_adapter_id(Some("aa-bb-cc-dd-ee-ff")).unwrap();
        assert_eq!(first, second);
        assert!(first.starts_with("adapter-v1:"));
        assert_eq!(first.len(), "adapter-v1:".len() + 64);
        assert!(stable_adapter_id(Some("00:00:00:00:00:00")).is_none());
        assert!(stable_adapter_id(None).is_none());
    }
}
