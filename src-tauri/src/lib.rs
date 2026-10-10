// Drclick Desktop — local-first shell
//
// Architecture (2026): the desktop application owns the clinic's data. On a
// fresh installation the shell supervises the bundled PHP/Laravel runtime
// against a SQLite database under the per-install application-data directory,
// and the webview loads that loopback origin. Every clinical workflow works
// with the machine offline; nothing is stored on the hosted service.
//
// Three ownership modes are supported (see `runtime_mode`):
//   - Local  — this PC owns the database (default for a new installation)
//   - Attach — another Drclick PC or Cabinet Hub on the LAN owns it, for
//              cabinets where several machines must share one record set
//   - Cloud  — the hosted control plane owns it (pre-2026 thin-client
//              behaviour, preserved for existing installations)
//
// This reverses the 2025 thin-client change, which removed the bundled runtime
// and made internet connectivity mandatory. The supervision core it depends on
// lives in the `drclick-runtime` crate and is wired back in by `local_runtime`.
//
// Kept from the thin client:
//   - System tray + hide-to-tray behaviour (desktop_behavior.rs)
//   - Signed updater (updates.rs)
//   - NavigationPolicy, now covering the loopback origin as well
//
// Several PCs, one cabinet (2026-10, ADR-005): the PC that owns the data can
// also serve it to the cabinet LAN ("poste principal"), and the other PCs
// attach to it over plain LAN HTTP ("poste secondaire"). See `lan`.

mod connection;
mod desktop_behavior;
mod lan;
mod local_runtime;
mod runtime_mode;
mod updates;

use std::{
    path::PathBuf,
    sync::{Arc, Mutex, RwLock},
};

use serde::Serialize;
use tauri::{
    plugin::{Builder as PluginBuilder, TauriPlugin},
    webview::WebviewWindowBuilder,
    AppHandle, Manager, RunEvent, State, WebviewUrl,
};
use tauri_plugin_opener::OpenerExt;
use url::Url;

use crate::connection::{persist_server_url, probe_server, validate_server_url, ServerProbe};
use crate::desktop_behavior::{install_system_tray, show_desktop_window, DesktopBehaviorState};
use crate::local_runtime::{LocalRuntime, LocalRuntimeError};
use crate::runtime_mode::{persist_runtime_mode, resolve_runtime_mode, RuntimeMode};
use crate::updates::SignedUpdaterState;

// ---------------------------------------------------------------------------
// Server URL configuration
// ---------------------------------------------------------------------------

/// Fallback hosted origin, used when the build supplies no override.
///
/// Kept as the shipped default so an unconfigured checkout still builds and
/// points at the current control plane.
const DEFAULT_CLOUD_SERVER_URL: &str = "https://drclickdz.com/";

/// Hosted Drclick control-plane origin. Only used in `Cloud` mode and as the
/// "use the Cloud" option on the connection page.
///
/// The value is a *deployment input*, not a source constant: `build.rs` reads
/// `DRCLICK_CLOUD_SERVER_URL`, validates it, and normalises it into the binary,
/// exactly as it already does for `MEDISMART_UPDATER_ENDPOINT`. Moving the
/// control plane to another host, standing up a staging plane, or building for
/// a reseller is therefore a build-environment change rather than a code patch.
const CLOUD_SERVER_URL: &str = match option_env!("DRCLICK_CLOUD_SERVER_URL") {
    Some(url) => url,
    None => DEFAULT_CLOUD_SERVER_URL,
};

/// Shown when the stored runtime mode exists but cannot be understood. The
/// clinic is asked where its data lives rather than being dropped onto a new,
/// empty local database.
const DAMAGED_CONFIGURATION_MESSAGE: &str =
    "La configuration de ce poste est illisible. Indiquez où se trouvent les données du cabinet      avant de continuer, afin de ne pas créer une base vide par erreur.";

fn cloud_server_url() -> Url {
    Url::parse(CLOUD_SERVER_URL).expect("CLOUD_SERVER_URL is validated by build.rs")
}

/// `<app-local-data>/config`, where runtime mode and installation identity live.
fn configuration_directory(app: &AppHandle) -> Result<PathBuf, String> {
    app.path()
        .app_local_data_dir()
        .map(|root| root.join("config"))
        .map_err(|_| "Impossible d’ouvrir le dossier de configuration.".to_owned())
}

