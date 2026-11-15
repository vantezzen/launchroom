<?php

declare(strict_types=1);

namespace App\Support\Files;

use SensitiveParameter;

/**
 * Writes secret files (key files, restored keys): created atomically, never
 * over an existing file, readable only by the owner while being written.
 */
final class SecureFile
{
    /**
     * Creates $path (parent directories 0700) with $contents and then $mode.
     * Fails if the file exists ('x' is atomic: no check-then-create race); a
     * failed write leaves no file behind.
     *
     * @throws SecureFileFailed
     */
    public static function createExclusive(string $path, #[SensitiveParameter] string $contents, int $mode = 0400): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw SecureFileFailed::notWritable($path);
        }

        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw file_exists($path) ? SecureFileFailed::exists($path) : SecureFileFailed::notWritable($path);
        }

        chmod($path, 0600);
        $written = fwrite($handle, $contents);
        fclose($handle);

        if ($written !== strlen($contents)) {
            @unlink($path);

            throw SecureFileFailed::notWritable($path);
        }

        chmod($path, $mode);
    }
}
