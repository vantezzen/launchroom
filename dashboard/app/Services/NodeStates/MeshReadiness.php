<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Servers\NodeStateFacts;
use App\Enums\ServerStatus;
use App\Enums\WorkloadRole;
use App\Models\NodeStateVersion;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Whether a new replica's cross-server dependencies are reachable yet
 * (docs/05 §3, found by the failover test): a replica placed on server C may reach
 * the environment's services on other servers only once each of those
 * servers has APPLIED a NodeState whose `firewall.meshAllow` admits C's mesh
 * IP on their mesh-published ports. Until then its ambassador's upstreams
 * are dropped by the provider's nftables, so a replica that passes its own
 * health check (often a static `/health`) would still fail every request
 * that touches the database.
 *
 * Health gating (AdvanceDeployment) and rolling traffic shift
 * (NodeStateContent::rollingHealthy) treat such a replica as not healthy
 * yet. Providers on servers that aren't online are skipped: a dead server
 * can't apply anything, and waiting for it would block the rollout. The
 * applied allow-list comes from the version's recorded facts.
 */
final readonly class MeshReadiness
{
    public function ready(Placement $placement): bool
    {
        // Explicit queries: callers hold placements without these relations.
        $consumerId = $placement->server_id;
        $ip = Server::query()->whereKey($consumerId)->value('mesh_ip');
        $environmentId = ServiceInstance::query()->whereKey($placement->service_instance_id)->value('environment_id');

        if (! is_string($ip) || $ip === '' || ! is_numeric($environmentId)) {
            return true;
        }

        $providers = Placement::query()
            ->whereIn('service_instance_id', static fn (QueryBuilder $q): QueryBuilder => $q->select('id')->from('service_instances')
                ->where('environment_id', (int) $environmentId))
            ->where('server_id', '!=', $consumerId)
            // Services with a replica on C are reached locally (MeshLinks: C
            // consumes only what it doesn't host), including the replica's own.
            ->whereNotIn('service_instance_id', Placement::query()->select('service_instance_id')->where('server_id', $consumerId))
            ->whereNotNull('mesh_port')
            ->where('role', WorkloadRole::Serving->value)
            ->whereIn('server_id', Server::query()->select('id')->where('status', ServerStatus::Online->value))
            ->get(['server_id', 'mesh_port']);

        if ($providers->isEmpty()) {
            return true;
        }

        $applied = NodeStateVersion::query()
            ->join('servers', 'servers.id', '=', 'node_state_versions.server_id')
            ->whereIn('node_state_versions.server_id', $providers->pluck('server_id')->unique()->values())
            ->whereColumn('node_state_versions.version', 'servers.observed_version')
            ->get(['node_state_versions.server_id', 'node_state_versions.facts'])
            ->keyBy('server_id');

        foreach ($providers as $provider) {
            $facts = $applied->get($provider->server_id)?->facts;

            if (! $facts instanceof NodeStateFacts || ! in_array($ip, $facts->allowedOn((int) $provider->mesh_port), true)) {
                return false;
            }
        }

        return true;
    }
}
