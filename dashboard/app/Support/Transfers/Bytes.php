<?php

declare(strict_types=1);

namespace App\Support\Transfers;

/**
 * Sizes for messages, as the design system writes them: "4.1 GB", "412 MB".
 */
final class Bytes
{
    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) max(0, $bytes);
        $unit = 0;

        while ($value >= 1000 && $unit < count($units) - 1) {
            $value /= 1000;
            $unit++;
        }

        $digits = $unit === 0 || $value >= 10 ? 0 : 1;

        return number_format($value, $digits, '.', '').' '.$units[$unit];
    }
}
