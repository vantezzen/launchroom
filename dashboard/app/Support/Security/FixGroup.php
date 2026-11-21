<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * A "thing to fix" on the Security page (docs/22 §5.6, D107): every open
 * finding of one package, however many advisories and services it has,
 * because one update fixes them together. Its key is stable across
 * evaluations and safe in alert subjects and URL fragments. The impact
 * line turns the advisories' weakness types (CWE) into what an attacker
 * could do, in plain words. Pure.
 */
final class FixGroup
{
    /**
     * What an attacker could do, by CWE, most serious first: the order
     * decides which kinds the impact line names.
     */
    private const array IMPACTS = [
        'run code on the server' => ['CWE-77', 'CWE-78', 'CWE-94', 'CWE-95', 'CWE-96', 'CWE-502', 'CWE-434', 'CWE-917', 'CWE-1336'],
        'read or change the database' => ['CWE-89', 'CWE-564', 'CWE-943'],
        'get past authorization' => ['CWE-284', 'CWE-285', 'CWE-287', 'CWE-288', 'CWE-290', 'CWE-306', 'CWE-639', 'CWE-862', 'CWE-863', 'CWE-1390'],
        'make the server send requests for them' => ['CWE-918'],
        'read files on the server' => ['CWE-22', 'CWE-23', 'CWE-35', 'CWE-36', 'CWE-73', 'CWE-98', 'CWE-552'],
        'smuggle requests past checks' => ['CWE-444', 'CWE-113'],
        'change the app’s objects' => ['CWE-915', 'CWE-1321'],
        'read data they shouldn’t see' => ['CWE-200', 'CWE-201', 'CWE-209', 'CWE-359', 'CWE-532'],
        'serve poisoned pages from the cache' => ['CWE-349', 'CWE-524', 'CWE-525'],
        'inject scripts into pages' => ['CWE-79', 'CWE-80', 'CWE-116'],
        'send visitors to other sites' => ['CWE-601'],
        'crash or slow down the service' => ['CWE-400', 'CWE-401', 'CWE-404', 'CWE-674', 'CWE-770', 'CWE-789', 'CWE-834', 'CWE-835', 'CWE-1333', 'CWE-405', 'CWE-407', 'CWE-409'],
    ];

    /** `fix_` and 16 hex characters of the package's hash. */
    public const string KEY_PATTERN = '/^fix_[0-9a-f]{16}$/D';

    public static function key(string $ecosystem, string $name): string
    {
        return 'fix_'.substr(hash('sha256', $ecosystem."\0".$name), 0, 16);
    }

    /**
     * "Attackers could run code on the server, get past authorization and
     * more." (the two most serious kinds). Null when no CWE is known.
     *
     * @param  list<string>  $cweIds  of every advisory in the group
     */
    public static function impact(array $cweIds): ?string
    {
        $ids = array_map(strtoupper(...), $cweIds);
        $kinds = [];

        foreach (self::IMPACTS as $words => $cwes) {
            if (array_intersect($cwes, $ids) !== []) {
                $kinds[] = $words;
            }
        }

        $first = $kinds[0] ?? null;
        $second = $kinds[1] ?? null;

        if ($first === null) {
            return null;
        }

        return 'Attackers could '.match (true) {
            $second === null => $first,
            count($kinds) === 2 => "{$first} or {$second}",
            default => "{$first}, {$second} and more",
        }.'.';
    }

    /**
     * The highest of the given versions (comparable ecosystems), else the
     * first one; null for none.
     *
     * @param  list<string>  $versions
     */
    public static function highest(array $versions): ?string
    {
        $best = null;

        foreach ($versions as $version) {
            if ($best === null || (VersionOrder::compare($version, $best) ?? 0) > 0) {
                $best = $version;
            }
        }

        return $best;
    }
}
