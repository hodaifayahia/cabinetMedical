use std::{
    fs::{self, OpenOptions},
    io::Write,
    time::Duration,
};

use reqwest::{redirect::Policy, StatusCode};
use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Manager};
use url::Url;

const HEALTH_RESPONSE_LIMIT: u64 = 64 * 1024;

#[derive(Debug, Clone, Serialize)]
pub(crate) struct ServerProbe {
    pub(crate) url: String,
    pub(crate) status: String,
    pub(crate) version: String,
    /// Present only when the endpoint is a Cabinet Hub. The connection screen
    /// shows which cabinet was reached so the operator confirms the Hub before
    /// anyone signs in to it (ADR-002: discovery is not trust).
    pub(crate) hub: Option<HubIdentity>,
}

/// The Hub identity advertised by `/health`. It is deliberately readable
/// without credentials: a desktop must establish which cabinet's Hub it found
/// before it can authenticate against it.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub(crate) struct HubIdentity {
    pub(crate) mode: String,
    pub(crate) protocol_version: u32,
    pub(crate) hub_id: Option<String>,
    pub(crate) cabinet_id: Option<u64>,
    pub(crate) hostname: Option<String>,
    pub(crate) tls_spki_sha256: Option<String>,
    pub(crate) ready: bool,
    pub(crate) reason: Option<String>,
}

/// Highest advertisement version this build knows how to read.
const SUPPORTED_HUB_PROTOCOL_VERSION: u32 = 1;

#[derive(Debug, Deserialize)]
struct HealthResponse {
    status: String,
    application: HealthApplication,
    #[serde(default)]
    hub: Option<HubIdentity>,
}

#[derive(Debug, Deserialize)]
struct HealthApplication {
    name: String,
    version: String,
}

#[derive(Serialize)]
struct ServerConfiguration<'a> {
    url: &'a str,
}

pub(crate) fn validate_server_url(value: &str) -> Result<Url, String> {
    let mut url = Url::parse(value.trim())
        .map_err(|_| "Saisissez une adresse de serveur valide.".to_owned())?;

    if url.username() != "" || url.password().is_some() {
        return Err("Les identifiants ne doivent pas figurer dans l’adresse.".to_owned());
    }

    if url.query().is_some() || url.fragment().is_some() || url.path() != "/" {
        return Err(
            "Saisissez uniquement l’adresse du serveur, sans chemin ni paramètres.".to_owned(),
        );
    }

    match url.scheme() {
        "https" if url.host().is_some() => {}
        _ => return Err("Le serveur doit utiliser HTTPS.".to_owned()),
    }

    url.set_path("/");

    Ok(url)
}

pub(crate) async fn probe_server(url: &Url) -> Result<ServerProbe, String> {
    let health_url = url
        .join("health")
        .map_err(|_| "Impossible de construire l’adresse de vérification.".to_owned())?;
    let client = reqwest::Client::builder()
        .redirect(Policy::none())
        .connect_timeout(Duration::from_secs(4))
        .timeout(Duration::from_secs(8))
        .user_agent("Drclick-Desktop/0.1")
        .build()
        .map_err(|_| "Impossible de préparer la vérification du serveur.".to_owned())?;

    let response = client
        .get(health_url)
        .header("Accept", "application/json")
        .send()
        .await
        .map_err(|_| {
            "Le serveur ne répond pas ou son certificat HTTPS n’est pas valide.".to_owned()
        })?;

    if !matches!(
        response.status(),
        StatusCode::OK | StatusCode::SERVICE_UNAVAILABLE
    ) {
        return Err("Cette adresse ne répond pas comme un serveur Drclick.".to_owned());
    }

    if response
        .content_length()
        .is_some_and(|length| length > HEALTH_RESPONSE_LIMIT)
    {
        return Err("La réponse du serveur est invalide.".to_owned());
    }

    let bytes = response
        .bytes()
        .await
        .map_err(|_| "La réponse du serveur est illisible.".to_owned())?;
    if bytes.len() as u64 > HEALTH_RESPONSE_LIMIT {
        return Err("La réponse du serveur est invalide.".to_owned());
    }

    let health: HealthResponse = serde_json::from_slice(&bytes)
        .map_err(|_| "Cette adresse ne répond pas comme un serveur Drclick.".to_owned())?;
    if health.application.name != "Drclick"
        || !matches!(health.status.as_str(), "healthy" | "degraded")
    {
        return Err("Cette adresse ne répond pas comme un serveur Drclick.".to_owned());
    }

    // A Hub that cannot say which cabinet it serves is refused here rather
    // than after someone has typed their password into it.
    if let Some(hub) = health.hub.as_ref() {
        validate_hub_identity(hub)?;
    }

    Ok(ServerProbe {
        url: url.as_str().to_owned(),
        status: health.status,
        version: health.application.version,
        hub: health.hub,
    })
}

/// Reject a Hub this build cannot safely talk to, before any credential is
/// offered to it.
pub(crate) fn validate_hub_identity(hub: &HubIdentity) -> Result<(), String> {
    if hub.mode != "hub" {
        return Err("Cette adresse ne répond pas comme un Hub Drclick.".to_owned());
    }

    if hub.protocol_version > SUPPORTED_HUB_PROTOCOL_VERSION {
        return Err(
            "Ce Hub utilise une version plus récente de Drclick. Mettez ce poste à jour.".to_owned(),
        );
    }

    if !hub.ready || hub.hub_id.is_none() || hub.cabinet_id.is_none() {
        return Err(
            "Ce Hub n’est relié à aucun cabinet valide. Contactez le support Drclick.".to_owned(),
        );
    }

    Ok(())
}

