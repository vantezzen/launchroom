<?php

declare(strict_types=1);

namespace App\Support\Git;

use App\Support\Protocol\Grammar;
use Closure;

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

    /**
     * A validation rule for a branch name (without `refs/heads/`).
     */
    public static function branchRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isValid('refs/heads/'.$value)) {
                $fail('Enter a valid branch name.');
            }
        };
    }

    /**
     * A full commit id: SHA-1, or SHA-256 for repositories in git's SHA-256
     * object format (the grammar launchroomd accepts, docs/19 §2).
     */
    public static function isSha(string $value): bool
    {
        return preg_match(Grammar::GIT_SHA, $value) === 1;
    }

    /**
     * The all-zero id a push reports for a created (before) or deleted
     * (after) ref, in either object format.
     */
    public static function isZeroSha(string $value): bool
    {
        return self::isSha($value) && trim($value, '0') === '';
    }

    /**
     * `refs/heads/main` → `main`, `refs/tags/v1` → `v1`.
     */
    public static function shortName(string $ref): string
    {
        return (string) preg_replace('#^refs/(heads|tags)/#', '', $ref);
    }
}
