use std::collections::BTreeMap;

use url::Url;

const GOOGLE_AUTHORIZATION_HOST: &str = "accounts.google.com";
const GOOGLE_AUTHORIZATION_PATH: &str = "/o/oauth2/v2/auth";
const GOOGLE_DRIVE_FILE_SCOPE: &str = "https://www.googleapis.com/auth/drive.file";
const GOOGLE_CALLBACK_PATH: &str = "/app/configuration/backup/google/callback";
const MAXIMUM_AUTHORIZATION_URL_BYTES: usize = 16 * 1024;
const MAXIMUM_CLIENT_ID_BYTES: usize = 512;
const REQUIRED_QUERY_KEYS: [&str; 9] = [
    "access_type",
    "client_id",
    "code_challenge",
    "code_challenge_method",
    "prompt",
    "redirect_uri",
    "response_type",
    "scope",
    "state",
];

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum GoogleOAuthOpenFailure {
    InvalidAuthorizationUrl,
    BrowserOpenFailed,
}

pub(crate) fn validate_and_open_google_oauth_authorization<E>(
    authorization_url: &str,
    current_loopback_port: u16,
    opener: impl FnOnce(&str) -> Result<(), E>,
) -> Result<(), GoogleOAuthOpenFailure> {
    let url = validate_google_oauth_authorization_url(authorization_url, current_loopback_port)?;
    opener(url.as_str()).map_err(|_| GoogleOAuthOpenFailure::BrowserOpenFailed)
}

fn validate_google_oauth_authorization_url(
    authorization_url: &str,
    current_loopback_port: u16,
) -> Result<Url, GoogleOAuthOpenFailure> {
    if authorization_url.is_empty()
        || authorization_url.len() > MAXIMUM_AUTHORIZATION_URL_BYTES
        || authorization_url.trim() != authorization_url
        || authorization_url
            .chars()
            .any(|character| character.is_control() || character.is_whitespace())
        || current_loopback_port < 1024
    {
        return Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl);
    }

    let url = Url::parse(authorization_url)
        .map_err(|_| GoogleOAuthOpenFailure::InvalidAuthorizationUrl)?;
    if url.scheme() != "https"
        || url.host_str() != Some(GOOGLE_AUTHORIZATION_HOST)
        || url.port_or_known_default() != Some(443)
        || !url.username().is_empty()
        || url.password().is_some()
        || url.path() != GOOGLE_AUTHORIZATION_PATH
        || url.fragment().is_some()
        || url.query().is_none()
    {
        return Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl);
    }

    let mut query = BTreeMap::new();
    for (key, value) in url.query_pairs() {
        if query.insert(key.into_owned(), value.into_owned()).is_some() {
            return Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl);
        }
    }
    if query.len() != REQUIRED_QUERY_KEYS.len()
        || REQUIRED_QUERY_KEYS
            .iter()
            .any(|key| !query.contains_key(*key))
    {
        return Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl);
    }

    let expected_redirect =
        format!("http://127.0.0.1:{current_loopback_port}{GOOGLE_CALLBACK_PATH}");
    let client_id = query
        .get("client_id")
        .map(String::as_str)
        .unwrap_or_default();
    let state = query.get("state").map(String::as_str).unwrap_or_default();
    let challenge = query
        .get("code_challenge")
        .map(String::as_str)
        .unwrap_or_default();

    if !valid_client_id(client_id)
        || !valid_base64url_43(state)
        || !valid_base64url_43(challenge)
        || query.get("redirect_uri").map(String::as_str) != Some(expected_redirect.as_str())
        || query.get("response_type").map(String::as_str) != Some("code")
        || query.get("scope").map(String::as_str) != Some(GOOGLE_DRIVE_FILE_SCOPE)
        || query.get("access_type").map(String::as_str) != Some("offline")
        || query.get("prompt").map(String::as_str) != Some("consent")
        || query.get("code_challenge_method").map(String::as_str) != Some("S256")
    {
        return Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl);
    }

    Ok(url)
}

fn valid_client_id(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= MAXIMUM_CLIENT_ID_BYTES
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'-'))
}

fn valid_base64url_43(value: &str) -> bool {
    value.len() == 43
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-'))
}

#[cfg(test)]
mod tests {
    use std::sync::{Arc, Mutex};

    use super::*;

    const PORT: u16 = 43123;
    const STATE: &str = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ";
    const CHALLENGE: &str = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFG";

