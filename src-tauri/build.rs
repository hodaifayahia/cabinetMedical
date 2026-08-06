use std::{
    env,
    fs::File,
    io::{BufReader, Read},
    path::Path,
};

use serde::Deserialize;
use sha2::{Digest, Sha256};
use url::Url;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct CloudflaredManifest {
    schema_version: u8,
    version: String,
    sha256: String,
}

fn main() {
    tauri_build::try_build(tauri_build::Attributes::new().app_manifest(
        tauri_build::AppManifest::new().commands(&[
            "apply_prepared_offline_restore",
            "open_google_oauth_authorization",
            "list_lan_adapters",
            "apply_lan_listener_configuration",
            "signed_updater_status",
            "check_for_signed_update",
            "install_signed_update",
        ]),
    ))
    .expect("failed to build the MediSmart Tauri application manifest");

    println!("cargo:rerun-if-env-changed=PROFILE");
    println!("cargo:rerun-if-env-changed=MEDISMART_UPDATER_PUBLIC_KEY");
    println!("cargo:rerun-if-env-changed=MEDISMART_UPDATER_ENDPOINT");
    println!("cargo:rerun-if-env-changed=TAURI_SIGNING_PRIVATE_KEY");
    println!("cargo:rerun-if-env-changed=TAURI_SIGNING_PRIVATE_KEY_PASSWORD");
    println!("cargo:rerun-if-changed=resources/laravel/artisan");
    println!("cargo:rerun-if-changed=resources/laravel/release.manifest.json");
    println!(
        "cargo:rerun-if-changed=resources/laravel/app/Console/Commands/NativeMigrationGate.php"
    );
    println!("cargo:rerun-if-changed=resources/php/php-runtime.manifest.json");
    println!("cargo:rerun-if-changed=resources/initial/database.manifest.json");
    println!("cargo:rerun-if-changed=resources/initial/migration-contract.json");
    println!("cargo:rerun-if-changed=resources/laravel/config/queue.php");
    println!("cargo:rerun-if-changed=resources/laravel/routes/console.php");
    println!(
        "cargo:rerun-if-changed=resources/laravel/database/migrations/0001_01_01_000002_create_jobs_table.php"
    );
    println!(
        "cargo:rerun-if-changed=resources/laravel/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php"
    );
    println!(
        "cargo:rerun-if-changed=resources/laravel/vendor/laravel/framework/src/Illuminate/Queue/Worker.php"
    );
    println!(
        "cargo:rerun-if-changed=resources/laravel/vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleWorkCommand.php"
    );
    println!(
        "cargo:rerun-if-changed=resources/laravel/vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php"
    );
    println!(
        "cargo:rerun-if-changed=resources/laravel/vendor/laravel/framework/src/Illuminate/Console/Scheduling/Schedule.php"
    );
    println!(
        "cargo:rerun-if-changed=resources/laravel/app/Console/Commands/NativeApplyOfflineRestore.php"
    );
    println!("cargo:rerun-if-changed=resources/laravel/app/Backups/OfflineRestoreExecutor.php");
    println!("cargo:rerun-if-changed=resources/laravel/app/Backups/PreparedRestore.php");
    println!(
        "cargo:rerun-if-changed=resources/laravel/app/Backups/SupervisorOfflineRestoreGuard.php"
    );
    println!("cargo:rerun-if-changed=resources/php/php.exe");
    println!("cargo:rerun-if-changed=resources/initial/database.sqlite");
    println!("cargo:rerun-if-changed=resources/cloudflared/cloudflared.exe");
    println!("cargo:rerun-if-changed=resources/cloudflared/cloudflared.manifest.json");

    if env::var("PROFILE").as_deref() != Ok("release") {
        return;
    }

    let updater_public_key = required_release_environment("MEDISMART_UPDATER_PUBLIC_KEY");
    let updater_endpoint = required_release_environment("MEDISMART_UPDATER_ENDPOINT");
    let _signing_private_key = required_release_environment("TAURI_SIGNING_PRIVATE_KEY");
    let _signing_private_key_password =
        required_release_environment("TAURI_SIGNING_PRIVATE_KEY_PASSWORD");
    validate_updater_public_key(&updater_public_key);
    validate_updater_endpoint(&updater_endpoint);
    println!("cargo:rustc-env=MEDISMART_UPDATER_PUBLIC_KEY={updater_public_key}");
    println!("cargo:rustc-env=MEDISMART_UPDATER_ENDPOINT={updater_endpoint}");

    let required = [
        "resources/laravel/artisan",
        "resources/laravel/release.manifest.json",
        "resources/laravel/app/Console/Commands/NativeMigrationGate.php",
        "resources/laravel/config/queue.php",
        "resources/laravel/routes/console.php",
        "resources/laravel/database/migrations/0001_01_01_000002_create_jobs_table.php",
        "resources/laravel/public/index.php",
        "resources/laravel/vendor/autoload.php",
        "resources/laravel/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php",
        "resources/laravel/vendor/laravel/framework/src/Illuminate/Queue/Worker.php",
        "resources/laravel/vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleWorkCommand.php",
        "resources/laravel/vendor/laravel/framework/src/Illuminate/Console/Scheduling/ScheduleRunCommand.php",
        "resources/laravel/vendor/laravel/framework/src/Illuminate/Console/Scheduling/Schedule.php",
        "resources/laravel/app/Console/Commands/NativeApplyOfflineRestore.php",
        "resources/laravel/app/Backups/OfflineRestoreExecutor.php",
        "resources/laravel/app/Backups/PreparedRestore.php",
        "resources/laravel/app/Backups/SupervisorOfflineRestoreGuard.php",
        "resources/php/php.exe",
        "resources/php/php-runtime.manifest.json",
        "resources/initial/database.sqlite",
        "resources/initial/database.manifest.json",
        "resources/initial/migration-contract.json",
        "resources/cloudflared/cloudflared.exe",
        "resources/cloudflared/cloudflared.manifest.json",
    ];

    let missing: Vec<_> = required
        .iter()
        .filter(|path| !Path::new(path).is_file())
        .copied()
        .collect();

    if !missing.is_empty() {
        panic!(
            "desktop release resources are incomplete (missing: {}). Run the documented staging procedure before building an installer; a partial installer is intentionally refused",
            missing.join(", ")
        );
    }

    verify_cloudflared_release_resource(
        Path::new("resources/cloudflared/cloudflared.exe"),
        Path::new("resources/cloudflared/cloudflared.manifest.json"),
    );

    for (path, environment_name) in [
        (
            "resources/laravel/release.manifest.json",
            "MEDISMART_BUILD_LARAVEL_MANIFEST_SHA256",
        ),
        (
            "resources/php/php-runtime.manifest.json",
            "MEDISMART_BUILD_PHP_MANIFEST_SHA256",
        ),
        (
            "resources/initial/database.manifest.json",
            "MEDISMART_BUILD_DATABASE_MANIFEST_SHA256",
        ),
        (
            "resources/initial/migration-contract.json",
            "MEDISMART_BUILD_MIGRATION_CONTRACT_SHA256",
        ),
    ] {
        println!(
            "cargo:rustc-env={environment_name}={}",
            sha256_file(Path::new(path))
        );
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

fn sha256_file(path: &Path) -> String {
    let file = File::open(path).unwrap_or_else(|error| {
        panic!(
            "cannot open staged release manifest {}: {error}",
            path.display()
        )
    });
    let mut reader = BufReader::new(file);
    let mut hasher = Sha256::new();
    let mut buffer = [0_u8; 64 * 1024];
    loop {
        let count = reader.read(&mut buffer).unwrap_or_else(|error| {
            panic!(
                "cannot hash staged release manifest {}: {error}",
                path.display()
            )
        });
        if count == 0 {
            break;
        }
        hasher.update(&buffer[..count]);
    }
    format!("{:x}", hasher.finalize())
}

fn verify_cloudflared_release_resource(executable: &Path, manifest_path: &Path) {
    let manifest_bytes = std::fs::read(manifest_path)
        .unwrap_or_else(|error| panic!("cannot read cloudflared manifest: {error}"));
    let manifest: CloudflaredManifest = serde_json::from_slice(&manifest_bytes)
        .unwrap_or_else(|error| panic!("cloudflared manifest is invalid: {error}"));
    let valid_version = {
        let components = manifest.version.split('.').collect::<Vec<_>>();
        matches!(components.len(), 3 | 4)
            && components[0]
                .parse::<u16>()
                .is_ok_and(|year| (2025..=2100).contains(&year))
            && components[1]
                .parse::<u8>()
                .is_ok_and(|month| (1..=12).contains(&month))
            && components[2].parse::<u16>().is_ok()
            && components
                .get(3)
                .is_none_or(|component| component.parse::<u16>().is_ok())
    };
    if manifest.schema_version != 1
        || !valid_version
        || manifest.sha256.len() != 64
        || !manifest
            .sha256
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
    {
        panic!("cloudflared manifest must contain schema 1, an approved version, and lowercase SHA-256");
    }

    let file = File::open(executable)
        .unwrap_or_else(|error| panic!("cannot open staged cloudflared.exe: {error}"));
    let mut reader = BufReader::new(file);
    let mut hasher = Sha256::new();
    let mut buffer = [0_u8; 64 * 1024];
    loop {
        let count = reader
            .read(&mut buffer)
            .unwrap_or_else(|error| panic!("cannot hash staged cloudflared.exe: {error}"));
        if count == 0 {
            break;
        }
        hasher.update(&buffer[..count]);
    }
    let actual = format!("{:x}", hasher.finalize());
    if actual != manifest.sha256 {
        panic!("staged cloudflared.exe does not match cloudflared.manifest.json");
    }
}
