<?php

declare(strict_types=1);

namespace App\Support\Services;

use App\Data\Services\ConfigFile;
use App\Support\Variables\EnvFileParser;

/**
 * What a service's config files may be (D65): the same limits launchroomd
 * enforces as its policy floor (docs/03 §8), checked early so a blueprint,
 * an import or a settings save fails with a sentence instead of a refused
 * NodeState. Pure.
 */
final class ConfigFileRules
{
    public const int MAX_FILES = 32;

    public const int MAX_BYTES = 64 * 1024;

    public const int MAX_TOTAL_BYTES = 256 * 1024;

    private const string PATH = '/^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/D';

    /** Kernel and device trees: never written. */
    private const array FORBIDDEN_TREES = ['/proc', '/sys', '/dev'];

    /** Files Docker manages itself (bind-mounted into every container). */
    private const array FORBIDDEN_FILES = ['/etc/hosts', '/etc/hostname', '/etc/resolv.conf'];

    /**
     * @param  list<ConfigFile>  $files
     * @param  list<string>  $mounts  volume mount paths of the service: files never write into a volume
     * @return list<string> one sentence per problem; empty when valid
     */
    public static function errors(array $files, array $mounts = []): array
    {
        $errors = [];

        if (count($files) > self::MAX_FILES) {
            $errors[] = 'A service has at most '.self::MAX_FILES.' config files.';
        }

        $paths = [];
        $total = 0;

        foreach ($files as $file) {
            $path = $file->path;
            $error = self::pathError($path, $mounts);

            if ($error !== null) {
                $errors[] = $error;

                continue;
            }

            if (isset($paths[$path])) {
                $errors[] = "The config file {$path} is defined twice.";
            }

            $paths[$path] = true;
            $size = strlen($file->content);
            $total += $size;

            if ($size > self::MAX_BYTES) {
                $errors[] = "{$path} is larger than ".(self::MAX_BYTES / 1024).' KiB.';
            } elseif (! mb_check_encoding($file->content, 'UTF-8') || str_contains($file->content, "\0")) {
                $errors[] = "{$path} must be text (UTF-8, no NUL bytes).";
            } elseif (EnvFileParser::looksSecret($file->content)) {
                $errors[] = "{$path} looks like it contains a secret: keep secrets in variables and let the program read them from its environment.";
            }
        }

        foreach (array_keys($paths) as $path) {
            foreach (array_keys($paths) as $other) {
                if ($path !== $other && str_starts_with((string) $other, $path.'/')) {
                    $errors[] = "{$path} can’t be a file and the folder of {$other}.";
                }
            }
        }

        if ($total > self::MAX_TOTAL_BYTES) {
            $errors[] = 'Config files are limited to '.(self::MAX_TOTAL_BYTES / 1024).' KiB per service.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  list<string>  $mounts
     */
    public static function pathError(string $path, array $mounts = []): ?string
    {
        if (preg_match(self::PATH, $path) !== 1 || strlen($path) > 255
            || array_any(explode('/', $path), static fn (string $segment): bool => $segment === '.' || $segment === '..')) {
            return "“{$path}” isn’t a valid file path: use an absolute path like /etc/app/config.yml.";
        }

        if (in_array($path, self::FORBIDDEN_FILES, true)
            || array_any(self::FORBIDDEN_TREES, static fn (string $tree): bool => $path === $tree || str_starts_with($path, $tree.'/'))) {
            return "{$path} can’t be a config file: Docker and the kernel manage it.";
        }

        foreach ($mounts as $mount) {
            $mount = rtrim($mount, '/');

            if ($path === $mount || str_starts_with($path, $mount.'/')) {
                return "{$path} is inside the volume at {$mount}: config files never write into volumes.";
            }
        }

        return null;
    }
}
