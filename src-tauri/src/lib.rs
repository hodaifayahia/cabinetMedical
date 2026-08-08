// Drclick Desktop — thin connected-client shell
//
// Architecture change (2025): The app no longer bundles a local PHP/Laravel runtime.
// It simply loads the central hosted server (SERVER_URL) in a Tauri webview window.
// Internet connectivity is required. This eliminates:
//   - Bug 1 (migration_resources_invalid): the bundled-resource validation no longer exists.
//   - Bug 2 (visible console): main.rs now has #![windows_subsystem = "windows"] and no
//     external processes are spawned by the desktop shell.
//
// Kept:
//   - System tray + hide-to-tray behaviour (desktop_behavior.rs)
//   - Signed updater (updates.rs)
//   - NavigationPolicy — rewritten for the hosted server origin
//
// Removed:
//   - desktop.rs (PHP supervisor preparation)
//   - runtime-core dependency
//   - oauth_opener.rs Google Drive loopback flow (tied to local runtime port)
//   - All LAN / offline-restore / tunnel commands

mod connection;
mod desktop_behavior;
mod updates;

use std::{
    fs,
    sync::{Arc, RwLock},
};

use tauri::{
    plugin::{Builder as PluginBuilder, TauriPlugin},
    webview::WebviewWindowBuilder,
    AppHandle, Manager, RunEvent, State, WebviewUrl,
};
use tauri_plugin_opener::OpenerExt;
use url::Url;

use crate::connection::{persist_server_url, probe_server, validate_server_url, ServerProbe};
use crate::desktop_behavior::{install_system_tray, show_desktop_window, DesktopBehaviorState};
use crate::updates::SignedUpdaterState;

// ---------------------------------------------------------------------------
// Server URL configuration
// ---------------------------------------------------------------------------

/// Compile-time default server URL. Override at runtime by placing a JSON file
/// at `<app-local-data>/config/server.json` with content `{"url": "https://..."}`.
/// The override is read once at startup and never re-read while the app is running.
const DEFAULT_SERVER_URL: &str = "https://app.medismart.dz";

#[cfg(debug_assertions)]
const LOCAL_DEVELOPMENT_SERVER_ENV: &str = "DRCLICKDZ_DEV_SERVER_URL";

#[cfg(debug_assertions)]
fn local_development_server_url() -> Option<Url> {
    let value = std::env::var(LOCAL_DEVELOPMENT_SERVER_ENV).ok()?;
    let url = Url::parse(value.trim()).ok()?;

    is_valid_local_development_server_url(&url).then_some(url)
}

#[cfg(debug_assertions)]
fn is_valid_local_development_server_url(url: &Url) -> bool {
    url.scheme() == "http"
        && url.host_str() == Some("localhost")
        && url.port() == Some(8000)
        && url.path() == "/"
        && url.query().is_none()
        && url.fragment().is_none()
        && url.username().is_empty()
        && url.password().is_none()
}

/// Load the server URL: first checks the runtime override file, falls back to
/// the compiled-in constant.  The override file is optional and silently ignored
/// on any parse/IO error so a misconfigured file cannot prevent startup.
fn resolve_server_url(app: &AppHandle) -> Url {
    // Local HTTP is an explicit debug-build escape hatch only. The entire
    // branch is compiled out of release installers, which remain HTTPS-only.
    #[cfg(debug_assertions)]
    if let Some(url) = local_development_server_url() {
        return url;
    }

    if let Ok(data_dir) = app.path().app_local_data_dir() {
        let override_path = data_dir.join("config/server.json");
        if let Ok(bytes) = fs::read(&override_path) {
            if let Ok(value) = serde_json::from_slice::<serde_json::Value>(&bytes) {
                if let Some(url_str) = value.get("url").and_then(|v| v.as_str()) {
                    if let Ok(url) = validate_server_url(url_str) {
                        return url;
                    }
                }
            }
        }
    }
    // Fallback: compile-time constant (always valid, panics only in tests if
    // the constant itself is malformed — caught at development time).
    Url::parse(DEFAULT_SERVER_URL).expect("DEFAULT_SERVER_URL is a valid HTTPS URL")
}

