use std::{
    fs::{self, OpenOptions},
    io::Write,
    net::{Ipv4Addr, Ipv6Addr},
    time::Duration,
};

use reqwest::{redirect::Policy, StatusCode};
use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Manager};
use url::{Host, Url};

const HEALTH_RESPONSE_LIMIT: u64 = 64 * 1024;
const LOOPBACK_DEVELOPMENT_PORT: u16 = 8000;

#[derive(Debug, Clone, Serialize)]
pub(crate) struct ServerProbe {
    pub(crate) url: String,
    pub(crate) status: String,
    pub(crate) version: String,
}

#[derive(Debug, Deserialize)]
struct HealthResponse {
    status: String,
    application: HealthApplication,
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
        "http" if is_exact_loopback_development_url(&url) => {}
        "http" => {
            return Err(
                "Un Hub du cabinet doit utiliser HTTPS. HTTP est limité à localhost:8000 pour le test local."
                    .to_owned(),
            )
        }
        _ => return Err("Le serveur doit utiliser HTTPS.".to_owned()),
    }

    url.set_path("/");

    Ok(url)
}

pub(crate) fn is_exact_loopback_development_url(url: &Url) -> bool {
    if url.scheme() != "http" || url.port() != Some(LOOPBACK_DEVELOPMENT_PORT) {
        return false;
    }

    matches!(
        url.host(),
        Some(Host::Domain("localhost"))
            | Some(Host::Ipv4(Ipv4Addr::LOCALHOST))
            | Some(Host::Ipv6(Ipv6Addr::LOCALHOST))
    )
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

    Ok(ServerProbe {
        url: url.as_str().to_owned(),
        status: health.status,
        version: health.application.version,
    })
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

    #[test]
    fn https_cloud_and_hub_origins_are_valid() {
        for accepted in [
            "https://app.medismart.dz",
            "https://hub-cabinet-42.drclick.local:8443/",
            "https://192.168.1.20/",
        ] {
            assert!(validate_server_url(accepted).is_ok(), "{accepted}");
        }
    }

    #[test]
    fn http_is_limited_to_exact_loopback_development_origins() {
        for accepted in [
            "http://localhost:8000/",
            "http://127.0.0.1:8000/",
            "http://[::1]:8000/",
        ] {
            assert!(validate_server_url(accepted).is_ok(), "{accepted}");
        }

        for rejected in [
            "http://192.168.1.20:8000/",
            "http://localhost:8001/",
            "http://127.0.0.2:8000/",
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
