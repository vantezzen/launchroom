<?php

declare(strict_types=1);

namespace App\Services\Sessions;

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
        return self::TICKET_PREFIX.self::base64url(random_bytes(32));
    }

    public static function key(): string
    {
        return self::base64url(random_bytes(32));
    }

    public static function hash(string $ticket): string
    {
        return hash('sha256', $ticket);
    }

    public static function looksLikeTicket(string $value): bool
    {
        return preg_match('/^lr_st_[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
