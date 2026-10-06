//! Several Drclick PCs, one cabinet database, no Internet.
//!
//! - **Poste principal** (LAN host): the PC that owns the database in `Local`
//!   mode can additionally serve it to the cabinet LAN on a fixed port
//!   ([`DEFAULT_LAN_HOST_PORT`]). Its own window keeps using the loopback
//!   listener; a second supervised PHP listener binds `0.0.0.0:<port>`.
//! - **Poste secondaire** (LAN client): another PC switches to `Attach` mode
//!   against `http://<host>:<port>/` and becomes a thin client of that
//!   database. Every user still signs in with their own account on the host.
//!
//! See `docs/architecture/ADR-005-desktop-lan-host.md` for the trade-offs.

use std::{net::SocketAddr, sync::Mutex, time::Duration};

use drclick_runtime::{
    default_discovery_targets, discover_lan_hosts as discover_hosts, load_lan_host_settings,
    local_computer_name, private_lan_addresses, save_lan_host_settings, validate_lan_host_port,
    DiscoveredLanHost, LanDiscoveryResponder, LanHostAdvertisement, LanHostSettings,
    DEFAULT_LAN_HOST_PORT, LAN_DISCOVERY_PORT,
};
use serde::Serialize;
use tauri::{AppHandle, Manager};

use crate::{
    cloud_server_url, configuration_directory,
    connection::{persist_server_url, probe_server, validate_server_url, ServerProbe},
    local_runtime::LanHostHealth,
    runtime_mode::{persist_runtime_mode, resolve_runtime_mode, RuntimeMode},
    LocalRuntimeState,
};

/// How long a PC listens for hosts answering a discovery broadcast.
const DISCOVERY_TIMEOUT: Duration = Duration::from_millis(1500);

/// Delay between answering a mode-switch command and restarting, so the
/// page receives its answer and can say "Redémarrage…".
const RESTART_DELAY: Duration = Duration::from_millis(700);

/// Native state of the LAN host feature.
#[derive(Default)]
pub(crate) struct LanHostState {
    responder: Mutex<Option<LanDiscoveryResponder>>,
    last_error: Mutex<Option<String>>,
    /// Serialises enable/disable so two clicks (or a click during the
    /// startup resume) never race two listeners for the same port.
    operation: Mutex<()>,
}

impl LanHostState {
    fn set_error(&self, error: Option<String>) {
        if let Ok(mut current) = self.last_error.lock() {
            *current = error;
        }
    }

    fn stop_responder(&self) {
        if let Ok(mut responder) = self.responder.lock() {
            if let Some(mut responder) = responder.take() {
                responder.stop();
            }
        }
    }

    /// Answer discovery broadcasts. Best effort: a blocked UDP port only
    /// costs the one-click join; typing the address still works.
    fn start_responder(&self, port: u16, version: &str) {
        self.stop_responder();
        let bind = SocketAddr::from(([0, 0, 0, 0], LAN_DISCOVERY_PORT));
        let advertisement = LanHostAdvertisement::new(local_computer_name(), port, version);
        if let Ok(responder) = LanDiscoveryResponder::spawn(bind, advertisement) {
            if let Ok(mut current) = self.responder.lock() {
                *current = Some(responder);
            }
        }
    }

    pub(crate) fn shutdown(&self) {
        self.stop_responder();
    }
}

#[derive(Debug, Serialize)]
pub(crate) struct LanAddressView {
    address: String,
    interface: String,
    url: String,
}

#[derive(Debug, Serialize)]
pub(crate) struct LanHostStatus {
    /// Runtime mode of this PC: `local`, `attach`, `cloud`, or `damaged`.
    mode: &'static str,
    /// True when this PC owns its database and can share it.
    available: bool,
    /// The clinic's persisted choice.
    enabled: bool,
    running: bool,
    starting: bool,
    port: u16,
    default_port: u16,
    discovery_port: u16,
    computer_name: String,
    addresses: Vec<LanAddressView>,
    /// `http://<computer-name>:<port>/`, for networks where names resolve.
    name_url: Option<String>,
    error: Option<String>,
    /// In `attach` mode: the poste principal this PC uses.
    attached_to: Option<String>,
}

fn host_url(host: &str, port: u16) -> String {
    format!("http://{host}:{port}/")
}

