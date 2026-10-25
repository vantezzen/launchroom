<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Which analysed file a library's scope came from (docs/22 §3.3): a
 * lockfile of its ecosystem before a manifest such as package.json.
 * Runtimes and OS packages are read from the image, so they have none. Pure.
 */
final class Lockfiles
{
    /** Lockfile kinds per ecosystem (docs/03 §13.1 `LockfileKind`). */
    private const array KINDS = [
        'npm' => ['npm', 'pnpm', 'yarn', 'bun'],
        'pypi' => ['poetry', 'uv', 'pipfile', 'requirements', 'pyproject'],
        'packagist' => ['composer'],
        'rubygems' => ['gemfile'],
        'go' => ['gomod'],
    ];

    private const array MANIFESTS = ['package.json', 'pyproject.toml', 'Gemfile', 'composer.json'];

    /**
     * The path as the inventory lists it, or null.
     *
     * @param  list<array{path: string, kind: string}>  $lockfiles
     */
    public static function evidence(string $ecosystem, string $kind, array $lockfiles): ?string
    {
        if ($kind !== 'library') {
            return null;
        }

        $matching = array_values(array_filter($lockfiles, static fn (array $entry): bool => in_array($entry['kind'], self::KINDS[$ecosystem] ?? [], true)));

        foreach ($matching as $entry) {
            if (! in_array(basename($entry['path']), self::MANIFESTS, true)) {
                return $entry['path'];
            }
        }

        return $matching[0]['path'] ?? null;
    }
}
