<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

final class ServicePlacementData extends Data
{
    /**
     * @param  list<PlacementServerOptionData>  $servers
     */
    public function __construct(
        public array $servers,
        public ?PlacementIssueData $issue,
        /** Previews and production only run on servers that allow them. */
        public string $environmentKind,
        /** Why the saved settings can't be placed (null: they fit). */
        public ?string $planError = null,
        /** One replica, never moved: databases and services with a volume (docs/02 §6). */
        public bool $stateful = false,
        /** The server holding the stateful service's volume, once placed. */
        public ?string $pinnedServer = null,
        /** Memory and CPU overcommit factors the scheduler applies (1.0 = none; CPU null = not checked). */
        public float $memoryOvercommit = 1.0,
        public ?float $cpuOvercommit = null,
    ) {}
}
