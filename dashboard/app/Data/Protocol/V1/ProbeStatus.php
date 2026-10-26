<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `workloads[].health` (report.json ProbeStatus).
 */
final class ProbeStatus extends Data
{
    public function __construct(
        #[In(['starting', 'passing', 'failing'])]
        public string $state,
        #[Min(0)]
        public int $consecutiveFailures,
        #[Max(1024)]
        public string|Optional $lastError = new Optional,
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $lastCheckedAt = new Optional,
        /** Checks since the container started (D68). */
        #[Min(0)]
        public int|Optional $attempts = new Optional,
    ) {}
}