    fn authorization_url() -> String {
        let mut url = Url::parse("https://accounts.google.com/o/oauth2/v2/auth").unwrap();
        url.query_pairs_mut()
            .append_pair("client_id", "123-client.apps.googleusercontent.com")
            .append_pair(
                "redirect_uri",
                "http://127.0.0.1:43123/app/configuration/backup/google/callback",
            )
            .append_pair("response_type", "code")
            .append_pair("scope", GOOGLE_DRIVE_FILE_SCOPE)
            .append_pair("access_type", "offline")
            .append_pair("prompt", "consent")
            .append_pair("state", STATE)
            .append_pair("code_challenge", CHALLENGE)
            .append_pair("code_challenge_method", "S256");
        url.into()
    }

    fn replace_parameter(name: &str, replacement: &str) -> String {
        let mut url = Url::parse(&authorization_url()).unwrap();
        let parameters = url
            .query_pairs()
            .map(|(key, value)| {
                let value = if key == name {
                    replacement.to_owned()
                } else {
                    value.into_owned()
                };
                (key.into_owned(), value)
            })
            .collect::<Vec<_>>();
        url.set_query(None);
        url.query_pairs_mut().extend_pairs(parameters);
        url.into()
    }

    #[test]
    fn exact_backend_authorization_url_is_opened_once() {
        let opened = Arc::new(Mutex::new(Vec::new()));
        let captured = Arc::clone(&opened);
        let authorization_url = authorization_url();

        validate_and_open_google_oauth_authorization(&authorization_url, PORT, move |validated| {
            captured.lock().unwrap().push(validated.to_owned());
            Ok::<_, ()>(())
        })
        .unwrap();

        assert_eq!(*opened.lock().unwrap(), vec![authorization_url]);
    }

    #[test]
    fn endpoint_credentials_fragments_and_nondefault_ports_are_rejected() {
        for invalid in [
            authorization_url().replacen("https://", "http://", 1),
            authorization_url().replacen(
                "accounts.google.com",
                "accounts.google.com.attacker.invalid",
                1,
            ),
            authorization_url().replacen("/o/oauth2/v2/auth", "/o/oauth2/auth", 1),
            authorization_url().replacen("https://", "https://user@", 1),
            authorization_url().replacen("accounts.google.com", "accounts.google.com:444", 1),
            format!("{}#fragment", authorization_url()),
        ] {
            assert_eq!(
                validate_google_oauth_authorization_url(&invalid, PORT),
                Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl)
            );
        }
    }

    #[test]
    fn redirect_scope_response_type_and_pkce_are_exact() {
        for (name, invalid_value) in [
            (
                "redirect_uri",
                "http://127.0.0.1:43124/app/configuration/backup/google/callback",
            ),
            (
                "redirect_uri",
                "http://localhost:43123/app/configuration/backup/google/callback",
            ),
            (
                "redirect_uri",
                "http://127.0.0.1:43123/app/configuration/backup/google/callback/extra",
            ),
            ("scope", "https://www.googleapis.com/auth/drive"),
            ("response_type", "token"),
            ("code_challenge_method", "plain"),
            ("code_challenge", "short"),
            ("state", ""),
            ("client_id", ""),
            ("access_type", "online"),
            ("prompt", "none"),
        ] {
            assert_eq!(
                validate_google_oauth_authorization_url(
                    &replace_parameter(name, invalid_value),
                    PORT,
                ),
                Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl)
            );
        }
    }

    #[test]
    fn duplicate_unknown_or_missing_query_parameters_are_rejected_without_opening() {
        let duplicate = format!("{}&state={STATE}", authorization_url());
        let unknown = format!(
            "{}&continue=https%3A%2F%2Fattacker.invalid",
            authorization_url()
        );
        let missing = authorization_url().replace("&prompt=consent", "");

        for invalid in [duplicate, unknown, missing] {
            let invoked = Arc::new(Mutex::new(false));
            let captured = Arc::clone(&invoked);
            assert_eq!(
                validate_and_open_google_oauth_authorization(&invalid, PORT, move |_| {
                    *captured.lock().unwrap() = true;
                    Ok::<_, ()>(())
                }),
                Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl)
            );
            assert!(!*invoked.lock().unwrap());
        }
    }

    #[test]
    fn opener_failure_is_stable_and_the_current_port_is_mandatory() {
        assert_eq!(
            validate_and_open_google_oauth_authorization(&authorization_url(), PORT, |_| {
                Err("unavailable")
            }),
            Err(GoogleOAuthOpenFailure::BrowserOpenFailed)
        );
        assert_eq!(
            validate_google_oauth_authorization_url(&authorization_url(), 0),
            Err(GoogleOAuthOpenFailure::InvalidAuthorizationUrl)
        );
    }
}
