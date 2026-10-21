<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Outcome of a successful plan task (protocol PlanResult).
 */
final class PlanResult extends Data
{
    public function __construct(
        #[Min(0)]
        public int $durationMs,
        public BuildSummary $summary,
    ) {}
}
