<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Mesh\MeshPath;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A peer in a server's mesh status. `name`/`serverId` only for servers of the
 * viewer's team; other peers (other teams on the same install) show their
 * address only.
 */
final class MeshPeerData extends Data
{
    public function __construct(
        public string $ip,
        public ?string $serverId,
        public ?string $name,
        public bool $isControlPlane,
        public bool $online,
        public MeshPath $path,
        public ?string $relay,
        public ?CarbonInterface $lastHandshake,
        /** Measured link quality to this peer (docs/05 §2.1); null when nothing was measured. */
        public ?LinkQualityData $quality = null,
    ) {}
}
