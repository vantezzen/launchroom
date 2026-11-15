<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\WorkloadSpec;
use Spatie\LaravelData\Data;

/**
 * What other code needs to know about a stored NodeState version
 * (`node_state_versions.facts`), recorded when it is rendered so nobody
 * re-parses the signed document: the networks and workloads it declares, the
 * images its workloads run and its mesh allow-list (port => allowed mesh IPs).
 */
final class NodeStateFacts extends Data
{
    /**
     * @param  list<string>  $networks
     * @param  list<string>  $workloads
     * @param  array<int, list<string>>  $meshAllow
     * @param  list<string>  $images
     */
    public function __construct(
        public array $networks = [],
        public array $workloads = [],
        public array $meshAllow = [],
        public array $images = [],
    ) {}

    public static function of(NodeState $state): self
    {
        $allow = [];

        foreach ($state->meshAllow as $rule) {
            $allow[$rule->port] = $rule->from;
        }

        return new self(
            array_map(static fn (NetworkSpec $network): string => $network->id, $state->networks),
            array_map(static fn (WorkloadSpec $workload): string => $workload->id, $state->workloads),
            $allow,
            array_values(array_unique(array_map(static fn (WorkloadSpec $workload): string => $workload->image, $state->workloads))),
        );
    }

    /**
     * @return list<string>
     */
    public function allowedOn(int $port): array
    {
        return $this->meshAllow[$port] ?? [];
    }
}
