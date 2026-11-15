<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\PortHint;
use Spatie\LaravelData\Data;

/**
 * `placements.probe`: the health check's progress and launchroomd's port
 * hint (Report.workloads[].health and portHint, D68).
 */
final class ProbeProgressData extends Data
{
    /**
     * @param  list<int>  $listening
     */
    public function __construct(
        public ?int $attempts = null,
        public ?int $failures = null,
        public ?string $lastError = null,
        public ?string $checkedAt = null,
        public ?PortHint $portHint = null,
        public array $listening = [],
    ) {}

    /**
     * Whether it moved in a way worth a refetch: `checkedAt` changes on every
     * report of a running workload, so on its own it is not progress.
     */
    public function movedFrom(?self $before): bool
    {
        return ! $before instanceof self
            || [$this->attempts, $this->failures, $this->lastError, $this->portHint, $this->listening] !== [$before->attempts, $before->failures, $before->lastError, $before->portHint, $before->listening];
    }
}
