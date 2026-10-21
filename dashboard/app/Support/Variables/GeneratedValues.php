<?php

declare(strict_types=1);

namespace App\Support\Variables;

use InvalidArgumentException;

/**
 * Generator functions in variable values (docs/06 §3.1), evaluated **once**
 * when the variable is created and stored as the value:
 *
 * - `${{ secret(32) }}`: 32 random alphanumeric characters (URL-safe)
 * - `${{ secret(64, "base64") }}`: base64 of 64 random bytes; also "hex"
 *   (hex of n bytes) and "base64url" (unpadded, of n bytes)
 * - `${{ uuid() }}`: a random UUID v4
 */
final class GeneratedValues
{
    private const string FUNCTION = '/^\s*(secret|uuid)\(\s*(?:([0-9]{1,4})\s*(?:,\s*"([a-z0-9]+)"\s*)?)?\)\s*$/D';

    private const string ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public static function isFunction(string $inner): bool
    {
        return preg_match(self::FUNCTION, $inner) === 1;
    }

    /**
     * The generator when $value is exactly one generator expression
     * (`${{ secret(32) }}` → `secret(32)`), so a copy of the variable in
     * another environment can get a fresh value (docs/06 §6.2).
     */
    public static function sole(string $value): ?string
    {
        if (preg_match('/^\s*\$\{\{(.*?)\}\}\s*$/sD', $value, $m) !== 1 || ! self::isFunction($m[1])) {
            return null;
        }

        return (string) preg_replace('/\s+/', '', $m[1]);
    }

    /**
     * A fresh value of a generator returned by sole().
     *
     * @throws InvalidArgumentException
     */
    public static function fresh(string $generator): string
    {
        return self::evaluate('${{ '.$generator.' }}');
    }

    public static function contains(string $value): bool
    {
        $found = false;

        preg_replace_callback('/\$\{\{(.*?)\}\}/s', static function (array $m) use (&$found): string {
            $found = $found || self::isFunction($m[1]);

            return $m[0];
        }, $value);

        return $found;
    }

    /**
     * Replaces every generator expression with its output.
     *
     * @throws InvalidArgumentException on an invalid length or encoding
     */
    public static function evaluate(string $value): string
    {
        return (string) preg_replace_callback('/\$\{\{(.*?)\}\}/s', static function (array $m): string {
            if (preg_match(self::FUNCTION, $m[1], $f) !== 1) {
                return $m[0];
            }

            return $f[1] === 'uuid' ? self::uuid() : self::secret((int) ($f[2] ?? 32), ($f[3] ?? '') === '' ? 'alnum' : $f[3]);
        }, $value);
    }

    public static function secret(int $length, string $encoding = 'alnum'): string
    {
        if ($length < 8 || $length > 1024) {
            throw new InvalidArgumentException('secret() length must be between 8 and 1024.');
        }

        return match ($encoding) {
            'alnum' => self::alphanumeric($length),
            'base64' => base64_encode(random_bytes($length)),
            'base64url' => rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '='),
            'hex' => bin2hex(random_bytes($length)),
            default => throw new InvalidArgumentException('secret() encoding must be one of base64, base64url, hex.'),
        };
    }

    public static function alphanumeric(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHANUMERIC) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHANUMERIC[random_int(0, $max)];
        }

        return $out;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
