//! Cabinet LAN hosting: one Drclick PC (the *poste principal*) serves the
//! clinic's database to the other Drclick PCs of the same cabinet.
//!
//! This module holds the parts of that feature that do not need Tauri:
//!
//! - [`LanHostSettings`] — the persisted opt-in (`config/lan-host.json`). The
//!   LAN listener is **off** unless the clinic turns it on; a damaged file is
//!   read as "off", never as "on".
//! - [`private_lan_addresses`] — the addresses to show to the other PCs.
//! - A tiny UDP discovery protocol so a *poste secondaire* can find the host
//!   with one click instead of typing an IP address:
//!   [`LanDiscoveryResponder`] (host side) and [`discover_lan_hosts`] (client).
//!
//! Discovery is a convenience, not trust: it only proposes an address. The
//! client still probes `/health`, and every user still signs in with their own
//! account on the host.

use std::{
    fs::{self, OpenOptions},
    io::{self, Write},
    net::{IpAddr, Ipv4Addr, SocketAddr, SocketAddrV4, UdpSocket},
    path::{Path, PathBuf},
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc,
    },
    thread::{self, JoinHandle},
    time::{Duration, Instant},
};

use network_interface::{Addr, NetworkInterface, NetworkInterfaceConfig};
use serde::{Deserialize, Serialize};

/// Fixed, documented TCP port of the LAN host listener. A fixed port keeps the
/// address typed on the other PCs (and the firewall rule) stable across
/// restarts. It sits well above the ephemeral ranges Windows hands out.
pub const DEFAULT_LAN_HOST_PORT: u16 = 47850;

/// UDP port the host answers discovery requests on.
pub const LAN_DISCOVERY_PORT: u16 = 47851;

const SETTINGS_FILE: &str = "lan-host.json";
const SETTINGS_SCHEMA_VERSION: u8 = 1;
const DISCOVERY_REQUEST: &[u8] = b"DRCLICK_DISCOVER v1";
const DISCOVERY_REPLY_PREFIX: &[u8] = b"DRCLICK_HOST v1\n";
const MAX_DATAGRAM: usize = 1024;

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/// Whether this PC shares its database with the cabinet LAN, and on which port.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct LanHostSettings {
    pub enabled: bool,
    pub port: u16,
}

impl Default for LanHostSettings {
    fn default() -> Self {
        Self {
            enabled: false,
            port: DEFAULT_LAN_HOST_PORT,
        }
    }
}

#[derive(Debug, Serialize, Deserialize)]
struct StoredLanHostSettings {
    schema_version: u8,
    enabled: bool,
    port: u16,
}

pub fn lan_host_settings_path(configuration_directory: &Path) -> PathBuf {
    configuration_directory.join(SETTINGS_FILE)
}

/// Read the persisted opt-in. Anything absent, unreadable, or invalid means
/// "not shared": exposing the clinic's database on the network must never be
/// the result of a parse error.
pub fn load_lan_host_settings(configuration_directory: &Path) -> LanHostSettings {
    let Ok(bytes) = fs::read(lan_host_settings_path(configuration_directory)) else {
        return LanHostSettings::default();
    };
    let Ok(stored) = serde_json::from_slice::<StoredLanHostSettings>(&bytes) else {
        return LanHostSettings::default();
    };
    if stored.schema_version != SETTINGS_SCHEMA_VERSION {
        return LanHostSettings::default();
    }
    match validate_lan_host_port(stored.port) {
        Ok(port) => LanHostSettings {
            enabled: stored.enabled,
            port,
        },
        Err(_) => LanHostSettings::default(),
    }
}

/// Persist the opt-in atomically (write a sibling file, then rename over).
pub fn save_lan_host_settings(
    configuration_directory: &Path,
    settings: &LanHostSettings,
) -> io::Result<()> {
    validate_lan_host_port(settings.port)
        .map_err(|message| io::Error::new(io::ErrorKind::InvalidInput, message))?;
    fs::create_dir_all(configuration_directory)?;

    let payload = serde_json::to_vec_pretty(&StoredLanHostSettings {
        schema_version: SETTINGS_SCHEMA_VERSION,
        enabled: settings.enabled,
        port: settings.port,
    })
    .map_err(io::Error::other)?;

    let target = lan_host_settings_path(configuration_directory);
    let temporary = target.with_extension("json.tmp");
    let mut file = OpenOptions::new()
        .create(true)
        .truncate(true)
        .write(true)
        .open(&temporary)?;
    file.write_all(&payload)?;
    file.sync_all()?;
    drop(file);

    fs::rename(&temporary, &target).inspect_err(|_| {
        let _ = fs::remove_file(&temporary);
    })
}

