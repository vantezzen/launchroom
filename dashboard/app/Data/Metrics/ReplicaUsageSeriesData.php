<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * One replica slot of a service (stable across deploys), aligned with the
 * response's `timestamps`.
 */
final class ReplicaUsageSeriesData extends Data
{
    /**
     * @param  list<float|null>  $cpuCores
     * @param  list<float|null>  $memMb
     */
    public function __construct(
        /** Replica slot (0-based). */
        public int $replica,
        public array $cpuCores,
        public array $memMb,
    ) {}
}
