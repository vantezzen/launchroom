<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Server;
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
     * (`dpl_<ULID>`) of the server's team. Routes seen before keep their
     * attribution; unknown ones (the dashboard's own route) are missing.
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

            $ulids = array_map(static fn (string $route): string => substr($route, 3), $chunk);

            $domains = DB::table('domains')
                ->join('service_instances', 'service_instances.id', '=', 'domains.service_instance_id')
                ->join('environments', 'environments.id', '=', 'service_instances.environment_id')
                ->where('domains.team_id', $server->team_id)
                ->whereIn('domains.public_id', array_map(static fn (string $ulid): string => 'dom_'.$ulid, $ulids))
                ->get(['domains.public_id', 'domains.service_instance_id', 'environments.project_id']);

            foreach ($domains as $row) {
                $owners['rt_'.substr(Cast::string($row->public_id ?? null), 4)] = [Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null)];
            }

            $deployments = DB::table('deployments')
                ->join('service_instances', 'service_instances.id', '=', 'deployments.service_instance_id')
                ->join('environments', 'environments.id', '=', 'service_instances.environment_id')
                ->join('projects', 'projects.id', '=', 'environments.project_id')
                ->where('projects.team_id', $server->team_id)
                ->whereIn('deployments.public_id', array_map(static fn (string $ulid): string => 'dpl_'.$ulid, $ulids))
                ->get(['deployments.public_id', 'deployments.service_instance_id', 'environments.project_id']);

            foreach ($deployments as $row) {
                $owners['rt_'.substr(Cast::string($row->public_id ?? null), 4)] ??= [Cast::id($row->service_instance_id ?? null), Cast::id($row->project_id ?? null)];
            }
        }

        return $owners;
    }
}
