<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\DaemonTaskStatus;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/** The last "Free space now" (task `cleanup`) and what it removed. */
final class CleanupTaskData extends Data
{
    public function __construct(
        public DaemonTaskStatus $status,
        public ?CarbonInterface $requestedAt,
        public ?CarbonInterface $finishedAt = null,
        public ?int $removedImages = null,
        public ?int $removedContainers = null,
        public ?int $reclaimedMb = null,
        public ?string $error = null,
    ) {}
}