/// A computer name usable as a URL host (letters, digits and hyphens).
fn name_url(name: &str, port: u16) -> Option<String> {
    let valid = !name.is_empty()
        && name.len() <= 63
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
        && !name.starts_with('-')
        && !name.ends_with('-');

    valid.then(|| host_url(&name.to_ascii_lowercase(), port))
}

fn health_error(health: &LanHostHealth) -> Option<String> {
    match health {
        LanHostHealth::Failed(code) => Some(format!(
            "Le partage réseau s’est arrêté ({code}). Désactivez-le puis réactivez-le."
        )),
        _ => None,
    }
}

pub(crate) fn current_status(app: &AppHandle) -> Result<LanHostStatus, String> {
    let directory = configuration_directory(app)?;
    let mode = resolve_runtime_mode(&directory, &cloud_server_url());
    let settings = load_lan_host_settings(&directory);
    let local = app.state::<LocalRuntimeState>();
    let lan = app.state::<LanHostState>();
    let runtime = local.running();

    let (mode_name, attached_to) = match &mode {
        Ok(mode @ RuntimeMode::Attach { url }) => (mode.as_str(), Some(url.to_string())),
        Ok(mode) => (mode.as_str(), None),
        Err(_) => ("damaged", None),
    };
    let available = matches!(mode, Ok(RuntimeMode::Local)) && runtime.is_some();
    let listener = runtime.as_ref().and_then(|runtime| runtime.lan_host());
    let port = listener.as_ref().map_or(settings.port, |(port, _)| *port);
    let running = matches!(listener, Some((_, LanHostHealth::Running)));
    let starting = matches!(listener, Some((_, LanHostHealth::Starting)));
    let error = listener
        .as_ref()
        .and_then(|(_, health)| health_error(health))
        .or_else(|| lan.last_error.lock().ok().and_then(|error| error.clone()));
    let computer_name = local_computer_name();

    Ok(LanHostStatus {
        mode: mode_name,
        available,
        enabled: settings.enabled,
        running,
        starting,
        port,
        default_port: DEFAULT_LAN_HOST_PORT,
        discovery_port: LAN_DISCOVERY_PORT,
        name_url: name_url(&computer_name, port),
        computer_name,
        addresses: private_lan_addresses()
            .into_iter()
            .map(|entry| LanAddressView {
                address: entry.address.to_string(),
                url: host_url(&entry.address.to_string(), port),
                interface: entry.interface,
            })
            .collect(),
        error,
        attached_to,
    })
}

/// Start the LAN listener and the discovery responder. Blocking.
fn start_sharing(app: &AppHandle, port: u16) -> Result<(), String> {
    let local = app.state::<LocalRuntimeState>();
    let lan = app.state::<LanHostState>();
    let runtime = local
        .running()
        .ok_or_else(|| "L’application locale n’est pas démarrée sur ce poste.".to_owned())?;

    match runtime.start_lan_host(port) {
        Ok(()) => {
            lan.start_responder(port, &app.package_info().version.to_string());
            lan.set_error(None);
            Ok(())
        }
        Err(error) => {
            lan.stop_responder();
            lan.set_error(Some(error.message.clone()));
            Err(error.message)
        }
    }
}

/// Re-open the LAN listener at startup when the clinic left it enabled.
pub(crate) fn resume_sharing_if_enabled(app: &AppHandle) {
    let Ok(directory) = configuration_directory(app) else {
        return;
    };
    let settings = load_lan_host_settings(&directory);
    if !settings.enabled {
        return;
    }

    let app = app.clone();
    let _ = std::thread::Builder::new()
        .name("drclick-lan-host-resume".to_owned())
        .spawn(move || {
            let lan = app.state::<LanHostState>();
            let Ok(_operation) = lan.operation.lock() else {
                return;
            };
            let _ = start_sharing(&app, settings.port);
        });
}

/// Restart Drclick shortly, from a background thread, so the caller's answer
/// reaches the page first. A restart is what gives a new mode a fresh
/// WebView2 environment (browser arguments are fixed per environment).
pub(crate) fn schedule_restart(app: &AppHandle) {
    let app = app.clone();
    let _ = std::thread::Builder::new()
        .name("drclick-mode-restart".to_owned())
        .spawn(move || {
            std::thread::sleep(RESTART_DELAY);
            app.request_restart();
        });
}

