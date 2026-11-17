<?php

declare(strict_types=1);

namespace App\Data\Instance\System;

use Spatie\LaravelData\Data;

/**
 * One job queue (Horizon's workload): jobs waiting, how long the newest
 * would wait, and the workers serving it.
 */
final class SystemQueueData extends Data
{
    public function __construct(
        public string $name,
        public string $label,
        public int $waiting,
        public int $waitSeconds,
        public int $workers,
    ) {}
}