// ---------------------------------------------------------------------------
// Local runtime state
// ---------------------------------------------------------------------------

/// Holds the supervised local runtime so the exit path can stop PHP, the queue
/// worker, and the scheduler with the window.
#[derive(Default)]
struct LocalRuntimeState {
    running: Mutex<Option<Arc<LocalRuntime>>>,
    /// Set when local mode was selected but could not start; surfaced to the
    /// connection page so the clinic sees why, instead of a blank window.
    failure: Mutex<Option<LocalRuntimeError>>,
}

impl LocalRuntimeState {
    fn shutdown(&self) {
        if let Some(runtime) = self.running() {
            runtime.shutdown();
        }
    }

    /// The supervised runtime, when this PC owns its data and it started.
    fn running(&self) -> Option<Arc<LocalRuntime>> {
        self.running.lock().ok().and_then(|running| running.clone())
    }
}

#[derive(Serialize)]
struct RuntimeModeStatus {
    mode: &'static str,
    url: Option<String>,
    local_error: Option<String>,
}

#[tauri::command]
fn runtime_mode_status(
    app: AppHandle,
    local: State<'_, LocalRuntimeState>,
) -> Result<RuntimeModeStatus, String> {
    let directory = configuration_directory(&app)?;
    let Ok(mode) = resolve_runtime_mode(&directory, &cloud_server_url()) else {
        return Ok(RuntimeModeStatus {
            mode: "damaged",
            url: None,
            local_error: Some(DAMAGED_CONFIGURATION_MESSAGE.to_owned()),
        });
    };
    let url = match &mode {
        RuntimeMode::Local => local
            .running
            .lock()
            .ok()
            .and_then(|running| running.as_ref().map(|runtime| runtime.url().to_string())),
        other => other.remote_url().map(|url| url.to_string()),
    };
    let local_error = local
        .failure
        .lock()
        .ok()
        .and_then(|failure| failure.as_ref().map(|error| error.message.clone()));

    Ok(RuntimeModeStatus {
        mode: mode.as_str(),
        url,
        local_error,
    })
}

/// Hand data ownership back to this PC. The database owner cannot be swapped
/// underneath a running session, so Drclick restarts into local mode.
#[tauri::command]
fn configure_local_mode(app: AppHandle) -> Result<bool, String> {
    let directory = configuration_directory(&app)?;
    persist_runtime_mode(&directory, &RuntimeMode::Local)?;
    lan::schedule_restart(&app);

    Ok(true)
}

/// "Utiliser ce PC hors ligne", offered by the online cabinet page while
/// this PC still opens the online service. The website may ask, but only the
/// person at the PC decides: a native confirmation the page cannot answer,
/// then Drclick restarts on this PC's own database (empty until the doctor
/// brings the cabinet's records back). Does nothing in any other mode.
#[tauri::command]
async fn request_offline_mode(app: AppHandle) -> Result<bool, String> {
    use tauri_plugin_dialog::{DialogExt, MessageDialogButtons, MessageDialogKind};

    let directory = configuration_directory(&app)?;
    let Ok(RuntimeMode::Cloud { .. }) = resolve_runtime_mode(&directory, &cloud_server_url())
    else {
        return Ok(false);
    };

    let dialog_app = app.clone();
    let confirmed = tauri::async_runtime::spawn_blocking(move || {
        dialog_app
            .dialog()
            .message(
                "Drclick va redémarrer et enregistrer les dossiers sur ce PC, sans Internet.\n\n\
                 Au démarrage, choisissez « Cabinet existant » et connectez-vous avec le compte \
                 du médecin titulaire en laissant cochée « Récupérer les dossiers enregistrés \
                 en ligne » : vos patients seront copiés sur ce PC.",
            )
            .title("Utiliser ce PC hors ligne")
            .kind(MessageDialogKind::Info)
            .buttons(MessageDialogButtons::OkCancelCustom(
                "Continuer".to_owned(),
                "Annuler".to_owned(),
            ))
            .blocking_show()
    })
    .await
    .map_err(|_| "La confirmation a été interrompue.".to_owned())?;

    if !confirmed {
        return Ok(false);
    }

    persist_runtime_mode(&directory, &RuntimeMode::Local)?;
    lan::schedule_restart(&app);

    Ok(true)
}

