use std::sync::atomic::{AtomicBool, Ordering};

use tauri::{
    menu::{Menu, MenuItem},
    tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent},
    AppHandle, Manager,
};

const OPEN_MENU_ID: &str = "medismart-tray-open";
const QUIT_MENU_ID: &str = "medismart-tray-quit";

#[derive(Default)]
pub(crate) struct DesktopBehaviorState {
    quit_requested: AtomicBool,
}

impl DesktopBehaviorState {
    pub(crate) fn request_quit(&self) {
        self.quit_requested.store(true, Ordering::SeqCst);
    }

    pub(crate) fn should_hide_on_close(&self, window_label: &str) -> bool {
        matches!(window_label, "main" | "startup") && !self.quit_requested.load(Ordering::SeqCst)
    }
}

pub(crate) fn install_system_tray(app: &AppHandle) -> tauri::Result<()> {
    let open = MenuItem::with_id(app, OPEN_MENU_ID, "Ouvrir MediSmart", true, None::<&str>)?;
    let quit = MenuItem::with_id(app, QUIT_MENU_ID, "Quitter MediSmart", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&open, &quit])?;
    let mut tray = TrayIconBuilder::with_id("medismart-main-tray")
        .menu(&menu)
        .tooltip("MediSmart")
        .show_menu_on_left_click(false)
        .on_menu_event(|app, event| match event.id().as_ref() {
            OPEN_MENU_ID => show_desktop_window(app),
            QUIT_MENU_ID => {
                app.state::<DesktopBehaviorState>().request_quit();
                app.exit(0);
            }
            _ => {}
        })
        .on_tray_icon_event(|tray, event| {
            if matches!(
                event,
                TrayIconEvent::Click {
                    button: MouseButton::Left,
                    button_state: MouseButtonState::Up,
                    ..
                } | TrayIconEvent::DoubleClick {
                    button: MouseButton::Left,
                    ..
                }
            ) {
                show_desktop_window(tray.app_handle());
            }
        });

    if let Some(icon) = app.default_window_icon() {
        tray = tray.icon(icon.clone());
    }

    tray.build(app)?;

    Ok(())
}

pub(crate) fn show_desktop_window(app: &AppHandle) {
    for label in ["main", "startup"] {
        if let Some(window) = app.get_webview_window(label) {
            let _ = window.show();
            let _ = window.unminimize();
            let _ = window.set_focus();

            return;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::DesktopBehaviorState;

    #[test]
    fn managed_windows_hide_until_an_explicit_tray_quit() {
        let state = DesktopBehaviorState::default();

        assert!(state.should_hide_on_close("main"));
        assert!(state.should_hide_on_close("startup"));
        assert!(!state.should_hide_on_close("unmanaged"));

        state.request_quit();

        assert!(!state.should_hide_on_close("main"));
        assert!(!state.should_hide_on_close("startup"));
    }
}