#[tauri::command]
async fn probe_server_connection(url: String) -> Result<ServerProbe, String> {
    let url = validate_server_url(&url)?;

    probe_server(&url).await
}

#[tauri::command]
async fn configure_server_connection(
    app: AppHandle,
    policy: State<'_, NavigationPolicy>,
    url: String,
) -> Result<ServerProbe, String> {
    let url = validate_server_url(&url)?;
    let probe = probe_server(&url).await?;

    persist_server_url(&app, &url)?;
    policy.set_server_url(url);

    Ok(probe)
}

// ---------------------------------------------------------------------------
// NavigationPolicy — rewritten for hosted-server origin
// ---------------------------------------------------------------------------

/// Holds the server origin (scheme + host + optional port) and decides which
/// navigations the webview may perform.
///
/// Rules:
///   - Allow:   the configured server origin and all its sub-paths
///   - Allow:   tauri://, asset://, about: (internal Tauri schemes)
///   - Block:   everything else — external http(s) links are opened in the
///     system browser by the on_navigation handler instead
#[derive(Clone, Default)]
struct NavigationPolicy {
    /// The server origin, e.g. `https://app.medismart.dz`. Set once on startup.
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
        // Navigation guard: allow server origin + tauri/asset schemes;
        // open external HTTPS links in the system browser.
        .plugin(navigation_guard(policy_for_guard))
        .manage(DesktopBehaviorState::default())
        .manage(SignedUpdaterState::compiled())
        .manage(policy_for_commands)
        .invoke_handler(tauri::generate_handler![
            probe_server_connection,
            configure_server_connection,
            updates::signed_updater_status,
            updates::check_for_signed_update,
            updates::install_signed_update
        ])
        .setup(move |app| {
            if let Some(plugin) = updates::configured_plugin() {
                app.handle().plugin(plugin)?;
            }

            install_system_tray(app.handle())?;

            // Resolve which server URL to load (compile-time default or override file)
            let server_url = resolve_server_url(app.handle());
            navigation_policy.set_server_url(server_url.clone());

            // Build the main window. It loads `index.html` (the offline/redirect
            // page bundled as frontendDist) which immediately tries to navigate to
            // the server URL and shows an offline error page on failure.
            build_main_window(app, server_url)?;

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

    // No runtime shutdown needed — there are no supervised processes.
    application.run(|_app, _event| {
        if matches!(_event, RunEvent::ExitRequested { .. } | RunEvent::Exit) {
            // Nothing to clean up in thin-client mode.
        }
    });
}

/// Build the single main application window.
/// The window loads the bundled `index.html` (frontendDist) as its initial
/// page; that page's JavaScript immediately redirects to `server_url`.
fn build_main_window(app: &mut tauri::App, server_url: Url) -> tauri::Result<()> {
    #[cfg(debug_assertions)]
    let initial_url = if is_valid_local_development_server_url(&server_url) {
        // Chromium can reject the local loader's cross-origin loopback probe
        // under its Private Network Access rules. The exact debug-only origin
        // has already been validated, so navigate to its authentication page
        // directly. The public root is the marketing landing page and must
        // never be the desktop app's entry point.
        let mut authentication_url = server_url.clone();
        if authentication_url.path() == "" || authentication_url.path() == "/" {
            authentication_url.set_path("/login");
        }

        WebviewUrl::External(authentication_url)
    } else {
        WebviewUrl::App("index.html".into())
    };

    #[cfg(not(debug_assertions))]
    let initial_url = WebviewUrl::App("index.html".into());

    WebviewWindowBuilder::new(app, "main", initial_url)
        .title("Drclick")
        .inner_size(1440.0, 900.0)
        .min_inner_size(1100.0, 720.0)
        .center()
        .resizable(true)
        .maximizable(true)
        // Inject the server URL into window so the loader page can read it.
        .initialization_script(format!(
            "window.__MEDISMART_SERVER_URL = {};",
            serde_json::to_string(server_url.as_str()).unwrap_or_default()
        ))
        .build()?;

    Ok(())
}

/// Tauri plugin that enforces the NavigationPolicy and opens external links in
/// the system browser.
fn navigation_guard(policy: NavigationPolicy) -> TauriPlugin<tauri::Wry> {
    PluginBuilder::new("medismart-navigation-guard")
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
            // Block in-webview navigation for anything not on the server origin
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
        let policy = make_policy("https://app.medismart.dz");

        assert!(policy.allows(&Url::parse("https://app.medismart.dz").unwrap()));
        assert!(policy.allows(&Url::parse("https://app.medismart.dz/").unwrap()));
        assert!(policy.allows(&Url::parse("https://app.medismart.dz/login").unwrap()));
        assert!(policy
            .allows(&Url::parse("https://app.medismart.dz/patients/123/consultation").unwrap()));
    }

    #[test]
    fn different_origin_is_blocked() {
        let policy = make_policy("https://app.medismart.dz");

        // Different host
        assert!(!policy.allows(&Url::parse("https://evil.example.com/").unwrap()));
        // Different scheme
        assert!(!policy.allows(&Url::parse("http://app.medismart.dz/").unwrap()));
        // Subdomain is NOT the same origin
        assert!(!policy.allows(&Url::parse("https://sub.app.medismart.dz/").unwrap()));
        // Port mismatch
        assert!(!policy.allows(&Url::parse("https://app.medismart.dz:8443/").unwrap()));
    }

    #[test]
    fn credentials_in_url_are_always_blocked() {
        let policy = make_policy("https://app.medismart.dz");

        assert!(!policy.allows(&Url::parse("https://user:pass@app.medismart.dz/").unwrap()));
    }

    #[test]
    fn tauri_and_asset_schemes_are_always_allowed() {
        let policy = make_policy("https://app.medismart.dz");

        assert!(policy.allows(&Url::parse("tauri://localhost/").unwrap()));
        assert!(policy.allows(&Url::parse("asset://localhost/").unwrap()));
        assert!(policy.allows(&Url::parse("about:blank").unwrap()));
    }

    #[test]
    fn no_server_configured_blocks_everything_except_internal_schemes() {
        let policy = NavigationPolicy::default(); // no server URL set

        assert!(!policy.allows(&Url::parse("https://app.medismart.dz/").unwrap()));
        // Internal schemes still pass
        assert!(policy.allows(&Url::parse("tauri://localhost/").unwrap()));
    }

    #[test]
    fn external_link_detection_is_correct() {
        let policy = make_policy("https://app.medismart.dz");

        // External HTTPS on a different host → should be opened in browser
        assert!(policy.is_external_link(&Url::parse("https://example.com/docs").unwrap()));
        // Same origin → not external
        assert!(!policy.is_external_link(&Url::parse("https://app.medismart.dz/login").unwrap()));
        // HTTP (non-HTTPS) on different host → not treated as an openable external link
        assert!(!policy.is_external_link(&Url::parse("http://example.com/").unwrap()));
    }

    #[test]
    fn default_server_url_is_valid_https() {
        let url = Url::parse(DEFAULT_SERVER_URL).unwrap();
        assert_eq!(url.scheme(), "https");
        assert!(url.host_str().is_some());
    }

    #[cfg(debug_assertions)]
    #[test]
    fn local_development_url_is_strictly_loopback_port_8000() {
        assert!(is_valid_local_development_server_url(
            &Url::parse("http://localhost:8000").unwrap()
        ));

        for rejected in [
            "http://127.0.0.1:8000/",
            "http://[::1]:8000/",
            "https://localhost:8000/",
            "http://localhost:5173/",
            "http://localhost:8000/login",
            "http://localhost:8000/?debug=1",
            "http://user@localhost:8000/",
        ] {
            assert!(!is_valid_local_development_server_url(
                &Url::parse(rejected).unwrap()
            ));
        }
    }
}