/// Native folder picker for the local backup destination. Only the PC that
/// owns the data runs backups, so only the loopback origin may call this.
#[tauri::command]
async fn pick_backup_folder(app: AppHandle) -> Result<Option<String>, String> {
    use tauri_plugin_dialog::DialogExt;

    tauri::async_runtime::spawn_blocking(move || {
        app.dialog()
            .file()
            .set_title("Choisir le dossier des sauvegardes Drclick")
            .blocking_pick_folder()
            .and_then(|folder| folder.into_path().ok())
            .map(|folder| folder.to_string_lossy().into_owned())
    })
    .await
    .map_err(|_| "La sélection du dossier a été interrompue.".to_owned())
}

#[tauri::command]
async fn probe_server_connection(url: String) -> Result<ServerProbe, String> {
    let url = validate_server_url(&url)?;

    probe_server(&url).await
}

/// Point this PC at a machine that owns the database — a Cabinet Hub, another
/// Drclick PC running locally, or the hosted service.
#[tauri::command]
async fn configure_server_connection(
    app: AppHandle,
    policy: State<'_, NavigationPolicy>,
    url: String,
) -> Result<ServerProbe, String> {
    let url = validate_server_url(&url)?;
    let probe = probe_server(&url).await?;

    let cloud = cloud_server_url();
    let mode = if url.host_str() == cloud.host_str() {
        RuntimeMode::Cloud { url: url.clone() }
    } else {
        RuntimeMode::Attach { url: url.clone() }
    };

    let directory = configuration_directory(&app)?;
    persist_runtime_mode(&directory, &mode)?;
    // Kept in step so a downgrade to a thin-client build still finds its origin.
    persist_server_url(&app, &url)?;

    // A poste principal reached over LAN HTTP needs a WebView2 environment
    // that treats its origin as secure (microphone), which only a restart
    // provides. The connection page shows "Redémarrage…" meanwhile.
    if lan::insecure_lan_origin(&mode).is_some() {
        lan::schedule_restart(&app);
    } else {
        policy.set_server_url(url);
    }

    Ok(probe)
}

// ---------------------------------------------------------------------------
// NavigationPolicy
// ---------------------------------------------------------------------------

/// Holds the origin the webview is allowed to stay on and decides which
/// navigations may proceed.
///
/// Rules:
///   - Allow:   the configured origin and all its sub-paths. In local mode that
///     is `http://127.0.0.1:<port>`; otherwise an HTTPS server origin.
///   - Allow:   tauri://, asset://, about: (internal Tauri schemes)
///   - Block:   everything else — external http(s) links are opened in the
///     system browser by the on_navigation handler instead
#[derive(Clone, Default)]
struct NavigationPolicy {
    /// The origin (scheme + host + optional port). Set once on startup.
    server_origin: Arc<RwLock<Option<Url>>>,
}

impl NavigationPolicy {
    fn set_server_url(&self, url: Url) {
        if let Ok(mut current) = self.server_origin.write() {
            *current = Some(url);
        }
    }

    /// Returns true if the webview is allowed to navigate to `url` directly.
    /// External HTTPS links that are not on the server origin are NOT allowed
    /// here; the caller opens them in the system browser instead.
    fn allows(&self, url: &Url) -> bool {
        // Always allow internal Tauri / asset schemes used by the offline page
        if matches!(url.scheme(), "tauri" | "asset" | "about")
            || matches!(url.host_str(), Some("tauri.localhost" | "asset.localhost"))
        {
            return true;
        }

        let origin = match self.server_origin.read().ok().and_then(|o| o.clone()) {
            Some(o) => o,
            None => return false,
        };

        // Must match scheme + host exactly; port must also match (None == default)
        url.scheme() == origin.scheme()
            && url.host() == origin.host()
            && url.port() == origin.port()
            && url.username().is_empty()
            && url.password().is_none()
    }

