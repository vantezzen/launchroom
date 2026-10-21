<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.maintenance (protocol/schema/v1/task-completion.json).
 */
final class MaintenanceResult extends Data
{
    public function __construct(
        #[Min(0)]
        public int $durationMs,
        #[Min(0)]
        public int|Optional $removedSnapshots = new Optional,
    ) {}
}
