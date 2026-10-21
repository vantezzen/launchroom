<?php

declare(strict_types=1);

namespace App\Support\Git;

use App\Support\Network\IpClassifier;

/**
 * Whether github.com can deliver webhooks to this dashboard's URL. GitHub
 * rejects an app manifest whose hook URL isn't reachable over the public
 * internet (localhost, private addresses), even for an inactive hook, so the
 * manifest leaves the webhook out then and pushes arrive by polling
 * (docs/06 §2). Grammar only, no DNS lookup: a name like `launchroom.lan`
 * or a single label never resolves on the internet.
 */
final class WebhookReachability
{
    /** Suffixes that are never public (RFC 6761, RFC 8375, common LAN ones). */
    private const array LOCAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home', '.home.arpa', '.test', '.example', '.invalid', '.intranet', '.corp'];

    public static function isPublic(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $literal = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;

        if (IpClassifier::isIp($literal)) {
            return IpClassifier::isPublic($literal);
        }

        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }

        return array_all(self::LOCAL_SUFFIXES, fn (string $suffix): bool => ! str_ends_with($host, $suffix));
    }
}