    /// True when `url` is on the owning web origin itself (not an internal
    /// Tauri scheme). Used to scope native permission grants.
    #[cfg_attr(not(windows), allow(dead_code))]
    fn allows_origin(&self, url: &Url) -> bool {
        matches!(url.scheme(), "http" | "https")
            && self.allows(url)
            && !matches!(url.host_str(), Some("tauri.localhost" | "asset.localhost"))
    }

    /// Returns true if the URL is an external HTTPS link on a different origin
    /// that should be opened in the system browser rather than allowed in-app.
    fn is_external_link(&self, url: &Url) -> bool {
        if url.scheme() != "https" {
            return false;
        }
        !self.allows(url)
    }
}

// ---------------------------------------------------------------------------
// Tauri setup
// ---------------------------------------------------------------------------

pub fn run() {
    let navigation_policy = NavigationPolicy::default();
    let policy_for_guard = navigation_policy.clone();
    let policy_for_commands = navigation_policy.clone();

    let application = tauri::Builder::default()
        .plugin(
            tauri_plugin_opener::Builder::new()
                .open_js_links_on_click(false)
                .build(),
        )
        .plugin(tauri_plugin_single_instance::init(
            |app, _arguments, _working_directory| {
                show_desktop_window(app);
            },
        ))
        // Navigation guard: allow the owning origin + tauri/asset schemes;
        // open external HTTPS links in the system browser.
        .plugin(navigation_guard(policy_for_guard))
        .plugin(tauri_plugin_dialog::init())
        .manage(DesktopBehaviorState::default())
        .manage(SignedUpdaterState::compiled())
        .manage(LocalRuntimeState::default())
        .manage(lan::LanHostState::default())
        .manage(policy_for_commands)
        .invoke_handler(tauri::generate_handler![
            probe_server_connection,
            configure_server_connection,
            configure_local_mode,
            runtime_mode_status,
            request_offline_mode,
            pick_backup_folder,
            lan::lan_host_status,
            lan::set_lan_host,
            lan::open_lan_firewall,
            lan::discover_lan_hosts,
            lan::connect_to_lan_host,
            lan::use_local_mode,
            updates::signed_updater_status,
            updates::check_for_signed_update,
            updates::install_signed_update
        ])
        .setup(move |app| {
            if let Some(plugin) = updates::configured_plugin() {
                app.handle().plugin(plugin)?;
            }

            install_system_tray(app.handle())?;

            let handle = app.handle().clone();
            let directory = configuration_directory(&handle)
                .map_err(|error| -> Box<dyn std::error::Error> { error.into() })?;
            // A damaged configuration is presented to the clinic, never
            // silently replaced with the default.
            let mode = match resolve_runtime_mode(&directory, &cloud_server_url()) {
                Ok(mode) => mode,
                Err(_) => {
                    let state = handle.state::<LocalRuntimeState>();
                    if let Ok(mut failure) = state.failure.lock() {
                        *failure = Some(LocalRuntimeError::damaged_configuration());
                    }
                    build_main_window(app, None, &RuntimeMode::Local)?;

                    return Ok(());
                }
            };

            // Local mode opens the window at once on the bundled page, which
            // shows that Drclick is starting and moves to the cabinet as soon
            // as the local runtime is ready (or shows why it is not). Starting
            // it can take a while; until then no window at all appeared, so a
            // restart looked like Drclick had closed for good.
            if mode.is_local() {
                build_main_window(app, None, &mode)?;
                start_local_runtime_in_background(handle, navigation_policy.clone());

                return Ok(());
            }

            let resolved = mode.remote_url().cloned();

            match resolved {
                Some(url) => {
                    navigation_policy.set_server_url(url.clone());
                    build_main_window(app, Some(url), &mode)?;
                }
                None => {
                    // No usable origin. The bundled connection page explains the
                    // problem and offers Cloud / Hub alternatives.
                    build_main_window(app, None, &mode)?;
                }
            }

            Ok(())
        })
        .on_window_event(|window, event| {
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                let behavior = window.app_handle().state::<DesktopBehaviorState>();
                if behavior.should_hide_on_close(window.label()) {
                    api.prevent_close();
                    let _ = window.hide();
                }
            }
        })
        .build(tauri::generate_context!())
        .expect("failed to build the Drclick desktop shell");

    application.run(|app, event| {
        if matches!(event, RunEvent::ExitRequested { .. } | RunEvent::Exit) {
            // Stop PHP, the queue worker, and the scheduler with the window so
            // no orphan process keeps the SQLite database open.
            app.state::<lan::LanHostState>().shutdown();
            app.state::<LocalRuntimeState>().shutdown();
        }
    });
}

