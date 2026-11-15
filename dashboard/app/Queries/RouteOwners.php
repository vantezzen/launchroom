<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Server;
use App\Support\Ingress\RouteId;
use App\Support\Metrics\Cast;
use Illuminate\Support\Facades\DB;

/**
 * Which service instance an edge's route belongs to, for request metrics,
 * analytics and web firewall samples (docs/15 §1.3, docs/22 §6.5).
 */
final readonly class RouteOwners
{
    /**
     * Route → [service instance, project] (docs/15 §1.3): `rt_<ULID>` is a
     * domain route (`dom_<ULID>`) or an immutable deployment URL
     * (`dpl_<ULID>`) of the server's team. Routes whose domain or deployment
     * is gone keep the attribution their samples recorded; unknown ones (the
     * dashboard's own route) are missing.
     *
     * @param  list<string>  $routes
     * @return array<string, array{int, int}>
     */
    public function handle(Server $server, array $routes): array
    {
        $owners = [];

        foreach (array_chunk($routes, 1000) as $chunk) {
            foreach (['request_metric_samples', 'request_analytics_samples', 'waf_samples'] as $table) {
                $known = DB::table($table)
                    ->where('server_id', $server->id)
                    ->whereIn('route_id', $chunk)
                    ->distinct()
                    ->get(['route_id', 'service_instance_id', 'project_id']);

                foreach ($known as $row) {
                    $owners[Cast::string($row->route_id ?? null)] = [Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null)];
                }
            }

            $owners = array_replace($owners, $this->current($server->team_id, $chunk));
        }

        return $owners;
    }

    /**
     * Route → [service instance, project] from the team's domains and
     * deployments as they are now (a domain wins over a deployment).
     *
     * @param  list<string>  $routes
     * @return array<string, array{int, int}>
     */
    public function current(int $teamId, array $routes): array
    {
        $domainIds = [];
        $deploymentIds = [];

        foreach ($routes as $route) {
            $domain = RouteId::domainPublicId($route);
            $deployment = RouteId::deploymentPublicId($route);

            if ($domain !== null && $deployment !== null) {
                $domainIds[] = $domain;
                $deploymentIds[] = $deployment;
            }
        }

        if ($domainIds === []) {
            return [];
        }

        $owners = [];

        $domains = DB::table('domains')
            ->join('service_instances', 'service_instances.id', '=', 'domains.service_instance_id')
            ->join('environments', 'environments.id', '=', 'service_instances.environment_id')
            ->where('domains.team_id', $teamId)
            ->whereIn('domains.public_id', $domainIds)
            ->get(['domains.public_id', 'domains.service_instance_id', 'environments.project_id']);

        foreach ($domains as $row) {
            $owners[RouteId::forDomain(Cast::string($row->public_id ?? null))] = [Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null)];
        }

        $deployments = DB::table('deployments')
            ->join('service_instances', 'service_instances.id', '=', 'deployments.service_instance_id')
            ->join('environments', 'environments.id', '=', 'service_instances.environment_id')
            ->where('deployments.team_id', $teamId)
            ->whereIn('deployments.public_id', $deploymentIds)
            ->get(['deployments.public_id', 'deployments.service_instance_id', 'environments.project_id']);

        foreach ($deployments as $row) {
            $owners[RouteId::forDeployment(Cast::string($row->public_id ?? null))] ??= [Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null)];
        }

        return $owners;
    }
}
