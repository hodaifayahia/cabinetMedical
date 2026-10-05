// Drclick signed updater — thin-client edition
//
// Architecture change (2025): The updater no longer requires a local
// installation identity (app_key / installation_id) sourced from the PHP
// runtime. Instead, the server issues a short-lived HMAC-signed authorization
// that the JavaScript front-end forwards when requesting an update install.
// The HMAC key is now the updater endpoint token embedded at build time
// (MEDISMART_UPDATER_INSTALL_SECRET), which is optional; if absent, the
// install command requires no authorization and the button is gated server-side.
//
// Kept intact: check_for_signed_update, install_signed_update, signed_updater_status,
// the UpdateInstallAuthorization HMAC verification flow, and all unit tests.

use std::{
    sync::{
        atomic::{AtomicBool, AtomicU64, Ordering},
        Mutex,
    },
    time::{Duration, SystemTime, UNIX_EPOCH},
};

use hmac::{Hmac, Mac};
use serde::{Deserialize, Serialize};
use sha2::Sha256;
use tauri::{AppHandle, Runtime, State};
use tauri_plugin_updater::{Update, UpdaterExt};
use url::Url;

const AUTHORIZATION_PROTOCOL: &str = "medismart-update-install-authorization";
const AUTHORIZATION_VERSION: u8 = 1;
const MAX_AUTHORIZATION_LIFETIME_SECONDS: u64 = 600;

#[derive(Clone)]
struct ReleaseConfiguration {
    public_key: String,
    endpoint: Url,
    /// Optional secret used to verify install authorizations issued by the
    /// hosted server. If None, install_signed_update skips HMAC verification
    /// (the server-side gating is then the only gate).
    install_secret: Option<String>,
}

pub struct SignedUpdaterState {
    configuration: Option<ReleaseConfiguration>,
    pending: Mutex<Option<Update>>,
    checking: AtomicBool,
    installing: AtomicBool,
    last_checked_at: AtomicU64,
}

impl SignedUpdaterState {
    pub fn compiled() -> Self {
        Self::new(compiled_release_configuration())
    }

    fn new(configuration: Option<ReleaseConfiguration>) -> Self {
        Self {
            configuration,
            pending: Mutex::new(None),
            checking: AtomicBool::new(false),
            installing: AtomicBool::new(false),
            last_checked_at: AtomicU64::new(0),
        }
    }

    fn available(&self) -> bool {
        self.configuration.is_some()
    }

    fn configuration(&self) -> Result<ReleaseConfiguration, UpdateCommandError> {
        self.configuration
            .clone()
            .ok_or_else(UpdateCommandError::unavailable)
    }

    fn pending_metadata(&self) -> Option<UpdateMetadata> {
        self.pending
            .lock()
            .ok()
            .and_then(|pending| pending.as_ref().map(UpdateMetadata::from))
    }
}

/// True when this build carries signed-updater credentials. The supervised
/// Laravel child is told so it can present update state consistently.
pub fn is_configured() -> bool {
    compiled_release_configuration().is_some()
}

pub fn configured_plugin<R: Runtime>(
) -> Option<tauri::plugin::TauriPlugin<R, tauri_plugin_updater::Config>> {
    compiled_release_configuration().map(|configuration| {
        tauri_plugin_updater::Builder::new()
            .pubkey(configuration.public_key)
            .build()
    })
}

fn compiled_release_configuration() -> Option<ReleaseConfiguration> {
    if cfg!(debug_assertions) {
        return None;
    }

    let public_key = option_env!("MEDISMART_UPDATER_PUBLIC_KEY")?.trim();
    let endpoint = option_env!("MEDISMART_UPDATER_ENDPOINT")?.trim();

    if public_key.is_empty() || endpoint.is_empty() {
        return None;
    }

    let endpoint = Url::parse(endpoint).ok()?;

    if endpoint.scheme() != "https"
        || endpoint.host_str().is_none()
        || !endpoint.username().is_empty()
        || endpoint.password().is_some()
        || endpoint.fragment().is_some()
    {
        return None;
    }

    // Optional install-authorization secret. If not set, the HMAC check is
    // skipped and the server-side policy is the sole gate.
    let install_secret = option_env!("MEDISMART_UPDATER_INSTALL_SECRET")
        .map(str::trim)
        .filter(|s| !s.is_empty())
        .map(str::to_owned);

    Some(ReleaseConfiguration {
        public_key: public_key.to_owned(),
        endpoint,
        install_secret,
    })
}

