<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Input fields that must never be flashed to the session as old input or
 * written to logs (docs/09 R12, R16): passwords, passphrases, tokens, secret
 * keys and variable values (which may be secrets).
 */
final class SensitiveInput
{
    /** Exact keys (Laravel's `dontFlash` list). */
    public const array KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'passphrase', 'passphrase_confirmation',
        'token', 'setupToken', 'setup_token',
        'accessKeyId', 'secretAccessKey', 'repositoryPassword',
        'clientSecret', 'webhookSecret', 'privateKey',
        'value', 'content',
    ];

    /** Any key that contains one of these (case-insensitive) is sensitive too. */
    private const string PATTERN = '/password|passphrase|secret|token|private_?key|access_?key|credential|authorization|cookie/i';

    public static function isSensitive(string $key): bool
    {
        return in_array($key, self::KEYS, true) || preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * $input without sensitive keys, at any depth.
     *
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    public static function except(array $input): array
    {
        $clean = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::except($value) : $value;
        }

        return $clean;
    }
}
