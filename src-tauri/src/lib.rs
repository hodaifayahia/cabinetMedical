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

mod connection;
mod desktop_behavior;
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
    running: Mutex<Option<LocalRuntime>>,
    /// Set when local mode was selected but could not start; surfaced to the
    /// connection page so the clinic sees why, instead of a blank window.
    failure: Mutex<Option<LocalRuntimeError>>,
}

impl LocalRuntimeState {
    fn shutdown(&self) {
        if let Ok(running) = self.running.lock() {
            if let Some(runtime) = running.as_ref() {
                runtime.shutdown();
            }
        }
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

/// Hand data ownership back to this PC. Takes effect on the next start: the
/// database owner cannot be swapped underneath a running session.
#[tauri::command]
fn configure_local_mode(app: AppHandle) -> Result<bool, String> {
    let directory = configuration_directory(&app)?;
    persist_runtime_mode(&directory, &RuntimeMode::Local)?;

    Ok(true)
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
    policy.set_server_url(url);

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
        .manage(DesktopBehaviorState::default())
        .manage(SignedUpdaterState::compiled())
        .manage(LocalRuntimeState::default())
        .manage(policy_for_commands)
        .invoke_handler(tauri::generate_handler![
            probe_server_connection,
            configure_server_connection,
            configure_local_mode,
            runtime_mode_status,
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

            // Resolve the origin that will own this session's data.
            let resolved = match &mode {
                RuntimeMode::Local => match local_runtime::start(&handle) {
                    Ok(runtime) => {
                        let url = runtime.url().clone();
                        let state = handle.state::<LocalRuntimeState>();
                        if let Ok(mut running) = state.running.lock() {
                            *running = Some(runtime);
                        }
                        Some(url)
                    }
                    Err(error) => {
                        // Never silently fall back to the hosted service: that
                        // would move a clinic's data off the machine without
                        // consent. Show the failure and let them choose.
                        let state = handle.state::<LocalRuntimeState>();
                        if let Ok(mut failure) = state.failure.lock() {
                            *failure = Some(error);
                        }
                        None
                    }
                },
                other => other.remote_url().cloned(),
            };

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
            app.state::<LocalRuntimeState>().shutdown();
        }
    });
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

    WebviewWindowBuilder::new(app, "main", initial_url)
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

    Ok(())
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
}
