<?php

declare(strict_types=1);

namespace App\Support\Cron;

use DateTimeZone;

/**
 * The cron grammar of scheduled cron services, the same as launchroomd's
 * (daemon/internal/cronexpr): five fields separated by single spaces, each a
 * comma list of `*`, `n` or `a-b`, optionally `/step`; minute 0-59, hour
 * 0-23, day of month 1-31, month 1-12, day of week 0-7 (0 and 7 are
 * Sunday). No names, macros, `?`, `L` or `W`. Time zones are IANA names.
 */
final class CronGrammar
{
    public const int MAX_LENGTH = 100;

    private const array FIELDS = [
        ['minute', 0, 59],
        ['hour', 0, 23],
        ['day of month', 1, 31],
        ['month', 1, 12],
        ['day of week', 0, 7],
    ];

    /** One sentence for the first problem, or null when the expression is valid. */
    public static function error(string $cron): ?string
    {
        if (strlen($cron) > self::MAX_LENGTH) {
            return 'Keep the cron expression under '.self::MAX_LENGTH.' characters.';
        }

        $parts = explode(' ', $cron);

        if (count($parts) !== 5) {
            return 'Use five fields separated by single spaces: minute hour day month weekday, for example "0 3 * * *".';
        }

        foreach ($parts as $index => $part) {
            [$name, $min, $max] = self::FIELDS[$index];

            if (($problem = self::field($part, $name, $min, $max)) !== null) {
                return $problem;
            }
        }

        return null;
    }

    public static function timeZoneError(string $timeZone): ?string
    {
        return $timeZone === 'UTC' || in_array($timeZone, DateTimeZone::listIdentifiers(), true)
            ? null
            : "\"{$timeZone}\" isn't a time zone. Use a name such as UTC or Europe/Berlin.";
    }

    private static function field(string $source, string $name, int $min, int $max): ?string
    {
        if ($source === '') {
            return "The {$name} field is empty.";
        }

        foreach (explode(',', $source) as $item) {
            $range = $item;

            if (str_contains($item, '/')) {
                [$range, $step] = explode('/', $item, 2);

                if (! self::isNumber($step) || (int) $step < 1 || (int) $step > $max) {
                    return "The {$name} step \"{$step}\" must be a number from 1 to {$max}.";
                }
            }

            if ($range === '*') {
                continue;
            }

            $bounds = explode('-', $range);

            if (count($bounds) > 2) {
                return "\"{$range}\" isn't a {$name} range.";
            }

            foreach ($bounds as $bound) {
                if (! self::isNumber($bound)) {
                    return "\"{$bound}\" isn't a {$name}: use numbers ({$min}-{$max}), *, ranges, lists and steps.";
                }

                if ((int) $bound < $min || (int) $bound > $max) {
                    return ucfirst($name)." {$bound} is outside {$min}-{$max}.";
                }
            }

            if (count($bounds) === 2 && (int) $bounds[0] > (int) $bounds[1]) {
                return "The {$name} range \"{$range}\" runs backwards.";
            }
        }

        return null;
    }

    private static function isNumber(string $value): bool
    {
        return preg_match('/^\d{1,2}$/D', $value) === 1;
    }
}
