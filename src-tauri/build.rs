// Drclick Desktop — build script (local-first edition)
//
// Release builds ship the application itself: the PHP runtime, the Laravel
// tree, and an empty migrated SQLite template. A release that bundles none of
// them produces an installer that looks fine but can only work online, which is
// exactly the regression this gate exists to prevent. `verify_release_payload`
// therefore fails the build rather than emitting a shell.
//
// Debug builds skip the gate: `tauri dev` supervises the repository's own
// Laravel tree through DRCLICK_LOCAL_APP_ROOT instead of a staged payload.

use std::{env, path::{Path, PathBuf}};

use url::Url;

fn main() {
    // Generate the Tauri application manifest (permissions/capabilities).
    // Commands list is trimmed to only what the thin client exposes.
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(
        tauri_build::AppManifest::new().commands(&[
            "signed_updater_status",
            "check_for_signed_update",
            "install_signed_update",
            "probe_server_connection",
            "configure_server_connection",
            "configure_local_mode",
            "runtime_mode_status",
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

    println!("cargo:rerun-if-env-changed=DRCLICK_CLOUD_SERVER_URL");

    println!("cargo:rerun-if-changed=resources");

    // The hosted control-plane origin is a deployment input, not a source
    // constant. Validate and normalise it on every profile: a debug build that
    // silently points at a typo'd host is as broken as a release one, and
    // `lib.rs` trusts this gate rather than re-checking at runtime.
    configure_cloud_server_url();

    if env::var("PROFILE").as_deref() != Ok("release") {
        return;
    }

    verify_release_payload();

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

/// Validate `DRCLICK_CLOUD_SERVER_URL` and re-export it in normalised form.
///
/// Unset (or empty) leaves the compiled-in default in `lib.rs` alone, so an
/// unconfigured checkout keeps building. When it *is* set, the same rules the
/// connection page enforces on a user-entered address apply here, so a bad
/// deployment value fails the build instead of shipping a shell that cannot
/// reach its own control plane.
fn configure_cloud_server_url() {
    let Ok(raw) = env::var("DRCLICK_CLOUD_SERVER_URL") else {
        return;
    };

    let raw = raw.trim();
    if raw.is_empty() {
        return;
    }

    let url = Url::parse(raw)
        .unwrap_or_else(|_| panic!("DRCLICK_CLOUD_SERVER_URL must be a valid HTTPS URL"));

    if url.scheme() != "https" || url.host_str().is_none() {
        panic!("DRCLICK_CLOUD_SERVER_URL must use HTTPS and name a host");
    }

    if !url.username().is_empty() || url.password().is_some() {
        panic!("DRCLICK_CLOUD_SERVER_URL must not embed credentials");
    }

    if url.query().is_some() || url.fragment().is_some() || url.path() != "/" {
        panic!("DRCLICK_CLOUD_SERVER_URL must be a bare origin, without a path, query, or fragment");
    }

    // Re-export the parsed form so the constant always carries a trailing
    // slash; NavigationPolicy and runtime-mode resolution compare origins.
    println!("cargo:rustc-env=DRCLICK_CLOUD_SERVER_URL={}", url.as_str());
}

/// Fail the build unless the read-only application payload has been staged.
///
/// See `resources/README.md` for what each directory must contain and how it is
/// prepared on the controlled build machine.
fn verify_release_payload() {
    // An explicit escape hatch for building the shell alone (for example to
    // test the updater). It must be set deliberately; it is never the default.
    if env::var("DRCLICK_ALLOW_EMPTY_PAYLOAD").as_deref() == Ok("1") {
        println!(
            "cargo:warning=DRCLICK_ALLOW_EMPTY_PAYLOAD=1: building a shell with no bundled              Laravel runtime. The resulting installer cannot work offline."
        );
        return;
    }

    let resources = PathBuf::from(
        env::var("CARGO_MANIFEST_DIR").expect("CARGO_MANIFEST_DIR is always set by cargo"),
    )
    .join("resources");

    let php_binary = if cfg!(windows) { "php.exe" } else { "php" };

    require_staged_file(
        &resources.join("php").join(php_binary),
        "the reviewed Windows PHP runtime",
    );
    require_staged_file(
        &resources.join("laravel/artisan"),
        "the production Laravel application",
    );
    require_staged_file(
        &resources.join("laravel/public/index.php"),
        "the Laravel public entry point",
    );
    require_staged_file(
        &resources.join("laravel/vendor/autoload.php"),
        "the Laravel Composer dependencies",
    );
    require_staged_file(
        &resources.join("initial/database.sqlite"),
        "the empty migrated SQLite template",
    );

    // A staged .env would ship one clinic's APP_KEY to every installation.
    let leaked_environment = resources.join("laravel/.env");
    if leaked_environment.exists() {
        panic!(
            "resources/laravel/.env must not be staged: each installation generates its own              APP_KEY at runtime, and a shared key would make every clinic's encrypted data              readable with the same secret"
        );
    }
}

fn require_staged_file(path: &Path, description: &str) {
    if !path.is_file() {
        panic!(
            "desktop release builds require {description} at {}; see resources/README.md.              Set DRCLICK_ALLOW_EMPTY_PAYLOAD=1 only to build a deliberately offline-incapable shell",
            path.display()
        );
    }
}

/// Read a release credential, distinguishing "absent" from "present but empty".
///
/// The two cases need different fixes and the old message conflated them. The
/// empty case matters most for `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`: minisign
/// happily generates a passwordless key and Tauri will sign with one, so an
/// operator holding a valid key can otherwise be told their credential is
/// "required" while looking straight at it.
fn required_release_environment(name: &str) -> String {
    match env::var(name) {
        Ok(value) if !value.trim().is_empty() => value,
        Ok(_) => panic!(
            "{name} is set but empty. Drclick requires a password-protected \
             updater signing key: generate one with `tauri signer generate` and \
             supply the password here. See docs/DESKTOP-RELEASE.md."
        ),
        Err(_) => panic!(
            "desktop release builds require {name}; signed updater credentials \
             must be supplied by the protected release environment. See \
             docs/DESKTOP-RELEASE.md."
        ),
    }
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
