<?php

declare(strict_types=1);

namespace App\Support\Api;

/**
 * The short code a person compares between the terminal and the browser
 * during a CLI sign-in (docs/16 §4): 8 characters, `WDJB-MJHT`.
 */
final class CliUserCode
{
    /** RFC 8628 §6.1: consonants only, nothing ambiguous. */
    private const string ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * A typed or pasted code in canonical form (`wdjbmjht` → `WDJB-MJHT`),
     * or null when it can't be one.
     */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $input));

        if (strlen($code) !== 8 || strspn($code, self::ALPHABET) !== 8) {
            return null;
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
