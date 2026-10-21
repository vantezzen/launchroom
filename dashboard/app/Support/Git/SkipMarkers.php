<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Commit-message markers that skip a deploy (docs/06 §2). Matches the
 * conventions GitHub Actions and other CI systems honour, plus our own
 * `[skip deploy]`.
 */
final class SkipMarkers
{
    private const array MARKERS = [
        '[skip ci]',
        '[ci skip]',
        '[no ci]',
        '[skip deploy]',
        '[deploy skip]',
    ];

    public static function matches(string $commitMessage): bool
    {
        $message = mb_strtolower($commitMessage);

        return array_any(self::MARKERS, fn (string $marker): bool => str_contains($message, $marker));
    }
}
