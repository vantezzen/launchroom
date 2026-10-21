<?php

declare(strict_types=1);

namespace App\Services\Git\Ssh;

use SensitiveParameter;

/**
 * Per-repository ed25519 deploy keys (docs/19 §4.2), generated with
 * libsodium. The 64-byte secret key (seed ‖ public key) is what the
 * dashboard stores, envelope-encrypted; build servers get only the 32-byte
 * seed, sealed per task.
 */
final class DeployKey
{
    /**
     * @return array{secretKey: string, publicKey: string} the raw secret key and the OpenSSH public key line
     */
    public static function generate(string $comment): array
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        sodium_memzero($pair);

        return ['secretKey' => $secret, 'publicKey' => self::publicLine($public, $comment)];
    }

    public static function publicLine(string $publicKey, string $comment): string
    {
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', strlen($publicKey)).$publicKey;

        return 'ssh-ed25519 '.base64_encode($blob).' '.preg_replace('/[^A-Za-z0-9@._-]/', '', $comment);
    }

    /**
     * The seed for a build server's TaskToken (base64, no line breaks).
     */
    public static function seed(#[SensitiveParameter] string $secretKey): string
    {
        return base64_encode(substr($secretKey, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES));
    }
}