// ---------------------------------------------------------------------------
// Commands — poste principal
// ---------------------------------------------------------------------------

#[tauri::command]
pub(crate) fn lan_host_status(app: AppHandle) -> Result<LanHostStatus, String> {
    current_status(&app)
}

/// Turn sharing of this PC's database with the cabinet LAN on or off.
#[tauri::command]
pub(crate) async fn set_lan_host(
    app: AppHandle,
    enabled: bool,
    port: Option<u16>,
) -> Result<LanHostStatus, String> {
    let directory = configuration_directory(&app)?;
    if !matches!(
        resolve_runtime_mode(&directory, &cloud_server_url()),
        Ok(RuntimeMode::Local)
    ) {
        return Err(
            "Seul le poste qui détient les données du cabinet (mode autonome) peut les partager."
                .to_owned(),
        );
    }
    let current = load_lan_host_settings(&directory);
    let port = validate_lan_host_port(port.unwrap_or(current.port))?;

    let worker = app.clone();
    tauri::async_runtime::spawn_blocking(move || -> Result<(), String> {
        let lan = worker.state::<LanHostState>();
        let _operation = lan.operation.lock().map_err(|_| {
            "Le réglage du réseau local est indisponible. Redémarrez Drclick.".to_owned()
        })?;
        if enabled {
            start_sharing(&worker, port)?;
        } else {
            if let Some(runtime) = worker.state::<LocalRuntimeState>().running() {
                runtime.stop_lan_host();
            }
            lan.stop_responder();
            lan.set_error(None);
        }

        save_lan_host_settings(&directory, &LanHostSettings { enabled, port })
            .map_err(|_| "Impossible d’enregistrer le réglage du réseau local.".to_owned())
    })
    .await
    .map_err(|_| "Le changement de réglage a été interrompu.".to_owned())??;

    current_status(&app)
}

/// Ask Windows (through the UAC prompt) to admit the LAN host and discovery
/// ports on private networks. Without it, Windows shows its own firewall
/// prompt the first time PHP listens, which a non-administrator cannot accept.
#[tauri::command]
pub(crate) async fn open_lan_firewall(app: AppHandle) -> Result<bool, String> {
    let directory = configuration_directory(&app)?;
    let port = load_lan_host_settings(&directory).port;

    tauri::async_runtime::spawn_blocking(move || add_firewall_rules(port))
        .await
        .map_err(|_| "L’opération a été interrompue.".to_owned())?
}

/// The `netsh` script run elevated. Rule names are fixed, so running it again
/// replaces the rules instead of piling up duplicates.
#[cfg_attr(not(windows), allow(dead_code))]
pub(crate) fn firewall_script(port: u16) -> String {
    format!(
        "netsh advfirewall firewall delete rule name=Drclick-PostePrincipal-TCP >nul 2>&1 & \
         netsh advfirewall firewall delete rule name=Drclick-Decouverte-UDP >nul 2>&1 & \
         netsh advfirewall firewall add rule name=Drclick-PostePrincipal-TCP dir=in action=allow \
         protocol=TCP localport={port} profile=private,domain & \
         netsh advfirewall firewall add rule name=Drclick-Decouverte-UDP dir=in action=allow \
         protocol=UDP localport={LAN_DISCOVERY_PORT} profile=private,domain"
    )
}

#[cfg(windows)]
fn add_firewall_rules(port: u16) -> Result<bool, String> {
    use std::os::windows::process::CommandExt;

    const CREATE_NO_WINDOW: u32 = 0x0800_0000;

    let script = firewall_script(port).replace('\'', "''");
    let status = std::process::Command::new("powershell.exe")
        .args([
            "-NoProfile",
            "-NonInteractive",
            "-Command",
            &format!(
                "Start-Process -FilePath cmd.exe -ArgumentList '/c {script}' \
                 -Verb RunAs -WindowStyle Hidden -Wait"
            ),
        ])
        .creation_flags(CREATE_NO_WINDOW)
        .status()
        .map_err(|_| "Impossible d’ouvrir le pare-feu Windows.".to_owned())?;

    if status.success() {
        Ok(true)
    } else {
        Err("Le pare-feu n’a pas été modifié (autorisation administrateur refusée).".to_owned())
    }
}

