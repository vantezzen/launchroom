<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Report.stateRejected: launchroomd refused a NodeState (it did not decode or
 * validate) and keeps converging to its last good one.
 */
final class StateRejection extends Data
{
    public function __construct(
        #[Min(0)]
        public int $version,
        #[Max(4096)]
        public string $error,
        #[Regex(Report::TIME_PATTERN)]
        public string $rejectedAt,
    ) {}
}
