<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Services\ScalingSettings;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Scheduling\PlacementFailed;
use App\Scheduling\SchedulableServer;
use App\Scheduling\Scheduler;
use App\Support\Servers\ServerLabels;

/**
 * Validates a service instance's placement constraints when they are saved
 * (docs/02 §2): label grammar, server lists naming the team's servers, and
 * that at least one of the team's servers satisfies them (offline servers
 * and capacity aside: those are deploy-time `placement_error`s). Teams
 * without any runtime server yet aren't checked (nothing to check against).
 */
final readonly class PlacementConstraints
{
    public function __construct(
        private Placer $placer,
    ) {}

    /**
     * @return ScalingSettings normalized (sorted labels, unique server lists)
     *
     * @throws DomainRuleViolation
     */
    public function validate(ServiceInstance $instance, ScalingSettings $scaling): ScalingSettings
    {
        $labels = ServerLabels::validate($scaling->placementLabels, 'scaling.placementLabels');
        $allow = array_values(array_unique($scaling->allowServers));
        $deny = array_values(array_unique($scaling->denyServers));

        $known = Server::query()
            ->where('team_id', $instance->team_id)
            ->where('status', '!=', ServerStatus::Revoked->value)
            ->whereIn('public_id', [...$allow, ...$deny])
            ->get(['public_id'])
            ->map(static fn (Server $server): string => $server->public_id)
            ->all();

        foreach (['allowServers' => $allow, 'denyServers' => $deny] as $field => $ids) {
            $unknown = array_diff($ids, $known);

            if ($unknown !== []) {
                throw DomainRuleViolation::on("scaling.{$field}", 'Unknown server: '.implode(', ', $unknown).'.');
            }
        }

        if (array_intersect($allow, $deny) !== []) {
            throw DomainRuleViolation::on('scaling.denyServers', 'A server can’t be both allowed and excluded.');
        }

        $normalized = new ScalingSettings($scaling->replicas, $scaling->cpus, $scaling->memoryMb, $labels, $scaling->strategy, $allow, $deny);
        $current = $instance->scaling;

        // Only changed constraints are checked against the servers: a
        // replica or limit change never fails because a server went away.
        if ($labels === $current->placementLabels && $allow === $current->allowServers && $deny === $current->denyServers) {
            return $normalized;
        }

        $this->assertSatisfiable($instance, $normalized);

        return $normalized;
    }

    /**
     * @throws DomainRuleViolation
     */
    private function assertSatisfiable(ServiceInstance $instance, ScalingSettings $scaling): void
    {
        $servers = array_values(array_filter(
            $this->placer->servers($instance->team_id, $instance->id),
            static fn (SchedulableServer $server): bool => $server->hasRole(ServerRole::Runtime),
        ));

        if ($servers === []) {
            return;
        }

        $instance->loadMissing(['service', 'environment', 'volumes']);
        $request = $this->placer->request($instance, $scaling, null, 1);
        $rejected = [];
        $names = [];

        foreach ($servers as $server) {
            $names[$server->id] = $server->displayName();
            $reason = Scheduler::rejection($server, $request, checkOnline: false);

            if ($reason === null) {
                return;
            }

            $rejected[$server->id] = $reason;
        }

        throw DomainRuleViolation::on('scaling', PlacementFailed::noEligibleServers($rejected, $names)->getMessage());
    }
}
