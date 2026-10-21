<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * One segment of a server's "used by" meter: a project's current memory and
 * CPU on that server. The top few projects come first (largest memory); the
 * rest are folded into one row with `projectId` null ("Other", grey).
 */
final class ProjectUsageData extends Data
{
    public function __construct(
        public ?string $projectId,
        public string $name,
        /** Data palette slot 0–7 (projects.color_slot); null for "Other". */
        public ?int $colorSlot,
        public int $memMb,
        public float $cpuCores,
        /** Share of the server's memory, 0–1 (null without a memory total). */
        public ?float $memShare,
    ) {}
}
