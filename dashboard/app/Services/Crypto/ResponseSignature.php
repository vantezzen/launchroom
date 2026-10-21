<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Support\Crypto\PublicKeyFormat;

/**
 * Verifies `X-LR-Signature` headers (protocol/SIGNING.md §3) the way the daemon
 * does. The dashboard only signs; verification exists for tests and tooling.
 */
final class ResponseSignature
{
    private const string PATTERN = '/^kid=([A-Za-z0-9._-]{1,64}), sig=([A-Za-z0-9+\/]{86}==)$/D';

    /**
     * @param  array<string, string>  $trustedKeys  kid => "ed25519:<base64>"
     */
    public static function verify(string $header, string $body, array $trustedKeys): bool
    {
        if (preg_match(self::PATTERN, $header, $m) !== 1 || ! isset($trustedKeys[$m[1]])) {
            return false;
        }

        $publicKey = PublicKeyFormat::parseEd25519($trustedKeys[$m[1]]);
        $signature = base64_decode($m[2], true);

        if ($publicKey === null || $publicKey === '' || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $body, $publicKey);
    }
}
