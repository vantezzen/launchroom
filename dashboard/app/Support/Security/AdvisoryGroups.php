<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Advisory groups (docs/22 §4.5): ids that share an alias are one advisory
 * (GHSA-…, CVE-… and PYSEC-… for the same issue). The group's display id is
 * the GHSA id, else the CVE, else the record's own id. Pure.
 */
final class AdvisoryGroups
{
    public const string CVE = '/^CVE-\d{4}-\d{4,}$/D';

    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $upstream
     */
    public static function key(string $id, array $aliases, array $upstream): string
    {
        $ids = [$id, ...$aliases];

        foreach ($ids as $candidate) {
            if (str_starts_with($candidate, 'GHSA-')) {
                return $candidate;
            }
        }

        foreach ([...$ids, ...$upstream] as $candidate) {
            if (preg_match(self::CVE, $candidate) === 1) {
                return $candidate;
            }
        }

        return $id;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function cves(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => preg_match(self::CVE, $id) === 1)));
    }
}
