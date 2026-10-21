<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * Deterministic JSON for hashing (audit chain): object keys sorted
 * recursively, no insignificant whitespace, unescaped slashes and unicode.
 * Lists keep their order.
 */
final class CanonicalJson
{
    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);

            return self::normalizeMap($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        return self::normalizeMap($value);
    }

    /**
     * @param  array<array-key, mixed>  $map
     */
    private static function normalizeMap(array $map): \stdClass
    {
        ksort($map, SORT_STRING);

        return (object) array_map(self::normalize(...), $map);
    }
}
