# Google Drive installed-app OAuth contract

## Deployment configuration

MediSmart uses an installed-app OAuth client, PKCE S256, the operating system's
default browser, and only the `drive.file` scope. A production package needs:

```dotenv
GOOGLE_CLIENT_ID=<desktop OAuth client ID>
GOOGLE_CLIENT_SECRET=
GOOGLE_DRIVE_SCOPE=https://www.googleapis.com/auth/drive.file
```

`GOOGLE_CLIENT_SECRET` is optional. Leave it empty for a public desktop client;
an existing confidential deployment may continue supplying it. A secret bundled
inside a desktop executable is not considered confidential.

The native supervisor supplies these values for each run:

```dotenv
APP_URL=http://127.0.0.1:<selected-high-port>
MEDISMART_LOCAL_URL=http://127.0.0.1:<selected-high-port>
MEDISMART_DESKTOP_SUPERVISED=true
```

Laravel derives the callback as the exact runtime origin plus:

```text
/app/configuration/backup/google/callback
```

`GOOGLE_REDIRECT_URI` should normally be omitted. When retained for a
confidential compatibility deployment, it is only an assertion and must equal
the derived callback byte for byte. `localhost`, a wildcard, a LAN address, a
public hostname, HTTPS termination, a low port, or an unsupervised listener
does not enable OAuth.

The Google project must enable the Drive API and provision the client as a
desktop/installed application. Access and refresh tokens never belong in
`.env`; they remain encrypted in the existing local Drive connection store.
The installation's stable `APP_KEY` is also required to decrypt in-flight PKCE
verifiers and existing provider tokens after an application restart.

## Laravel browser handshake

The authenticated webview starts a connection with:

```http
POST /app/configuration/backup/google/prepare
Accept: application/json
```

The route requires the existing configuration and sensitive-settings
permissions plus recent password confirmation. Its successful response has one
field only:

```json
{
  "authorization_url": "https://accounts.google.com/o/oauth2/v2/auth?..."
}
```

Before returning that URL, Laravel stores a ten-minute attempt bound to the
current cabinet, actor, and exact callback URI. It stores only SHA-256 of the
random OAuth state and an `APP_KEY`-encrypted PKCE verifier. Preparing a newer
attempt invalidates the actor's older pending attempt.

Google returns to the unauthenticated system-browser endpoint:

```http
GET /app/configuration/backup/google/callback
```

This is intentionally independent of webview cookies. The route exists only on
the exact supervised loopback origin, accepts only a direct loopback peer, and
rejects forwarding headers, LAN listeners, and remote tunnel hosts. Laravel
atomically claims the matching unexpired attempt before exchanging the code,
revalidates its stored actor and cabinet authorization, sends the PKCE
`code_verifier`, and then clears the verifier. Replays, races, denial, expiry,
and exchange failures are terminal and receive the same generic French page;
no OAuth query value or provider error is reflected. Success is audited as the
stored actor, not as the unauthenticated browser request.

`medismart:oauth-attempts:prune` expires stale attempts, clears abandoned
verifiers, and removes old terminal metadata. The supervised scheduler runs it
hourly.

## Dedicated native opener contract

The implemented Configuration UI does not navigate the embedded webview to the
returned URL and does not depend on shared browser cookies. It passes the
response value unchanged to one dedicated native command whose only job is to
open a validated Google authorization request in the operating system browser.
The stable interface for that command is:

```json
{
  "authorization_url": "<value returned by the prepare endpoint>"
}
```

The native side must parse the URL without invoking a shell and reject it unless
all of these properties hold:

- scheme `https`, host `accounts.google.com`, default HTTPS port, no user info,
  and no fragment;
- exact path `/o/oauth2/v2/auth`;
- no duplicate or unknown query keys;
- the configured client ID and the current supervised loopback callback;
- `response_type=code`, `scope=https://www.googleapis.com/auth/drive.file`,
  `access_type=offline`, `prompt=consent`, and
  `code_challenge_method=S256`; and
- one 43-character base64url state and one 43-character base64url PKCE
  challenge.

No generic shell/open-URL command is exposed to Vue. The authorization URL,
state, code, verifier, tokens, and provider error text are excluded from native
logs and runtime-state files. If opening fails, the attempt is left to expire;
the UI may safely request a new one after password confirmation.
