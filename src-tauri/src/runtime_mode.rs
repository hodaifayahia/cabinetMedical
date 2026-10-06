//! Runtime mode selection for the Drclick desktop shell.
//!
//! A Drclick installation answers one question before it opens a window: *who
//! owns the clinical database?*
//!
//! - [`RuntimeMode::Local`] — this PC owns it. The shell supervises the bundled
//!   PHP/Laravel runtime against a SQLite database under the per-install
//!   application-data directory. No internet is required for any clinical work.
//! - [`RuntimeMode::Attach`] — another machine on the cabinet LAN owns it (a
//!   second Drclick PC running in `Local` mode, or a Cabinet Hub). This PC is a
//!   thin client. Required whenever several PCs must share one record set,
//!   because independent per-PC databases would diverge (see ADR-002).
//! - [`RuntimeMode::Cloud`] — the hosted control plane owns it. This is the
//!   pre-2026 thin-client behaviour, kept so existing installations keep
//!   working and so a cabinet can deliberately opt back into hosted operation.
//!
//! `Local` is the default for a fresh installation: a clinic that installs
//! Drclick and never configures anything gets a fully offline, self-contained
//! application whose data never leaves the machine.

use std::{
    fs::{self, OpenOptions},
    io::Write,
    path::{Path, PathBuf},
};

use serde::{Deserialize, Serialize};
use url::Url;

use crate::connection::validate_server_url;

/// Current on-disk schema for `runtime-mode.json`.
const RUNTIME_MODE_SCHEMA_VERSION: u8 = 1;

/// Where the database lives for this installation.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) enum RuntimeMode {
    /// This PC supervises the bundled Laravel runtime and owns the database.
    Local,
    /// Another Drclick machine on the cabinet LAN owns the database.
    Attach { url: Url },
    /// The hosted Drclick control plane owns the database.
    Cloud { url: Url },
}

impl RuntimeMode {
    /// True when this process must start and supervise a local PHP runtime.
    pub(crate) fn is_local(&self) -> bool {
        matches!(self, Self::Local)
    }

    /// The remote origin this shell points at, if any. `Local` has none until
    /// the supervisor reports its loopback port.
    pub(crate) fn remote_url(&self) -> Option<&Url> {
        match self {
            Self::Local => None,
            Self::Attach { url } | Self::Cloud { url } => Some(url),
        }
    }

    /// Stable identifier used in diagnostics and in the loader page.
    pub(crate) fn as_str(&self) -> &'static str {
        match self {
            Self::Local => "local",
            Self::Attach { .. } => "attach",
            Self::Cloud { .. } => "cloud",
        }
    }
}

// ---------------------------------------------------------------------------
// On-disk representation
// ---------------------------------------------------------------------------

#[derive(Debug, Deserialize, Serialize)]
struct StoredRuntimeMode {
    schema_version: u8,
    mode: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    url: Option<String>,
}

/// Legacy `server.json` written by the thin-client releases. Its presence means
/// the installation was already pointed at a remote origin, and upgrading must
/// not silently move that clinic onto an empty local database.
#[derive(Debug, Deserialize)]
struct LegacyServerConfiguration {
    url: String,
}

pub(crate) fn runtime_mode_path(configuration_directory: &Path) -> PathBuf {
    configuration_directory.join("runtime-mode.json")
}

pub(crate) fn legacy_server_path(configuration_directory: &Path) -> PathBuf {
    configuration_directory.join("server.json")
}

/// The stored configuration exists but cannot be understood.
///
/// This is never treated as "assume the default". A PC attached to a Cabinet
/// Hub that silently fell back to local mode would open a brand-new empty
/// database, show the clinic an app with no patients in it, and accept new
/// records into the wrong place. Refusing to guess is the safe answer.
#[derive(Debug, PartialEq, Eq)]
pub(crate) struct DamagedRuntimeMode;

/// Resolve the effective runtime mode for this installation.
///
/// Resolution order, first match wins:
///   1. `runtime-mode.json` — the explicit, current format.
///   2. Legacy `server.json` — an installation upgraded from a thin-client
///      release keeps talking to the origin it already used.
///   3. [`RuntimeMode::Local`] — the default, and only when *neither* file
///      exists. A present-but-unreadable file yields [`DamagedRuntimeMode`]
///      instead, so the shell can ask rather than assume.
pub(crate) fn resolve_runtime_mode(
    configuration_directory: &Path,
    cloud_url: &Url,
) -> Result<RuntimeMode, DamagedRuntimeMode> {
    if let Some(result) = read_runtime_mode(&runtime_mode_path(configuration_directory), cloud_url)
    {
        return result;
    }

    if let Some(result) =
        read_legacy_server_mode(&legacy_server_path(configuration_directory), cloud_url)
    {
        return result;
    }

    Ok(RuntimeMode::Local)
}

