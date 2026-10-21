<?php

declare(strict_types=1);

namespace App\Support\Git;

/**
 * Fully qualified git refs (`refs/heads/main`, `refs/tags/v1.2.0`).
 */
final class GitRef
{
    public const string ZERO_SHA = '0000000000000000000000000000000000000000';

    /**
     * Conservative subset of `git check-ref-format` (docs/09 R2).
     */
    public static function isValid(string $ref): bool
    {
        return strlen($ref) <= 255
            && preg_match('#^refs/(heads|tags)/[A-Za-z0-9._/+-]+$#D', $ref) === 1
            && ! str_contains($ref, '..')
            && ! str_contains($ref, '//')
            && ! str_ends_with($ref, '/')
            && ! str_ends_with($ref, '.lock');
    }

    public static function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/D', $value) === 1;
    }

    /**
     * `refs/heads/main` → `main`, `refs/tags/v1` → `v1`.
     */
    public static function shortName(string $ref): string
    {
        return (string) preg_replace('#^refs/(heads|tags)/#', '', $ref);
    }
}
