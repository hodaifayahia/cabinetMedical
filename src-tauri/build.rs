// Drclick Desktop — build script (thin-client edition)
//
// Removed (2025): bundled resource existence checks, cloudflared manifest
// verification, PHP/Laravel/migration resource SHA injection. These were only
// needed for the embedded local runtime which has been retired.
//
// Kept: updater key/endpoint validation and the Tauri app-manifest generation.

use std::env;

use url::Url;

fn main() {
    // Generate the Tauri application manifest (permissions/capabilities).
    // Commands list is trimmed to only what the thin client exposes.
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(
        tauri_build::AppManifest::new().commands(&[
            "signed_updater_status",
            "check_for_signed_update",
            "install_signed_update",
        ]),
    ))
    .expect("failed to build the Drclick Tauri application manifest");

    // Rebuild triggers
    println!("cargo:rerun-if-env-changed=PROFILE");
    println!("cargo:rerun-if-env-changed=MEDISMART_UPDATER_PUBLIC_KEY");
    println!("cargo:rerun-if-env-changed=MEDISMART_UPDATER_ENDPOINT");
    println!("cargo:rerun-if-env-changed=MEDISMART_UPDATER_INSTALL_SECRET");
    println!("cargo:rerun-if-env-changed=TAURI_SIGNING_PRIVATE_KEY");
    println!("cargo:rerun-if-env-changed=TAURI_SIGNING_PRIVATE_KEY_PASSWORD");

    if env::var("PROFILE").as_deref() != Ok("release") {
        return;
    }

    // Release builds require a signed updater. Validate and embed the keys.
    let updater_public_key = required_release_environment("MEDISMART_UPDATER_PUBLIC_KEY");
    let updater_endpoint = required_release_environment("MEDISMART_UPDATER_ENDPOINT");
    let _signing_private_key = required_release_environment("TAURI_SIGNING_PRIVATE_KEY");
    let _signing_private_key_password =
        required_release_environment("TAURI_SIGNING_PRIVATE_KEY_PASSWORD");

    validate_updater_public_key(&updater_public_key);
    validate_updater_endpoint(&updater_endpoint);

    println!("cargo:rustc-env=MEDISMART_UPDATER_PUBLIC_KEY={updater_public_key}");
    println!("cargo:rustc-env=MEDISMART_UPDATER_ENDPOINT={updater_endpoint}");

    // Optional: install-authorization HMAC secret. Validated but not required.
    if let Ok(secret) = env::var("MEDISMART_UPDATER_INSTALL_SECRET") {
        let secret = secret.trim().to_owned();
        if !secret.is_empty() {
            println!("cargo:rustc-env=MEDISMART_UPDATER_INSTALL_SECRET={secret}");
        }
    }
}

fn required_release_environment(name: &str) -> String {
    env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
        .unwrap_or_else(|| {
            panic!(
                "desktop release builds require {name}; signed updater credentials must be supplied by the protected release environment"
            )
        })
}

fn validate_updater_public_key(value: &str) {
    if value.len() < 80
        || value.len() > 4096
        || !value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'+' | b'/' | b'='))
    {
        panic!(
            "MEDISMART_UPDATER_PUBLIC_KEY must be the single-line base64 Tauri updater public key"
        );
    }
}

fn validate_updater_endpoint(value: &str) {
    let probe = value
        .replace("{{current_version}}", "0.1.0")
        .replace("{{target}}", "windows")
        .replace("{{arch}}", "x86_64");

    if probe.contains('{') || probe.contains('}') {
        panic!("MEDISMART_UPDATER_ENDPOINT contains an unsupported template variable");
    }

    let endpoint = Url::parse(&probe)
        .unwrap_or_else(|_| panic!("MEDISMART_UPDATER_ENDPOINT must be a valid HTTPS URL"));

    if endpoint.scheme() != "https"
        || endpoint.host_str().is_none()
        || !endpoint.username().is_empty()
        || endpoint.password().is_some()
        || endpoint.fragment().is_some()
    {
        panic!("MEDISMART_UPDATER_ENDPOINT must be HTTPS without credentials or a fragment");
    }
}