#[cfg(not(windows))]
fn add_firewall_rules(_port: u16) -> Result<bool, String> {
    Err("Le réglage automatique du pare-feu n’existe que sous Windows.".to_owned())
}

// ---------------------------------------------------------------------------
// Commands — poste secondaire
// ---------------------------------------------------------------------------

/// Look for Drclick postes principaux on the LAN.
#[tauri::command]
pub(crate) async fn discover_lan_hosts() -> Result<Vec<DiscoveredLanHost>, String> {
    tauri::async_runtime::spawn_blocking(|| {
        let own: Vec<String> = private_lan_addresses()
            .into_iter()
            .map(|entry| entry.address.to_string())
            .collect();
        discover_hosts(
            &default_discovery_targets(LAN_DISCOVERY_PORT),
            DISCOVERY_TIMEOUT,
        )
        .map(|hosts| {
            hosts
                .into_iter()
                .filter(|host| !own.contains(&host.address) && host.address != "127.0.0.1")
                .collect()
        })
        .map_err(|_| "La recherche des postes du cabinet a échoué.".to_owned())
    })
    .await
    .map_err(|_| "La recherche a été interrompue.".to_owned())?
}

/// Make this PC a *poste secondaire* of the host at `url`, then restart.
#[tauri::command]
pub(crate) async fn connect_to_lan_host(
    app: AppHandle,
    url: String,
) -> Result<ServerProbe, String> {
    let url = validate_server_url(&url)?;
    if url.host_str() == cloud_server_url().host_str() {
        return Err(
            "Cette adresse est celle du service en ligne, pas d’un poste du cabinet.".to_owned(),
        );
    }
    if let Some(runtime) = app.state::<LocalRuntimeState>().running() {
        if runtime.lan_host().is_some() {
            return Err(
                "Ce poste partage déjà ses propres données. Désactivez d’abord « Poste principal »."
                    .to_owned(),
            );
        }
    }

    let probe = probe_server(&url).await.map_err(|error| {
        if url.scheme() == "http" {
            lan_probe_error(&error)
        } else {
            error
        }
    })?;

    let directory = configuration_directory(&app)?;
    persist_runtime_mode(&directory, &RuntimeMode::Attach { url: url.clone() })?;
    persist_server_url(&app, &url)?;
    schedule_restart(&app);

    Ok(probe)
}

/// The generic probe error mentions HTTPS certificates, which means nothing
/// for a poste principal on the LAN.
fn lan_probe_error(error: &str) -> String {
    if error.starts_with("Le serveur ne répond pas") {
        "Le poste principal ne répond pas. Vérifiez qu’il est allumé, que Drclick y est ouvert \
         avec le partage actif (Configuration › Réseau local) et que le pare-feu l’autorise."
            .to_owned()
    } else {
        error.to_owned()
    }
}

/// Go back to this PC's own database, then restart.
#[tauri::command]
pub(crate) fn use_local_mode(app: AppHandle) -> Result<bool, String> {
    let directory = configuration_directory(&app)?;
    persist_runtime_mode(&directory, &RuntimeMode::Local)?;
    schedule_restart(&app);

    Ok(true)
}

/// The origin to treat as secure in WebView2 so `getUserMedia` (dictation)
/// works on a poste secondaire, whose host is reached over plain LAN HTTP.
pub(crate) fn insecure_lan_origin(mode: &RuntimeMode) -> Option<String> {
    match mode {
        RuntimeMode::Attach { url } if url.scheme() == "http" => {
            Some(url.origin().ascii_serialization())
        }
        _ => None,
    }
}

/// WebView2 browser arguments for the main window.
///
/// Tauri replaces its own defaults when arguments are supplied, so they are
/// repeated here first. Media capture is granted without the WebView2 prompt
/// (a denied prompt was persisted per profile and silently broke dictation);
/// navigation stays locked to the owning origin by `NavigationPolicy`.
#[cfg_attr(not(windows), allow(dead_code))]
pub(crate) fn webview_browser_arguments(mode: &RuntimeMode) -> String {
    let mut arguments = String::from(
        "--disable-features=msWebOOUI,msPdfOOUI,msSmartScreenProtection \
         --use-fake-ui-for-media-stream",
    );
    if let Some(origin) = insecure_lan_origin(mode) {
        arguments.push_str(" --unsafely-treat-insecure-origin-as-secure=");
        arguments.push_str(&origin);
    }
    arguments
}

