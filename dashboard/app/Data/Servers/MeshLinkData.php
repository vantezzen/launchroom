<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Mesh\MeshPath;
use Spatie\LaravelData\Data;

/**
 * One private-network link between two servers of the team, as their
 * launchroomd last reported it (Report.host.mesh.peers): direct, relayed
 * (with the relay region) or idle (no recent traffic), plus what launchroomd
 * measured (docs/05 §2.1): the round trip (mean of both directions' latest
 * probes, only when one is at most 10 minutes old) and the latest transfer
 * rate per direction (at most 2 days old). Unmeasured values are null/empty:
 * nothing is estimated.
 */
final class MeshLinkData extends Data
{
    /**
     * @param  list<LinkSpeedData>  $speeds
     */
    public function __construct(
        public string $from,
        public string $to,
        public MeshPath $path,
        public ?string $relay,
        public ?float $latencyMs = null,
        public array $speeds = [],
    ) {}
}