pub(crate) fn persist_server_url(app: &AppHandle, url: &Url) -> Result<(), String> {
    let configuration_directory = app
        .path()
        .app_local_data_dir()
        .map_err(|_| "Impossible d’ouvrir le dossier de configuration.".to_owned())?
        .join("config");
    fs::create_dir_all(&configuration_directory)
        .map_err(|_| "Impossible de créer le dossier de configuration.".to_owned())?;

    let target = configuration_directory.join("server.json");
    let temporary = configuration_directory.join("server.json.tmp");
    let payload = serde_json::to_vec_pretty(&ServerConfiguration { url: url.as_str() })
        .map_err(|_| "Impossible de préparer la configuration.".to_owned())?;

    let mut file = OpenOptions::new()
        .create(true)
        .truncate(true)
        .write(true)
        .open(&temporary)
        .map_err(|_| "Impossible d’écrire la configuration.".to_owned())?;
    file.write_all(&payload)
        .and_then(|_| file.sync_all())
        .map_err(|_| "Impossible d’enregistrer la configuration.".to_owned())?;
    drop(file);

    if target.exists() {
        fs::remove_file(&target)
            .map_err(|_| "Impossible de remplacer l’ancienne configuration.".to_owned())?;
    }
    fs::rename(&temporary, &target)
        .map_err(|_| "Impossible d’activer la nouvelle configuration.".to_owned())?;

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn hub() -> HubIdentity {
        HubIdentity {
            mode: "hub".to_owned(),
            protocol_version: SUPPORTED_HUB_PROTOCOL_VERSION,
            hub_id: Some("hub-01HZ".to_owned()),
            cabinet_id: Some(42),
            hostname: Some("hub-cabinet.drclick.local".to_owned()),
            tls_spki_sha256: Some("ab".repeat(32)),
            ready: true,
            reason: None,
        }
    }

    #[test]
    fn a_complete_hub_identity_is_accepted() {
        assert!(validate_hub_identity(&hub()).is_ok());
    }

    #[test]
    fn a_hub_that_names_no_cabinet_is_refused() {
        let mut unbound = hub();
        unbound.cabinet_id = None;
        unbound.ready = false;
        unbound.reason = Some("hub_cabinet_missing".to_owned());

        assert!(validate_hub_identity(&unbound).is_err());
    }

    #[test]
    fn a_hub_without_an_identity_is_refused() {
        let mut anonymous = hub();
        anonymous.hub_id = None;
        anonymous.ready = false;

        assert!(validate_hub_identity(&anonymous).is_err());
    }

    #[test]
    fn a_newer_protocol_is_refused_rather_than_guessed_at() {
        let mut newer = hub();
        newer.protocol_version = SUPPORTED_HUB_PROTOCOL_VERSION + 1;

        assert!(validate_hub_identity(&newer).is_err());
    }

    #[test]
    fn health_without_a_hub_block_parses_as_the_hosted_service() {
        let body = r#"{"status":"healthy","application":{"name":"Drclick","version":"0.1.1"}}"#;
        let health: HealthResponse = serde_json::from_str(body).unwrap();

        assert!(health.hub.is_none());
    }

    #[test]
    fn health_with_a_hub_block_parses_the_identity() {
        let body = r#"{"status":"healthy","application":{"name":"Drclick","version":"0.1.1"},
            "hub":{"mode":"hub","protocol_version":1,"hub_id":"hub-01HZ","cabinet_id":42,
            "hostname":"hub.local","tls_spki_sha256":null,"ready":true,"reason":null}}"#;
        let health: HealthResponse = serde_json::from_str(body).unwrap();
        let identity = health.hub.expect("hub identity");

        assert_eq!(identity.cabinet_id, Some(42));
        assert!(validate_hub_identity(&identity).is_ok());
    }

    #[test]
    fn https_cloud_and_hub_origins_are_valid() {
        for accepted in [
            "https://app.drclick.dz",
            "https://hub-cabinet-42.drclick.local:8443/",
            "https://192.168.1.20/",
        ] {
            assert!(validate_server_url(accepted).is_ok(), "{accepted}");
        }
    }

    #[test]
    fn http_and_non_https_origins_are_rejected() {
        for rejected in [
            "http://localhost:8000/",
            "http://127.0.0.1:8000/",
            "http://[::1]:8000/",
            "http://192.168.1.20:8000/",
            "ftp://localhost:8000/",
        ] {
            assert!(validate_server_url(rejected).is_err(), "{rejected}");
        }
    }

    #[test]
    fn credentials_paths_queries_and_fragments_are_rejected() {
        for rejected in [
            "https://user:pass@hub.example.test/",
            "https://hub.example.test/login",
            "https://hub.example.test/?cabinet=1",
            "https://hub.example.test/#login",
        ] {
            assert!(validate_server_url(rejected).is_err(), "{rejected}");
        }
    }
}
