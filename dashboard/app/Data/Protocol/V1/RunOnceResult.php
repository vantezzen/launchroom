<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class RunOnceResult extends Data
{
    public function __construct(
        public int $exitCode,
        #[Min(0)]
        public int $durationMs,
        /** Set when the spec had a `probe` (docs/20 §6). */
        public ProbeOutcome|null|Optional $probe = new Optional,
        /** The output passed the spec's `outputLimit` and the rest wasn't shipped (feature `commandRuns`). */
        public bool|Optional $outputTruncated = new Optional,
    ) {}
}
