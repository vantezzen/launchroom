<?php

declare(strict_types=1);

namespace App\Services\Backups;

use RuntimeException;
use SensitiveParameter;

/**
 * Writes a restored key bundle ({@see ControlPlaneKeyBundle}) where the
 * installer expects it (docs/14 §9.7): `<out>/keys/<files>` (0400, public
 * key 0444), `<out>/headscale/<files>` (0600) and `<out>/restored.env`
 * (0600, `NAME=value` lines plus APP_URL). Used by `launchroom:restore`
 * (from a backup) and `launchroom:move:receive` (from a hand-over,
 * docs/14 §13.4). Existing files are refused unless $force.
 */
final class RestoredKeyFiles
{
    /**
     * @param  array{appUrl: string|null, env: array<string, string>, files: array<string, string>, headscale: array<string, string>}  $keys
     * @return list<string> written paths
     */
    public static function write(#[SensitiveParameter] array $keys, string $out, bool $force): array
    {
        $out = rtrim($out, '/');

        if (! is_dir($out) && ! @mkdir($out, 0700, true) && ! is_dir($out)) {
            throw new RuntimeException("Can't create {$out}.");
        }

        $written = [];

        foreach ($keys['files'] as $name => $contents) {
            $path = $out.'/keys/'.ControlPlaneKeyBundle::FILES[$name];
            self::file($path, $contents, str_ends_with($name, '.pub.pem') ? 0444 : 0400, $force);
            $written[] = $path;
        }

        // Headscale's state (docs/14 §9.6): the installer copies it to
        // data/headscale/lib before Headscale starts.
        foreach ($keys['headscale'] as $name => $contents) {
            $path = $out.'/headscale/'.$name;
            self::file($path, $contents, 0600, $force);
            $written[] = $path;
        }

        $env = '';

        foreach ($keys['env'] as $name => $value) {
            $env .= $name.'='.(preg_match('/^[A-Za-z0-9_.:\/+=,-]*$/D', $value) === 1 ? $value : '"'.addcslashes($value, '"\\$`').'"')."\n";
        }

        if ($keys['appUrl'] !== null && preg_match('/^https?:\/\/[^\s"]+$/D', $keys['appUrl']) === 1) {
            $env .= 'APP_URL='.$keys['appUrl']."\n";
        }

        self::file($out.'/restored.env', $env, 0600, $force);
        $written[] = $out.'/restored.env';

        return $written;
    }

    private static function file(string $path, #[SensitiveParameter] string $contents, int $mode, bool $force): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Can't create {$dir}.");
        }

        if (file_exists($path)) {
            if (! $force) {
                throw new RuntimeException("{$path} exists; pass --force to overwrite it.");
            }

            @chmod($path, 0600);
            @unlink($path);
        }

        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new RuntimeException("Can't write {$path}.");
        }

        chmod($path, 0600);
        fwrite($handle, $contents);
        fclose($handle);
        chmod($path, $mode);
    }
}
