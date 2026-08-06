<?php

namespace App\Services;

use Illuminate\Http\Request;
use Throwable;

final class GoogleOAuthLoopbackOrigin
{
    public const CALLBACK_PATH = '/app/configuration/backup/google/callback';

    public function available(): bool
    {
        try {
            $this->redirectUri();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function redirectUri(): string
    {
        $origin = $this->configuredOrigin();
        $redirectUri = $origin['origin'].self::CALLBACK_PATH;
        $configuredRedirect = config('services.google.redirect');

        if (is_string($configuredRedirect)
            && $configuredRedirect !== ''
            && ! hash_equals($redirectUri, $configuredRedirect)) {
            throw new GoogleDriveOAuthException('redirect_configuration_mismatch');
        }

        return $redirectUri;
    }

    public function assertCallbackRequest(Request $request): string
    {
        $origin = $this->configuredOrigin();
        $rawHost = $request->server('HTTP_HOST');
        $peer = $request->server('REMOTE_ADDR');

        if (! $request->isMethod('GET')
            || $request->getPathInfo() !== self::CALLBACK_PATH
            || ! is_string($rawHost)
            || ! hash_equals($origin['authority'], strtolower($rawHost))
            || ! is_string($peer)
            || ! in_array($peer, ['127.0.0.1', '::1'], true)
            || $request->getScheme() !== 'http'
            || $this->hasForwardingHeaders($request)) {
            throw new GoogleDriveOAuthException('callback_origin_mismatch');
        }

        return $this->redirectUri();
    }

    /** @return array{origin: string, authority: string} */
    private function configuredOrigin(): array
    {
        if (! (bool) config('medismart.runtime.desktop_supervised', false)) {
            throw new GoogleDriveOAuthException('desktop_supervision_unavailable');
        }

        $configured = config('medismart.runtime.local_url');

        if (! is_string($configured)
            || $configured === ''
            || trim($configured) !== $configured
            || preg_match('/[\x00-\x20\x7F]/', $configured) === 1) {
            throw new GoogleDriveOAuthException('loopback_origin_invalid');
        }

        $parts = parse_url($configured);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ! is_string($parts['host'] ?? null)
            || ! is_int($parts['port'] ?? null)
            || $parts['port'] < 1024
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            throw new GoogleDriveOAuthException('loopback_origin_invalid');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (! in_array($host, ['127.0.0.1', '::1'], true)) {
            throw new GoogleDriveOAuthException('loopback_origin_invalid');
        }

        $displayHost = $host === '::1' ? '[::1]' : $host;
        $authority = $displayHost.':'.$parts['port'];

        return [
            'origin' => 'http://'.$authority,
            'authority' => $authority,
        ];
    }

    private function hasForwardingHeaders(Request $request): bool
    {
        foreach ([
            'Forwarded',
            'X-Forwarded-For',
            'X-Forwarded-Host',
            'X-Forwarded-Port',
            'X-Forwarded-Proto',
        ] as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }
}
