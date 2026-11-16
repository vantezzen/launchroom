<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * An environment's rolled-up status in the environment switcher.
 */
final class EnvironmentStatusData extends Data
{
    public function __construct(
        public string $environmentId,
        public NodeStatusData $status,
    ) {}
}
