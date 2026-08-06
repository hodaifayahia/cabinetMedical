<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;

final class RemoteUploadBoundary
{
    public const REQUEST_ATTRIBUTE = 'medismart.remote_upload_boundary';

    public const ROUTE_SET = 'public_upload_v1';

    private const LOCAL = 'local';

    private const REMOTE = 'remote';

    private const TRUSTED_PROXY_HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO;

    /** @var array<string, array{uri: string, methods: list<string>}> */
    private const PUBLIC_ROUTES = [
        'upload.show' => [
            'uri' => 'upload/{selector}',
            'methods' => ['GET', 'HEAD'],
        ],
        'upload.session' => [
            'uri' => 'upload/{selector}/authorize',
            'methods' => ['POST'],
        ],
        'upload.files.store' => [
            'uri' => 'upload/{selector}/files',
            'methods' => ['POST'],
        ],
        'upload.complete' => [
            'uri' => 'upload/{selector}/complete',
            'methods' => ['POST'],
        ],
    ];

    public function __construct(private readonly Router $router) {}

    public function enforcementEnabled(): bool
    {
        return (bool) config('medismart.runtime.desktop_supervised', false)
            || trim((string) config('medismart.runtime.remote_upload_url')) !== '';
    }

    public function hostKind(Request $request): ?string
    {
        $authority = $this->rawAuthority($request);

        if ($authority === null) {
            return null;
        }

        $local = $this->localOrigin();
        $remote = $this->remoteOrigin();
        $localMatches = $local !== null && hash_equals($local['authority'], $authority);
        $remoteMatches = $remote !== null && hash_equals($remote['authority'], $authority);

        if ($localMatches === $remoteMatches) {
            return null;
        }

        return $localMatches ? self::LOCAL : self::REMOTE;
    }

    public function configuredAuthorityMatches(Request $request): bool
    {
        $authority = $this->rawAuthority($request);

        if ($authority === null) {
            return false;
        }

        $local = $this->localOrigin();
        $remote = $this->remoteOrigin();

        return ($local !== null && hash_equals($local['authority'], $authority))
            || ($remote !== null && hash_equals($remote['authority'], $authority));
    }

    public function isDirectLocalRequest(Request $request): bool
    {
        $local = $this->localOrigin();

        return $local !== null
            && $this->hostKind($request) === self::LOCAL
            && $this->socketPeerIsLoopback($request)
            && ! $this->hasForwardingHeaders($request)
            && hash_equals($local['origin'], $this->requestOrigin($request));
    }

    public function isVerifiedRemoteProxyRequest(Request $request): bool
    {
        return $this->hostKind($request) === self::REMOTE
            && $this->socketPeerIsLoopback($request)
            && $request->isFromTrustedProxy()
            && $request->headers->get('X-Forwarded-Proto') === 'https'
            && $request->isSecure();
    }

    public function remoteRouteAllowed(Request $request): bool
    {
        $path = $request->getPathInfo();

        if ($path === '/health') {
            return $request->isMethod('GET');
        }

        if (preg_match(
            '#\A/upload/[A-Za-z0-9_-]{22}(?:/(authorize|files|complete))?\z#D',
            $path,
            $matches,
        ) !== 1) {
            return false;
        }

        return isset($matches[1])
            ? $request->isMethod('POST')
            : $request->isMethod('GET');
    }

    public function audienceMatches(
        Request $request,
        string $mode,
        ?string $localUploadOrigin = null,
    ): bool {
        if ($mode === self::REMOTE) {
            return $this->isVerifiedRemoteProxyRequest($request);
        }

        $expected = is_string($localUploadOrigin)
            ? $this->parseOrigin($localUploadOrigin, false)
            : null;

        return $mode === self::LOCAL
            && $this->hostKind($request) !== self::REMOTE
            && $expected !== null
            && hash_equals($expected['origin'], $this->requestOrigin($request));
    }

    public function configuredRemoteOrigin(): ?string
    {
        return $this->remoteOrigin()['origin'] ?? null;
    }

    /** @param array<string, bool|string|null> $tunnel */
    public function tunnelMatchesConfiguredHost(array $tunnel): bool
    {
        $remote = $this->remoteOrigin();
        $hostname = $tunnel['hostname'] ?? null;

        return $remote !== null
            && is_string($hostname)
            && hash_equals($remote['host'], strtolower(trim($hostname)));
    }

