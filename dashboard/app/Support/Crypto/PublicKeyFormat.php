<?php

declare(strict_types=1);

namespace App\Support\Crypto;

/**
 * Text formats of public keys shared with the daemon (protocol/SIGNING.md §1):
 * `ed25519:<std base64 of 32 bytes>`, `x25519:<…>`, and the human-facing
 * `SHA256:<unpadded std base64 of sha256(raw key)>` fingerprint.
 */
final class PublicKeyFormat
{
    public const string ED25519_PATTERN = '/^ed25519:[A-Za-z0-9+\/]{43}=$/D';

    public const string X25519_PATTERN = '/^x25519:[A-Za-z0-9+\/]{43}=$/D';

    public static function formatEd25519(string $raw): string
    {
        return 'ed25519:'.base64_encode(self::assertLength($raw));
    }

    /**
     * Raw 32-byte key, or null if $value isn't a well-formed `ed25519:` key.
     */
    public static function parseEd25519(string $value): ?string
    {
        return self::parse($value, self::ED25519_PATTERN, 'ed25519:');
    }

    public static function parseX25519(string $value): ?string
    {
        return self::parse($value, self::X25519_PATTERN, 'x25519:');
    }

    /**
     * Fingerprint of a formatted (`ed25519:`/`x25519:`) key, for humans comparing keys.
     */
    public static function fingerprint(string $formatted): ?string
    {
        $raw = self::parseEd25519($formatted) ?? self::parseX25519($formatted);

        if ($raw === null) {
            return null;
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $raw, true)), '=');
    }

    private static function parse(string $value, string $pattern, string $prefix): ?string
    {
        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        $raw = base64_decode(substr($value, strlen($prefix)), true);

        return $raw !== false && strlen($raw) === 32 ? $raw : null;
    }

    private static function assertLength(string $raw): string
    {
        if (strlen($raw) !== 32) {
            throw new \InvalidArgumentException('A public key must be exactly 32 bytes.');
        }

        return $raw;
    }
}
