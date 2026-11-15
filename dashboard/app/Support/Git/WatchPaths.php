<?php

declare(strict_types=1);

namespace App\Support\Git;

use Illuminate\Support\Str;

/**
 * Whether a change concerns a service (docs/06 §2): its build watch paths are
 * repo-relative globs or directory prefixes. Unknown changes (null) always
 * count; without watch paths everything counts.
 */
final class WatchPaths
{
    /**
     * @param  list<string>  $watchPaths
     * @param  list<string>|null  $changedFiles
     */
    public static function matches(array $watchPaths, ?array $changedFiles): bool
    {
        if ($changedFiles === null || $watchPaths === []) {
            return true;
        }

        foreach ($changedFiles as $file) {
            foreach ($watchPaths as $path) {
                $path = trim($path);
                $prefix = rtrim($path, '/').'/';

                if ($path !== '' && ($file === $path || str_starts_with($file, $prefix) || Str::is($path, $file))) {
                    return true;
                }
            }
        }

        return false;
    }
}