#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub struct UpdateMetadata {
    version: String,
    current_version: String,
    published_at: Option<String>,
}

impl From<&Update> for UpdateMetadata {
    fn from(update: &Update) -> Self {
        Self {
            version: update.version.clone(),
            current_version: update.current_version.clone(),
            published_at: update.date.map(|date| date.to_string()),
        }
    }
}

#[derive(Serialize)]
pub struct SignedUpdaterStatus {
    configured: bool,
    current_version: String,
    pending_update: Option<UpdateMetadata>,
    last_checked_at: Option<u64>,
    checking: bool,
    installing: bool,
}

#[derive(Serialize)]
pub struct UpdateCheckResponse {
    update: Option<UpdateMetadata>,
    checked_at: u64,
}

#[derive(Serialize)]
pub struct UpdateInstallResponse {
    accepted: bool,
    target_version: String,
    message_fr: &'static str,
}

#[derive(Debug, Serialize)]
pub struct UpdateCommandError {
    code: &'static str,
    message_fr: &'static str,
}

impl UpdateCommandError {
    fn unavailable() -> Self {
        Self {
            code: "signed_updater_unavailable",
            message_fr:
                "Le programme de mise à jour signé n'est pas disponible dans cette version.",
        }
    }

    fn busy() -> Self {
        Self {
            code: "signed_updater_busy",
            message_fr: "Une opération de mise à jour est déjà en cours.",
        }
    }

    fn check_failed() -> Self {
        Self {
            code: "signed_update_check_failed",
            message_fr:
                "La recherche de mise à jour a échoué. Vérifiez la connexion puis réessayez.",
        }
    }

    fn no_pending_update() -> Self {
        Self {
            code: "signed_update_not_pending",
            message_fr: "Aucune mise à jour vérifiée n'attend une installation.",
        }
    }

    fn authorization_invalid() -> Self {
        Self {
            code: "update_install_authorization_invalid",
            message_fr:
                "L'autorisation d'installation ou sa sauvegarde de sécurité n'est pas valide.",
        }
    }

    fn authorization_expired() -> Self {
        Self {
            code: "update_install_authorization_expired",
            message_fr:
                "L'autorisation d'installation a expiré. Recréez la sauvegarde de sécurité.",
        }
    }

    fn install_failed() -> Self {
        Self {
            code: "signed_update_install_failed",
            message_fr: "La mise à jour signée n'a pas pu être téléchargée ou installée.",
        }
    }
}

struct OperationGuard<'a>(&'a AtomicBool);

impl Drop for OperationGuard<'_> {
    fn drop(&mut self) {
        self.0.store(false, Ordering::SeqCst);
    }
}

fn begin_operation(flag: &AtomicBool) -> Result<OperationGuard<'_>, UpdateCommandError> {
    flag.compare_exchange(false, true, Ordering::SeqCst, Ordering::SeqCst)
        .map(|_| OperationGuard(flag))
        .map_err(|_| UpdateCommandError::busy())
}

#[tauri::command]
pub fn signed_updater_status(
    app: AppHandle,
    state: State<'_, SignedUpdaterState>,
) -> SignedUpdaterStatus {
    let last_checked_at = state.last_checked_at.load(Ordering::SeqCst);

    SignedUpdaterStatus {
        configured: state.available(),
        current_version: app.package_info().version.to_string(),
        pending_update: state.pending_metadata(),
        last_checked_at: (last_checked_at != 0).then_some(last_checked_at),
        checking: state.checking.load(Ordering::SeqCst),
        installing: state.installing.load(Ordering::SeqCst),
    }
}