/// An unprivileged TCP port that is not the discovery port.
pub fn validate_lan_host_port(port: u16) -> Result<u16, &'static str> {
    if port < 1024 {
        return Err("Choisissez un port entre 1024 et 65535.");
    }
    if port == LAN_DISCOVERY_PORT {
        return Err("Ce port est réservé à la découverte des postes Drclick.");
    }
    Ok(port)
}

// ---------------------------------------------------------------------------
// Addresses
// ---------------------------------------------------------------------------

/// RFC 1918 private ranges plus IPv4 link-local (APIPA), which is what two PCs
/// cabled together without a router end up with.
pub fn is_private_lan_ipv4(address: Ipv4Addr) -> bool {
    address.is_private() || address.is_link_local()
}

/// One address the other PCs may use to reach this one.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct LanAddress {
    pub address: Ipv4Addr,
    pub interface: String,
}

/// Private IPv4 addresses of this PC's network adapters, most likely first
/// (home/office `192.168.x.x`, then `10.x`, then `172.16-31.x`, then APIPA).
pub fn private_lan_addresses() -> Vec<LanAddress> {
    let Ok(interfaces) = NetworkInterface::show() else {
        return Vec::new();
    };

    let mut addresses = Vec::new();
    for interface in interfaces {
        if interface.internal {
            continue;
        }
        for address in &interface.addr {
            let Addr::V4(address) = address else {
                continue;
            };
            if is_private_lan_ipv4(address.ip) && !address.ip.is_loopback() {
                addresses.push(LanAddress {
                    address: address.ip,
                    interface: interface.name.clone(),
                });
            }
        }
    }

    sort_lan_addresses(&mut addresses);
    addresses
}

fn address_preference(address: Ipv4Addr) -> u8 {
    match address.octets() {
        [192, 168, ..] => 0,
        [10, ..] => 1,
        [172, ..] => 2,
        _ => 3,
    }
}

fn sort_lan_addresses(addresses: &mut Vec<LanAddress>) {
    addresses.sort_by_key(|entry| (address_preference(entry.address), entry.address.octets()));
    addresses.dedup_by(|left, right| left.address == right.address);
}

/// Broadcast destinations for discovery: the limited broadcast address plus
/// each adapter's directed broadcast (Windows sends `255.255.255.255` out of a
/// single adapter only, so directed broadcasts reach the others).
pub fn default_discovery_targets(port: u16) -> Vec<SocketAddr> {
    let mut targets = vec![SocketAddr::V4(SocketAddrV4::new(Ipv4Addr::BROADCAST, port))];

    if let Ok(interfaces) = NetworkInterface::show() {
        for interface in interfaces {
            if interface.internal {
                continue;
            }
            for address in interface.addr {
                let Addr::V4(address) = address else {
                    continue;
                };
                if !is_private_lan_ipv4(address.ip) {
                    continue;
                }
                if let Some(broadcast) = address.broadcast {
                    let target = SocketAddr::V4(SocketAddrV4::new(broadcast, port));
                    if !targets.contains(&target) {
                        targets.push(target);
                    }
                }
            }
        }
    }

    targets
}

/// Best-effort computer name, shown to the other PCs during discovery.
pub fn local_computer_name() -> String {
    for variable in ["COMPUTERNAME", "HOSTNAME"] {
        if let Ok(value) = std::env::var(variable) {
            let value = value.trim();
            if !value.is_empty() {
                return sanitize_name(value);
            }
        }
    }

    fs::read_to_string("/etc/hostname")
        .ok()
        .map(|value| sanitize_name(value.trim()))
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| "Poste principal".to_owned())
}

fn sanitize_name(value: &str) -> String {
    value
        .chars()
        .filter(|character| !character.is_control())
        .take(63)
        .collect()
}

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

