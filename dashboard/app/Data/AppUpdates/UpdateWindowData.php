<?php

declare(strict_types=1);

namespace App\Data\AppUpdates;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * When automatic updates may run (docs/04 §14.3): on these weekdays
 * (1 = Monday … 7 = Sunday), from `start` for `hours`, in `timezone`. A
 * window that starts late may run past midnight; it belongs to the day it
 * starts on.
 */
final class UpdateWindowData extends Data
{
    /**
     * @param  list<int>  $days
     */
    public function __construct(
        public array $days,
        #[Regex('/^([01]\d|2[0-3]):[0-5]\d$/')]
        public string $start,
        #[Between(1, 12)]
        public int $hours,
        public string $timezone,
    ) {}

    public static function default(string $timezone = 'UTC'): self
    {
        return new self([1, 2, 3, 4, 5, 6, 7], '03:00', 3, $timezone);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'distinct', 'between:1,7'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    public function contains(CarbonImmutable $at): bool
    {
        $zone = in_array($this->timezone, DateTimeZone::listIdentifiers(), true) ? $this->timezone : 'UTC';
        $local = $at->setTimezone($zone);
        [$hour, $minute] = array_map(intval(...), explode(':', $this->start));

        // Today's window, or yesterday's when it runs past midnight.
        foreach ([$local, $local->subDay()] as $day) {
            $from = $day->setTime($hour, $minute);

            if (in_array($from->dayOfWeekIso, $this->days, true) && $local->greaterThanOrEqualTo($from) && $local->lessThan($from->addHours($this->hours))) {
                return true;
            }
        }

        return false;
    }
}