/// `None` when the file is absent; otherwise the parse outcome.
fn read_runtime_mode(
    path: &Path,
    cloud_url: &Url,
) -> Option<Result<RuntimeMode, DamagedRuntimeMode>> {
    if !path.exists() {
        return None;
    }

    let Ok(bytes) = fs::read(path) else {
        return Some(Err(DamagedRuntimeMode));
    };
    let Ok(stored) = serde_json::from_slice::<StoredRuntimeMode>(&bytes) else {
        return Some(Err(DamagedRuntimeMode));
    };

    if stored.schema_version != RUNTIME_MODE_SCHEMA_VERSION {
        return Some(Err(DamagedRuntimeMode));
    }

    let parsed_url = stored
        .url
        .as_deref()
        .and_then(|url| validate_server_url(url).ok());

    Some(match stored.mode.as_str() {
        "local" => Ok(RuntimeMode::Local),
        // An attach entry is only meaningful with a usable origin. Without one
        // this PC must not quietly become its own data owner.
        "attach" => parsed_url
            .map(|url| RuntimeMode::Attach { url })
            .ok_or(DamagedRuntimeMode),
        // A cloud entry without a usable URL falls back to the compiled-in
        // hosted origin, which is the same service by definition.
        "cloud" => Ok(RuntimeMode::Cloud {
            url: parsed_url.unwrap_or_else(|| cloud_url.clone()),
        }),
        _ => Err(DamagedRuntimeMode),
    })
}

/// `None` when the file is absent; otherwise the parse outcome.
fn read_legacy_server_mode(
    path: &Path,
    cloud_url: &Url,
) -> Option<Result<RuntimeMode, DamagedRuntimeMode>> {
    if !path.exists() {
        return None;
    }

    let Ok(bytes) = fs::read(path) else {
        return Some(Err(DamagedRuntimeMode));
    };
    let Ok(stored) = serde_json::from_slice::<LegacyServerConfiguration>(&bytes) else {
        return Some(Err(DamagedRuntimeMode));
    };
    let Ok(url) = validate_server_url(&stored.url) else {
        return Some(Err(DamagedRuntimeMode));
    };

    // Distinguish "the clinic used the hosted service" from "the clinic used a
    // machine on its own LAN"; both were written to the same legacy file.
    Some(Ok(if url.host_str() == cloud_url.host_str() {
        RuntimeMode::Cloud { url }
    } else {
        RuntimeMode::Attach { url }
    }))
}

/// Persist the runtime mode atomically. The legacy `server.json` is removed for
/// local mode so a later downgrade cannot resurrect a stale remote origin.
pub(crate) fn persist_runtime_mode(
    configuration_directory: &Path,
    mode: &RuntimeMode,
) -> Result<(), String> {
    fs::create_dir_all(configuration_directory)
        .map_err(|_| "Impossible de créer le dossier de configuration.".to_owned())?;

    let stored = StoredRuntimeMode {
        schema_version: RUNTIME_MODE_SCHEMA_VERSION,
        mode: mode.as_str().to_owned(),
        url: mode.remote_url().map(|url| url.as_str().to_owned()),
    };
    let payload = serde_json::to_vec_pretty(&stored)
        .map_err(|_| "Impossible de préparer la configuration.".to_owned())?;

    write_atomically(&runtime_mode_path(configuration_directory), &payload)?;

    if mode.is_local() {
        let _ = fs::remove_file(legacy_server_path(configuration_directory));
    }

    Ok(())
}

