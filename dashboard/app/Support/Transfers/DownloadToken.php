<?php

declare(strict_types=1);

namespace App\Support\Transfers;

use App\Support\Crypto\OpaqueToken;

/**
 * Bearer tokens of export download links (docs/14): `lr_dl_` + 32 random bytes
 * in unpadded base64url. Only the token's SHA-256 is stored.
 */
final class DownloadToken
{
    private const string PREFIX = 'lr_dl_';

    /** Unanchored, for route constraints. */
    public const string ROUTE_PATTERN = self::PREFIX.OpaqueToken::BODY;

    public static function generate(): string
    {
        return OpaqueToken::generate(self::PREFIX);
    }

    public static function isValid(string $token): bool
    {
        return OpaqueToken::isWellFormed($token, self::PREFIX);
    }

    public static function hash(string $token): string
    {
        return OpaqueToken::hash($token);
    }
}
