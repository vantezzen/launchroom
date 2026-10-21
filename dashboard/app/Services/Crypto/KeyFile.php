<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Services\Crypto\Exceptions\InvalidKeyFile;

/**
 * Reads and creates key files: a single line "lrkey1:<base64 of 32 bytes>".
 */
final class KeyFile
{
    private const string PREFIX = 'lrkey1:';

    /**
     * @throws InvalidKeyFile
     */
    public static function read(string $path): SecretKey
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidKeyFile::missing($path);
        }

        $contents = trim((string) file_get_contents($path));

        if (! str_starts_with($contents, self::PREFIX)) {
            throw InvalidKeyFile::malformed($path);
        }

        $bytes = base64_decode(substr($contents, strlen(self::PREFIX)), true);

        if ($bytes === false || strlen($bytes) !== SecretKey::BYTES) {
            throw InvalidKeyFile::malformed($path);
        }

        return SecretKey::fromBytes($bytes);
    }

    /**
     * Create a new key file readable only by its owner (0400). Never
     * overwrites: losing a KEK makes every stored secret unrecoverable.
     *
     * @throws InvalidKeyFile
     */
    public static function create(string $path): SecretKey
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw InvalidKeyFile::notWritable($path);
        }

        // 'x' fails if the file exists, atomically (no check-then-create race).
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw file_exists($path) ? InvalidKeyFile::alreadyExists($path) : InvalidKeyFile::notWritable($path);
        }

        chmod($path, 0600);

        $key = SecretKey::generate();
        $written = fwrite($handle, self::PREFIX.base64_encode($key->reveal()).PHP_EOL);
        fclose($handle);

        if ($written === false) {
            @unlink($path);

            throw InvalidKeyFile::notWritable($path);
        }

        chmod($path, 0400);

        return $key;
    }
}
