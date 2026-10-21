<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Prefixed ULIDs used as external identifiers (docs/07-data-model.md):
 * `prj_01JB7Z6X9Q3T8M5N2P4R6S8V0W`. The prefix makes IDs self-describing in
 * URLs, logs and support requests; the ULID part is Crockford base32.
 */
final class PublicId
{
    /** Regex fragment for a prefix: 2–5 lowercase ASCII letters. */
    public const string PREFIX_PATTERN = '[a-z]{2,5}';

    /** Regex fragment for a canonical (uppercase) ULID. */
    public const string ULID_PATTERN = '[0-7][0-9A-HJKMNP-TV-Z]{25}';

    public const string SEPARATOR = '_';

    public static function generate(string $prefix): string
    {
        self::assertValidPrefix($prefix);

        return $prefix.self::SEPARATOR.Str::ulid()->toBase32();
    }

    /**
     * Whether $value is a well-formed public ID, optionally with the given prefix.
     */
    public static function isValid(string $value, ?string $prefix = null): bool
    {
        $prefixPattern = $prefix === null ? self::PREFIX_PATTERN : preg_quote($prefix, '/');

        return preg_match('/^'.$prefixPattern.self::SEPARATOR.self::ULID_PATTERN.'$/D', $value) === 1;
    }

    /**
     * Anchored regex for validation rules (`'regex:'.PublicId::regex('prj')`).
     */
    public static function regex(string $prefix): string
    {
        self::assertValidPrefix($prefix);

        return '/^'.$prefix.self::SEPARATOR.self::ULID_PATTERN.'$/D';
    }

    /**
     * Unanchored pattern for route constraints (`->where('project', ...)`).
     */
    public static function routePattern(string $prefix): string
    {
        self::assertValidPrefix($prefix);

        return $prefix.self::SEPARATOR.self::ULID_PATTERN;
    }

    public static function prefixOf(string $value): ?string
    {
        if (! self::isValid($value)) {
            return null;
        }

        return Str::before($value, self::SEPARATOR);
    }

    private static function assertValidPrefix(string $prefix): void
    {
        if (preg_match('/^'.self::PREFIX_PATTERN.'$/D', $prefix) !== 1) {
            throw new InvalidArgumentException("Invalid public ID prefix [{$prefix}]: expected 2-5 lowercase letters.");
        }
    }
}
