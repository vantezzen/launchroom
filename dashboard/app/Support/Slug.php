<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DNS-safe slugs (`^[a-z0-9][a-z0-9-]{0,62}$`, no trailing dash). Service
 * slugs are network aliases and reference names (docs/05 §1, docs/06 §3.1).
 */
final class Slug
{
    public const string PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /** Names a service slug can't take: they mean something else in references. */
    public const array RESERVED = ['shared', 'launchroom', 'localhost'];

    public static function from(string $name, string $fallback = 'app'): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        $slug = trim(substr($slug, 0, 63), '-');

        return $slug === '' ? $fallback : $slug;
    }

    public static function isValid(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1;
    }

    /**
     * $slug, or $slug-2, $slug-3, … — the first one $taken doesn't contain.
     *
     * @param  list<string>  $taken
     */
    public static function unique(string $slug, array $taken): string
    {
        $candidate = $slug;

        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $suffix = '-'.$i;
            $candidate = rtrim(substr($slug, 0, 63 - strlen($suffix)), '-').$suffix;
        }

        return $candidate;
    }
}
