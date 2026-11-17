<?php

declare(strict_types=1);

namespace App\Support\Git;

use App\Data\Protocol\V1\WorkspaceInfo;
use Illuminate\Support\Str;

/**
 * Whether a change concerns a service (docs/06 §2): its build watch paths are
 * repo-relative globs or directory prefixes. Unknown changes (null) always
 * count; without watch paths everything counts.
 */
final class WatchPaths
{
    /** Workspace-root files every package's build reads, by package manager and tool. */
    private const array ROOT_FILES = [
        'pnpm' => ['pnpm-lock.yaml', 'pnpm-workspace.yaml'],
        'npm' => ['package-lock.json'],
        'yarn' => ['yarn.lock', '.yarnrc.yml'],
        'bun' => ['bun.lock', 'bun.lockb'],
        'turbo' => ['turbo.json'],
        'nx' => ['nx.json'],
    ];

    /**
     * The watch paths a service deploys on (docs/04 §3 "Monorepos"): the
     * ones the user set; without any, an app in a monorepo workspace
     * (`summary.workspace` of its last build) watches its own folder, the
     * workspace packages it depends on and the workspace root's manifest,
     * lockfile and tool config. Anything else: none, so every change counts.
     *
     * @param  list<string>  $watchPaths  the service's own (build.watchPaths)
     * @return list<string>
     */
    public static function effective(array $watchPaths, string $rootDirectory, ?WorkspaceInfo $workspace): array
    {
        $rootDirectory = trim($rootDirectory, '/');

        if ($watchPaths !== [] || ! $workspace instanceof WorkspaceInfo || $rootDirectory === '' || $rootDirectory === '.') {
            return $watchPaths;
        }

        $root = trim($workspace->root ?? '', '/');
        $base = $root === '' || $root === '.' ? '' : $root.'/';
        $files = ['package.json', ...(self::ROOT_FILES[$workspace->manager] ?? []), ...(self::ROOT_FILES[$workspace->tool ?? ''] ?? [])];

        return array_values(array_unique([
            $rootDirectory,
            ...array_filter(array_map(static fn (string $path): string => trim($path, '/'), $workspace->dependsOn ?? []), static fn (string $path): bool => $path !== '' && $path !== '.'),
            ...array_map(static fn (string $file): string => $base.$file, $files),
        ]));
    }

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
