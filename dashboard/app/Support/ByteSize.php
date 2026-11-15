<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A byte count in decimal units for server-written messages ("1.5 GB").
 */
final class ByteSize
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1000 && $unit < count($units) - 1) {
            $value /= 1000;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 1 : 0)).' '.$units[$unit];
    }
}
