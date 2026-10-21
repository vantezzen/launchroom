<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use SensitiveParameter;

/**
 * High-entropy bearer tokens (join tokens, invitations, setup tokens).
 *
 * Tokens are 256 bits of CSPRNG output, base64url without padding, behind a
 * short prefix that makes them recognisable in secret scanners. Only the
 * SHA-256 hash is stored; lookups go by hash and are confirmed with
 * `hash_equals` (docs/09 R6, R21).
 */
final class OpaqueToken
{
    public const int BYTES = 32;

    public static function generate(string $prefix): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public static function matches(#[SensitiveParameter] string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($token));
    }
}
