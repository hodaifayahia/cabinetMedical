import { describe, expect, it, vi } from 'vitest';

import { HttpError } from '@/lib/http';
import {
    googleOAuthErrorMessage,
    isGoogleAuthorizationUrl,
    openGoogleAuthorization,
} from './googleOAuthContract';

const authorizationUrl = (): URL => {
    const url = new URL('https://accounts.google.com/o/oauth2/v2/auth');
    url.searchParams.set('client_id', '123-client.apps.googleusercontent.com');
    url.searchParams.set(
        'redirect_uri',
        'http://127.0.0.1:43123/app/configuration/backup/google/callback',
    );
    url.searchParams.set('response_type', 'code');
    url.searchParams.set('scope', 'https://www.googleapis.com/auth/drive.file');
    url.searchParams.set('access_type', 'offline');
    url.searchParams.set('prompt', 'consent');
    url.searchParams.set(
        'state',
        'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ',
    );
    url.searchParams.set(
        'code_challenge',
        '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG',
    );
    url.searchParams.set('code_challenge_method', 'S256');

    return url;
};

describe('Google OAuth browser contract', () => {
    it('accepts the strict Drive PKCE request produced by Laravel', () => {
        expect(isGoogleAuthorizationUrl(authorizationUrl().toString())).toBe(
            true,
        );
    });

    it.each([
        ['scheme', 'http://accounts.google.com/o/oauth2/v2/auth'],
        [
            'lookalike host',
            'https://accounts.google.com.attacker.invalid/o/oauth2/v2/auth',
        ],
        ['path', 'https://accounts.google.com/o/oauth2/auth'],
        ['credentials', 'https://user@accounts.google.com/o/oauth2/v2/auth'],
        ['port', 'https://accounts.google.com:444/o/oauth2/v2/auth'],
    ])('rejects an invalid %s', (_label, base) => {
        const valid = authorizationUrl();
        const invalid = new URL(base);
        invalid.search = valid.search;

        expect(isGoogleAuthorizationUrl(invalid.toString())).toBe(false);
    });

    it.each([
        ['response_type', 'token'],
        ['scope', 'https://www.googleapis.com/auth/drive'],
        ['access_type', 'online'],
        ['prompt', 'none'],
        ['code_challenge_method', 'plain'],
        ['state', 'short'],
        ['code_challenge', 'short'],
        [
            'redirect_uri',
            'http://localhost:43123/app/configuration/backup/google/callback',
        ],
    ])('rejects an invalid %s parameter', (name, value) => {
        const invalid = authorizationUrl();
        invalid.searchParams.set(name, value);

        expect(isGoogleAuthorizationUrl(invalid.toString())).toBe(false);
    });

    it('rejects duplicate, missing, extra, and fragmented requests', () => {
        const duplicate = authorizationUrl();
        duplicate.searchParams.append(
            'state',
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ',
        );
        const missing = authorizationUrl();
        missing.searchParams.delete('prompt');
        const extra = authorizationUrl();
        extra.searchParams.set('include_granted_scopes', 'true');
        const fragmented = authorizationUrl();
        fragmented.hash = 'token';

        for (const invalid of [duplicate, missing, extra, fragmented]) {
            expect(isGoogleAuthorizationUrl(invalid.toString())).toBe(false);
        }
    });

    it('uses guarded top-level HTTPS navigation in the hosted desktop', () => {
        const navigateHostedDesktop = vi.fn();
        const authorization = authorizationUrl().toString();

        openGoogleAuthorization(
            authorization,
            true,
            null,
            navigateHostedDesktop,
        );

        expect(navigateHostedDesktop).toHaveBeenCalledOnce();
        expect(navigateHostedDesktop).toHaveBeenCalledWith(authorization);
    });

    it('keeps browser OAuth in the popup opened by the user gesture', () => {
        const replace = vi.fn();
        const browserAuthorizationWindow = {
            location: { replace },
        } as unknown as Window;
        const navigateHostedDesktop = vi.fn();
        const authorization = authorizationUrl().toString();

        openGoogleAuthorization(
            authorization,
            false,
            browserAuthorizationWindow,
            navigateHostedDesktop,
        );

        expect(replace).toHaveBeenCalledWith(authorization);
        expect(navigateHostedDesktop).not.toHaveBeenCalled();
    });

    it('rejects an unsafe URL before either navigation path runs', () => {
        const replace = vi.fn();
        const browserAuthorizationWindow = {
            location: { replace },
        } as unknown as Window;
        const navigateHostedDesktop = vi.fn();

        expect(() =>
            openGoogleAuthorization(
                'https://attacker.invalid/oauth',
                true,
                browserAuthorizationWindow,
                navigateHostedDesktop,
            ),
        ).toThrow('invalid_google_authorization_url');
        expect(replace).not.toHaveBeenCalled();
        expect(navigateHostedDesktop).not.toHaveBeenCalled();
    });

    it('shows bounded native French errors and maps password confirmation', () => {
        expect(
            googleOAuthErrorMessage({
                message_fr: 'Le navigateur système n’a pas pu être ouvert.',
            }),
        ).toBe('Le navigateur système n’a pas pu être ouvert.');
        expect(
            googleOAuthErrorMessage({ message_fr: 'unsafe\nmessage' }),
        ).not.toContain('unsafe');
        expect(
            googleOAuthErrorMessage(
                new HttpError(423, 'Password confirmation required.'),
            ),
        ).toContain('mot de passe');
    });
});
