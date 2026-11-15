<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * `placements.last_exit`: the container's last exit and its final output
 * lines, masked (WorkloadEvent `exited`, D68).
 */
final class ContainerExitData extends Data
{
    /**
     * @param  list<array{time: string, stream: string, text: string}>  $output
     */
    public function __construct(
        public string $at,
        public ?int $code = null,
        public bool $oomKilled = false,
        public array $output = [],
    ) {}
}
