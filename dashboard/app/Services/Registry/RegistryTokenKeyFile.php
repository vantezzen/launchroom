<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Services\Crypto\Exceptions\InvalidKeyFile;
use App\Support\Files\SecureFile;
use App\Support\Files\SecureFileFailed;
use OpenSSLAsymmetricKey;

/**
 * Key files for registry tokens (docs/05 §6, docs/09 R22: a separate key per
 * purpose). The private key is a PEM file (0400); the public key is a PEM
 * file in its own directory (0444 in 0755), so Zot can be given exactly that
 * directory and nothing else. The private key is never overwritten.
 */
final class RegistryTokenKeyFile
{
    /**
     * @throws InvalidKeyFile
     */
    public static function read(string $privatePath): RegistryTokenKey
    {
        if (! is_file($privatePath) || ! is_readable($privatePath)) {
            throw InvalidKeyFile::missing($privatePath);
        }

        $key = openssl_pkey_get_private((string) file_get_contents($privatePath));

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw InvalidKeyFile::malformed($privatePath);
        }

        return self::fromKey($key, $privatePath);
    }

    /**
     * Creates the private key and its public key file. Fails if the private
     * key already exists.
     *
     * @throws InvalidKeyFile
     */
    public static function create(string $privatePath, string $publicPath): RegistryTokenKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = null;

        if (! $key instanceof OpenSSLAsymmetricKey || ! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            throw InvalidKeyFile::notWritable($privatePath);
        }

        try {
            SecureFile::createExclusive($privatePath, $pem);
        } catch (SecureFileFailed $e) {
            throw $e->exists ? InvalidKeyFile::alreadyExists($privatePath) : InvalidKeyFile::notWritable($privatePath);
        }

        $registryKey = self::fromKey($key, $privatePath);
        self::writePublic($registryKey, $publicPath);

        return $registryKey;
    }

    /**
     * Writes (or restores) the public key file the registry verifies with.
     *
     * @throws InvalidKeyFile
     */
    public static function writePublic(RegistryTokenKey $key, string $publicPath): void
    {
        $directory = dirname($publicPath);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw InvalidKeyFile::notWritable($publicPath);
        }

        if (is_file($publicPath) && (string) file_get_contents($publicPath) === $key->publicPem) {
            return;
        }

        $tmp = $publicPath.'.tmp-'.bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $key->publicPem) === false || ! chmod($tmp, 0444) || ! rename($tmp, $publicPath)) {
            @unlink($tmp);

            throw InvalidKeyFile::notWritable($publicPath);
        }
    }

    /**
     * @throws InvalidKeyFile
     */
    private static function fromKey(OpenSSLAsymmetricKey $key, string $path): RegistryTokenKey
    {
        $details = openssl_pkey_get_details($key);
        $ec = is_array($details) ? ($details['ec'] ?? null) : null;
        $public = is_array($details) ? ($details['key'] ?? null) : null;

        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ! is_array($ec)
            || ($ec['curve_name'] ?? null) !== 'prime256v1' || ! is_string($public) || $public === '') {
            throw InvalidKeyFile::malformed($path);
        }

        $pem = null;

        if (! openssl_pkey_export($key, $pem) || ! is_string($pem) || $pem === '') {
            throw InvalidKeyFile::malformed($path);
        }

        return new RegistryTokenKey($pem, $public);
    }
}
