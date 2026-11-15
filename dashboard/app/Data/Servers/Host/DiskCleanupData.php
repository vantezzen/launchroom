<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use App\Enums\Servers\CleanupTrigger;
use Spatie\LaravelData\Data;

/** launchroomd's latest prune of unused images and containers. */
final class DiskCleanupData extends Data
{
    public function __construct(
        public string $at,
        public CleanupTrigger $trigger,
        public int $removedImages = 0,
        public int $removedContainers = 0,
        public int $reclaimedMb = 0,
        public ?string $error = null,
    ) {}
}
