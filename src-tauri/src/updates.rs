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
}

#[derive(Clone)]
struct AuthorizationContext {
    app_key: String,
    installation_id: String,
}

pub struct SignedUpdaterState {
    configuration: Option<ReleaseConfiguration>,
    authorization: Mutex<Option<AuthorizationContext>>,
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
            authorization: Mutex::new(None),
            pending: Mutex::new(None),
            checking: AtomicBool::new(false),
            installing: AtomicBool::new(false),
            last_checked_at: AtomicU64::new(0),
        }
    }

    pub fn configure_authorization(
        &self,
        app_key: Option<String>,
        installation_id: Option<String>,
    ) {
        let context = match (app_key, installation_id) {
            (Some(app_key), Some(installation_id))
                if !app_key.is_empty() && valid_uuid(&installation_id) =>
            {
                Some(AuthorizationContext {
                    app_key,
                    installation_id,
                })
            }
            _ => None,
        };

        if let Ok(mut current) = self.authorization.lock() {
            *current = context;
        }
    }

    fn available(&self) -> bool {
        self.configuration.is_some()
            && self
                .authorization
                .lock()
                .is_ok_and(|authorization| authorization.is_some())
    }

    fn configuration(&self) -> Result<ReleaseConfiguration, UpdateCommandError> {
        if !self.available() {
            return Err(UpdateCommandError::unavailable());
        }

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

    fn authorization_context(&self) -> Result<AuthorizationContext, UpdateCommandError> {
        self.authorization
            .lock()
            .ok()
            .and_then(|authorization| authorization.clone())
            .ok_or_else(UpdateCommandError::unavailable)
    }
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

    Some(ReleaseConfiguration {
        public_key: public_key.to_owned(),
        endpoint,
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
                "Le programme de mise à jour signé n’est pas disponible dans cette version.",
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
            message_fr: "Aucune mise à jour vérifiée n’attend une installation.",
        }
    }

    fn authorization_invalid() -> Self {
        Self {
            code: "update_install_authorization_invalid",
            message_fr:
                "L’autorisation d’installation ou sa sauvegarde de sécurité n’est pas valide.",
        }
    }

    fn authorization_expired() -> Self {
        Self {
            code: "update_install_authorization_expired",
            message_fr:
                "L’autorisation d’installation a expiré. Recréez la sauvegarde de sécurité.",
        }
    }

    fn install_failed() -> Self {
        Self {
            code: "signed_update_install_failed",
            message_fr: "La mise à jour signée n’a pas pu être téléchargée ou installée.",
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

    fn verify(
        &self,
        context: &AuthorizationContext,
        expected_target_version: &str,
        now: u64,
    ) -> Result<(), UpdateCommandError> {
        if self.protocol != AUTHORIZATION_PROTOCOL
            || self.version != AUTHORIZATION_VERSION
            || self.target_version != expected_target_version
            || self.installation_id != context.installation_id
            || !valid_version(&self.target_version)
            || !valid_uuid(&self.backup_record_id)
            || !valid_uuid(&self.nonce)
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
        let mut mac = Hmac::<Sha256>::new_from_slice(context.app_key.as_bytes())
            .map_err(|_| UpdateCommandError::authorization_invalid())?;
        mac.update(self.canonical_payload().as_bytes());
        mac.verify_slice(&signature)
            .map_err(|_| UpdateCommandError::authorization_invalid())
    }
}

#[tauri::command]
pub async fn install_signed_update(
    app: AppHandle,
    state: State<'_, SignedUpdaterState>,
    authorization: UpdateInstallAuthorization,
) -> Result<UpdateInstallResponse, UpdateCommandError> {
    let _operation = begin_operation(&state.installing)?;
    let _configuration = state.configuration()?;
    let context = state.authorization_context()?;
    let update = state
        .pending
        .lock()
        .map_err(|_| UpdateCommandError::no_pending_update())?
        .clone()
        .ok_or_else(UpdateCommandError::no_pending_update)?;

    authorization.verify(&context, &update.version, unix_timestamp())?;

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
        app.restart();
    }

    #[cfg(windows)]
    {
        Ok(UpdateInstallResponse {
            accepted: true,
            target_version,
            message_fr: "La mise à jour vérifiée est installée; MediSmart va redémarrer.",
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

    fn authorization(now: u64) -> (UpdateInstallAuthorization, AuthorizationContext) {
        let context = AuthorizationContext {
            app_key: "base64:test-app-key".to_owned(),
            installation_id: "e169a732-1f4e-46ed-b5b8-a0bc752f6f09".to_owned(),
        };
        let mut artifact = UpdateInstallAuthorization {
            protocol: AUTHORIZATION_PROTOCOL.to_owned(),
            version: AUTHORIZATION_VERSION,
            target_version: "1.2.3".to_owned(),
            backup_record_id: "57dca9dd-6c10-49c8-ae81-3d773bf36582".to_owned(),
            backup_sha256: "42".repeat(32),
            installation_id: context.installation_id.clone(),
            issued_at: now,
            expires_at: now + 300,
            nonce: "ad7b2dc9-9c8b-4c82-acf3-f76aa915ee09".to_owned(),
            signature: String::new(),
        };
        let mut mac = Hmac::<Sha256>::new_from_slice(context.app_key.as_bytes()).unwrap();
        mac.update(artifact.canonical_payload().as_bytes());
        artifact.signature = mac
            .finalize()
            .into_bytes()
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();

        (artifact, context)
    }

    #[test]
    fn content_bound_backup_authorization_is_accepted_once_fields_match() {
        let (artifact, context) = authorization(1_700_000_000);

        artifact.verify(&context, "1.2.3", 1_700_000_120).unwrap();
    }

    #[test]
    fn authorization_rejects_target_tampering_wrong_installation_and_expiry() {
        let (mut artifact, context) = authorization(1_700_000_000);
        artifact.target_version = "1.2.4".to_owned();
        assert_eq!(
            artifact
                .verify(&context, "1.2.4", 1_700_000_120)
                .unwrap_err()
                .code,
            "update_install_authorization_invalid"
        );

        let (mut artifact, context) = authorization(1_700_000_000);
        artifact.installation_id = "a9ea5c31-6489-43a2-9341-c2fc0dbd9606".to_owned();
        assert!(artifact.verify(&context, "1.2.3", 1_700_000_120).is_err());

        let (artifact, context) = authorization(1_700_000_000);
        assert_eq!(
            artifact
                .verify(&context, "1.2.3", 1_700_000_601)
                .unwrap_err()
                .code,
            "update_install_authorization_expired"
        );
    }

    #[test]
    fn debug_builds_never_claim_release_updater_configuration() {
        if cfg!(debug_assertions) {
            assert!(compiled_release_configuration().is_none());
            assert!(!SignedUpdaterState::compiled().available());
        }
    }
}
