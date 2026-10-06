<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * The cabinet LAN boundary of a desktop "poste principal" (ADR-005).
 *
 * When the native supervisor runs the dedicated LAN listener it sets
 * MEDISMART_LAN_HOST_ENABLED and MEDISMART_LAN_HOST_PORT for that PHP process
 * only. Requests on that listener are then admitted to the whole application
 * (sign-in, patients, consultations…) — but only when they come straight from
 * a machine on the cabinet's private network and name this PC by a LAN
 * address:
 *
 * - the TCP peer is a private, link-local, unique-local or loopback address;
 * - the Host header is `<private IP>:<port>`, `<computer-name>:<port>` or
 *   `<name>.local:<port>` with exactly the configured port, so a public DNS
 *   name rebound to a LAN address (DNS rebinding) is refused;
 * - no forwarding header is present: nothing on the LAN is a trusted proxy;
 * - the scheme is plain HTTP, as served by the listener.
 *
 * Everything else keeps the loopback-only behaviour of
 * {@see RemoteUploadBoundary}. Sign-in, CSRF, sessions and permissions apply
 * exactly as on the poste principal itself.
 */
final class LanHostBoundary
{
    public const REQUEST_ATTRIBUTE = 'medismart.lan_host_client';

    public function enabled(): bool
    {
        return (bool) config('medismart.runtime.desktop_supervised', false)
            && (bool) config('medismart.runtime.lan_host_enabled', false)
            && $this->port() !== null;
    }

    public function port(): ?int
    {
        $port = config('medismart.runtime.lan_host_port');
        $port = is_numeric($port) ? (int) $port : 0;

        return $port >= 1024 && $port <= 65535 ? $port : null;
    }

    public function allows(Request $request): bool
    {
        $port = $this->port();

        if (! $this->enabled() || $port === null) {
            return false;
        }

        $authority = $this->rawAuthority($request);

        if ($authority === null) {
            return false;
        }

        [$host, $authorityPort] = $authority;

        return $authorityPort === $port
            && $this->hostIsLanName($host)
            && $this->peerIsLocalNetwork($request->server->get('REMOTE_ADDR'))
            && ! $this->hasForwardingHeaders($request)
            && ! $request->isSecure()
            && strtolower($request->getHost()) === $host
            && $request->getPort() === $port;
    }

    /**
     * Whether this request reached the application from another PC of the
     * cabinet through the LAN listener (set by the boundary middleware).
     */
    public static function isLanClientRequest(Request $request): bool
    {
        return $request->attributes->get(self::REQUEST_ATTRIBUTE) === true;
    }

    /** @return array{0: string, 1: int}|null */
    private function rawAuthority(Request $request): ?array
    {
        $authority = $request->headers->get('Host');

        if (! is_string($authority)
            || $authority === ''
            || strlen($authority) > 300
            || trim($authority) !== $authority
            || preg_match('/[\x00-\x20\x7F,@\/\\\\]/', $authority) === 1) {
            return null;
        }

        $authority = strtolower($authority);

        if (preg_match('/\A(\[[0-9a-f:.]+\]|[^:\[\]]+):([0-9]{1,5})\z/D', $authority, $matches) !== 1) {
            return null;
        }

        return [$matches[1], (int) $matches[2]];
    }

    private function hostIsLanName(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);

            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && $this->isLanIpv6($address);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->isLanIpv4($host);
        }

        // A dotted decimal string that is not a valid IPv4 address is never a
        // name (e.g. 192.168.1.256); refuse rather than resolve.
        if (preg_match('/\A[0-9.]+\z/', $host) === 1) {
            return false;
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        // A bare Windows computer name (CABINET-PC) or an mDNS .local name.
        // Neither can be registered in public DNS, which is what keeps a
        // rebinding page on the Internet from addressing this listener.
        return $host !== 'localhost'
            && (preg_match('/\A'.$label.'\z/D', $host) === 1
                || preg_match('/\A(?:'.$label.'\.)+local\z/D', $host) === 1);
    }

    private function peerIsLocalNetwork(mixed $peer): bool
    {
        if (! is_string($peer)) {
            return false;
        }

        if (filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->isLanIpv4($peer) || str_starts_with($peer, '127.');
        }

        if (filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($peer);

            if ($packed === false) {
                return false;
            }

            // IPv4-mapped (::ffff:a.b.c.d), as a dual-stack listener reports.
            if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xFF\xFF") {
                $mapped = inet_ntop(substr($packed, 12, 4));

                return is_string($mapped)
                    && ($this->isLanIpv4($mapped) || str_starts_with($mapped, '127.'));
            }

            return $peer === '::1' || $this->isLanIpv6($peer);
        }

        return false;
    }

    private function isLanIpv4(string $address): bool
    {
        $packed = inet_pton($address);

        if ($packed === false || strlen($packed) !== 4) {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        return $first === 10
            || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168)
            || ($first === 169 && $second === 254);
    }

    private function isLanIpv6(string $address): bool
    {
        $packed = inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        return ($first & 0xFE) === 0xFC
            || ($first === 0xFE && ($second & 0xC0) === 0x80);
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
}
