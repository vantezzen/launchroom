<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Typed reads of raw query rows (Postgres returns numerics as strings).
 */
final class Cast
{
    public static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    public static function id(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function round(?float $value, int $precision): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
