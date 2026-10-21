<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report.volumeDeletions[]: the outcome of one NodeState.deleteVolumes entry.
 */
final class VolumeDeletionResult extends Data
{
    public function __construct(
        #[Regex('/^vol_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public string $id,
        #[Regex('/^lr-[a-z0-9][a-z0-9_-]{0,59}$/D')]
        public string $name,
        #[In(['deleted', 'absent', 'refused', 'failed'])]
        public string $outcome,
        #[Regex(Report::TIME_PATTERN)]
        public string $at,
        #[Max(1024)]
        public string|Optional $message = new Optional,
    ) {}
}
