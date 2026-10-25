<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Maps an inventory component to the OSV package it is queried as
 * (docs/22 §4.5): language ecosystems by name, OS packages by their source
 * package in the distribution's OSV ecosystem, a fixed table of runtimes
 * and servers found as binaries in Bitnami's database, and Go's toolchain
 * as `Go`/`stdlib`. Null: inventoried but not queried. Pure.
 */
final class OsvPackage
{
    private const array LANGUAGES = [
        'npm' => 'npm', 'pypi' => 'PyPI', 'go' => 'Go', 'maven' => 'Maven', 'nuget' => 'NuGet',
        'cargo' => 'crates.io', 'rubygems' => 'RubyGems', 'packagist' => 'Packagist', 'hex' => 'Hex', 'pub' => 'Pub',
    ];

    /** Binaries the scanner classifies → Bitnami package names. */
    private const array BITNAMI = [
        'node' => 'node', 'nodejs' => 'node',
        'python' => 'python', 'python3' => 'python',
        'php' => 'php', 'php-cli' => 'php', 'php-fpm' => 'php',
        'ruby' => 'ruby',
        'nginx' => 'nginx',
        'httpd' => 'apache', 'apache' => 'apache', 'apache2' => 'apache',
        'postgresql' => 'postgresql', 'postgres' => 'postgresql',
        'mysql' => 'mysql', 'mysqld' => 'mysql',
        'mariadb' => 'mariadb', 'mariadbd' => 'mariadb',
        'redis' => 'redis', 'redis-server' => 'redis',
        'mongodb' => 'mongodb', 'mongod' => 'mongodb',
    ];

    private const array RPM_DISTROS = ['rhel' => 'Red Hat', 'rocky' => 'Rocky Linux', 'almalinux' => 'AlmaLinux'];

    /**
     * @param  array{id: string, versionId?: string}|null  $distro  the image's /etc/os-release (fallback when the purl has no distro)
     * @return array{ecosystem: string, name: string, version: string}|null
     */
    public static function for(string $ecosystem, string $name, string $version, ?string $purl, ?array $distro = null): ?array
    {
        if (isset(self::LANGUAGES[$ecosystem])) {
            if ($ecosystem === 'go') {
                // Go's toolchain is listed as `stdlib` with `go1.22.3`.
                $version = ltrim(preg_replace('/^go/', '', $version) ?? $version, 'v');
            }

            return ['ecosystem' => self::LANGUAGES[$ecosystem], 'name' => $name, 'version' => $version];
        }

        $qualifiers = self::qualifiers($purl);
        [$distroId, $distroVersion] = self::distro($qualifiers['distro'] ?? null, $distro);
        [$source, $sourceVersion] = self::upstream($qualifiers['upstream'] ?? null);

        return match ($ecosystem) {
            'deb' => self::deb($distroId, $distroVersion, $source ?? $name, $sourceVersion ?? $version),
            'apk' => self::apk($distroId, $distroVersion, $source ?? $name, $version),
            'rpm' => $distroId !== null && isset(self::RPM_DISTROS[$distroId]) ? ['ecosystem' => self::RPM_DISTROS[$distroId], 'name' => $source ?? $name, 'version' => $version] : null,
            'binary' => self::binary($name, $version),
            default => null,
        };
    }

    /**
     * @return array{ecosystem: string, name: string, version: string}|null
     */
    private static function deb(?string $id, ?string $version, string $name, string $packageVersion): ?array
    {
        if ($id === 'debian' && $version !== null && preg_match('/^(\d+)/', $version, $m) === 1) {
            return ['ecosystem' => 'Debian:'.$m[1], 'name' => $name, 'version' => $packageVersion];
        }

        if ($id === 'ubuntu' && $version !== null && preg_match('/^(\d{2})\.(\d{2})/', $version, $m) === 1) {
            // Ubuntu's LTS releases are the April releases of even years.
            $lts = $m[2] === '04' && (int) $m[1] % 2 === 0;

            return ['ecosystem' => "Ubuntu:{$m[1]}.{$m[2]}".($lts ? ':LTS' : ''), 'name' => $name, 'version' => $packageVersion];
        }

        return null;
    }

    /**
     * @return array{ecosystem: string, name: string, version: string}|null
     */
    private static function apk(?string $id, ?string $version, string $name, string $packageVersion): ?array
    {
        if ($id !== 'alpine' || $version === null || preg_match('/^(\d+)\.(\d+)/', $version, $m) !== 1) {
            return null;
        }

        return ['ecosystem' => "Alpine:v{$m[1]}.{$m[2]}", 'name' => $name, 'version' => $packageVersion];
    }

    /**
     * @return array{ecosystem: string, name: string, version: string}|null
     */
    private static function binary(string $name, string $version): ?array
    {
        $name = strtolower($name);

        if ($name === 'go' || $name === 'golang') {
            return ['ecosystem' => 'Go', 'name' => 'stdlib', 'version' => ltrim(preg_replace('/^go/', '', $version) ?? $version, 'v')];
        }

        if (! isset(self::BITNAMI[$name])) {
            return null;
        }

        return ['ecosystem' => 'Bitnami', 'name' => self::BITNAMI[$name], 'version' => ltrim($version, 'v')];
    }

    /**
     * @return array<string, string>
     */
    private static function qualifiers(?string $purl): array
    {
        if ($purl === null || ! str_contains($purl, '?')) {
            return [];
        }

        $query = explode('#', substr($purl, (int) strpos($purl, '?') + 1), 2)[0];
        $out = [];

        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);

            if (count($parts) === 2 && $parts[0] !== '') {
                $out[strtolower(rawurldecode($parts[0]))] = rawurldecode($parts[1]);
            }
        }

        return $out;
    }

    /**
     * `debian-12`, `ubuntu-22.04`, `alpine-3.20.3` (Syft's `distro` qualifier).
     *
     * @param  array{id: string, versionId?: string}|null  $fallback
     * @return array{string|null, string|null}
     */
    private static function distro(?string $qualifier, ?array $fallback): array
    {
        if ($qualifier !== null && preg_match('/^([a-z0-9._]+)-([A-Za-z0-9._]+)$/D', $qualifier, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [$fallback['id'] ?? null, $fallback['versionId'] ?? null];
    }

    /**
     * The source package (`upstream=openssl` or `upstream=glibc@2.36-9`).
     *
     * @return array{string|null, string|null}
     */
    private static function upstream(?string $value): array
    {
        if ($value === null || $value === '' || preg_match('/^([A-Za-z0-9][A-Za-z0-9.+_-]{0,127})(?:@([A-Za-z0-9.+~:_-]{1,128}))?$/D', $value, $m) !== 1) {
            return [null, null];
        }

        return [$m[1], $m[2] ?? null];
    }
}
