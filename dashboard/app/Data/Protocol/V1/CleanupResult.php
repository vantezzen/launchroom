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
 * TaskCompletion.cleanup of a `cleanup` task, and the shape of
 * Report.host.health.cleanup (protocol CleanupResult): what one cleanup run
 * removed. Volumes are never pruned.
 */
final class CleanupResult extends Data
{
    public function __construct(
        #[Regex(Report::TIME_PATTERN)]
        public string $at,
        #[In(['schedule', 'disk_pressure', 'manual'])]
        public string $trigger,
        #[Min(0)]
        public int $removedImages,
        #[Min(0)]
        public int $removedContainers,
        #[Min(0)]
        public int $reclaimedMb,
        #[Max(1024)]
        public string|Optional $error = new Optional,
    ) {}
}
