<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\WorkloadIssueKind;
use Spatie\LaravelData\Data;

/**
 * `placements.issue`: what launchroomd says is clearly wrong with a starting
 * container (Report.workloads[].issue, D68).
 */
final class WorkloadIssueData extends Data
{
    /**
     * @param  list<int>  $listening
     */
    public function __construct(
        public WorkloadIssueKind $kind,
        public string $since,
        public ?int $port = null,
        public array $listening = [],
        public ?int $exitCode = null,
        public bool $oomKilled = false,
        public ?int $restarts = null,
    ) {}
}
