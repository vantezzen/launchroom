<?php

declare(strict_types=1);

namespace App\Scheduling;

final readonly class PlacementPlan
{
    /**
     * @param  list<int>  $serverIds  server id per replica slot (slot = index)
     */
    public function __construct(
        public array $serverIds,
    ) {}
}
