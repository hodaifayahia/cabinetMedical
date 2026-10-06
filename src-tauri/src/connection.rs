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
        // A Drclick "poste principal" on the cabinet's own network serves plain
        // HTTP: there is no certificate a LAN IP could present. That is only
        // accepted for addresses that cannot be on the Internet. Every other
        // origin (the hosted service, a public Hub) still requires HTTPS.
        "http" if is_private_lan_host(&url) => {}
        _ => return Err("Le serveur doit utiliser HTTPS.".to_owned()),
    }

    url.set_path("/");

    Ok(url)
}

/// True when `url` names a machine that can only be on the local network:
/// a private or link-local IPv4 address, an IPv6 unique-local or link-local
/// address, a bare Windows computer name (`CABINET-PC`), or an mDNS `.local`
/// name. Loopback is excluded: attaching a PC to itself is never what the
/// clinic meant.
pub(crate) fn is_private_lan_host(url: &Url) -> bool {
    match url.host() {
        Some(url::Host::Ipv4(address)) => address.is_private() || address.is_link_local(),
        Some(url::Host::Ipv6(address)) => {
            let first = address.segments()[0];
            (first & 0xfe00) == 0xfc00 || (first & 0xffc0) == 0xfe80
        }
        Some(url::Host::Domain(name)) => {
            let name = name.trim_end_matches('.');
            if name.eq_ignore_ascii_case("localhost") || name.is_empty() {
                return false;
            }
            let single_label = !name.contains('.');
            let mdns = name
                .rsplit_once('.')
                .is_some_and(|(label, tld)| !label.is_empty() && tld.eq_ignore_ascii_case("local"));

            (single_label || mdns)
                && name.split('.').all(|label| {
                    !label.is_empty()
                        && label.len() <= 63
                        && label
                            .bytes()
                            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
                })
        }
        None => false,
    }
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
            "Ce Hub utilise une version plus récente de Drclick. Mettez ce poste à jour."
                .to_owned(),
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
            "http://8.8.8.8:47850/",
            "http://172.32.0.1:47850/",
            "http://hub.example.com/",
            "http://app.drclick.dz/",
            "http://evil.local.example.com/",
            "ftp://localhost:8000/",
        ] {
            assert!(validate_server_url(rejected).is_err(), "{rejected}");
        }
    }

    #[test]
    fn plain_http_is_accepted_only_for_a_poste_principal_on_the_lan() {
        for accepted in [
            "http://192.168.1.20:47850/",
            "http://192.168.1.20:8000/",
            "http://10.0.0.5:47850",
            "http://172.16.4.2:47850/",
            "http://169.254.10.20:47850/",
            "http://CABINET-PC:47850/",
            "http://cabinet-pc.local:47850/",
            "http://[fd00::10]:47850/",
            "http://[fe80::1]:47850/",
        ] {
            let url =
                validate_server_url(accepted).unwrap_or_else(|error| panic!("{accepted}: {error}"));
            assert_eq!(url.scheme(), "http");
            assert_eq!(url.path(), "/");
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

    use std::{io::Read, net::TcpListener, sync::mpsc, thread};

    /// Serve exactly one canned HTTP response on a loopback port and report
    /// the raw request back, so `probe_server` is exercised without a network.
    fn one_shot_server(response: Vec<u8>) -> (Url, mpsc::Receiver<String>) {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let (sender, receiver) = mpsc::channel();
        thread::spawn(move || {
            let (mut stream, _) = listener.accept().unwrap();
            let mut request = Vec::new();
            let mut buffer = [0_u8; 1024];
            while !request.windows(4).any(|window| window == b"\r\n\r\n") {
                let read = stream.read(&mut buffer).unwrap_or(0);
                if read == 0 {
                    break;
                }
                request.extend_from_slice(&buffer[..read]);
            }
            let _ = stream.write_all(&response);
            let _ = stream.flush();
            let _ = sender.send(String::from_utf8_lossy(&request).into_owned());
        });
        (Url::parse(&format!("http://{address}/")).unwrap(), receiver)
    }

    fn http_response(status: &str, body: &str) -> Vec<u8> {
        format!(
            "HTTP/1.1 {status}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
            body.len()
        )
        .into_bytes()
    }

    fn probe(response: Vec<u8>) -> (Result<ServerProbe, String>, String) {
        let (url, request) = one_shot_server(response);
        let result = tauri::async_runtime::block_on(probe_server(&url));
        let request = request
            .recv_timeout(std::time::Duration::from_secs(5))
            .unwrap_or_default();
        (result, request)
    }

    const HEALTHY: &str =
        r#"{"status":"healthy","application":{"name":"Drclick","version":"2.3.4"}}"#;

    #[test]
    fn validation_trims_whitespace_and_normalises_to_the_root_path() {
        let url = validate_server_url("  https://Hub.Example.TEST  ").unwrap();

        assert_eq!(url.as_str(), "https://hub.example.test/");
        assert_eq!(
            validate_server_url("https://hub.example.test:8443")
                .unwrap()
                .as_str(),
            "https://hub.example.test:8443/"
        );
        assert_eq!(
            validate_server_url("https://hub.example.test:443/")
                .unwrap()
                .port(),
            None
        );
    }

    #[test]
    fn validation_reports_the_specific_problem_in_french() {
        assert_eq!(
            validate_server_url("").unwrap_err(),
            "Saisissez une adresse de serveur valide."
        );
        assert_eq!(
            validate_server_url("not a url").unwrap_err(),
            "Saisissez une adresse de serveur valide."
        );
        assert_eq!(
            validate_server_url("https://user@hub.example.test/").unwrap_err(),
            "Les identifiants ne doivent pas figurer dans l’adresse."
        );
        assert_eq!(
            validate_server_url("http://user:pw@hub.example.test/").unwrap_err(),
            "Les identifiants ne doivent pas figurer dans l’adresse."
        );
        assert_eq!(
            validate_server_url("https://hub.example.test/?").unwrap_err(),
            "Saisissez uniquement l’adresse du serveur, sans chemin ni paramètres."
        );
        assert_eq!(
            validate_server_url("http://hub.example.test/").unwrap_err(),
            "Le serveur doit utiliser HTTPS."
        );
        assert_eq!(
            validate_server_url("http://192.168.1.20:47850/login").unwrap_err(),
            "Saisissez uniquement l’adresse du serveur, sans chemin ni paramètres."
        );
    }

    #[test]
    fn non_web_schemes_are_never_accepted() {
        for rejected in [
            "file:///etc/passwd",
            "javascript:alert(1)",
            "data:text/html,hi",
            "wss://hub.example.test/",
            "mailto:support@drclick.dz",
        ] {
            assert!(validate_server_url(rejected).is_err(), "{rejected}");
        }
    }

    #[test]
    fn a_non_hub_mode_is_refused_with_a_hub_specific_message() {
        let mut cloud = hub();
        cloud.mode = "cloud".to_owned();

        assert_eq!(
            validate_hub_identity(&cloud).unwrap_err(),
            "Cette adresse ne répond pas comme un Hub Drclick."
        );
    }

    #[test]
    fn older_protocol_versions_remain_readable() {
        let mut older = hub();
        older.protocol_version = 0;

        assert!(validate_hub_identity(&older).is_ok());
    }

    #[test]
    fn a_hub_that_is_not_ready_is_refused_even_with_identity() {
        let mut not_ready = hub();
        not_ready.ready = false;

        assert_eq!(
            validate_hub_identity(&not_ready).unwrap_err(),
            "Ce Hub n’est relié à aucun cabinet valide. Contactez le support Drclick."
        );
    }

    #[test]
    fn missing_optional_hub_fields_parse_as_absent() {
        let identity: HubIdentity =
            serde_json::from_str(r#"{"mode":"hub","protocol_version":1,"ready":false}"#).unwrap();

        assert_eq!(identity.hub_id, None);
        assert_eq!(identity.cabinet_id, None);
        assert!(validate_hub_identity(&identity).is_err());
        assert!(serde_json::from_str::<HubIdentity>(r#"{"mode":"hub","ready":true}"#).is_err());
    }

    #[test]
    fn health_without_application_details_does_not_parse() {
        assert!(serde_json::from_str::<HealthResponse>(r#"{"status":"healthy"}"#).is_err());
        assert!(serde_json::from_str::<HealthResponse>(
            r#"{"status":"healthy","application":{"name":"Drclick"}}"#
        )
        .is_err());
    }

    #[test]
    fn probe_results_serialise_for_the_connection_screen() {
        let probe = ServerProbe {
            url: "https://hub.example.test/".to_owned(),
            status: "healthy".to_owned(),
            version: "2.3.4".to_owned(),
            hub: None,
        };

        assert_eq!(
            serde_json::to_value(&probe).unwrap(),
            serde_json::json!({
                "url": "https://hub.example.test/",
                "status": "healthy",
                "version": "2.3.4",
                "hub": null,
            })
        );
        assert_eq!(
            serde_json::to_string(&ServerConfiguration {
                url: "https://x.test/"
            })
            .unwrap(),
            r#"{"url":"https://x.test/"}"#
        );
    }

    #[test]
    fn probing_a_healthy_server_reads_its_version_from_the_health_endpoint() {
        let (result, request) = probe(http_response("200 OK", HEALTHY));

        let probe = result.unwrap();
        assert_eq!(probe.status, "healthy");
        assert_eq!(probe.version, "2.3.4");
        assert!(probe.hub.is_none());
        assert!(probe.url.starts_with("http://127.0.0.1:"));
        assert!(request.starts_with("GET /health HTTP/1.1\r\n"), "{request}");
        assert!(request
            .to_ascii_lowercase()
            .contains("accept: application/json"));
        assert!(request.contains("Drclick-Desktop/"));
    }

    #[test]
    fn a_degraded_server_answering_503_is_still_recognised() {
        let body = r#"{"status":"degraded","application":{"name":"Drclick","version":"2.3.4"}}"#;

        let (result, _) = probe(http_response("503 Service Unavailable", body));

        assert_eq!(result.unwrap().status, "degraded");
    }

    #[test]
    fn probing_rejects_other_statuses_and_redirects() {
        let (not_found, _) = probe(http_response("404 Not Found", HEALTHY));
        assert_eq!(
            not_found.unwrap_err(),
            "Cette adresse ne répond pas comme un serveur Drclick."
        );

        let (redirect, _) = probe(
            b"HTTP/1.1 302 Found\r\nLocation: https://evil.example/health\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
                .to_vec(),
        );
        assert!(redirect.is_err());
    }

    #[test]
    fn probing_rejects_foreign_applications_and_unknown_statuses() {
        let foreign = r#"{"status":"healthy","application":{"name":"OtherApp","version":"1.0.0"}}"#;
        let (result, _) = probe(http_response("200 OK", foreign));
        assert_eq!(
            result.unwrap_err(),
            "Cette adresse ne répond pas comme un serveur Drclick."
        );

        let broken = r#"{"status":"broken","application":{"name":"Drclick","version":"1.0.0"}}"#;
        let (result, _) = probe(http_response("200 OK", broken));
        assert!(result.is_err());

        let (result, _) = probe(http_response("200 OK", "<html>login</html>"));
        assert!(result.is_err());
    }

    #[test]
    fn probing_rejects_oversized_health_responses() {
        let declared = format!(
            "HTTP/1.1 200 OK\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
            HEALTH_RESPONSE_LIMIT + 1
        )
        .into_bytes();
        let (result, _) = probe(declared);
        assert_eq!(result.unwrap_err(), "La réponse du serveur est invalide.");

        let padding = " ".repeat(HEALTH_RESPONSE_LIMIT as usize);
        let mut streamed = b"HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n".to_vec();
        streamed.extend_from_slice(format!("{HEALTHY}{padding}").as_bytes());
        let (result, _) = probe(streamed);
        assert!(result.is_err());
    }

    #[test]
    fn probing_a_hub_validates_its_identity_before_returning_it() {
        let ready = r#"{"status":"healthy","application":{"name":"Drclick","version":"2.3.4"},
            "hub":{"mode":"hub","protocol_version":1,"hub_id":"hub-1","cabinet_id":7,
            "hostname":"hub.local","tls_spki_sha256":null,"ready":true,"reason":null}}"#;
        let (result, _) = probe(http_response("200 OK", ready));
        assert_eq!(result.unwrap().hub.unwrap().cabinet_id, Some(7));

        let unbound = r#"{"status":"healthy","application":{"name":"Drclick","version":"2.3.4"},
            "hub":{"mode":"hub","protocol_version":1,"hub_id":"hub-1","cabinet_id":null,
            "hostname":null,"tls_spki_sha256":null,"ready":false,"reason":"hub_cabinet_missing"}}"#;
        let (result, _) = probe(http_response("200 OK", unbound));
        assert_eq!(
            result.unwrap_err(),
            "Ce Hub n’est relié à aucun cabinet valide. Contactez le support Drclick."
        );
    }

    #[test]
    fn probing_an_unreachable_server_reports_a_connection_problem() {
        let port = {
            let listener = TcpListener::bind("127.0.0.1:0").unwrap();
            listener.local_addr().unwrap().port()
        };
        let url = Url::parse(&format!("http://127.0.0.1:{port}/")).unwrap();

        let result = tauri::async_runtime::block_on(probe_server(&url));

        assert_eq!(
            result.unwrap_err(),
            "Le serveur ne répond pas ou son certificat HTTPS n’est pas valide."
        );
    }
}