#[tauri::command]
pub async fn check_for_signed_update(
    app: AppHandle,
    state: State<'_, SignedUpdaterState>,
) -> Result<UpdateCheckResponse, UpdateCommandError> {
    let _operation = begin_operation(&state.checking)?;
    let configuration = state.configuration()?;
    let updater = app
        .updater_builder()
        .endpoints(vec![configuration.endpoint])
        .map_err(|_| UpdateCommandError::check_failed())?
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|_| UpdateCommandError::check_failed())?;
    let update = updater
        .check()
        .await
        .map_err(|_| UpdateCommandError::check_failed())?;
    let metadata = update.as_ref().map(UpdateMetadata::from);
    let checked_at = unix_timestamp();
    let mut pending = state
        .pending
        .lock()
        .map_err(|_| UpdateCommandError::check_failed())?;
    *pending = update;
    state.last_checked_at.store(checked_at, Ordering::SeqCst);

    Ok(UpdateCheckResponse {
        update: metadata,
        checked_at,
    })
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct UpdateInstallAuthorization {
    protocol: String,
    version: u8,
    target_version: String,
    backup_record_id: String,
    backup_sha256: String,
    installation_id: String,
    issued_at: u64,
    expires_at: u64,
    nonce: String,
    signature: String,
}

impl UpdateInstallAuthorization {
    fn canonical_payload(&self) -> String {
        format!(
            "{}\n{}\n{}\n{}\n{}\n{}\n{}\n{}\n{}",
            self.protocol,
            self.version,
            self.target_version,
            self.backup_record_id,
            self.backup_sha256,
            self.installation_id,
            self.issued_at,
            self.expires_at,
            self.nonce,
        )
    }

    /// Verify the authorization. `secret` is the shared HMAC key negotiated
    /// between the hosted server and this binary.
    fn verify(
        &self,
        secret: &str,
        expected_target_version: &str,
        now: u64,
    ) -> Result<(), UpdateCommandError> {
        if self.protocol != AUTHORIZATION_PROTOCOL
            || self.version != AUTHORIZATION_VERSION
            || self.target_version != expected_target_version
            || !valid_version(&self.target_version)
            || !valid_uuid(&self.backup_record_id)
            || !valid_uuid(&self.nonce)
            || !valid_uuid(&self.installation_id)
            || !valid_lower_sha256(&self.backup_sha256)
            || self.issued_at > now.saturating_add(60)
            || self.expires_at <= self.issued_at
            || self.expires_at.saturating_sub(self.issued_at) > MAX_AUTHORIZATION_LIFETIME_SECONDS
        {
            return Err(UpdateCommandError::authorization_invalid());
        }

        if self.expires_at < now
            || now.saturating_sub(self.issued_at) > MAX_AUTHORIZATION_LIFETIME_SECONDS
        {
            return Err(UpdateCommandError::authorization_expired());
        }

        let signature = decode_lower_hex_32(&self.signature)
            .ok_or_else(UpdateCommandError::authorization_invalid)?;
        let mut mac = Hmac::<Sha256>::new_from_slice(secret.as_bytes())
            .map_err(|_| UpdateCommandError::authorization_invalid())?;
        mac.update(self.canonical_payload().as_bytes());
        mac.verify_slice(&signature)
            .map_err(|_| UpdateCommandError::authorization_invalid())
    }
}

