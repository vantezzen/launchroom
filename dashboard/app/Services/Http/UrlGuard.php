<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Contracts\DnsResolver;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Support\Network\IpClassifier;

/**
 * Validates a user-influenced URL and resolves it exactly once
 * (docs/09-security.md R5).
 */
final readonly class UrlGuard
{
    private const array DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /** Ranges the private-network override may reach (RFC 1918, CGNAT/mesh, ULA; docs/09 R5). */
    public const array PRIVATE_RANGES = ['10.0.0.0/8', '100.64.0.0/10', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /** The control plane's own compose services and loopback names: never reachable, even with the override. */
    public const array RESERVED_HOSTS = ['localhost', 'dashboard', 'horizon', 'scheduler', 'reverb', 'postgres', 'valkey', 'redis', 'zot', 'registry', 'launchroomd', 'daemon', 'agent', 'caddy', 'metadata', 'metadata.google.internal'];

    /**
     * @param  list<string>  $allowedSchemes
     */
    public function __construct(
        private DnsResolver $resolver,
        private array $allowedSchemes = ['https'],
        private bool $allowPrivateNetwork = false,
    ) {}

    /**
     * The instance-owner override (docs/09 R5) for a destination on a
     * private network: RFC 1918, CGNAT/mesh and ULA addresses and plain http
     * are allowed; loopback, link-local/metadata, other reserved ranges and
     * the control plane's own service names stay refused.
     */
    public function withPrivateNetwork(): self
    {
        return new self($this->resolver, array_values(array_unique([...$this->allowedSchemes, 'http'])), true);
    }

    /**
     * @throws UnsafeUrl
     */
    public function check(string $url): ResolvedTarget
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw UnsafeUrl::because('it is empty, too long or contains whitespace or control characters');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw UnsafeUrl::because('it is not an absolute URL');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, $this->allowedSchemes, true) || ! isset(self::DEFAULT_PORTS[$scheme])) {
            throw UnsafeUrl::because("the scheme \"{$scheme}\" is not allowed");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            // Credentials in URLs end up in logs and exceptions.
            throw UnsafeUrl::because('it contains credentials');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $literal = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme];

        if ($literal === '' || $port === 0) {
            throw UnsafeUrl::because('the host or port is invalid');
        }

        if ($this->allowPrivateNetwork && (in_array($literal, self::RESERVED_HOSTS, true) || str_ends_with($literal, '.localhost') || str_ends_with($literal, '.internal'))) {
            throw UnsafeUrl::because('it points to the control plane itself');
        }

        $ip = IpClassifier::isIp($literal) ? $literal : $this->resolveHost($literal);

        if (! $this->allowed($ip)) {
            throw UnsafeUrl::because('it points to a private or reserved network address');
        }

        return new ResolvedTarget(
            url: $host === $parts['host'] ? $url : $this->withHost($url, $parts['host'], $host),
            scheme: $scheme,
            host: $literal,
            port: $port,
            ip: $ip,
        );
    }

    /**
     * $url with its host replaced by the normalised one (lowercase, no
     * trailing dot), so the request URL and the CURLOPT_RESOLVE pin name the
     * same host: libcurl versions differ in whether `example.com.` matches a
     * pin for `example.com`, and a miss would resolve the name again
     * (DNS rebinding).
     */
    private function withHost(string $url, string $rawHost, string $host): string
    {
        $position = strpos($url, '//'.$rawHost);

        if ($position === false) {
            throw UnsafeUrl::because('the host name is invalid');
        }

        return substr_replace($url, '//'.$host, $position, strlen($rawHost) + 2);
    }

    private function resolveHost(string $host): string
    {
        // Reject hostnames that look numeric but aren't canonical IPs
        // (e.g. "2130706433", "0x7f.1", "127.1"): resolvers expand them to
        // loopback and other surprising addresses.
        if (preg_match('/^[0-9.]+$|^0x/i', $host) === 1 || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            throw UnsafeUrl::because('the host name is invalid');
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw UnsafeUrl::because('the host name does not resolve');
        }

        // Every record must be public: otherwise an attacker could mix a
        // public and a private record and win whichever one we picked.
        foreach ($ips as $ip) {
            if (! $this->allowed($ip)) {
                throw UnsafeUrl::because('it points to a private or reserved network address');
            }
        }

        return $ips[0];
    }

    private function allowed(string $ip): bool
    {
        if (IpClassifier::isPublic($ip)) {
            return true;
        }

        return $this->allowPrivateNetwork && IpClassifier::isIp($ip) && in_array(IpClassifier::blockedRange($ip), self::PRIVATE_RANGES, true);
    }
}