/// What the host tells a PC looking for it.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct LanHostAdvertisement {
    pub app: String,
    pub name: String,
    pub port: u16,
    pub version: String,
}

impl LanHostAdvertisement {
    pub fn new(name: impl Into<String>, port: u16, version: impl Into<String>) -> Self {
        Self {
            app: "Drclick".to_owned(),
            name: name.into(),
            port,
            version: version.into(),
        }
    }

    fn encode(&self) -> Vec<u8> {
        let mut datagram = DISCOVERY_REPLY_PREFIX.to_vec();
        datagram.extend(serde_json::to_vec(self).unwrap_or_default());
        datagram
    }

    fn decode(datagram: &[u8]) -> Option<Self> {
        let body = datagram.strip_prefix(DISCOVERY_REPLY_PREFIX)?;
        let advertisement = serde_json::from_slice::<Self>(body).ok()?;
        (advertisement.app == "Drclick" && validate_lan_host_port(advertisement.port).is_ok())
            .then_some(advertisement)
    }
}

/// A host found on the LAN, ready to be offered to the user.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct DiscoveredLanHost {
    pub name: String,
    pub address: String,
    pub port: u16,
    pub url: String,
    pub version: String,
}

/// Host side: answers discovery datagrams until stopped or dropped.
pub struct LanDiscoveryResponder {
    stopping: Arc<AtomicBool>,
    thread: Option<JoinHandle<()>>,
    local_address: SocketAddr,
}

impl LanDiscoveryResponder {
    /// Bind `bind` (normally `0.0.0.0:47851`) and answer in a background thread.
    pub fn spawn(bind: SocketAddr, advertisement: LanHostAdvertisement) -> io::Result<Self> {
        let socket = UdpSocket::bind(bind)?;
        socket.set_read_timeout(Some(Duration::from_millis(250)))?;
        let local_address = socket.local_addr()?;
        let stopping = Arc::new(AtomicBool::new(false));
        let reply = advertisement.encode();

        let thread_stopping = Arc::clone(&stopping);
        let thread = thread::Builder::new()
            .name("drclick-lan-discovery".to_owned())
            .spawn(move || {
                let mut buffer = [0_u8; MAX_DATAGRAM];
                while !thread_stopping.load(Ordering::SeqCst) {
                    let Ok((length, peer)) = socket.recv_from(&mut buffer) else {
                        continue;
                    };
                    // Never answer the Internet, and never act as a reflector
                    // for anything but the exact request.
                    if !peer_is_local_network(peer.ip()) {
                        continue;
                    }
                    if trim_ascii(&buffer[..length]) != DISCOVERY_REQUEST {
                        continue;
                    }
                    let _ = socket.send_to(&reply, peer);
                }
            })?;

        Ok(Self {
            stopping,
            thread: Some(thread),
            local_address,
        })
    }

    pub fn local_address(&self) -> SocketAddr {
        self.local_address
    }

    pub fn stop(&mut self) {
        self.stopping.store(true, Ordering::SeqCst);
        if let Some(thread) = self.thread.take() {
            let _ = thread.join();
        }
    }
}

impl Drop for LanDiscoveryResponder {
    fn drop(&mut self) {
        self.stop();
    }
}

fn peer_is_local_network(address: IpAddr) -> bool {
    match address {
        IpAddr::V4(address) => address.is_loopback() || is_private_lan_ipv4(address),
        IpAddr::V6(address) => address.is_loopback(),
    }
}

fn trim_ascii(bytes: &[u8]) -> &[u8] {
    let start = bytes
        .iter()
        .position(|byte| !byte.is_ascii_whitespace())
        .unwrap_or(bytes.len());
    let end = bytes
        .iter()
        .rposition(|byte| !byte.is_ascii_whitespace())
        .map_or(start, |position| position + 1);
    &bytes[start..end]
}

