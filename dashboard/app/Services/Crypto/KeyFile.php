<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Services\Crypto\Exceptions\InvalidKeyFile;
use App\Support\Files\SecureFile;
use App\Support\Files\SecureFileFailed;

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
        $key = SecretKey::generate();

        try {
            SecureFile::createExclusive($path, self::PREFIX.base64_encode($key->reveal()).PHP_EOL);
        } catch (SecureFileFailed $e) {
            throw $e->exists ? InvalidKeyFile::alreadyExists($path) : InvalidKeyFile::notWritable($path);
        }

        return $key;
    }
}
