<?php

declare(strict_types=1);

namespace App\Support\Alerts;

/**
 * Grammar of notification URLs (docs/15 §3.5). Checked before a URL is
 * stored; every request still goes through SafeHttpClient (https only,
 * public addresses only, pinned, no redirects).
 */
final class ChannelUrls
{
    private const int MAX_LENGTH = 2048;

    /** Slack incoming webhooks: https://hooks.slack.com/services/T…/B…/… */
    public static function isSlack(string $url): bool
    {
        return self::matches($url, ['hooks.slack.com'], '#^/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+$#D');
    }

    /** Discord webhooks: https://discord.com/api/webhooks/<id>/<token> */
    public static function isDiscord(string $url): bool
    {
        return self::matches($url, ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'], '#^/api(/v\d+)?/webhooks/\d+/[A-Za-z0-9_-]+$#D');
    }

    /** Generic webhooks: any https URL without credentials or fragment. */
    public static function isWebhook(string $url): bool
    {
        $parts = self::parts($url);

        return $parts !== null && ! isset($parts['fragment']);
    }

    /**
     * The clear-text trace of a secret URL: host and the last 4 characters
     * ("hooks.slack.com/…x7Qa").
     */
    public static function hint(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $tail = substr(rtrim($url, '/'), -4);

        return (is_string($host) ? strtolower($host) : 'webhook').'/…'.$tail;
    }

    /**
     * @param  list<string>  $hosts
     */
    private static function matches(string $url, array $hosts, string $pathPattern): bool
    {
        $parts = self::parts($url);

        return $parts !== null
            && in_array(strtolower($parts['host']), $hosts, true)
            && ! isset($parts['port'], $parts['query'], $parts['fragment'])
            && preg_match($pathPattern, $parts['path'] ?? '') === 1;
    }

    /**
     * @return array{host: string, port?: int, path?: string, query?: string, fragment?: string}|null
     */
    private static function parts(string $url): ?array
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $out = ['host' => $parts['host']];

        foreach (['port', 'path', 'query', 'fragment'] as $key) {
            if (isset($parts[$key])) {
                $out[$key] = $parts[$key];
            }
        }

        /** @var array{host: string, port?: int, path?: string, query?: string, fragment?: string} $out */
        return $out;
    }
}
