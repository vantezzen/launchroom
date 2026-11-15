<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Data\Forms\NumberRangeData;
use Spatie\LaravelData\Data;

/**
 * Settings → Deploy steppers: the bounds DeploySettings and
 * HealthcheckSettings validate, and the Roll out timeout's default.
 */
final class DeployLimitsData extends Data
{
    public function __construct(
        public NumberRangeData $startPeriod,
        public NumberRangeData $interval,
        public NumberRangeData $timeout,
        public NumberRangeData $failures,
        public NumberRangeData $drain,
        public NumberRangeData $rolloutTimeout,
        public NumberRangeData $healthTimeout,
        public int $rolloutTimeoutDefault,
    ) {}

    public static function make(): self
    {
        return new self(
            startPeriod: NumberRangeData::of(HealthcheckSettings::class, 'startPeriodSeconds'),
            interval: NumberRangeData::of(HealthcheckSettings::class, 'intervalSeconds'),
            timeout: NumberRangeData::of(HealthcheckSettings::class, 'timeoutSeconds'),
            failures: NumberRangeData::of(HealthcheckSettings::class, 'failureThreshold'),
            drain: NumberRangeData::of(DeploySettings::class, 'drainSeconds'),
            rolloutTimeout: NumberRangeData::of(DeploySettings::class, 'rolloutTimeoutSeconds'),
            healthTimeout: NumberRangeData::of(DeploySettings::class, 'healthTimeoutSeconds'),
            rolloutTimeoutDefault: DeploySettings::ROLLOUT_TIMEOUT_DEFAULT,
        );
    }
}
