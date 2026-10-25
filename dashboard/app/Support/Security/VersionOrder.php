<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Version ordering for the "fixed in" display (docs/22 §4.5): semver-like
 * versions of npm, Go and Packagist (`v1.2.3`, `1.2.3-rc.1`, `1.2.3.4`), and
 * of runtimes found as binaries (Bitnami's ranges are SEMVER: node 22.23.3
 * is fixed in 22.23.4 of the list 22.23.4, 24.22.1, 26.12.1).
 * Other ecosystems keep OSV's list of fixed versions. Pure.
 */
final class VersionOrder
{
    private const string PATTERN = '/^v?(\d+(?:\.\d+){0,3})(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/D';

    public const array COMPARABLE = ['npm', 'go', 'packagist', 'binary'];

    public static function comparable(string $ecosystem): bool
    {
        return in_array($ecosystem, self::COMPARABLE, true);
    }

    /**
     * <0, 0 or >0 like strcmp, or null when either version isn't semver-like.
     */
    public static function compare(string $a, string $b): ?int
    {
        $left = self::parse($a);
        $right = self::parse($b);

        if ($left === null || $right === null) {
            return null;
        }

        $length = max(count($left[0]), count($right[0]));

        for ($i = 0; $i < $length; $i++) {
            $diff = ($left[0][$i] ?? 0) <=> ($right[0][$i] ?? 0);

            if ($diff !== 0) {
                return $diff;
            }
        }

        // A pre-release sorts before its release.
        return match (true) {
            $left[1] === $right[1] => 0,
            $left[1] === null => 1,
            $right[1] === null => -1,
            default => self::comparePrerelease($left[1], $right[1]),
        };
    }

    /**
     * The smallest fixed version above the installed one (comparable
     * ecosystems), or null.
     *
     * @param  list<string>  $fixed
     */
    public static function nextFixed(string $ecosystem, string $installed, array $fixed): ?string
    {
        if (! self::comparable($ecosystem)) {
            return null;
        }

        $best = null;

        foreach ($fixed as $candidate) {
            $above = self::compare($candidate, $installed);

            if ($above === null || $above <= 0) {
                continue;
            }

            if ($best === null || self::compare($candidate, $best) < 0) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array{list<int>, string|null}|null
     */
    private static function parse(string $version): ?array
    {
        if (strlen($version) > 128 || preg_match(self::PATTERN, $version, $m) !== 1) {
            return null;
        }

        return [array_map(intval(...), explode('.', $m[1])), $m[2] ?? null];
    }

    private static function comparePrerelease(string $a, string $b): int
    {
        $left = explode('.', $a);
        $right = explode('.', $b);

        foreach ($left as $i => $part) {
            if (! isset($right[$i])) {
                return 1;
            }

            $other = $right[$i];
            $numeric = ctype_digit($part) && ctype_digit($other);
            $diff = $numeric ? ((int) $part <=> (int) $other) : strcmp($part, $other);

            if ($diff !== 0) {
                return $diff < 0 ? -1 : 1;
            }
        }

        return count($left) < count($right) ? -1 : 0;
    }
}
