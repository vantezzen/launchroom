<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Enums\ServerStatus;
use App\Enums\WorkloadRole;
use App\Models\NodeStateVersion;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use Illuminate\Database\Query\Builder as QueryBuilder;
use JsonException;

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
 * can't apply anything, and waiting for it would block the rollout.
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

        /** @var array<int, array<int, list<string>>|null> $applied */
        $applied = [];

        foreach ($providers as $provider) {
            $allow = array_key_exists($provider->server_id, $applied)
                ? $applied[$provider->server_id]
                : ($applied[$provider->server_id] = $this->appliedAllow($provider->server_id));

            if ($allow === null || ! in_array($ip, $allow[(int) $provider->mesh_port] ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * meshAllow of the NodeState the server last reported as applied.
     *
     * @return array<int, list<string>>|null
     */
    private function appliedAllow(int $serverId): ?array
    {
        $observed = Server::query()->whereKey($serverId)->value('observed_version');
        $document = is_numeric($observed)
            ? NodeStateVersion::query()->where('server_id', $serverId)->where('version', (int) $observed)->value('document')
            : null;
        $allow = null;

        if (is_string($document)) {
            try {
                $doc = json_decode($document, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $doc = null;
            }

            $rules = is_array($doc) && is_array($doc['firewall'] ?? null) && is_array($doc['firewall']['meshAllow'] ?? null) ? $doc['firewall']['meshAllow'] : [];
            $allow = [];

            foreach ($rules as $rule) {
                if (is_array($rule) && is_int($rule['port'] ?? null) && is_array($rule['from'] ?? null)) {
                    $allow[$rule['port']] = array_values(array_filter($rule['from'], is_string(...)));
                }
            }
        }

        return $allow;
    }
}
