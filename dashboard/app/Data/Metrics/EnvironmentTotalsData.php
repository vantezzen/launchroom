<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * One environment as a whole (docs/15 §2.3, the project Metrics page):
 * requests of every service summed (percentiles from the summed
 * histograms), CPU and memory summed over services per bucket, and their
 * averages over the range and the period before it (for deltas).
 */
final class EnvironmentTotalsData extends Data
{
    /**
     * @param  list<float|null>  $cpuCores
     * @param  list<float|null>  $memMb
     */
    public function __construct(
        public ?RequestSeriesData $requests,
        public array $cpuCores,
        public array $memMb,
        public ?float $cpuAvg,
        public ?float $memAvg,
        public ?float $previousCpuAvg,
        public ?float $previousMemAvg,
    ) {}
}
