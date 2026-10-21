<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Services\Crypto\Exceptions\InvalidKeyFile;

/**
 * The control-plane Ed25519 signing key file (docs/03 §4.1, protocol/SIGNING.md §3).
 *
 * One line: `lrsign1:<kid>:<base64 of the 32-byte Ed25519 seed>`. The kid is
 * part of the file so a key and its identifier can never drift apart. The
 * file is created 0400 and never overwritten: daemons pin the public key.
 */
final class SigningKeyFile
{
    private const string PREFIX = 'lrsign1';

    public const string KID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    /**
     * @throws InvalidKeyFile
     */
    public static function read(string $path): Signer
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidKeyFile::missing($path);
        }

        $parts = explode(':', trim((string) file_get_contents($path)));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || preg_match(self::KID_PATTERN, $parts[1]) !== 1) {
            throw InvalidKeyFile::malformed($path);
        }

        $seed = base64_decode($parts[2], true);

        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw InvalidKeyFile::malformed($path);
        }

        return Signer::fromSeed($parts[1], $seed);
    }

    /**
     * @throws InvalidKeyFile
     */
    public static function create(string $path, string $kid): Signer
    {
        if (preg_match(self::KID_PATTERN, $kid) !== 1) {
            throw new \InvalidArgumentException("Invalid signing key id [{$kid}].");
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw InvalidKeyFile::notWritable($path);
        }

        // 'x' fails if the file exists, atomically.
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw file_exists($path) ? InvalidKeyFile::alreadyExists($path) : InvalidKeyFile::notWritable($path);
        }

        chmod($path, 0600);

        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $written = fwrite($handle, self::PREFIX.':'.$kid.':'.base64_encode($seed).PHP_EOL);
        fclose($handle);

        if ($written === false) {
            @unlink($path);

            throw InvalidKeyFile::notWritable($path);
        }

        chmod($path, 0400);

        return Signer::fromSeed($kid, $seed);
    }
}
