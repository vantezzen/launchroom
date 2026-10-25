<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Runtimes, engines and distributions whose release cycles launchroom
 * tracks with endoflife.date (docs/22 §4.6): the product names, how to read
 * the cycle from a version, and which components and engines map to them.
 * Pure.
 */
final class LifecycleProducts
{
    /** product => version segments in the cycle name, display name. */
    public const array PRODUCTS = [
        'nodejs' => [1, 'Node.js'],
        'python' => [2, 'Python'],
        'php' => [2, 'PHP'],
        'ruby' => [2, 'Ruby'],
        'go' => [2, 'Go'],
        'eclipse-temurin' => [1, 'Java'],
        'dotnet' => [2, '.NET'],
        'nginx' => [2, 'nginx'],
        'postgresql' => [1, 'PostgreSQL'],
        'mysql' => [2, 'MySQL'],
        'mariadb' => [2, 'MariaDB'],
        'redis' => [2, 'Redis'],
        'valkey' => [2, 'Valkey'],
        'mongodb' => [2, 'MongoDB'],
        'clickhouse' => [2, 'ClickHouse'],
        'debian' => [1, 'Debian'],
        'ubuntu' => [2, 'Ubuntu'],
        'alpine-linux' => [2, 'Alpine Linux'],
    ];

    /** Runtime components (binaries or OS packages) → product. */
    private const array RUNTIMES = [
        'node' => 'nodejs', 'nodejs' => 'nodejs',
        'python' => 'python', 'python3' => 'python',
        'php' => 'php', 'php-cli' => 'php', 'php-fpm' => 'php', 'frankenphp' => null,
        'ruby' => 'ruby',
        'go' => 'go', 'stdlib' => 'go',
        'java' => 'eclipse-temurin',
        'dotnet' => 'dotnet',
        'nginx' => 'nginx',
    ];

    /** Database engines of the service settings → product. */
    private const array ENGINES = [
        'postgres' => 'postgresql', 'mysql' => 'mysql', 'mariadb' => 'mariadb', 'redis' => 'redis',
        'valkey' => 'valkey', 'mongodb' => 'mongodb', 'clickhouse' => 'clickhouse',
    ];

    /** /etc/os-release ID → product. */
    private const array DISTROS = ['debian' => 'debian', 'ubuntu' => 'ubuntu', 'alpine' => 'alpine-linux'];

    public static function forRuntime(string $ecosystem, string $name): ?string
    {
        if ($ecosystem === 'go' && $name !== 'stdlib') {
            return null;
        }

        return self::RUNTIMES[strtolower($name)] ?? null;
    }

    public static function forEngine(string $engine): ?string
    {
        return self::ENGINES[$engine] ?? null;
    }

    public static function forDistro(string $id): ?string
    {
        return self::DISTROS[$id] ?? null;
    }

    /**
     * The upstream version of a runtime as its project numbers it, for
     * display and endoflife.date matching: OS packages lose the epoch and
     * the distribution's revision and suffixes ("1.31.8-1~trixie" and
     * "1:1.31.8-1+deb13u1" → "1.31.8", apk "1.26.2-r0" → "1.26.2", rpm
     * "1.20.1-14.el9" → "1.20.1"); Go's "go1.22.3" and a leading "v" are
     * dropped. The raw version stays on the component.
     */
    public static function upstreamVersion(string $ecosystem, string $version): string
    {
        $version = trim($version);

        if (in_array($ecosystem, ['deb', 'apk', 'rpm'], true)) {
            $version = (string) preg_replace('/^\d+:/', '', $version);

            if (str_contains($version, '-')) {
                $version = substr($version, 0, (int) strrpos($version, '-'));
            }

            $version = (string) preg_replace('/[+~].*$/', '', $version);
        }

        $version = (string) preg_replace('/^go(?=\d)/', '', $version);

        return $version === '' ? $version : ltrim($version, 'v');
    }

    /**
     * The release cycle of a version ("22.11.0" → "22" for nodejs,
     * "3.12.1" → "3.12" for python, "go1.22.3" → "1.22").
     */
    public static function cycle(string $product, string $version): ?string
    {
        $segments = self::PRODUCTS[$product][0] ?? null;

        if ($segments === null || preg_match('/^(?:go|v)?(\d+(?:\.\d+)*)/', $version, $m) !== 1) {
            return null;
        }

        $parts = explode('.', $m[1]);

        if (count($parts) < $segments) {
            return null;
        }

        return implode('.', array_slice($parts, 0, $segments));
    }

    public static function label(string $product): string
    {
        return self::PRODUCTS[$product][1] ?? $product;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::PRODUCTS);
    }
}
