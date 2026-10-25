<?php

declare(strict_types=1);

namespace App\Support\RemoteAccess;

/**
 * Generated names and passwords of temporary access (docs/09 R39): fixed
 * alphabets, so launchroomd can quote them without surprises.
 */
final class AccessSecrets
{
    private const string NAME_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const string PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /** "lr-" + 10 characters. */
    public static function sshUser(): string
    {
        return 'lr-'.self::random(self::NAME_ALPHABET, 10);
    }

    /** "lr_tmp_" + 12 characters. */
    public static function databaseRole(): string
    {
        return 'lr_tmp_'.self::random(self::NAME_ALPHABET, 12);
    }

    /** 32 characters, about 187 bits. */
    public static function password(): string
    {
        return self::random(self::PASSWORD_ALPHABET, 32);
    }

    private static function random(string $alphabet, int $length): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
