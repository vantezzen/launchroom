<?php

declare(strict_types=1);

namespace App\Services\Registry;

use RuntimeException;
use SensitiveParameter;

/**
 * The Zot config file on the control-plane disk (docs/14 §9.11): a local
 * path from config (`launchroom.registry.zot_config_path`), never a URL.
 * Written in place (the path is a single-file bind mount, so a rename
 * can't replace it) with mode 0600: it holds the S3 secret key.
 */
final class ZotConfigFile
{
    /**
     * sha256 of the file's contents, or null when it doesn't exist.
     */
    public function hash(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? hash('sha256', $contents) : null;
    }

    /**
     * @throws RuntimeException
     */
    public function write(string $path, #[SensitiveParameter] string $contents): void
    {
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Can't write {$path}: check that the file exists and belongs to the dashboard's user.");
        }

        try {
            @chmod($path, 0600);

            if (! ftruncate($handle, 0) || fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException("Writing {$path} failed.");
            }
        } finally {
            fclose($handle);
        }
    }
}