/// Client side: ask `targets` who is a Drclick LAN host and collect the answers
/// received before `timeout`.
pub fn discover_lan_hosts(
    targets: &[SocketAddr],
    timeout: Duration,
) -> io::Result<Vec<DiscoveredLanHost>> {
    let socket = UdpSocket::bind((Ipv4Addr::UNSPECIFIED, 0))?;
    socket.set_broadcast(true)?;

    let mut sent = false;
    for target in targets {
        // One unreachable adapter must not hide the hosts on the others.
        if socket.send_to(DISCOVERY_REQUEST, target).is_ok() {
            sent = true;
        }
    }
    if !sent {
        return Ok(Vec::new());
    }

    let deadline = Instant::now() + timeout;
    let mut found: Vec<DiscoveredLanHost> = Vec::new();
    let mut buffer = [0_u8; MAX_DATAGRAM];

    loop {
        let remaining = deadline.saturating_duration_since(Instant::now());
        if remaining.is_zero() {
            break;
        }
        socket.set_read_timeout(Some(remaining.max(Duration::from_millis(1))))?;
        let Ok((length, peer)) = socket.recv_from(&mut buffer) else {
            continue;
        };
        let IpAddr::V4(address) = peer.ip() else {
            continue;
        };
        if !peer_is_local_network(IpAddr::V4(address)) {
            continue;
        }
        let Some(advertisement) = LanHostAdvertisement::decode(&buffer[..length]) else {
            continue;
        };
        let url = format!("http://{address}:{}/", advertisement.port);
        if found.iter().any(|host| host.url == url) {
            continue;
        }
        found.push(DiscoveredLanHost {
            name: advertisement.name,
            address: address.to_string(),
            port: advertisement.port,
            url,
            version: advertisement.version,
        });
    }

    found.sort_by(|left, right| left.url.cmp(&right.url));
    Ok(found)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn scratch(name: &str) -> PathBuf {
        let directory =
            std::env::temp_dir().join(format!("drclick-lan-host-{name}-{}", uuid::Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        directory
    }

    #[test]
    fn a_fresh_installation_does_not_share_its_database() {
        let directory = scratch("fresh");

        assert_eq!(
            load_lan_host_settings(&directory),
            LanHostSettings {
                enabled: false,
                port: DEFAULT_LAN_HOST_PORT
            }
        );
    }

    #[test]
    fn settings_round_trip_through_disk() {
        let directory = scratch("round-trip");
        let settings = LanHostSettings {
            enabled: true,
            port: 48000,
        };

        save_lan_host_settings(&directory, &settings).unwrap();

        assert_eq!(load_lan_host_settings(&directory), settings);
        assert!(!lan_host_settings_path(&directory)
            .with_extension("json.tmp")
            .exists());
    }

    #[test]
    fn damaged_or_foreign_settings_mean_not_shared() {
        for json in [
            "{ broken",
            r#"{"schema_version":2,"enabled":true,"port":47850}"#,
            r#"{"schema_version":1,"enabled":true,"port":80}"#,
            r#"{"schema_version":1,"enabled":true,"port":47851}"#,
            r#"{"enabled":true,"port":47850}"#,
        ] {
            let directory = scratch("damaged");
            fs::write(lan_host_settings_path(&directory), json).unwrap();

            assert!(!load_lan_host_settings(&directory).enabled, "{json}");
        }
    }

    #[test]
    fn privileged_and_reserved_ports_are_refused() {
        assert!(validate_lan_host_port(80).is_err());
        assert!(validate_lan_host_port(1023).is_err());
        assert!(validate_lan_host_port(LAN_DISCOVERY_PORT).is_err());
        assert_eq!(validate_lan_host_port(1024), Ok(1024));
        assert_eq!(
            validate_lan_host_port(DEFAULT_LAN_HOST_PORT),
            Ok(DEFAULT_LAN_HOST_PORT)
        );
        assert!(save_lan_host_settings(
            &scratch("refused"),
            &LanHostSettings {
                enabled: true,
                port: 22
            }
        )
        .is_err());
    }

    #[test]
    fn only_private_and_link_local_ipv4_count_as_lan() {
        for private in [
            "10.0.0.5",
            "172.16.0.1",
            "172.31.255.1",
            "192.168.1.20",
            "169.254.3.4",
        ] {
            assert!(is_private_lan_ipv4(private.parse().unwrap()), "{private}");
        }
        for public in ["8.8.8.8", "172.32.0.1", "100.64.0.1", "193.168.1.1"] {
            assert!(!is_private_lan_ipv4(public.parse().unwrap()), "{public}");
        }
    }

    #[test]
    fn home_office_addresses_are_listed_first() {
        let entry = |address: &str| LanAddress {
            address: address.parse().unwrap(),
            interface: "eth".to_owned(),
        };
        let mut addresses = vec![
            entry("172.20.0.1"),
            entry("10.0.0.2"),
            entry("192.168.1.20"),
            entry("169.254.1.1"),
            entry("192.168.1.20"),
        ];

        sort_lan_addresses(&mut addresses);

        let ordered: Vec<String> = addresses.iter().map(|a| a.address.to_string()).collect();
        assert_eq!(
            ordered,
            ["192.168.1.20", "10.0.0.2", "172.20.0.1", "169.254.1.1"]
        );
    }

    #[test]
    fn advertisements_round_trip_and_reject_garbage() {
        let advertisement = LanHostAdvertisement::new("CABINET-PC", 47850, "0.3.0");

        assert_eq!(
            LanHostAdvertisement::decode(&advertisement.encode()),
            Some(advertisement)
        );
        assert_eq!(LanHostAdvertisement::decode(b"DRCLICK_HOST v1\n{}"), None);
        assert_eq!(LanHostAdvertisement::decode(b"hello"), None);
        let foreign = br#"DRCLICK_HOST v1
{"app":"Other","name":"x","port":47850,"version":"1"}"#;
        assert_eq!(LanHostAdvertisement::decode(foreign), None);
    }

    #[test]
    fn a_client_finds_a_responding_host() {
        let responder = LanDiscoveryResponder::spawn(
            "127.0.0.1:0".parse().unwrap(),
            LanHostAdvertisement::new("CABINET-PC", 47850, "0.3.0"),
        )
        .unwrap();

        let hosts =
            discover_lan_hosts(&[responder.local_address()], Duration::from_millis(800)).unwrap();

        assert_eq!(
            hosts,
            vec![DiscoveredLanHost {
                name: "CABINET-PC".to_owned(),
                address: "127.0.0.1".to_owned(),
                port: 47850,
                url: "http://127.0.0.1:47850/".to_owned(),
                version: "0.3.0".to_owned(),
            }]
        );
    }

    #[test]
    fn the_responder_ignores_anything_but_the_exact_request() {
        let responder = LanDiscoveryResponder::spawn(
            "127.0.0.1:0".parse().unwrap(),
            LanHostAdvertisement::new("CABINET-PC", 47850, "0.3.0"),
        )
        .unwrap();
        let client = UdpSocket::bind("127.0.0.1:0").unwrap();
        client
            .set_read_timeout(Some(Duration::from_millis(400)))
            .unwrap();

        client
            .send_to(b"DRCLICK_DISCOVER v2", responder.local_address())
            .unwrap();
        let mut buffer = [0_u8; 64];
        assert!(client.recv_from(&mut buffer).is_err());

        client
            .send_to(b"DRCLICK_DISCOVER v1\n", responder.local_address())
            .unwrap();
        assert!(client.recv_from(&mut buffer).is_ok());
    }

    #[test]
    fn discovery_without_any_host_returns_nothing() {
        let silent = UdpSocket::bind("127.0.0.1:0").unwrap();

        let hosts = discover_lan_hosts(&[silent.local_addr().unwrap()], Duration::from_millis(200))
            .unwrap();

        assert!(hosts.is_empty());
    }

    #[test]
    fn stopping_the_responder_releases_its_port() {
        let mut responder = LanDiscoveryResponder::spawn(
            "127.0.0.1:0".parse().unwrap(),
            LanHostAdvertisement::new("CABINET-PC", 47850, "0.3.0"),
        )
        .unwrap();
        let address = responder.local_address();

        responder.stop();

        assert!(UdpSocket::bind(address).is_ok());
    }

    #[test]
    fn the_limited_broadcast_is_always_a_discovery_target() {
        let targets = default_discovery_targets(LAN_DISCOVERY_PORT);

        assert_eq!(
            targets[0],
            SocketAddr::V4(SocketAddrV4::new(Ipv4Addr::BROADCAST, LAN_DISCOVERY_PORT))
        );
    }

    #[test]
    fn computer_names_are_sanitised() {
        assert_eq!(sanitize_name("CABINET\u{7}-PC"), "CABINET-PC");
        assert_eq!(sanitize_name(&"x".repeat(100)).len(), 63);
        assert!(!local_computer_name().is_empty());
    }
}