/// Start the bundled Laravel runtime off the main thread. The connection page
/// polls `runtime_mode_status`: the loopback URL appears there once the
/// navigation policy allows it, a failure as `local_error`.
fn start_local_runtime_in_background(handle: AppHandle, navigation_policy: NavigationPolicy) {
    let spawned = std::thread::Builder::new()
        .name("drclick-local-startup".to_owned())
        .spawn({
            let handle = handle.clone();
            move || {
                let state = handle.state::<LocalRuntimeState>();
                match local_runtime::start(&handle) {
                    Ok(runtime) => {
                        navigation_policy.set_server_url(runtime.url().clone());
                        if let Ok(mut running) = state.running.lock() {
                            *running = Some(Arc::new(runtime));
                        }
                        // Re-open the cabinet LAN listener in the background
                        // when this PC is the poste principal.
                        lan::resume_sharing_if_enabled(&handle);
                    }
                    Err(error) => {
                        // Never silently fall back to the hosted service: that
                        // would move a clinic's data off the machine without
                        // consent. Show the failure and let them choose.
                        if let Ok(mut failure) = state.failure.lock() {
                            *failure = Some(error);
                        }
                    }
                }
            }
        });

    if spawned.is_err() {
        let state = handle.state::<LocalRuntimeState>();
        if let Ok(mut failure) = state.failure.lock() {
            *failure = Some(LocalRuntimeError::new(
                "local_runtime_start_failed",
                "Drclick n’a pas pu lancer l’application locale.",
            ));
        };
    }
}

/// Build the single main application window.
///
/// In local mode the supervisor has already confirmed the application is
/// healthy, so the window opens straight onto the loopback origin. Otherwise it
/// loads the bundled `index.html`, which probes the remote origin and offers
/// the Cloud / Hub connection choices.
fn build_main_window(
    app: &mut tauri::App,
    origin: Option<Url>,
    mode: &RuntimeMode,
) -> tauri::Result<()> {
    let local_failure = app
        .handle()
        .state::<LocalRuntimeState>()
        .failure
        .lock()
        .ok()
        .and_then(|failure| failure.as_ref().map(|error| error.message.clone()));

    let initial_url = match (&origin, mode) {
        // A healthy local runtime: no loader page, no probe, no flash.
        (Some(url), RuntimeMode::Local) => WebviewUrl::External(url.clone()),
        _ => WebviewUrl::App("index.html".into()),
    };

    #[allow(unused_mut)]
    let mut builder = WebviewWindowBuilder::new(app, "main", initial_url);

    // Microphone (dictation) and, on a poste secondaire, a secure context for
    // its plain-HTTP LAN origin. WebView2 fixes these per environment, so
    // they are chosen here, before the webview exists.
    #[cfg(windows)]
    {
        builder = builder.additional_browser_args(&lan::webview_browser_arguments(mode));
        if let Some(name) = lan::webview_data_directory_name(mode) {
            if let Ok(root) = app.path().app_local_data_dir() {
                builder = builder.data_directory(root.join(name));
            }
        }
    }

    let window = builder
        .title("Drclick")
        .inner_size(1440.0, 900.0)
        .min_inner_size(1100.0, 720.0)
        .center()
        .resizable(true)
        .maximizable(true)
        // Inject context so the loader page knows what it is connecting to.
        .initialization_script(format!(
            "window.__DRCLICK_SERVER_URL = {}; \
             window.__DRCLICK_CLOUD_SERVER_URL = {}; \
             window.__DRCLICK_RUNTIME_MODE = {}; \
             window.__DRCLICK_LOCAL_ERROR = {};",
            serde_json::to_string(&origin.as_ref().map(Url::to_string)).unwrap_or_default(),
            serde_json::to_string(CLOUD_SERVER_URL).unwrap_or_default(),
            serde_json::to_string(mode.as_str()).unwrap_or_default(),
            serde_json::to_string(&local_failure).unwrap_or_default(),
        ))
        .build()?;

    #[cfg(windows)]
    grant_microphone_to_owning_origin(&window, app.state::<NavigationPolicy>().inner().clone());
    #[cfg(not(windows))]
    let _ = window;

    Ok(())
}

