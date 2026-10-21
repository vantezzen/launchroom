<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

/**
 * Narrowing for decoded launchroom.json values (already schema-checked, but
 * typed `mixed` by json decoding).
 */
final class Arrays
{
    /**
     * @return array<string, mixed>
     */
    public static function assoc(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        return array_values(array_map(self::str(...), is_array($value) ? $value : []));
    }
}
