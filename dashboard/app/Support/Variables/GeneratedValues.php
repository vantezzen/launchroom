<?php

declare(strict_types=1);

namespace App\Support\Variables;

use Closure;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Generator functions in variable values (docs/06 §3.1), evaluated **once**
 * when the variable is created and stored as the value:
 *
 * - `${{ secret(32) }}`: 32 random alphanumeric characters (URL-safe)
 * - `${{ secret(64, "base64") }}`: base64 of 64 random bytes; also "hex"
 *   (hex of n bytes) and "base64url" (unpadded, of n bytes)
 * - `${{ uuid() }}`: a random UUID v4
 * - `${{ jwt("anon", JWT_SECRET) }}`: an HS256 JWT with the claim
 *   `role: anon`, signed with the value of the variable JWT_SECRET of the
 *   same service in the same environment (Supabase's API keys, D65). It is
 *   evaluated after that variable exists; the caller looks the secret up.
 */
final class GeneratedValues
{
    private const string FUNCTION = '/^\s*(secret|uuid)\(\s*(?:([0-9]{1,4})\s*(?:,\s*"([a-z0-9]+)"\s*)?)?\)\s*$/D';

    /** `jwt("<role>", <KEY>)`: a Postgres role name and a variable of the same service. */
    private const string JWT = '/^\s*jwt\(\s*"([a-z_][a-z0-9_]{0,62})"\s*,\s*([A-Za-z_][A-Za-z0-9_]{0,255})\s*\)\s*$/D';

    /** HS256 needs a key of at least 256 bits; Supabase's services refuse shorter secrets. */
    public const int JWT_MIN_SECRET_LENGTH = 32;

    /** Lifetime of a generated JWT: long-lived API keys, rotated by regenerating them. */
    public const int JWT_LIFETIME_SECONDS = 10 * 365 * 24 * 3600;

    private const string ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public static function isFunction(string $inner): bool
    {
        return preg_match(self::FUNCTION, $inner) === 1 || preg_match(self::JWT, $inner) === 1;
    }

    /**
     * The variable a generator is signed with (`jwt("anon", JWT_SECRET)` →
     * `JWT_SECRET`), null for generators that need nothing else. Copies
     * evaluate such generators after the variable they depend on.
     */
    public static function signingKey(?string $generator): ?string
    {
        return $generator !== null && preg_match(self::JWT, $generator, $m) === 1 ? $m[2] : null;
    }

    /**
     * The role of a JWT generator (`jwt("anon", KEY)` → `anon`), else null.
     */
    public static function jwtRole(?string $generator): ?string
    {
        return $generator !== null && preg_match(self::JWT, $generator, $m) === 1 ? $m[1] : null;
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
    public static function fresh(string $generator, ?Closure $secretOf = null): string
    {
        return self::evaluate('${{ '.$generator.' }}', $secretOf);
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
     * Replaces every generator expression with its output. `jwt()` needs
     * $secretOf, which returns the plaintext of the named variable of the
     * same service and environment (null when there is none).
     *
     * @param  (Closure(string): ?string)|null  $secretOf
     *
     * @throws InvalidArgumentException on an invalid length or encoding, or a missing signing secret
     */
    public static function evaluate(string $value, ?Closure $secretOf = null): string
    {
        return (string) preg_replace_callback('/\$\{\{(.*?)\}\}/s', static function (array $m) use ($secretOf): string {
            if (preg_match(self::JWT, $m[1], $j) === 1) {
                $secret = $secretOf instanceof Closure ? $secretOf($j[2]) : null;

                if (! is_string($secret)) {
                    throw new InvalidArgumentException("jwt() is signed with {$j[2]}: create {$j[2]} for the same service and environments first.");
                }

                return self::jwt($j[1], $secret);
            }

            if (preg_match(self::FUNCTION, $m[1], $f) !== 1) {
                return $m[0];
            }

            return $f[1] === 'uuid' ? self::uuid() : self::secret((int) ($f[2] ?? 32), ($f[3] ?? '') === '' ? 'alnum' : $f[3]);
        }, $value);
    }

    /**
     * An HS256 JWT `{"role", "iss": "supabase", "iat", "exp"}` signed with
     * $secret, the shape of Supabase's anon and service_role keys.
     *
     * @throws InvalidArgumentException when the secret is too short
     */
    public static function jwt(string $role, #[SensitiveParameter] string $secret, ?int $now = null): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $role) !== 1) {
            throw new InvalidArgumentException('jwt() needs a role name like anon or service_role.');
        }

        if (strlen($secret) < self::JWT_MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException('jwt() needs a signing secret of at least '.self::JWT_MIN_SECRET_LENGTH.' characters.');
        }

        $now ??= time();
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $header = $encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $encode((string) json_encode(['role' => $role, 'iss' => 'supabase', 'iat' => $now, 'exp' => $now + self::JWT_LIFETIME_SECONDS]));

        return $header.'.'.$payload.'.'.$encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));
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
