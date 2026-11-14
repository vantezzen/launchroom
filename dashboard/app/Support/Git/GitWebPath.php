<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Pieces of git hosts' web URLs.
 */
final class GitWebPath
{
    /**
     * A branch as a URL path (`feature/login` stays two segments, each
     * encoded).
     */
    public static function branch(string $branch): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $branch)));
    }
}
