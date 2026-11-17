<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use Spatie\LaravelData\Data;

/**
 * One of the service's volumes mounted into a run_once container (protocol
 * RunOnceMount): the volume must be declared in the server's NodeState for
 * the same service and environment.
 */
final class RunOnceMount extends Data
{
    public function __construct(
        public string $volume,
        public string $target,
        public ?bool $readOnly = null,
    ) {}
}
