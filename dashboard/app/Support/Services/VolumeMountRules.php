<?php

declare(strict_types=1);

namespace App\Support\Services;

/**
 * Where a service volume may be mounted (docs/14 §9.12). The grammar is
 * launchroomd's ContainerPath (checked by the Data objects); on top of it
 * the path must be clean, outside the kernel's trees and the Docker socket
 * (launchroomd refuses them too, docs/09 R4), and never inside or around
 * another volume of the same service.
 */
final class VolumeMountRules
{
    private const array FORBIDDEN = ['/proc', '/sys', '/dev', '/var/run/docker.sock', '/run/docker.sock'];

    /**
     * @param  iterable<string>  $others  the mount paths of the service's other volumes
     */
    public static function problem(string $path, iterable $others): ?string
    {
        if (array_any(explode('/', $path), static fn (string $segment): bool => $segment === '.' || $segment === '..')
            || array_any(self::FORBIDDEN, static fn (string $forbidden): bool => $path === $forbidden || str_starts_with($path, $forbidden.'/'))) {
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
