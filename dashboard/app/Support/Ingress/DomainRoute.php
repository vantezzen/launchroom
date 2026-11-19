<?php

declare(strict_types=1);

namespace App\Support\Ingress;

use App\Data\Protocol\V1\RouteSpec;
use App\Support\Hostname;

/**
 * The one way an app's hosts become a NodeState route (docs/05 §5): the
 * certificate mode (Caddy's internal CA for `*.localhost`, on-demand ACME
 * for hosts that are not under the platform domain or are immutable
 * deployment URLs, ACME otherwise), the www redirect to the canonical host
 * and `X-Robots-Tag: noindex` on every non-production host (docs/06 §5).
 * `$service` is the `sin_` id the request trace names (docs/23 §5).
 */
final class DomainRoute
{
    public const string NOINDEX_HEADER = 'X-Robots-Tag';

    /**
     * @param  list<string>  $hosts  the canonical host and its aliases
     * @param  list<array{workload: string, port: int}|array{address: string, server?: string}>  $upstreams
     * @param  array{forwardAuth: array{url: string, stripHeaders?: list<string>}}|null  $protection
     */
    public static function serving(
        string $id,
        string $canonical,
        array $hosts,
        bool $onDemand,
        bool $indexed,
        array $upstreams,
        ?array $protection = null,
        ?string $loadBalancing = null,
        ?string $service = null,
    ): RouteSpec {
        return new RouteSpec(
            id: $id,
            hosts: $hosts,
            tls: self::tls($canonical, $onDemand),
            upstreams: $upstreams,
            redirectWwwTo: count($hosts) > 1 ? $canonical : null,
            headers: $indexed ? null : [self::NOINDEX_HEADER => 'noindex'],
            protection: $protection,
            loadBalancing: $loadBalancing,
            service: $service,
        );
    }

    /**
     * The route of a service stopped in launchroom (D68): the edge answers
     * with its "stopped" page and has nothing to proxy to.
     *
     * @param  list<string>  $hosts
     */
    public static function stopped(string $id, string $canonical, array $hosts, bool $onDemand, bool $indexed, ?string $service = null): RouteSpec
    {
        $route = self::serving($id, $canonical, $hosts, $onDemand, $indexed, [], service: $service);
        $route->stopped = true;

        return $route;
    }

    private static function tls(string $canonical, bool $onDemand): string
    {
        return match (true) {
            Hostname::isLocal($canonical) => 'internal',
            $onDemand => 'on_demand',
            default => 'acme',
        };
    }
}