/// Answer WebView2's microphone permission request for the origin that owns
/// this session, instead of leaving it to a prompt whose "Bloquer" answer is
/// remembered per profile and silently breaks dictation. Every other
/// permission keeps WebView2's default handling.
#[cfg(windows)]
fn grant_microphone_to_owning_origin(window: &tauri::WebviewWindow, policy: NavigationPolicy) {
    let _ = window.with_webview(move |webview| unsafe {
        use webview2_com::{
            take_pwstr, Microsoft::Web::WebView2::Win32::*, PermissionRequestedEventHandler,
        };

        let Ok(core) = webview.controller().CoreWebView2() else {
            return;
        };
        let handler = PermissionRequestedEventHandler::create(Box::new(move |_, args| {
            let Some(args) = args else {
                return Ok(());
            };
            let mut kind = COREWEBVIEW2_PERMISSION_KIND::default();
            args.PermissionKind(&mut kind)?;
            if kind != COREWEBVIEW2_PERMISSION_KIND_MICROPHONE {
                return Ok(());
            }
            let mut uri = windows_core::PWSTR::null();
            args.Uri(&mut uri)?;
            let uri = take_pwstr(uri);
            if Url::parse(&uri).is_ok_and(|url| policy.allows_origin(&url)) {
                args.SetState(COREWEBVIEW2_PERMISSION_STATE_ALLOW)?;
            }
            Ok(())
        }));
        let mut token = 0_i64;
        let _ = core.add_PermissionRequested(&handler, &mut token);
    });
}

/// Tauri plugin that enforces the NavigationPolicy and opens external links in
/// the system browser.
fn navigation_guard(policy: NavigationPolicy) -> TauriPlugin<tauri::Wry> {
    PluginBuilder::new("drclick-navigation-guard")
        .on_navigation(move |webview, url| {
            if policy.allows(url) {
                return true;
            }
            // External HTTPS link on a different origin → open in system browser
            if policy.is_external_link(url) {
                let url_str = url.to_string();
                let app = webview.app_handle().clone();
                tauri::async_runtime::spawn(async move {
                    let _ = app.opener().open_url(&url_str, None::<&str>);
                });
            }
            // Block in-webview navigation for anything not on the owning origin
            false
        })
        .build()
}

