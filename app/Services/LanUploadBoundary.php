<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;

final class LanUploadBoundary
{
    public const REQUEST_ATTRIBUTE = 'medismart.lan_upload_boundary';

    public const ROUTE_SET = 'public_upload_v1';

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

    public function enabled(): bool
    {
        return (bool) config('medismart.runtime.desktop_supervised', false);
    }

    public function authorityMatches(Request $request): bool
    {
        $origin = $this->configuredOriginParts();
        $authority = $this->rawAuthority($request);

        return $origin !== null
            && $authority !== null
            && hash_equals($origin['authority'], $authority);
    }

    public function isDirectLanRequest(Request $request): bool
    {
        $origin = $this->configuredOriginParts();

        return $this->enabled()
            && $origin !== null
            && $this->authorityMatches($request)
            && $this->socketPeerIsDirectlyReachable($request)
            && ! $this->hasForwardingHeaders($request)
            && hash_equals($origin['origin'], $this->requestOrigin($request));
    }

    public function routeAllowed(Request $request): bool
    {
        $path = $this->rawPath($request);

        if ($path === null) {
            return false;
        }

        if ($path === '/health') {
            return $request->getMethod() === 'GET';
        }

        if (preg_match(
            '#\A/upload/[A-Za-z0-9_-]{22}(?:/(authorize|files|complete))?\z#D',
            $path,
            $matches,
        ) !== 1) {
            return false;
        }

        return isset($matches[1])
            ? $request->getMethod() === 'POST'
            : $request->getMethod() === 'GET';
    }

    public function audienceMatches(Request $request, string $expectedOrigin): bool
    {
        $configured = $this->configuredOrigin();

        return $configured !== null
            && hash_equals($configured, $expectedOrigin)
            && $this->isDirectLanRequest($request);
    }

    public function configuredOrigin(): ?string
    {
        return $this->configuredOriginParts()['origin'] ?? null;
    }

    /**
     * This attests the PHP request boundary only. It deliberately has no
     * listener-process state; only the native supervisor can establish that.
     *
     * @return array{
     *     schema_version: int,
     *     status: string,
     *     origin: string|null,
     *     route_set: string,
     *     upload_routes_only: bool,
     *     exact_origin_enforced: bool,
     *     explicit_high_port_enforced: bool,
     *     direct_private_peer_enforced: bool,
     *     forwarding_headers_rejected: bool,
     *     local_tokens_bound_to_lan_origin: bool
     * }
     */
    public function attestation(?Request $request): array
    {
        $origin = $this->configuredOriginParts();
        $marker = $request?->attributes->get(self::REQUEST_ATTRIBUTE);
        $marker = is_array($marker) ? $marker : [];
        $middlewareExecuted = ($marker['route_set'] ?? null) === self::ROUTE_SET;
        $uploadRoutesOnly = $middlewareExecuted
            && ($marker['upload_routes_only'] ?? false) === true
            && $this->publicRoutesMatch();
        $exactOriginEnforced = $middlewareExecuted
            && ($marker['exact_origin_enforced'] ?? false) === true;
        $explicitHighPortEnforced = $middlewareExecuted
            && ($marker['explicit_high_port_enforced'] ?? false) === true;
        $directPrivatePeerEnforced = $middlewareExecuted
            && ($marker['direct_private_peer_enforced'] ?? false) === true;
        $forwardingHeadersRejected = $middlewareExecuted
            && ($marker['forwarding_headers_rejected'] ?? false) === true;
        $localTokensBound = $middlewareExecuted
            && ($marker['local_tokens_bound_to_lan_origin'] ?? false) === true;
        $ready = $this->enabled()
            && $origin !== null
            && $uploadRoutesOnly
            && $exactOriginEnforced
            && $explicitHighPortEnforced
            && $directPrivatePeerEnforced
            && $forwardingHeadersRejected
            && $localTokensBound;

        return [
            'schema_version' => 1,
            'status' => $ready ? 'ready' : 'unavailable',
            'origin' => $origin['origin'] ?? null,
            'route_set' => self::ROUTE_SET,
            'upload_routes_only' => $uploadRoutesOnly,
            'exact_origin_enforced' => $exactOriginEnforced,
            'explicit_high_port_enforced' => $explicitHighPortEnforced,
            'direct_private_peer_enforced' => $directPrivatePeerEnforced,
            'forwarding_headers_rejected' => $forwardingHeadersRejected,
            'local_tokens_bound_to_lan_origin' => $localTokensBound,
        ];
    }

