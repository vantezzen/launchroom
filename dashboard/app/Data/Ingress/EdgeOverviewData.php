<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use Spatie\LaravelData\Data;

/**
 * How public traffic reaches a team's apps (docs/02 §5 "never hide a single
 * point of failure"): the number of edge servers, whether that is exactly
 * one (the single point of failure badge), edges whose shared certificate
 * storage is degraded, and the high-availability guide.
 */
final class EdgeOverviewData extends Data
{
    /**
     * @param  list<string>  $degradedStorage  names of edges serving from their certificate mirror
     */
    public function __construct(
        public int $edges,
        /** Exactly one edge while other servers exist (one server alone is not a warning). */
        public bool $singlePointOfFailure,
        /** Approved servers counted (online, unreachable, offline). */
        public int $servers,
        /** The whole fleet is one server: the gentle "add a second server" hint. */
        public bool $singleServer,
        public array $degradedStorage,
        public string $guideUrl,
    ) {}
}
