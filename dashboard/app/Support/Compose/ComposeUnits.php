<?php

declare(strict_types=1);

namespace App\Support\Compose;

/**
 * Compose durations (`1m30s`, `500ms`) and byte sizes (`512m`, `1.5g`,
 * `2GiB`, plain bytes).
 */
final class ComposeUnits
{
    /** Whole seconds (at least 1 for anything above zero), or null when unreadable. */
    public static function seconds(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^(?:\d+(?:\.\d+)?(?:h|m|s|ms|us|µs|ns))+$/D', trim($value)) !== 1) {
            return null;
        }

        preg_match_all('/(\d+(?:\.\d+)?)(h|ms|m|s|us|µs|ns)/', trim($value), $parts, PREG_SET_ORDER);
        $total = 0.0;

        foreach ($parts as [, $amount, $unit]) {
            $total += (float) $amount * match ($unit) {
                'h' => 3600,
                'm' => 60,
                's' => 1,
                'ms' => 0.001,
                'us', 'µs' => 0.000001,
                default => 0.000000001,
            };
        }

        return $total > 0 ? max(1, (int) round($total)) : 0;
    }

    /** Megabytes (rounded up), or null when unreadable. */
    public static function megabytes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? (int) ceil($value / 1048576) : null;
        }

        if (! is_string($value) || preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)(i?b?)$/iD', trim($value), $m) !== 1) {
            return null;
        }

        $bytes = (float) $m[1] * match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            't' => 1099511627776,
            default => 1,
        };

        return $bytes > 0 ? (int) ceil($bytes / 1048576) : null;
    }

    /** CPUs as a number (`0.5`, `"1.5"`), or null. */
    public static function cpus(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (float) $value : null;
        }

        if (is_string($value) && is_numeric(trim($value)) && (float) $value > 0) {
            return (float) $value;
        }

        return null;
    }
}