    /** @return array{scheme: string, host: string, port: int, authority: string, origin: string}|null */
    private function configuredOriginParts(): ?array
    {
        $url = config('medismart.runtime.lan_upload_url');

        if (! is_string($url)
            || $url === ''
            || trim($url) !== $url
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)
            || ! isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'];

        if (! in_array($scheme, ['http', 'https'], true)
            || $port < 1024
            || filter_var($host, FILTER_VALIDATE_IP) === false
            || ! $this->isPrivateLinkLocalOrLoopback($host)) {
            return null;
        }

        $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $authority = $displayHost.':'.$port;
        $origin = $scheme.'://'.$authority;

        if (! hash_equals($origin, $url)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'authority' => $authority,
            'origin' => $origin,
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

    private function rawPath(Request $request): ?string
    {
        $requestTarget = $request->server->get('REQUEST_URI');

        if (! is_string($requestTarget)
            || $requestTarget === ''
            || ! str_starts_with($requestTarget, '/')
            || str_starts_with($requestTarget, '//')
            || preg_match('/[\x00-\x20\x7F]/', $requestTarget) === 1) {
            return null;
        }

        $path = parse_url($requestTarget, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/') ? $path : null;
    }

    private function requestOrigin(Request $request): string
    {
        $scheme = strtolower($request->getScheme());
        $host = strtolower($request->getHost());
        $port = $request->getPort();
        $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;

        return $scheme.'://'.$displayHost.':'.$port;
    }

    private function socketPeerIsDirectlyReachable(Request $request): bool
    {
        $peer = $request->server->get('REMOTE_ADDR');

        return is_string($peer) && $this->isPrivateLinkLocalOrLoopback($peer);
    }

    private function isPrivateLinkLocalOrLoopback(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $this->isPrivateLinkLocalOrLoopbackIpv4($packed);
        }

        if (strlen($packed) !== 16) {
            return false;
        }

        $isLoopback = $packed === str_repeat("\0", 15)."\1";
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        $isUniqueLocal = ($first & 0xFE) === 0xFC;
        $isLinkLocal = $first === 0xFE && ($second & 0xC0) === 0x80;
        $isMappedIpv4 = substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xFF\xFF";

        return $isLoopback
            || $isUniqueLocal
            || $isLinkLocal
            || ($isMappedIpv4
                && $this->isPrivateLinkLocalOrLoopbackIpv4(substr($packed, 12, 4)));
    }

    private function isPrivateLinkLocalOrLoopbackIpv4(string $packed): bool
    {
        $first = ord($packed[0]);
        $second = ord($packed[1]);

        return $first === 10
            || $first === 127
            || ($first === 169 && $second === 254)
            || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168);
    }

    private function hasForwardingHeaders(Request $request): bool
    {
        foreach (array_keys($request->headers->all()) as $header) {
            if ($header === 'forwarded'
                || $header === 'x-forwarded'
                || str_starts_with($header, 'x-forwarded-')
                || in_array($header, [
                    'cf-connecting-ip',
                    'client-ip',
                    'fastly-client-ip',
                    'fly-client-ip',
                    'true-client-ip',
                    'x-cluster-client-ip',
                    'x-real-ip',
                ], true)) {
                return true;
            }
        }

        return false;
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
