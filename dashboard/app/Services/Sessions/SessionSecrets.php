<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Support\Crypto\OpaqueToken;

/**
 * Session tickets and keys (docs/03 §11.2, docs/09 R33). A ticket is shown
 * to the browser once and only its SHA-256 is stored; the key is 32 random
 * bytes, base64url without padding.
 */
final class SessionSecrets
{
    public const string TICKET_PREFIX = 'lr_st_';

    public static function ticket(): string
    {
        return OpaqueToken::generate(self::TICKET_PREFIX);
    }

    public static function key(): string
    {
        return OpaqueToken::generate();
    }

    public static function hash(string $ticket): string
    {
        return OpaqueToken::hash($ticket);
    }

    public static function looksLikeTicket(string $value): bool
    {
        return OpaqueToken::isWellFormed($value, self::TICKET_PREFIX);
    }
}
