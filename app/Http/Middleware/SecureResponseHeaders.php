<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class SecureResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Vite::useCspNonce($nonce);

        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        );
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if (
            ! $response->headers->has('Content-Security-Policy')
            && $this->isHtmlResponse($response)
        ) {
            $response->headers->set(
                'Content-Security-Policy',
                $this->contentSecurityPolicy($request, $nonce),
            );
        }

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = strtolower((string) $response->headers->get('Content-Type'));

        return str_starts_with($contentType, 'text/html');
    }

    private function contentSecurityPolicy(Request $request, string $nonce): string
    {
        $onlyOfficeOrigin = $this->loopbackOrigin(config('onlyoffice.url'));
        $hotOrigin = $this->hotOrigin();
        $usesFilamentRuntime = $this->usesFilamentRuntime($request);

        $scriptSources = ["'self'"];
        $styleSources = ["'self'"];
        $connectSources = ["'self'"];
        $fontSources = ["'self'", 'data:'];
        $imageSources = ["'self'", 'data:', 'blob:'];
        $workerSources = ["'self'", 'blob:'];

        if ($usesFilamentRuntime) {
            // Filament's Livewire/Alpine runtime evaluates inline expressions
            // and injects inline styles; a nonce would disable the 'unsafe-*'
            // keywords it depends on, so the panel uses a compatible policy.
            $scriptSources[] = "'unsafe-inline'";
            $scriptSources[] = "'unsafe-eval'";
            $styleSources[] = "'unsafe-inline'";
        } else {
            $scriptSources[] = "'nonce-{$nonce}'";
            $styleSources[] = "'nonce-{$nonce}'";
        }

        // Inertia is a persistent document: the editor can be reached through
        // client-side navigation from any initial page, so its one trusted
        // browser origin must be available to the whole normal app shell.
        if ($onlyOfficeOrigin !== null) {
            $scriptSources[] = $onlyOfficeOrigin;
            $connectSources[] = $onlyOfficeOrigin;
        }

        if ($hotOrigin !== null) {
            $scriptSources[] = $hotOrigin;
            $styleSources[] = $hotOrigin;
            $connectSources[] = $hotOrigin;
            $connectSources[] = $this->webSocketOrigin($hotOrigin);
            $fontSources[] = $hotOrigin;
            $imageSources[] = $hotOrigin;
            $workerSources[] = $hotOrigin;
        }

        return implode('; ', [
            "default-src 'none'",
            "base-uri 'none'",
            'connect-src '.implode(' ', $connectSources),
            "form-action 'self'",
            "frame-ancestors 'none'",
            'frame-src '.($onlyOfficeOrigin ?? "'none'"),
            'font-src '.implode(' ', $fontSources),
            'img-src '.implode(' ', $imageSources),
            "manifest-src 'self'",
            "media-src 'self' blob:",
            "object-src 'none'",
            'script-src '.implode(' ', $scriptSources),
            "script-src-attr 'none'",
            'style-src '.implode(' ', $styleSources),
            // Vue, chart components, progress indicators, and image previews
            // set bounded style properties at runtime. Keep this compromise
            // isolated from style elements and from the script policy.
            "style-src-attr 'unsafe-inline'",
            'worker-src '.implode(' ', $workerSources),
        ]);
    }

    private function usesFilamentRuntime(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if (is_string($routeName) && str_starts_with($routeName, 'filament.')) {
            return true;
        }

        return $request->is('admin') || $request->is('admin/*');
    }

    private function hotOrigin(): ?string
    {
        if (! in_array(config('app.env'), ['local', 'development', 'testing', 'e2e'], true)) {
            return null;
        }

        $hotFile = Vite::hotFile();

        if (! is_file($hotFile) || ! is_readable($hotFile)) {
            return null;
        }

        $size = filesize($hotFile);

        if (! is_int($size) || $size < 1 || $size > 2048) {
            return null;
        }

        $contents = file_get_contents($hotFile);

        if (! is_string($contents)) {
            return null;
        }

        $origin = rtrim($contents, "\r\n");

        if (
            $origin === ''
            || str_contains($origin, "\r")
            || str_contains($origin, "\n")
        ) {
            return null;
        }

        return $this->loopbackOrigin($origin);
    }

    private function loopbackOrigin(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $normalizedHost = is_string($host)
            && str_starts_with($host, '[')
            && str_ends_with($host, ']')
                ? substr($host, 1, -1)
                : $host;

        if (
            ! is_string($scheme)
            || ! in_array($scheme, ['http', 'https'], true)
            || ! is_string($normalizedHost)
            || ! $this->isLoopbackHost($normalizedHost)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $port = $parts['port'] ?? null;

        // parse_url() already constrains a parsed port to 0..65535; zero is
        // syntactically representable but is not a usable browser origin.
        if ($port === 0) {
            return null;
        }

        $serializedHost = str_contains($normalizedHost, ':') ? '['.$normalizedHost.']' : $normalizedHost;
        $origin = $scheme.'://'.$serializedHost.($port === null ? '' : ':'.$port);

        return $origin === $value ? $origin : null;
    }

    private function isLoopbackHost(string $host): bool
    {
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        $packed = inet_pton($host);

        return is_string($packed)
            && strlen($packed) === 4
            && ord($packed[0]) === 127;
    }

    private function webSocketOrigin(string $httpOrigin): string
    {
        return str_starts_with($httpOrigin, 'https://')
            ? 'wss://'.substr($httpOrigin, 8)
            : 'ws://'.substr($httpOrigin, 7);
    }
}