/// Replace a config file without ever leaving it missing.
///
/// A remove-then-rename sequence has a window in which neither file exists. A
/// crash there would delete the record of where this installation's data lives,
/// and the next start would fall back to a default — which for an attached PC
/// means opening an empty local database. `fs::rename` is atomic over an
/// existing destination on both Windows and Unix, so the destination is never
/// unlinked first.
fn write_atomically(target: &Path, payload: &[u8]) -> Result<(), String> {
    let temporary = target.with_extension("json.tmp");

    let mut file = OpenOptions::new()
        .create(true)
        .truncate(true)
        .write(true)
        .open(&temporary)
        .map_err(|_| "Impossible d’écrire la configuration.".to_owned())?;
    file.write_all(payload)
        .and_then(|_| file.sync_all())
        .map_err(|_| "Impossible d’enregistrer la configuration.".to_owned())?;
    drop(file);

    fs::rename(&temporary, target).map_err(|_| {
        let _ = fs::remove_file(&temporary);

        "Impossible d’activer la nouvelle configuration.".to_owned()
    })?;

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    use std::env;

    fn cloud() -> Url {
        Url::parse("https://app.drclick.dz/").unwrap()
    }

    /// Isolated temporary directory; avoids pulling in a dev-dependency.
    fn scratch(name: &str) -> PathBuf {
        let directory = env::temp_dir().join(format!("drclick-runtime-mode-{name}"));
        let _ = fs::remove_dir_all(&directory);
        fs::create_dir_all(&directory).unwrap();
        directory
    }

    #[test]
    fn a_fresh_installation_defaults_to_local_ownership() {
        let directory = scratch("fresh");

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Local)
        );
    }

    #[test]
    fn local_mode_round_trips_through_disk() {
        let directory = scratch("round-trip-local");

        persist_runtime_mode(&directory, &RuntimeMode::Local).unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Local)
        );
    }

    #[test]
    fn attach_mode_round_trips_through_disk() {
        let directory = scratch("round-trip-attach");
        let hub = Url::parse("https://192.168.1.20/").unwrap();

        persist_runtime_mode(&directory, &RuntimeMode::Attach { url: hub.clone() }).unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Attach { url: hub })
        );
    }

    #[test]
    fn attaching_to_a_poste_principal_over_lan_http_round_trips() {
        let directory = scratch("round-trip-lan-http");
        let host = Url::parse("http://192.168.1.20:47850/").unwrap();

        persist_runtime_mode(&directory, &RuntimeMode::Attach { url: host.clone() }).unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Attach { url: host })
        );
    }

    #[test]
    fn persisting_local_mode_clears_the_legacy_server_file() {
        let directory = scratch("clears-legacy");
        fs::write(
            legacy_server_path(&directory),
            br#"{"url":"https://app.drclick.dz/"}"#,
        )
        .unwrap();

        persist_runtime_mode(&directory, &RuntimeMode::Local).unwrap();

        assert!(!legacy_server_path(&directory).exists());
        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Local)
        );
    }

    #[test]
    fn an_upgraded_hosted_installation_keeps_using_the_cloud() {
        let directory = scratch("legacy-cloud");
        fs::write(
            legacy_server_path(&directory),
            br#"{"url":"https://app.drclick.dz/"}"#,
        )
        .unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Cloud { url: cloud() })
        );
    }

    #[test]
    fn an_upgraded_lan_installation_is_treated_as_attached() {
        let directory = scratch("legacy-lan");
        fs::write(
            legacy_server_path(&directory),
            br#"{"url":"https://192.168.1.20/"}"#,
        )
        .unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Attach {
                url: Url::parse("https://192.168.1.20/").unwrap()
            })
        );
    }

    #[test]
    fn a_corrupted_mode_file_is_refused_rather_than_guessed_at() {
        let directory = scratch("corrupt");
        fs::write(runtime_mode_path(&directory), b"{ not json").unwrap();

        // Defaulting to local here would open an empty database on a PC that
        // was attached to a Hub, and hide the clinic's records.
        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Err(DamagedRuntimeMode)
        );
    }

    #[test]
    fn a_newer_schema_version_is_refused_rather_than_downgraded() {
        let directory = scratch("schema");
        fs::write(
            runtime_mode_path(&directory),
            br#"{"schema_version":99,"mode":"attach","url":"https://192.168.1.20/"}"#,
        )
        .unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Err(DamagedRuntimeMode)
        );
    }

    #[test]
    fn an_attach_entry_without_a_valid_https_url_is_refused() {
        let directory = scratch("attach-invalid");
        fs::write(
            runtime_mode_path(&directory),
            br#"{"schema_version":1,"mode":"attach","url":"http://hub.example.com/"}"#,
        )
        .unwrap();

        // Neither attach to a public host over plain HTTP, nor quietly become
        // the data owner.
        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Err(DamagedRuntimeMode)
        );
    }

    #[test]
    fn a_damaged_legacy_file_is_refused_rather_than_guessed_at() {
        let directory = scratch("legacy-corrupt");
        fs::write(legacy_server_path(&directory), b"{ not json").unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Err(DamagedRuntimeMode)
        );
    }

    #[test]
    fn replacing_the_configuration_never_leaves_it_missing() {
        let directory = scratch("atomic");
        persist_runtime_mode(&directory, &RuntimeMode::Local).unwrap();

        persist_runtime_mode(
            &directory,
            &RuntimeMode::Attach {
                url: Url::parse("https://192.168.1.20/").unwrap(),
            },
        )
        .unwrap();

        assert!(runtime_mode_path(&directory).exists());
        assert!(!runtime_mode_path(&directory)
            .with_extension("json.tmp")
            .exists());
    }

    fn unique_scratch(name: &str) -> PathBuf {
        let directory = env::temp_dir().join(format!(
            "drclick-runtime-mode-x-{name}-{}-{:?}",
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = fs::remove_dir_all(&directory);
        fs::create_dir_all(&directory).unwrap();
        directory
    }

    fn write_mode(directory: &Path, json: &str) {
        fs::write(runtime_mode_path(directory), json).unwrap();
    }

    #[test]
    fn mode_accessors_describe_ownership() {
        let hub = Url::parse("https://192.168.1.20/").unwrap();
        let attach = RuntimeMode::Attach { url: hub.clone() };
        let hosted = RuntimeMode::Cloud { url: cloud() };

        assert!(RuntimeMode::Local.is_local());
        assert!(!attach.is_local());
        assert!(!hosted.is_local());
        assert_eq!(RuntimeMode::Local.remote_url(), None);
        assert_eq!(attach.remote_url(), Some(&hub));
        assert_eq!(hosted.remote_url(), Some(&cloud()));
        assert_eq!(RuntimeMode::Local.as_str(), "local");
        assert_eq!(attach.as_str(), "attach");
        assert_eq!(hosted.as_str(), "cloud");
    }

    #[test]
    fn configuration_file_names_are_fixed() {
        let directory = Path::new("/cfg");

        assert_eq!(
            runtime_mode_path(directory),
            Path::new("/cfg/runtime-mode.json")
        );
        assert_eq!(legacy_server_path(directory), Path::new("/cfg/server.json"));
    }

    #[test]
    fn cloud_mode_round_trips_through_disk() {
        let directory = unique_scratch("round-trip-cloud");
        let hosted = Url::parse("https://staging.drclick.dz/").unwrap();

        persist_runtime_mode(
            &directory,
            &RuntimeMode::Cloud {
                url: hosted.clone(),
            },
        )
        .unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Cloud { url: hosted })
        );
    }

    #[test]
    fn persisted_files_use_the_documented_schema() {
        let directory = unique_scratch("schema-shape");

        persist_runtime_mode(&directory, &RuntimeMode::Local).unwrap();
        let local: serde_json::Value =
            serde_json::from_slice(&fs::read(runtime_mode_path(&directory)).unwrap()).unwrap();
        assert_eq!(
            local,
            serde_json::json!({"schema_version": 1, "mode": "local"})
        );

        persist_runtime_mode(
            &directory,
            &RuntimeMode::Attach {
                url: Url::parse("https://192.168.1.20:8443/").unwrap(),
            },
        )
        .unwrap();
        let attach: serde_json::Value =
            serde_json::from_slice(&fs::read(runtime_mode_path(&directory)).unwrap()).unwrap();
        assert_eq!(
            attach,
            serde_json::json!({
                "schema_version": 1,
                "mode": "attach",
                "url": "https://192.168.1.20:8443/",
            })
        );
    }

    #[test]
    fn a_cloud_entry_without_a_usable_url_falls_back_to_the_compiled_origin() {
        for json in [
            r#"{"schema_version":1,"mode":"cloud"}"#,
            r#"{"schema_version":1,"mode":"cloud","url":"http://app.drclick.dz/"}"#,
            r#"{"schema_version":1,"mode":"cloud","url":"garbage"}"#,
        ] {
            let directory = unique_scratch("cloud-fallback");
            write_mode(&directory, json);

            assert_eq!(
                resolve_runtime_mode(&directory, &cloud()),
                Ok(RuntimeMode::Cloud { url: cloud() }),
                "{json}"
            );
        }
    }

    #[test]
    fn a_local_entry_ignores_any_stray_url() {
        let directory = unique_scratch("local-stray-url");
        write_mode(
            &directory,
            r#"{"schema_version":1,"mode":"local","url":"https://192.168.1.20/"}"#,
        );

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Local)
        );
    }

    #[test]
    fn unknown_or_incomplete_mode_entries_are_damaged() {
        for json in [
            r#"{"schema_version":1,"mode":"hub","url":"https://192.168.1.20/"}"#,
            r#"{"schema_version":1,"mode":"LOCAL"}"#,
            r#"{"schema_version":1}"#,
            r#"{"mode":"local"}"#,
            r#"{"schema_version":0,"mode":"local"}"#,
            r#"{"schema_version":1,"mode":"attach"}"#,
            r#"{"schema_version":1,"mode":"attach","url":"https://192.168.1.20/admin"}"#,
            r#"[]"#,
            "",
        ] {
            let directory = unique_scratch("damaged-entries");
            write_mode(&directory, json);

            assert_eq!(
                resolve_runtime_mode(&directory, &cloud()),
                Err(DamagedRuntimeMode),
                "{json:?}"
            );
        }
    }

    #[test]
    fn an_unreadable_mode_path_is_damaged_not_defaulted() {
        let directory = unique_scratch("unreadable");
        fs::create_dir(runtime_mode_path(&directory)).unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Err(DamagedRuntimeMode)
        );
    }

    #[test]
    fn the_current_mode_file_wins_over_the_legacy_server_file() {
        let directory = unique_scratch("precedence");
        fs::write(
            legacy_server_path(&directory),
            br#"{"url":"https://192.168.1.20/"}"#,
        )
        .unwrap();
        write_mode(&directory, r#"{"schema_version":1,"mode":"local"}"#);

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Local)
        );
    }

    #[test]
    fn a_damaged_mode_file_is_not_rescued_by_a_valid_legacy_file() {
        let directory = unique_scratch("no-rescue");
        fs::write(
            legacy_server_path(&directory),
            br#"{"url":"https://192.168.1.20/"}"#,
        )
        .unwrap();
        write_mode(&directory, "{ broken");

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Err(DamagedRuntimeMode)
        );
    }

    #[test]
    fn legacy_files_with_unusable_urls_are_damaged() {
        for json in [
            r#"{"url":"http://hub.example.com/"}"#,
            r#"{"url":"https://192.168.1.20/login"}"#,
            r#"{"url":""}"#,
            r#"{"address":"https://192.168.1.20/"}"#,
        ] {
            let directory = unique_scratch("legacy-unusable");
            fs::write(legacy_server_path(&directory), json).unwrap();

            assert_eq!(
                resolve_runtime_mode(&directory, &cloud()),
                Err(DamagedRuntimeMode),
                "{json}"
            );
        }
    }

    #[test]
    fn persisting_a_remote_mode_keeps_the_legacy_file_for_downgrades() {
        let directory = unique_scratch("keeps-legacy");
        fs::write(
            legacy_server_path(&directory),
            br#"{"url":"https://192.168.1.20/"}"#,
        )
        .unwrap();

        persist_runtime_mode(
            &directory,
            &RuntimeMode::Attach {
                url: Url::parse("https://192.168.1.20/").unwrap(),
            },
        )
        .unwrap();

        assert!(legacy_server_path(&directory).exists());
    }

    #[test]
    fn persisting_creates_a_missing_configuration_directory() {
        let root = unique_scratch("nested-create");
        let directory = root.join("a").join("config");

        persist_runtime_mode(&directory, &RuntimeMode::Local).unwrap();

        assert_eq!(
            resolve_runtime_mode(&directory, &cloud()),
            Ok(RuntimeMode::Local)
        );
    }

    #[test]
    fn persisting_into_a_file_path_reports_an_error() {
        let root = unique_scratch("blocked");
        let blocker = root.join("config");
        fs::write(&blocker, b"not a directory").unwrap();

        let error = persist_runtime_mode(&blocker, &RuntimeMode::Local).unwrap_err();

        assert_eq!(error, "Impossible de créer le dossier de configuration.");
        assert_eq!(fs::read(&blocker).unwrap(), b"not a directory");
    }

    #[test]
    fn atomic_writes_replace_existing_content_without_leftovers() {
        let directory = unique_scratch("atomic-content");
        let target = directory.join("file.json");
        fs::write(&target, b"old").unwrap();

        write_atomically(&target, b"new").unwrap();

        assert_eq!(fs::read(&target).unwrap(), b"new");
        assert!(!target.with_extension("json.tmp").exists());
    }
}
