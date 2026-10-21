<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Links into the public user docs (config launchroom.docs_url, docs/21).
 * Pages are addressed by their path below the docs root, e.g.
 * `DocsUrl::to('servers/high-availability-ingress')`.
 */
final class DocsUrl
{
    public static function base(): string
    {
        return rtrim(config()->string('launchroom.docs_url'), '/');
    }

    public static function to(string $page): string
    {
        return self::base().'/'.ltrim($page, '/');
    }
}
