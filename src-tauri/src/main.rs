// DrClickDz is always a GUI application on Windows, including local debug runs.
// This prevents a terminal window from opening alongside the desktop shell.
#![cfg_attr(windows, windows_subsystem = "windows")]

fn main() {
    drclickdz_desktop::run();
}
