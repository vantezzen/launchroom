<?php

declare(strict_types=1);

namespace App\Support\Git;

use App\Support\Json\JsonObject;

/**
 * The files a push touched, from the commits its webhook payload lists
 * (docs/06 §2 watch paths). Providers list at most 20 commits per payload,
 * so a push with more (or exactly that many) is unknown: every service
 * rebuilds.
 */
final class PushedFiles
{
    public const int MAX_PAYLOAD_COMMITS = 20;

    /**
     * Sorted union of added, modified and removed files, or null when unknown.
     *
     * @param  list<JsonObject>  $commits
     * @param  int|null  $total  the push's commit count when the payload states it
     * @return list<string>|null
     */
    public static function of(array $commits, ?int $total = null): ?array
    {
        if (($total ?? count($commits)) > self::MAX_PAYLOAD_COMMITS || count($commits) >= self::MAX_PAYLOAD_COMMITS) {
            return null;
        }

        $files = [];

        foreach ($commits as $commit) {
            foreach (['added', 'modified', 'removed'] as $key) {
                foreach ($commit->strings($key) as $file) {
                    $files[$file] = true;
                }
            }
        }

        $files = array_map(strval(...), array_keys($files));
        sort($files);

        return $files;
    }
}
