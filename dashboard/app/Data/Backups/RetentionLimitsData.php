<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Data\Forms\NumberRangeData;
use Spatie\LaravelData\Data;

/**
 * The "Keep" steppers of the schedule editor: the bounds RetentionData validates.
 */
final class RetentionLimitsData extends Data
{
    public function __construct(
        public NumberRangeData $keepHourly,
        public NumberRangeData $keepDaily,
        public NumberRangeData $keepWeekly,
        public NumberRangeData $keepMonthly,
    ) {}

    public static function make(): self
    {
        return new self(
            keepHourly: NumberRangeData::of(RetentionData::class, 'keepHourly'),
            keepDaily: NumberRangeData::of(RetentionData::class, 'keepDaily'),
            keepWeekly: NumberRangeData::of(RetentionData::class, 'keepWeekly'),
            keepMonthly: NumberRangeData::of(RetentionData::class, 'keepMonthly'),
        );
    }
}
