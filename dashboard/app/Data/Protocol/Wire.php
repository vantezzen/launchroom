<?php

declare(strict_types=1);

namespace App\Data\Protocol;

/**
 * Typed reads from decoded protocol JSON (`json_decode(..., false)`), used to
 * rebuild Data objects from Go fixtures in contract tests. Missing or
 * mistyped fields read as empty values; the schema validation catches them.
 */
final class Wire
{
    public static function string(object $wire, string $field): string
    {
        return self::nullableString($wire, $field) ?? '';
    }

    public static function nullableString(object $wire, string $field): ?string
    {
        $value = $wire->{$field} ?? null;

        return is_string($value) ? $value : null;
    }

    public static function int(object $wire, string $field): int
    {
        return self::nullableInt($wire, $field) ?? 0;
    }

    public static function nullableInt(object $wire, string $field): ?int
    {
        $value = $wire->{$field} ?? null;

        return is_int($value) ? $value : null;
    }

    public static function nullableBool(object $wire, string $field): ?bool
    {
        $value = $wire->{$field} ?? null;

        return is_bool($value) ? $value : null;
    }

    public static function float(object $wire, string $field): float
    {
        $value = $wire->{$field} ?? null;

        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    public static function object(object $wire, string $field): ?object
    {
        $value = $wire->{$field} ?? null;

        return is_object($value) ? $value : null;
    }

    /**
     * @return list<string>|null
     */
    public static function strings(object $wire, string $field): ?array
    {
        $value = $wire->{$field} ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : null;
    }

    /**
     * @return list<object>
     */
    public static function objects(object $wire, string $field): array
    {
        $value = $wire->{$field} ?? null;

        return is_array($value) ? array_values(array_filter($value, is_object(...))) : [];
    }

    /**
     * @return array<string, string>|null
     */
    public static function stringMap(object $wire, string $field): ?array
    {
        $value = self::object($wire, $field);

        if ($value === null) {
            return null;
        }

        $map = [];

        foreach (get_object_vars($value) as $key => $item) {
            if (is_string($item)) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
    }
}
