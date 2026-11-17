<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\PortHint;
use App\Enums\ProbeHint;
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
        /** What the failing answer means (Report `health.hint`, e.g. `host_not_allowed`). */
        public ?ProbeHint $hint = null,
        /** The failing answer's first ≤ 300 bytes as text, masked before it is stored (Report `health.body`). */
        public ?string $body = null,
    ) {}

    /**
     * Whether it moved in a way worth a refetch: `checkedAt` changes on every
     * report of a running workload, so on its own it is not progress.
     */
    public function movedFrom(?self $before): bool
    {
        return ! $before instanceof self
            || [$this->attempts, $this->failures, $this->lastError, $this->portHint, $this->listening, $this->hint] !== [$before->attempts, $before->failures, $before->lastError, $before->portHint, $before->listening, $before->hint];
    }
}