    /**
     * @return array{
     *     schema_version: int,
     *     status: string,
     *     hostname: string|null,
     *     listener_origin: string|null,
     *     route_set: string,
     *     upload_routes_only: bool,
     *     exact_host_enforced: bool,
     *     trusted_proxy_enforced: bool,
     *     forwarded_https_enforced: bool,
     *     local_tokens_rejected_on_remote_host: bool
     * }
     */
    public function attestation(?Request $request): array
    {
        $local = $this->localOrigin();
        $remote = $this->remoteOrigin();
        $marker = $request?->attributes->get(self::REQUEST_ATTRIBUTE);
        $marker = is_array($marker) ? $marker : [];
        $middlewareExecuted = ($marker['route_set'] ?? null) === self::ROUTE_SET;
        $uploadRoutesOnly = $middlewareExecuted
            && ($marker['upload_routes_only'] ?? false) === true
            && $this->publicRoutesMatch();
        $exactHostEnforced = $middlewareExecuted
            && ($marker['exact_host_enforced'] ?? false) === true;
        $trustedProxyEnforced = $middlewareExecuted && $this->trustedProxyPolicyIsActive();
        $forwardedHttpsEnforced = $middlewareExecuted
            && ($marker['forwarded_https_enforced'] ?? false) === true;
        $audienceSeparated = $middlewareExecuted
            && ($marker['local_tokens_rejected_on_remote_host'] ?? false) === true;
        $listenerReady = $local !== null
            && $local['scheme'] === 'http'
            && $local['explicit_port']
            && $local['port'] >= 1024
            && in_array($local['host'], ['127.0.0.1', '::1'], true);
        $ready = (bool) config('medismart.runtime.desktop_supervised', false)
            && $listenerReady
            && $remote !== null
            && $uploadRoutesOnly
            && $exactHostEnforced
            && $trustedProxyEnforced
            && $forwardedHttpsEnforced
            && $audienceSeparated;

        return [
            'schema_version' => 1,
            'status' => $ready ? 'ready' : 'unavailable',
            'hostname' => $remote['host'] ?? null,
            'listener_origin' => $listenerReady ? $local['origin'] : null,
            'route_set' => self::ROUTE_SET,
            'upload_routes_only' => $uploadRoutesOnly,
            'exact_host_enforced' => $exactHostEnforced,
            'trusted_proxy_enforced' => $trustedProxyEnforced,
            'forwarded_https_enforced' => $forwardedHttpsEnforced,
            'local_tokens_rejected_on_remote_host' => $audienceSeparated,
        ];
    }

    /** @return array{scheme: string, host: string, port: int, explicit_port: bool, authority: string, origin: string}|null */
    private function localOrigin(): ?array
    {
        return $this->parseOrigin(
            (string) config('medismart.runtime.local_url', config('app.url')),
            false,
        );
    }

    /** @return array{scheme: string, host: string, port: int, explicit_port: bool, authority: string, origin: string}|null */
    private function remoteOrigin(): ?array
    {
        return $this->parseOrigin(
            (string) config('medismart.runtime.remote_upload_url'),
            true,
        );
    }

    /** @return array{scheme: string, host: string, port: int, explicit_port: bool, authority: string, origin: string}|null */
    private function parseOrigin(string $url, bool $remote): ?array
    {
        if ($url === ''
            || trim($url) !== $url
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $explicitPort = isset($parts['port']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (! $validHost
            || ! in_array($scheme, ['http', 'https'], true)
            || ($remote && ($scheme !== 'https'
                || $explicitPort
                || filter_var($host, FILTER_VALIDATE_IP) !== false
                || ! str_contains($host, '.')))) {
            return null;
        }

        $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $authority = $displayHost.($port === $defaultPort ? '' : ':'.$port);

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'explicit_port' => $explicitPort,
            'authority' => $authority,
            'origin' => $scheme.'://'.$authority,
        ];
    }

    private function rawAuthority(Request $request): ?string
    {
        $authority = $request->headers->get('Host');

        if (! is_string($authority)
            || $authority === ''
            || trim($authority) !== $authority
            || preg_match('/[\x00-\x20\x7F,]/', $authority) === 1) {
            return null;
        }

        return strtolower($authority);
    }

    private function requestOrigin(Request $request): string
    {
        $scheme = strtolower($request->getScheme());
        $host = strtolower($request->getHost());
        $port = $request->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;

        return $scheme.'://'.$displayHost.($port === $defaultPort ? '' : ':'.$port);
    }

    private function socketPeerIsLoopback(Request $request): bool
    {
        return in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }

    private function hasForwardingHeaders(Request $request): bool
    {
        foreach ([
            'Forwarded',
            'X-Forwarded-For',
            'X-Forwarded-Host',
            'X-Forwarded-Port',
            'X-Forwarded-Prefix',
            'X-Forwarded-Proto',
            'CF-Connecting-IP',
        ] as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }

    private function trustedProxyPolicyIsActive(): bool
    {
        $actual = Request::getTrustedProxies();
        $expected = ['127.0.0.1', '::1'];
        sort($actual);
        sort($expected);

        return $actual === $expected
            && Request::getTrustedHeaderSet() === self::TRUSTED_PROXY_HEADERS;
    }

    private function publicRoutesMatch(): bool
    {
        $health = $this->router->getRoutes()->getByName('health');

        if ($health === null || $health->uri() !== 'health') {
            return false;
        }

        $healthMethods = $health->methods();
        sort($healthMethods);

        if ($healthMethods !== ['GET', 'HEAD']) {
            return false;
        }

        $actualNames = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'upload/')) {
                continue;
            }

            $name = $route->getName();

            if (! is_string($name) || ! isset(self::PUBLIC_ROUTES[$name])) {
                return false;
            }

            $expected = self::PUBLIC_ROUTES[$name];
            $methods = $route->methods();
            sort($methods);
            $expectedMethods = $expected['methods'];
            sort($expectedMethods);

            if ($route->uri() !== $expected['uri'] || $methods !== $expectedMethods) {
                return false;
            }

            $actualNames[] = $name;
        }

        sort($actualNames);
        $expectedNames = array_keys(self::PUBLIC_ROUTES);
        sort($expectedNames);

        return $actualNames === $expectedNames;
    }
}