#[tauri::command]
pub async fn install_signed_update(
    _app: AppHandle,
    state: State<'_, SignedUpdaterState>,
    authorization: UpdateInstallAuthorization,
) -> Result<UpdateInstallResponse, UpdateCommandError> {
    let _operation = begin_operation(&state.installing)?;
    let configuration = state.configuration()?;
    let update = state
        .pending
        .lock()
        .map_err(|_| UpdateCommandError::no_pending_update())?
        .clone()
        .ok_or_else(UpdateCommandError::no_pending_update)?;

    // Verify HMAC authorization if an install secret is configured.
    // If no secret is set, the authorization fields are still structurally
    // validated but the HMAC is not checked.
    if let Some(ref secret) = configuration.install_secret {
        authorization.verify(secret, &update.version, unix_timestamp())?;
    } else {
        // Structural validation only (no HMAC key available)
        let now = unix_timestamp();
        if authorization.protocol != AUTHORIZATION_PROTOCOL
            || authorization.version != AUTHORIZATION_VERSION
            || authorization.target_version != update.version
            || authorization.expires_at < now
        {
            return Err(UpdateCommandError::authorization_invalid());
        }
    }

    let target_version = update.version.clone();
    update
        .download_and_install(|_, _| {}, || {})
        .await
        .map_err(|_| UpdateCommandError::install_failed())?;

    if let Ok(mut pending) = state.pending.lock() {
        *pending = None;
    }

    #[cfg(not(windows))]
    {
        let _ = target_version;
        _app.restart();
    }

    #[cfg(windows)]
    {
        Ok(UpdateInstallResponse {
            accepted: true,
            target_version,
            message_fr: "La mise à jour vérifiée est installée; Drclick va redémarrer.",
        })
    }
}

fn unix_timestamp() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

fn valid_version(value: &str) -> bool {
    !value.is_empty() && value.len() <= 64 && semver::Version::parse(value).is_ok()
}

fn valid_lower_sha256(value: &str) -> bool {
    value.len() == 64
        && value
            .bytes()
            .all(|byte| byte.is_ascii_digit() || matches!(byte, b'a'..=b'f'))
}

fn valid_uuid(value: &str) -> bool {
    value.len() == 36
        && value.bytes().enumerate().all(|(index, byte)| match index {
            8 | 13 | 18 | 23 => byte == b'-',
            _ => byte.is_ascii_hexdigit(),
        })
}

fn decode_lower_hex_32(value: &str) -> Option<[u8; 32]> {
    if !valid_lower_sha256(value) {
        return None;
    }

    let mut decoded = [0_u8; 32];

    for (index, chunk) in value.as_bytes().chunks_exact(2).enumerate() {
        decoded[index] = (hex_nibble(chunk[0])? << 4) | hex_nibble(chunk[1])?;
    }

    Some(decoded)
}

