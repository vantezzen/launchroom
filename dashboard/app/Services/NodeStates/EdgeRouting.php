<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Protocol\V1\RouteSpec;
use App\Enums\CertStatus;
use App\Enums\DomainKind;
use App\Models\Domain;
use App\Models\Server;
use App\Services\Ingress\EdgeIngress;

/**
 * Edge-wide route settings applied after routes are computed (docs/05 §5):
 *
 * - generated hosts under the platform domain use the DNS-01 wildcard
 *   certificate `*.<project>.<apps domain>` (`tls: wildcard`) when a DNS
 *   provider is configured and the edge image has the modules (immutable
 *   deployment URLs included: no per-host issuance, no rate limits);
 * - custom domains with HSTS on (the default) send Strict-Transport-Security
 *   once an edge reported their certificate as issued (no includeSubDomains,
 *   no preload).
 */
final readonly class EdgeRouting
{
    public const string HSTS = 'Strict-Transport-Security';

    public function __construct(
        private EdgeIngress $ingress,
    ) {}

    /**
     * @param  list<RouteSpec>  $routes
     * @return list<RouteSpec>
     */
    public function apply(array $routes, Server $server): array
    {
        // The wildcard certificate needs the DNS-01 token: shared edges only.
        $wildcard = $server->isSharedEdge() ? $this->ingress->wildcardDomain($server) : null;
        $hsts = $this->hstsRouteIds($routes);
        $maxAge = 'max-age='.config()->integer('launchroom.ingress.hsts_max_age');

        foreach ($routes as $route) {
            if ($wildcard !== null && in_array($route->tls, ['acme', 'on_demand'], true) && $this->allUnder($route->hosts, $wildcard)) {
                $route->tls = 'wildcard';
            }

            if (isset($hsts[$route->id])) {
                $route->headers = [...($route->headers ?? []), self::HSTS => $maxAge];
            }
        }

        return $routes;
    }

    /**
     * @param  list<string>  $hosts
     */
    private function allUnder(array $hosts, string $domain): bool
    {
        foreach ($hosts as $host) {
            if (! str_ends_with($host, '.'.$domain)) {
                return false;
            }
        }

        return $hosts !== [];
    }

    /**
     * Route ids (`rt_` + domain ULID) of custom domains with HSTS on and an issued certificate.
     *
     * @param  list<RouteSpec>  $routes
     * @return array<string, true>
     */
    private function hstsRouteIds(array $routes): array
    {
        $domainIds = [];

        foreach ($routes as $route) {
            if ($route->tls === 'on_demand' && str_starts_with($route->id, 'rt_')) {
                $domainIds[] = 'dom_'.substr($route->id, 3);
            }
        }

        if ($domainIds === []) {
            return [];
        }

        $ids = [];

        $domains = Domain::query()
            ->whereIn('public_id', $domainIds)
            ->where('kind', DomainKind::Custom->value)
            ->where('hsts', true)
            ->where('cert_status', CertStatus::Issued->value)
            ->get(['public_id']);

        foreach ($domains as $domain) {
            $ids[Domain::routeIdFor($domain->public_id)] = true;
        }

        return $ids;
    }
}
