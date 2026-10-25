<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\WafCategory;

/**
 * Which firewall categories can show attempts against an advisory
 * (docs/22 §7.4): its CWE ids mapped to attack categories. Unmapped CWEs
 * (or none) mean every category. Pure.
 */
final class CweCategories
{
    private const array MAP = [
        'CWE-89' => ['sqli'], 'CWE-564' => ['sqli'],
        'CWE-79' => ['xss'], 'CWE-80' => ['xss'],
        'CWE-77' => ['rce', 'generic'], 'CWE-78' => ['rce', 'generic'], 'CWE-94' => ['rce', 'generic'],
        'CWE-95' => ['rce', 'generic'], 'CWE-917' => ['rce', 'generic'], 'CWE-1336' => ['rce', 'generic'],
        'CWE-22' => ['lfi'], 'CWE-23' => ['lfi'], 'CWE-36' => ['lfi'],
        'CWE-98' => ['rfi'],
        'CWE-918' => ['generic'],
        'CWE-502' => ['java', 'php'],
        'CWE-384' => ['session_fixation'],
    ];

    /**
     * @param  list<string>  $cweIds
     * @return list<WafCategory> empty = every category
     */
    public static function for(array $cweIds): array
    {
        $categories = [];

        foreach ($cweIds as $cwe) {
            $mapped = self::MAP[strtoupper($cwe)] ?? null;

            if ($mapped === null) {
                return [];
            }

            foreach ($mapped as $category) {
                $categories[$category] = WafCategory::from($category);
            }
        }

        return array_values($categories);
    }
}
