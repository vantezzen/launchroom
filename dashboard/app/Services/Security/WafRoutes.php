<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Data\Protocol\V1\RouteSpec;
use App\Enums\DaemonFeature;
use App\Enums\Security\WafMode;
use App\Models\Server;
use App\Support\Metrics\Cast;
use Illuminate\Support\Facades\DB;

/**
 * The web firewall's settings on an edge's routes (docs/22 §6.2–6.4,
 * docs/03 §13.3), applied after the routes are computed. A route belongs
 * to a service instance through its id (`rt_<ULID>` of a domain or of a
 * deployment's immutable URL); its instance's mode, paranoia and
 * exclusions become `RouteSpec.waf`. Only edges announcing `waf` get it;
 * Off, routes with client certificates and the dashboard's own route never
 * do. Exclusions are typed fields in id order, so renders are stable.
 */
final readonly class WafRoutes
{
    /**
     * @param  list<RouteSpec>  $routes
     * @return list<RouteSpec>
     */
    public function apply(array $routes, Server $server): array
    {
        if ($routes === [] || ! $server->supportsDaemonFeature(DaemonFeature::Waf)) {
            return $routes;
        }

        $owners = $this->owners($server, $routes);

        if ($owners === []) {
            return $routes;
        }

        $settings = $this->settings(array_values(array_unique($owners)));

        foreach ($routes as $route) {
            $instance = $owners[$route->id] ?? null;
            $waf = $instance === null ? null : ($settings[$instance] ?? null);

            if ($waf !== null && $route->clientAuth === null) {
                $route->waf = $waf;
            }
        }

        return $routes;
    }

    /**
     * Route id → service instance id, for the server's team only.
     *
     * @param  list<RouteSpec>  $routes
     * @return array<string, int>
     */
    private function owners(Server $server, array $routes): array
    {
        $ulids = [];

        foreach ($routes as $route) {
            if (preg_match('/^rt_([0-9A-HJKMNP-TV-Z]{26})$/D', $route->id, $m) === 1) {
                $ulids[] = $m[1];
            }
        }

        if ($ulids === []) {
            return [];
        }

        $owners = [];

        foreach (DB::table('domains')->where('team_id', $server->team_id)->whereIn('public_id', array_map(static fn (string $u): string => 'dom_'.$u, $ulids))->get(['public_id', 'service_instance_id']) as $row) {
            $owners['rt_'.substr(Cast::string($row->public_id ?? null), 4)] = Cast::id($row->service_instance_id ?? null);
        }

        foreach (DB::table('deployments')->where('team_id', $server->team_id)->whereIn('public_id', array_map(static fn (string $u): string => 'dpl_'.$u, $ulids))->get(['public_id', 'service_instance_id']) as $row) {
            $owners['rt_'.substr(Cast::string($row->public_id ?? null), 4)] ??= Cast::id($row->service_instance_id ?? null);
        }

        return $owners;
    }

    /**
     * @param  list<int>  $instanceIds
     * @return array<int, array{mode: string, paranoia?: int, inspectBodyKiB?: int, exclusions?: list<array{rule: int, path?: string, target?: string}>}>
     */
    private function settings(array $instanceIds): array
    {
        $exclusions = [];

        foreach (DB::table('waf_exclusions')->whereIn('service_instance_id', $instanceIds)->orderBy('id')->get(['service_instance_id', 'rule_id', 'path_prefix', 'target']) as $row) {
            $instance = Cast::id($row->service_instance_id ?? null);

            if (count($exclusions[$instance] ?? []) >= 50) {
                continue;
            }

            $exclusions[$instance][] = array_filter([
                'rule' => Cast::id($row->rule_id ?? null),
                'path' => is_string($row->path_prefix ?? null) ? $row->path_prefix : null,
                'target' => is_string($row->target ?? null) ? $row->target : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $out = [];

        foreach (DB::table('service_instances')->whereIn('id', $instanceIds)->where('waf_mode', '!=', WafMode::Off->value)->get(['id', 'waf_mode', 'waf_paranoia', 'waf_inspect_body_kib']) as $row) {
            $id = Cast::id($row->id ?? null);
            $paranoia = Cast::id($row->waf_paranoia ?? null);
            // 32 KiB is the default and left out (docs/03 §13.3).
            $body = Cast::id($row->waf_inspect_body_kib ?? null);
            $out[$id] = [
                'mode' => Cast::string($row->waf_mode ?? null),
                ...($paranoia === 2 ? ['paranoia' => 2] : []),
                ...(in_array($body, [128, 1024], true) ? ['inspectBodyKiB' => $body] : []),
                ...(isset($exclusions[$id]) ? ['exclusions' => $exclusions[$id]] : []),
            ];
        }

        return $out;
    }
}