/// Browser arguments are fixed for a WebView2 data directory while any
/// browser process uses it, and a restart can overlap the previous process
/// for a moment. A poste secondaire therefore keeps its own data directory
/// per host origin; local and cloud modes keep Tauri's default one.
#[cfg_attr(not(windows), allow(dead_code))]
pub(crate) fn webview_data_directory_name(mode: &RuntimeMode) -> Option<String> {
    use sha2::{Digest, Sha256};

    let origin = insecure_lan_origin(mode)?;
    let digest = Sha256::digest(origin.as_bytes());
    let suffix: String = digest[..6]
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect();

    Some(format!("EBWebView-lan-{suffix}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    use url::Url;

    fn attach(url: &str) -> RuntimeMode {
        RuntimeMode::Attach {
            url: Url::parse(url).unwrap(),
        }
    }

    #[test]
    fn only_a_lan_http_host_is_treated_as_a_secure_origin() {
        assert_eq!(
            insecure_lan_origin(&attach("http://192.168.1.20:47850/")),
            Some("http://192.168.1.20:47850".to_owned())
        );
        assert_eq!(
            insecure_lan_origin(&attach("https://hub.example.test/")),
            None
        );
        assert_eq!(insecure_lan_origin(&RuntimeMode::Local), None);
    }

    #[test]
    fn browser_arguments_keep_the_tauri_defaults_and_grant_the_microphone() {
        let local = webview_browser_arguments(&RuntimeMode::Local);
        assert!(local.starts_with("--disable-features=msWebOOUI,msPdfOOUI,msSmartScreenProtection"));
        assert!(local.contains("--use-fake-ui-for-media-stream"));
        assert!(!local.contains("unsafely-treat-insecure-origin-as-secure"));

        let client = webview_browser_arguments(&attach("http://192.168.1.20:47850/"));
        assert!(client
            .ends_with(" --unsafely-treat-insecure-origin-as-secure=http://192.168.1.20:47850"));
    }

    #[test]
    fn each_lan_host_gets_its_own_webview_profile() {
        let first = webview_data_directory_name(&attach("http://192.168.1.20:47850/")).unwrap();
        let second = webview_data_directory_name(&attach("http://192.168.1.21:47850/")).unwrap();

        assert!(first.starts_with("EBWebView-lan-"));
        assert_ne!(first, second);
        assert_eq!(webview_data_directory_name(&RuntimeMode::Local), None);
    }

    #[test]
    fn computer_names_become_urls_only_when_they_are_valid_hosts() {
        assert_eq!(
            name_url("CABINET-PC", 47850),
            Some("http://cabinet-pc:47850/".to_owned())
        );
        assert_eq!(name_url("Poste principal", 47850), None);
        assert_eq!(name_url("-PC", 47850), None);
        assert_eq!(name_url("", 47850), None);
    }

    #[test]
    fn the_firewall_script_opens_only_the_two_lan_ports_on_private_networks() {
        let script = firewall_script(47850);

        assert!(script.contains("protocol=TCP localport=47850 profile=private,domain"));
        assert!(script.contains("protocol=UDP localport=47851 profile=private,domain"));
        assert!(!script.contains("profile=public"));
        assert!(!script.contains('\''));
        assert_eq!(script.matches("delete rule").count(), 2);
    }

    #[test]
    fn an_unreachable_poste_principal_is_explained_without_https_jargon() {
        let message =
            lan_probe_error("Le serveur ne répond pas ou son certificat HTTPS n’est pas valide.");

        assert!(message.contains("poste principal"));
        assert!(!message.contains("HTTPS"));
        assert_eq!(
            lan_probe_error("Cette adresse ne répond pas comme un serveur Drclick."),
            "Cette adresse ne répond pas comme un serveur Drclick."
        );
    }

    #[test]
    fn a_failed_listener_is_explained_in_french() {
        assert!(
            health_error(&LanHostHealth::Failed("process_retries_exhausted"))
                .unwrap()
                .contains("partage réseau")
        );
        assert_eq!(health_error(&LanHostHealth::Running), None);
    }
}
