<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Data\Protocol\V1\RouteSpec;
use App\Enums\Security\WafMode;
use App\Models\Server;
use App\Queries\RouteOwners;
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
    public function __construct(
        private RouteOwners $owners,
    ) {}

    /**
     * @param  list<RouteSpec>  $routes
     * @return list<RouteSpec>
     */
    public function apply(array $routes, Server $server): array
    {
        if ($routes === []) {
            return $routes;
        }

        $owners = array_map(static fn (array $owner): int => $owner[0], $this->owners->current($server->team_id, array_map(static fn (RouteSpec $route): string => $route->id, $routes)));

        if ($owners === []) {
            return $routes;
        }

        $settings = $this->settings(array_values(array_unique($owners)));

        return array_map(static function (RouteSpec $route) use ($owners, $settings): RouteSpec {
            $instance = $owners[$route->id] ?? null;
            $waf = $instance === null ? null : ($settings[$instance] ?? null);

            return $waf !== null && $route->clientAuth === null ? $route->withWaf($waf) : $route;
        }, $routes);
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
