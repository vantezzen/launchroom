<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Web origins (`scheme://host[:port]`) of the dashboard (docs/05 §4.1):
 * lowercase, no path, no default port. Anything that isn't a plain http(s)
 * origin is refused (null).
 */
final class Origin
{
    public static function normalize(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || preg_match('/^(?:[a-z0-9.-]+|\[[0-9a-f:.]+\])$/D', $host) !== 1) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    /**
     * The host of an origin or URL (lowercase, IPv6 without brackets), or null.
     */
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(trim($host, '[]')) : null;
    }

    public static function isHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://');
    }

    /**
     * An IP literal (v4 or v6): never a WebAuthn relying party or a certificate name.
     */
    public static function isIp(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    public static function isLoopback(string $host): bool
    {
        $host = trim(strtolower($host), '[]');

        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }
}
