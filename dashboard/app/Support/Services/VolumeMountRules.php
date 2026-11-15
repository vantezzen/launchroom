<?php

declare(strict_types=1);

namespace App\Support\Services;

use App\Support\Protocol\Grammar;

/**
 * Where a service volume may be mounted (docs/14 §9.12). The grammar is
 * launchroomd's ContainerPath (checked by the Data objects); on top of it
 * the path must be clean, outside the kernel's trees and the Docker socket
 * (launchroomd refuses them too, docs/09 R4), and never inside or around
 * another volume of the same service.
 */
final class VolumeMountRules
{
    public const string PATH = Grammar::CONTAINER_PATH;

    public const int MAX_LENGTH = 255;

    /**
     * launchroomd's ContainerPath rule: the grammar, at most MAX_LENGTH bytes,
     * clean, and not (inside) a forbidden target.
     */
    public static function isMountable(string $path): bool
    {
        return preg_match(self::PATH, $path) === 1 && strlen($path) <= self::MAX_LENGTH && self::isClean($path) && ! self::isForbidden($path);
    }

    public static function isClean(string $path): bool
    {
        return ! array_any(explode('/', $path), static fn (string $segment): bool => $segment === '.' || $segment === '..');
    }

    /** Whether $path is (inside) /proc, /sys, /dev or the Docker socket (docs/09 R4). */
    public static function isForbidden(string $path): bool
    {
        return array_any(Grammar::FORBIDDEN_MOUNT_TARGETS, static fn (string $forbidden): bool => $path === $forbidden || str_starts_with($path, $forbidden.'/'));
    }

    /**
     * @param  iterable<string>  $others  the mount paths of the service's other volumes
     */
    public static function problem(string $path, iterable $others): ?string
    {
        if (! self::isClean($path) || self::isForbidden($path)) {
            return 'Pick another path: it must be clean and outside /proc, /sys and /dev.';
        }

        foreach ($others as $other) {
            if ($other === $path || str_starts_with($path, rtrim($other, '/').'/') || str_starts_with($other, rtrim($path, '/').'/')) {
                return 'This path is already (inside) a volume of this service.';
            }
        }

        return null;
    }
}
