<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Commit author avatars from GitLab and Gitea/Forgejo (docs/19 §7): only
 * https URLs on the connection's own origin or on `secure.gravatar.com`,
 * rebuilt from checked parts (path and a short query), so a webhook payload
 * can't point the dashboard's `<img>` (or the CSP's img-src) anywhere else.
 */
final class ProviderAvatar
{
    public const string GRAVATAR_HOST = 'secure.gravatar.com';

    public static function url(?string $url, string $origin): ?string
    {
        if ($url === null || strlen($url) > 255) {
            return null;
        }

        $parts = parse_url($url);
        $allowed = parse_url($origin);

        if ($parts === false || $allowed === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;
        $onOrigin = ($allowed['scheme'] ?? null) === 'https' && $host === strtolower($allowed['host'] ?? '') && $port === ($allowed['port'] ?? null);

        if (! $onOrigin && ($host !== self::GRAVATAR_HOST || $port !== null)) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (preg_match('#^/[A-Za-z0-9._~/-]{1,200}$#D', $path) !== 1 || str_contains($path, '..')) {
            return null;
        }

        $query = $parts['query'] ?? '';
        $query = preg_match('/^[A-Za-z0-9=&._-]{0,64}$/D', $query) === 1 && $query !== '' ? '?'.$query : '';

        return 'https://'.$host.($port === null ? '' : ':'.$port).$path.$query;
    }

    /**
     * The https origin of a connection base URL for the CSP, or null.
     */
    public static function origin(string $baseUrl): ?string
    {
        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || preg_match('/^[a-z0-9.-]+$/D', strtolower($parts['host'])) !== 1) {
            return null;
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
