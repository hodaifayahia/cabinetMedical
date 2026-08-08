import { isHttpError } from '@/lib/http';

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

export const isGoogleAuthorizationUrl = (value: unknown): value is string => {
    if (
        typeof value !== 'string' ||
        value.length === 0 ||
        value.length > 16 * 1024 ||
        value.trim() !== value ||
        /[\s\u0000-\u001f\u007f]/.test(value)
    ) {
        return false;
    }

    try {
        const url = new URL(value);
        const requiredKeys = [
            'access_type',
            'client_id',
            'code_challenge',
            'code_challenge_method',
            'prompt',
            'redirect_uri',
            'response_type',
            'scope',
            'state',
        ];
        const queryEntries = [...url.searchParams.entries()];
        const query = new Map(queryEntries);
        const redirect = new URL(query.get('redirect_uri') ?? 'invalid:');
        const redirectPort = Number(redirect.port);
        const clientId = query.get('client_id') ?? '';
        const state = query.get('state') ?? '';
        const challenge = query.get('code_challenge') ?? '';

        return (
            url.protocol === 'https:' &&
            url.hostname === 'accounts.google.com' &&
            url.port === '' &&
            url.username === '' &&
            url.password === '' &&
            url.pathname === '/o/oauth2/v2/auth' &&
            url.hash === '' &&
            queryEntries.length === requiredKeys.length &&
            query.size === requiredKeys.length &&
            requiredKeys.every((key) => query.has(key)) &&
            clientId.length > 0 &&
            clientId.length <= 512 &&
            /^[A-Za-z0-9._-]+$/.test(clientId) &&
            /^[A-Za-z0-9_-]{43}$/.test(state) &&
            /^[A-Za-z0-9_-]{43}$/.test(challenge) &&
            query.get('response_type') === 'code' &&
            query.get('scope') ===
                'https://www.googleapis.com/auth/drive.file' &&
            query.get('access_type') === 'offline' &&
            query.get('prompt') === 'consent' &&
            query.get('code_challenge_method') === 'S256' &&
            redirect.protocol === 'http:' &&
            redirect.hostname === '127.0.0.1' &&
            Number.isInteger(redirectPort) &&
            redirectPort >= 1024 &&
            redirectPort <= 65_535 &&
            redirect.username === '' &&
            redirect.password === '' &&
            redirect.pathname === '/app/configuration/backup/google/callback' &&
            redirect.search === '' &&
            redirect.hash === ''
        );
    } catch {
        return false;
    }
};

/**
 * Starts OAuth without granting the hosted desktop shell another native command.
 * External HTTPS navigation is denied in the webview and opened by the Rust
 * navigation guard; browsers keep using the popup opened by the user gesture.
 */
export const openGoogleAuthorization = (
    authorizationUrl: string,
    desktopRuntime: boolean,
    browserAuthorizationWindow: Window | null,
    navigateHostedDesktop: (url: string) => void = (url) =>
        window.location.assign(url),
): void => {
    if (!isGoogleAuthorizationUrl(authorizationUrl)) {
        throw new Error('invalid_google_authorization_url');
    }

    if (desktopRuntime) {
        navigateHostedDesktop(authorizationUrl);

        return;
    }

    if (browserAuthorizationWindow === null) {
        throw new Error('google_authorization_window_unavailable');
    }

    browserAuthorizationWindow.location.replace(authorizationUrl);
};

export const googleOAuthErrorMessage = (error: unknown): string => {
    if (
        isRecord(error) &&
        typeof error.message_fr === 'string' &&
        error.message_fr.trim() !== '' &&
        error.message_fr.length <= 1000 &&
        !/[\u0000-\u001f\u007f]/.test(error.message_fr)
    ) {
        return error.message_fr;
    }

    if (isHttpError(error)) {
        if (error.status === 401) {
            return 'Votre session a expiré. Reconnectez-vous avant de connecter Google Drive.';
        }

        if (error.status === 403) {
            return 'Vous n’êtes pas autorisé à connecter Google Drive.';
        }

        if (error.status === 419) {
            return 'La protection de session a expiré. Rechargez la page puis réessayez.';
        }

        if (error.status === 423) {
            return 'Confirmez de nouveau votre mot de passe avant de connecter Google Drive.';
        }
    }

    return 'La connexion Google Drive n’a pas pu être préparée. Réessayez après avoir vérifié la connexion et la configuration OAuth.';
};
