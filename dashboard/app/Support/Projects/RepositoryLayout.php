<?php

declare(strict_types=1);

namespace App\Support\Projects;

/**
 * Finds the deployable apps of a repository from its file list (the import
 * flow, flows.md §3): one app at the root, or the workspace apps of a
 * monorepo (`apps/*`, `services/*`, or top-level folders such as `web/`,
 * `api/` with their own manifest). Pure: the caller fetches the tree.
 */
final class RepositoryLayout
{
    /** Files that make a folder an app, with the runtime they suggest. */
    private const array MANIFESTS = [
        'package.json' => 'node',
        'composer.json' => 'php',
        'requirements.txt' => 'python',
        'pyproject.toml' => 'python',
        'Pipfile' => 'python',
        'go.mod' => 'go',
        'Gemfile' => 'ruby',
        'Cargo.toml' => 'rust',
        'deno.json' => 'deno',
        'Dockerfile' => 'docker',
    ];

    private const array RANK = ['php' => 0, 'python' => 1, 'ruby' => 2, 'go' => 3, 'rust' => 4, 'node' => 5, 'deno' => 6, 'docker' => 7];

    /** Monorepo folders whose children are apps. */
    private const array APP_PARENTS = ['apps', 'services'];

    /** Top-level folders that are never an app of their own. */
    private const array NOT_APPS = [
        'packages', 'libs', 'lib', 'src', 'app', 'config', 'database', 'public', 'resources', 'routes', 'storage',
        'tests', 'test', 'spec', 'examples', 'example', 'fixtures', 'scripts', 'tools', 'bin', 'vendor', 'node_modules',
        'build', 'dist', 'bootstrap', 'deploy', 'infra', 'terraform', 'charts', 'helm', 'k8s', 'e2e', 'cypress', 'playwright',
    ];

    /** Folders that are usually not deployed with the rest: found, but not pre-selected. */
    private const array UNCHECKED = ['docs', 'doc', 'storybook', 'website', 'site', 'playground', 'sandbox'];

    public const array ENV_EXAMPLES = ['.env.example', '.env.sample', '.env.dist', '.env.template'];

    /**
     * @param  list<string>  $paths  blob paths, `/`-separated, relative to the repository root
     * @return list<array{rootDirectory: string, name: string, runtime: string, selected: bool, envExample: string|null}>
     */
    public static function apps(array $paths, string $repositoryName): array
    {
        /** @var array<string, string> $runtimes folder => runtime of its strongest manifest */
        $runtimes = [];
        /** @var array<string, true> $files */
        $files = [];

        foreach ($paths as $path) {
            $files[$path] = true;
            $segments = explode('/', $path);
            $file = array_pop($segments);

            if (! isset(self::MANIFESTS[$file]) || count($segments) > 2 || self::ignored($segments)) {
                continue;
            }

            $folder = implode('/', $segments);
            $runtime = self::MANIFESTS[$file];

            // A framework manifest beats package.json (Laravel, Django and
            // Rails apps ship one for their assets); Dockerfile only wins
            // when nothing else describes the folder.
            if (! isset($runtimes[$folder]) || self::RANK[$runtime] < self::RANK[$runtimes[$folder]]) {
                $runtimes[$folder] = $runtime;
            }
        }

        $nested = [];

        foreach ($runtimes as $folder => $runtime) {
            $segments = $folder === '' ? [] : explode('/', $folder);

            $isApp = match (count($segments)) {
                1 => ! in_array(strtolower($segments[0]), [...self::NOT_APPS, ...self::APP_PARENTS], true),
                2 => in_array(strtolower($segments[0]), self::APP_PARENTS, true),
                default => false,
            };

            if ($isApp) {
                $nested[$folder] = $runtime;
            }
        }

        // A root manifest next to nested apps is the workspace root (package
        // manager config), unless there is just one folder app (e.g. a
        // Laravel app with a `docs/` site): then the root is the app.
        $apps = [];
        $rootIsApp = isset($runtimes['']) && (count($nested) === 0 || self::onlyUnchecked($nested));

        if ($rootIsApp) {
            $apps[] = self::app('', $repositoryName, $runtimes[''], true, $files);
        }

        ksort($nested);

        foreach ($nested as $folder => $runtime) {
            $name = basename($folder);
            $apps[] = self::app($folder, $name, $runtime, ! in_array(strtolower($name), self::UNCHECKED, true), $files);
        }

        if ($apps === []) {
            // Nothing recognisable: offer the root anyway; the build detects (or fails with a fix).
            $apps[] = self::app('', $repositoryName, 'unknown', true, $files);
        }

        return $apps;
    }

    /**
     * @param  array<string, true>  $files
     * @return array{rootDirectory: string, name: string, runtime: string, selected: bool, envExample: string|null}
     */
    private static function app(string $folder, string $name, string $runtime, bool $selected, array $files): array
    {
        $envExample = null;

        foreach (self::ENV_EXAMPLES as $candidate) {
            $path = $folder === '' ? $candidate : $folder.'/'.$candidate;

            if (isset($files[$path])) {
                $envExample = $path;
                break;
            }
        }

        return ['rootDirectory' => $folder, 'name' => $name, 'runtime' => $runtime, 'selected' => $selected, 'envExample' => $envExample];
    }

    /**
     * @param  list<string>  $segments
     */
    private static function ignored(array $segments): bool
    {
        return array_any($segments, fn (string $segment): bool => $segment === 'node_modules' || $segment === 'vendor' || str_starts_with($segment, '.'));
    }

    /**
     * @param  array<string, string>  $nested
     */
    private static function onlyUnchecked(array $nested): bool
    {
        return array_all(array_keys($nested), fn (string $folder): bool => in_array(strtolower(basename($folder)), self::UNCHECKED, true));
    }
}
