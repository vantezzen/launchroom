<?php

declare(strict_types=1);

namespace App\Support\Backups;

/**
 * Backup schedules as a sentence ("Daily at 03:12 UTC") instead of raw cron
 * (docs/design/flows.md §14). Only the shapes the schedule picker writes are
 * described; anything else returns null and the UI shows it as "Custom".
 */
final class CronDescription
{
    private const array WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public static function describe(string $cron): ?string
    {
        $fields = preg_split('/\s+/', trim($cron));

        if (! is_array($fields) || count($fields) !== 5) {
            return null;
        }

        [$minute, $hour, $day, $month, $weekday] = $fields;

        if (! self::isNumber($minute, 59) || $month !== '*') {
            return null;
        }

        $m = (int) $minute;

        if ($day === '*' && $weekday === '*') {
            if ($hour === '*') {
                return $m === 0 ? 'Every hour' : sprintf('Every hour at :%02d', $m);
            }

            if (preg_match('#^\*/(\d{1,2})$#D', $hour, $match) === 1) {
                $every = (int) $match[1];

                return match (true) {
                    $every <= 1 => 'Every hour',
                    $every >= 24 => null,
                    default => "Every {$every} hours",
                };
            }

            return self::isNumber($hour, 23) ? 'Daily at '.self::time((int) $hour, $m) : null;
        }

        if (! self::isNumber($hour, 23)) {
            return null;
        }

        if ($day === '*' && self::isNumber($weekday, 7)) {
            return 'Weekly on '.self::WEEKDAYS[(int) $weekday].' at '.self::time((int) $hour, $m);
        }

        if ($weekday === '*' && self::isNumber($day, 31) && (int) $day >= 1) {
            return 'Monthly on day '.(int) $day.' at '.self::time((int) $hour, $m);
        }

        return null;
    }

    private static function isNumber(string $field, int $max): bool
    {
        return preg_match('/^\d{1,2}$/D', $field) === 1 && (int) $field <= $max;
    }

    private static function time(int $hour, int $minute): string
    {
        return sprintf('%02d:%02d UTC', $hour, $minute);
    }
}