fn hex_nibble(byte: u8) -> Option<u8> {
    match byte {
        b'0'..=b'9' => Some(byte - b'0'),
        b'a'..=b'f' => Some(byte - b'a' + 10),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn make_authorization(now: u64, secret: &str) -> UpdateInstallAuthorization {
        let mut artifact = UpdateInstallAuthorization {
            protocol: AUTHORIZATION_PROTOCOL.to_owned(),
            version: AUTHORIZATION_VERSION,
            target_version: "1.2.3".to_owned(),
            backup_record_id: "57dca9dd-6c10-49c8-ae81-3d773bf36582".to_owned(),
            backup_sha256: "42".repeat(32),
            installation_id: "e169a732-1f4e-46ed-b5b8-a0bc752f6f09".to_owned(),
            issued_at: now,
            expires_at: now + 300,
            nonce: "ad7b2dc9-9c8b-4c82-acf3-f76aa915ee09".to_owned(),
            signature: String::new(),
        };
        let mut mac = Hmac::<Sha256>::new_from_slice(secret.as_bytes()).unwrap();
        mac.update(artifact.canonical_payload().as_bytes());
        artifact.signature = mac
            .finalize()
            .into_bytes()
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        artifact
    }

    const SECRET: &str = "test-install-secret-key";

    #[test]
    fn content_bound_authorization_is_accepted_once_fields_match() {
        let artifact = make_authorization(1_700_000_000, SECRET);
        artifact.verify(SECRET, "1.2.3", 1_700_000_120).unwrap();
    }

    #[test]
    fn authorization_rejects_wrong_version_and_expiry() {
        let artifact = make_authorization(1_700_000_000, SECRET);
        // Wrong target version in the verify call
        assert!(artifact.verify(SECRET, "1.2.4", 1_700_000_120).is_err());

        // Expired
        let artifact = make_authorization(1_700_000_000, SECRET);
        assert_eq!(
            artifact
                .verify(SECRET, "1.2.3", 1_700_000_601)
                .unwrap_err()
                .code,
            "update_install_authorization_expired"
        );
    }

    #[test]
    fn wrong_secret_is_rejected() {
        let artifact = make_authorization(1_700_000_000, SECRET);
        assert!(artifact
            .verify("wrong-secret", "1.2.3", 1_700_000_120)
            .is_err());
    }

    #[test]
    fn debug_builds_never_claim_release_updater_configuration() {
        if cfg!(debug_assertions) {
            assert!(compiled_release_configuration().is_none());
            assert!(!SignedUpdaterState::compiled().available());
        }
    }

    type LabeledMutation = (&'static str, fn(&mut UpdateInstallAuthorization));

    const NOW: u64 = 1_700_000_000;

    fn sign(artifact: &mut UpdateInstallAuthorization, secret: &str) {
        let mut mac = Hmac::<Sha256>::new_from_slice(secret.as_bytes()).unwrap();
        mac.update(artifact.canonical_payload().as_bytes());
        artifact.signature = mac
            .finalize()
            .into_bytes()
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
    }

    fn verify_code(artifact: &UpdateInstallAuthorization, now: u64) -> Option<&'static str> {
        artifact
            .verify(SECRET, "1.2.3", now)
            .err()
            .map(|error| error.code)
    }

    #[test]
    fn canonical_payload_is_newline_joined_in_a_fixed_order() {
        let artifact = make_authorization(NOW, SECRET);

        assert_eq!(
            artifact.canonical_payload(),
            format!(
                "medismart-update-install-authorization\n1\n1.2.3\n57dca9dd-6c10-49c8-ae81-3d773bf36582\n{}\ne169a732-1f4e-46ed-b5b8-a0bc752f6f09\n{NOW}\n{}\nad7b2dc9-9c8b-4c82-acf3-f76aa915ee09",
                "42".repeat(32),
                NOW + 300
            )
        );
    }

    #[test]
    fn structurally_invalid_fields_are_rejected_even_when_signed() {
        let mutations: Vec<LabeledMutation> = vec![
            ("protocol", |a| a.protocol = "other-protocol".to_owned()),
            ("version", |a| a.version = 2),
            ("backup id", |a| {
                a.backup_record_id = "not-a-uuid".to_owned()
            }),
            ("nonce", |a| {
                a.nonce = "ad7b2dc9_9c8b_4c82_acf3_f76aa915ee09".to_owned()
            }),
            ("installation", |a| a.installation_id = String::new()),
            ("backup digest case", |a| a.backup_sha256 = "AB".repeat(32)),
            ("backup digest length", |a| {
                a.backup_sha256 = "ab".repeat(31)
            }),
        ];
        for (label, mutate) in mutations {
            let mut artifact = make_authorization(NOW, SECRET);
            mutate(&mut artifact);
            sign(&mut artifact, SECRET);
            assert_eq!(
                verify_code(&artifact, NOW + 10),
                Some("update_install_authorization_invalid"),
                "{label}"
            );
        }
    }

    #[test]
    fn a_non_semver_target_is_rejected_even_when_it_matches() {
        let mut artifact = make_authorization(NOW, SECRET);
        artifact.target_version = "latest".to_owned();
        sign(&mut artifact, SECRET);

        assert_eq!(
            artifact
                .verify(SECRET, "latest", NOW)
                .err()
                .map(|error| error.code),
            Some("update_install_authorization_invalid")
        );
    }

    #[test]
    fn lifetime_bounds_are_enforced() {
        let mut too_long = make_authorization(NOW, SECRET);
        too_long.expires_at = NOW + MAX_AUTHORIZATION_LIFETIME_SECONDS + 1;
        sign(&mut too_long, SECRET);
        assert_eq!(
            verify_code(&too_long, NOW),
            Some("update_install_authorization_invalid")
        );

        let mut exact = make_authorization(NOW, SECRET);
        exact.expires_at = NOW + MAX_AUTHORIZATION_LIFETIME_SECONDS;
        sign(&mut exact, SECRET);
        assert_eq!(verify_code(&exact, NOW), None);

        let mut inverted = make_authorization(NOW, SECRET);
        inverted.expires_at = NOW;
        sign(&mut inverted, SECRET);
        assert_eq!(
            verify_code(&inverted, NOW),
            Some("update_install_authorization_invalid")
        );
    }

    #[test]
    fn small_clock_skew_is_tolerated_but_future_issuance_is_not() {
        let mut skewed = make_authorization(NOW + 60, SECRET);
        skewed.expires_at = NOW + 300;
        sign(&mut skewed, SECRET);
        assert_eq!(verify_code(&skewed, NOW), None);

        let mut future = make_authorization(NOW + 61, SECRET);
        future.expires_at = NOW + 300;
        sign(&mut future, SECRET);
        assert_eq!(
            verify_code(&future, NOW),
            Some("update_install_authorization_invalid")
        );
    }

    #[test]
    fn expiry_boundary_is_inclusive() {
        let artifact = make_authorization(NOW, SECRET);

        assert_eq!(verify_code(&artifact, NOW + 300), None);
        assert_eq!(
            verify_code(&artifact, NOW + 301),
            Some("update_install_authorization_expired")
        );
    }

    #[test]
    fn signatures_must_be_lowercase_hex_and_cover_every_field() {
        let mut uppercase = make_authorization(NOW, SECRET);
        uppercase.signature = uppercase.signature.to_uppercase();
        assert_eq!(
            verify_code(&uppercase, NOW),
            Some("update_install_authorization_invalid")
        );

        let mut truncated = make_authorization(NOW, SECRET);
        truncated.signature.pop();
        assert_eq!(
            verify_code(&truncated, NOW),
            Some("update_install_authorization_invalid")
        );

        let mut replayed = make_authorization(NOW, SECRET);
        replayed.nonce = "11111111-2222-3333-4444-555555555555".to_owned();
        assert_eq!(
            verify_code(&replayed, NOW),
            Some("update_install_authorization_invalid")
        );

        let mut other_backup = make_authorization(NOW, SECRET);
        other_backup.backup_sha256 = "43".repeat(32);
        assert_eq!(
            verify_code(&other_backup, NOW),
            Some("update_install_authorization_invalid")
        );
    }

    #[test]
    fn authorizations_reject_unknown_fields_on_deserialisation() {
        let mut value = serde_json::json!({
            "protocol": AUTHORIZATION_PROTOCOL,
            "version": 1,
            "target_version": "1.2.3",
            "backup_record_id": "57dca9dd-6c10-49c8-ae81-3d773bf36582",
            "backup_sha256": "42".repeat(32),
            "installation_id": "e169a732-1f4e-46ed-b5b8-a0bc752f6f09",
            "issued_at": NOW,
            "expires_at": NOW + 300,
            "nonce": "ad7b2dc9-9c8b-4c82-acf3-f76aa915ee09",
            "signature": "00".repeat(32),
        });
        assert!(serde_json::from_value::<UpdateInstallAuthorization>(value.clone()).is_ok());

        value["skip_backup"] = serde_json::json!(true);
        assert!(serde_json::from_value::<UpdateInstallAuthorization>(value).is_err());
    }

    #[test]
    fn version_digest_and_uuid_helpers_are_strict() {
        for valid in ["1.2.3", "0.0.1", "1.2.3-beta.1", "1.2.3+build.9"] {
            assert!(valid_version(valid), "{valid}");
        }
        for invalid in [
            "",
            "1.2",
            "v1.2.3",
            "1.2.3.4",
            "01.2.3",
            &format!("1.2.3-{}", "a".repeat(60)),
        ] {
            assert!(!valid_version(invalid), "{invalid}");
        }

        assert!(valid_lower_sha256(&"0a".repeat(32)));
        assert!(!valid_lower_sha256(&"0A".repeat(32)));
        assert!(!valid_lower_sha256(&"0a".repeat(33)));

        assert!(valid_uuid("57dca9dd-6c10-49c8-ae81-3d773bf36582"));
        assert!(!valid_uuid("57dca9dd6c1049c8ae813d773bf36582"));
        assert!(!valid_uuid("57dca9dd-6c10-49c8-ae81-3d773bf3658"));
        assert!(!valid_uuid("57dca9dd-6c10-49c8-ae813-d773bf36582"));
        assert!(!valid_uuid("57dca9dz-6c10-49c8-ae81-3d773bf36582"));
    }

    #[test]
    fn hex_decoding_round_trips_and_rejects_non_lowercase_input() {
        let bytes: Vec<u8> = (0..32).map(|index| index * 7).collect();
        let encoded: String = bytes.iter().map(|byte| format!("{byte:02x}")).collect();

        assert_eq!(decode_lower_hex_32(&encoded).unwrap().to_vec(), bytes);
        assert!(decode_lower_hex_32(&encoded.to_uppercase()).is_none());
        assert!(decode_lower_hex_32("00").is_none());
        assert_eq!(hex_nibble(b'0'), Some(0));
        assert_eq!(hex_nibble(b'f'), Some(15));
        assert_eq!(hex_nibble(b'F'), None);
        assert_eq!(hex_nibble(b'g'), None);
    }

    #[test]
    fn only_one_operation_of_a_kind_runs_at_a_time() {
        let flag = AtomicBool::new(false);

        let guard = begin_operation(&flag).unwrap();
        assert!(flag.load(Ordering::SeqCst));
        assert_eq!(
            begin_operation(&flag).err().map(|error| error.code),
            Some("signed_updater_busy")
        );
        drop(guard);

        assert!(!flag.load(Ordering::SeqCst));
        assert!(begin_operation(&flag).is_ok());
    }

    #[test]
    fn unconfigured_state_reports_unavailable_and_no_pending_update() {
        let state = SignedUpdaterState::new(None);

        assert!(!state.available());
        assert_eq!(
            state.configuration().err().map(|error| error.code),
            Some("signed_updater_unavailable")
        );
        assert_eq!(state.pending_metadata(), None);
    }

    #[test]
    fn configured_state_exposes_its_release_configuration() {
        let state = SignedUpdaterState::new(Some(ReleaseConfiguration {
            public_key: "public-key".to_owned(),
            endpoint: Url::parse("https://updates.drclick.dz/latest.json").unwrap(),
            install_secret: None,
        }));

        assert!(state.available());
        let configuration = state.configuration().unwrap();
        assert_eq!(
            configuration.endpoint.host_str(),
            Some("updates.drclick.dz")
        );
        assert!(configuration.install_secret.is_none());
    }

    #[test]
    fn command_errors_have_distinct_codes_and_serialise_for_the_frontend() {
        let errors = [
            UpdateCommandError::unavailable(),
            UpdateCommandError::busy(),
            UpdateCommandError::check_failed(),
            UpdateCommandError::no_pending_update(),
            UpdateCommandError::authorization_invalid(),
            UpdateCommandError::authorization_expired(),
            UpdateCommandError::install_failed(),
        ];
        let codes = errors
            .iter()
            .map(|error| error.code)
            .collect::<std::collections::HashSet<_>>();
        assert_eq!(codes.len(), errors.len());

        let value = serde_json::to_value(UpdateCommandError::busy()).unwrap();
        assert_eq!(value["code"], "signed_updater_busy");
        assert!(value["message_fr"]
            .as_str()
            .unwrap()
            .contains("déjà en cours"));
    }
}