// ---------------------------------------------------------------------------
// Unit tests
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;

    fn make_policy(server_url: &str) -> NavigationPolicy {
        let policy = NavigationPolicy::default();
        policy.set_server_url(Url::parse(server_url).unwrap());
        policy
    }

    #[test]
    fn server_origin_and_subpaths_are_allowed() {
        let policy = make_policy("https://app.drclick.dz");

        assert!(policy.allows(&Url::parse("https://app.drclick.dz").unwrap()));
        assert!(policy.allows(&Url::parse("https://app.drclick.dz/").unwrap()));
        assert!(policy.allows(&Url::parse("https://app.drclick.dz/login").unwrap()));
        assert!(
            policy.allows(&Url::parse("https://app.drclick.dz/patients/123/consultation").unwrap())
        );
    }

    #[test]
    fn different_origin_is_blocked() {
        let policy = make_policy("https://app.drclick.dz");

        // Different host
        assert!(!policy.allows(&Url::parse("https://evil.example.com/").unwrap()));
        // Different scheme
        assert!(!policy.allows(&Url::parse("http://app.drclick.dz/").unwrap()));
        // Subdomain is NOT the same origin
        assert!(!policy.allows(&Url::parse("https://sub.app.drclick.dz/").unwrap()));
        // Port mismatch
        assert!(!policy.allows(&Url::parse("https://app.drclick.dz:8443/").unwrap()));
    }

    #[test]
    fn credentials_in_url_are_always_blocked() {
        let policy = make_policy("https://app.drclick.dz");

        assert!(!policy.allows(&Url::parse("https://user:pass@app.drclick.dz/").unwrap()));
    }

    #[test]
    fn tauri_and_asset_schemes_are_always_allowed() {
        let policy = make_policy("https://app.drclick.dz");

        assert!(policy.allows(&Url::parse("tauri://localhost/").unwrap()));
        assert!(policy.allows(&Url::parse("asset://localhost/").unwrap()));
        assert!(policy.allows(&Url::parse("about:blank").unwrap()));
    }

    #[test]
    fn no_server_configured_blocks_everything_except_internal_schemes() {
        let policy = NavigationPolicy::default(); // no origin set

        assert!(!policy.allows(&Url::parse("https://app.drclick.dz/").unwrap()));
        // Internal schemes still pass
        assert!(policy.allows(&Url::parse("tauri://localhost/").unwrap()));
    }

    #[test]
    fn external_link_detection_is_correct() {
        let policy = make_policy("https://app.drclick.dz");

        // External HTTPS on a different host → should be opened in browser
        assert!(policy.is_external_link(&Url::parse("https://example.com/docs").unwrap()));
        // Same origin → not external
        assert!(!policy.is_external_link(&Url::parse("https://app.drclick.dz/login").unwrap()));
        // HTTP (non-HTTPS) on different host → not treated as an openable external link
        assert!(!policy.is_external_link(&Url::parse("http://example.com/").unwrap()));
    }

    #[test]
    fn the_local_loopback_origin_is_allowed_in_local_mode() {
        let policy = make_policy("http://127.0.0.1:51234/");

        assert!(policy.allows(&Url::parse("http://127.0.0.1:51234/").unwrap()));
        assert!(policy.allows(&Url::parse("http://127.0.0.1:51234/dashboard").unwrap()));
    }

    #[test]
    fn microphone_grants_are_scoped_to_the_owning_web_origin() {
        let policy = make_policy("http://192.168.1.20:47850/");

        assert!(
            policy.allows_origin(&Url::parse("http://192.168.1.20:47850/consultations/1").unwrap())
        );
        assert!(!policy.allows_origin(&Url::parse("http://tauri.localhost/index.html").unwrap()));
        assert!(!policy.allows_origin(&Url::parse("tauri://localhost/").unwrap()));
        assert!(!policy.allows_origin(&Url::parse("http://192.168.1.21:47850/").unwrap()));
        assert!(!NavigationPolicy::default()
            .allows_origin(&Url::parse("http://192.168.1.20:47850/").unwrap()));
    }

    #[test]
    fn another_loopback_port_is_not_the_local_origin() {
        let policy = make_policy("http://127.0.0.1:51234/");

        // A different local server must not borrow the desktop's privileges.
        assert!(!policy.allows(&Url::parse("http://127.0.0.1:8000/").unwrap()));
        assert!(!policy.allows(&Url::parse("http://localhost:51234/").unwrap()));
        assert!(!policy.allows(&Url::parse("https://127.0.0.1:51234/").unwrap()));
    }

    #[test]
    fn the_hosted_origin_is_blocked_while_local_mode_owns_the_session() {
        let policy = make_policy("http://127.0.0.1:51234/");

        // Local-first means the webview never wanders onto the hosted service.
        assert!(!policy.allows(&Url::parse(CLOUD_SERVER_URL).unwrap()));
    }

    #[test]
    fn the_cloud_server_url_constant_is_a_valid_https_origin() {
        let url = cloud_server_url();

        assert_eq!(url.scheme(), "https");
        assert_eq!(url.as_str(), CLOUD_SERVER_URL);
    }

    #[test]
    fn an_explicit_default_port_matches_the_implicit_one() {
        let policy = make_policy("https://app.drclick.dz:443/");

        assert!(policy.allows(&Url::parse("https://app.drclick.dz/patients").unwrap()));
        assert!(policy.allows(&Url::parse("https://app.drclick.dz:443/").unwrap()));
    }

    #[test]
    fn queries_and_fragments_on_the_owning_origin_are_allowed() {
        let policy = make_policy("https://app.drclick.dz");

        assert!(
            policy.allows(&Url::parse("https://app.drclick.dz/search?q=dupont#results").unwrap())
        );
    }

    #[test]
    fn a_username_alone_is_enough_to_block_navigation() {
        let policy = make_policy("https://app.drclick.dz");

        assert!(!policy.allows(&Url::parse("https://user@app.drclick.dz/").unwrap()));
        assert!(policy.is_external_link(&Url::parse("https://user@app.drclick.dz/").unwrap()));
    }

    #[test]
    fn dangerous_schemes_are_neither_allowed_nor_opened_externally() {
        let policy = make_policy("https://app.drclick.dz");

        for blocked in [
            "javascript:alert(1)",
            "data:text/html,<script>alert(1)</script>",
            "file:///C:/Windows/System32/cmd.exe",
            "mailto:support@drclick.dz",
            "ftp://app.drclick.dz/",
        ] {
            let url = Url::parse(blocked).unwrap();
            assert!(!policy.allows(&url), "{blocked}");
            assert!(!policy.is_external_link(&url), "{blocked}");
        }
    }

    #[test]
    fn windows_tauri_and_asset_hosts_are_internal() {
        let policy = NavigationPolicy::default();

        assert!(policy.allows(&Url::parse("http://tauri.localhost/index.html").unwrap()));
        assert!(policy.allows(&Url::parse("https://asset.localhost/logo.png").unwrap()));
        assert!(!policy.is_external_link(&Url::parse("https://tauri.localhost/").unwrap()));
        assert!(!policy.allows(&Url::parse("https://evil.tauri.localhost/").unwrap()));
    }

    #[test]
    fn changing_the_server_replaces_the_previous_origin() {
        let policy = make_policy("https://app.drclick.dz");

        policy.set_server_url(Url::parse("https://192.168.1.20/").unwrap());

        assert!(policy.allows(&Url::parse("https://192.168.1.20/login").unwrap()));
        assert!(!policy.allows(&Url::parse("https://app.drclick.dz/").unwrap()));
    }

    #[test]
    fn cloned_policies_share_the_origin_set_after_cloning() {
        let guard = NavigationPolicy::default();
        let commands = guard.clone();

        commands.set_server_url(Url::parse("https://192.168.1.20/").unwrap());

        assert!(guard.allows(&Url::parse("https://192.168.1.20/").unwrap()));
    }

    #[test]
    fn without_an_origin_every_https_link_is_external() {
        let policy = NavigationPolicy::default();

        assert!(policy.is_external_link(&Url::parse("https://app.drclick.dz/").unwrap()));
        assert!(!policy.is_external_link(&Url::parse("http://127.0.0.1:51234/").unwrap()));
    }

    #[test]
    fn shutting_down_without_a_running_runtime_is_a_no_op() {
        let state = LocalRuntimeState::default();

        state.shutdown();
        state.shutdown();

        assert!(state.running.lock().unwrap().is_none());
        assert!(state.failure.lock().unwrap().is_none());
    }

    #[test]
    fn runtime_mode_status_serialises_for_the_connection_page() {
        let status = RuntimeModeStatus {
            mode: "damaged",
            url: None,
            local_error: Some(DAMAGED_CONFIGURATION_MESSAGE.to_owned()),
        };

        let value = serde_json::to_value(&status).unwrap();

        assert_eq!(value["mode"], "damaged");
        assert_eq!(value["url"], serde_json::Value::Null);
        assert_eq!(value["local_error"], DAMAGED_CONFIGURATION_MESSAGE);
    }

    #[test]
    fn the_damaged_configuration_message_explains_the_risk_in_french() {
        assert!(DAMAGED_CONFIGURATION_MESSAGE.contains("illisible"));
        assert!(DAMAGED_CONFIGURATION_MESSAGE.contains("base vide"));
    }

    #[test]
    fn the_default_cloud_origin_is_a_bare_https_origin() {
        let url = Url::parse(DEFAULT_CLOUD_SERVER_URL).unwrap();

        assert_eq!(url.scheme(), "https");
        assert_eq!(url.path(), "/");
        assert!(url.query().is_none());
        assert!(crate::connection::validate_server_url(DEFAULT_CLOUD_SERVER_URL).is_ok());
        assert!(crate::connection::validate_server_url(CLOUD_SERVER_URL).is_ok());
    }
}
