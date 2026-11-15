<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Mesh\MeshPath;
use Spatie\LaravelData\Data;

/** One peer the mesh client sees (Report.host.mesh.peers[]). */
final class MeshReportPeerData extends Data
{
    public function __construct(
        public string $ipv4,
        public bool $online,
        public MeshPath $connection,
        public ?string $relay = null,
        public ?string $lastHandshake = null,
    ) {}
}
