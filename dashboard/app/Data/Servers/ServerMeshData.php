<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Mesh\MeshMemberState;
use App\Enums\Mesh\MeshPath;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A server's place in the mesh (docs/05 §2): its state and address, and how
 * it reaches the control plane (direct, relayed through DERP or idle). Null
 * path for the control plane itself, or before it reported its peers.
 */
final class ServerMeshData extends Data
{
    public function __construct(
        public MeshMemberState $state,
        public ?string $ip,
        public ?MeshPath $pathToControlPlane,
        public ?string $message,
        public ?CarbonInterface $checkedAt,
    ) {}

    public static function fromServer(Server $server, ?string $controlPlaneIp): self
    {
        $status = $server->mesh_status;

        if ($status === null) {
            return new self(MeshMemberState::Off, $server->mesh_ip, null, null, null);
        }

        $path = null;

        if (! $server->is_control_plane && $controlPlaneIp !== null) {
            foreach ($status['peers'] as $peer) {
                if ($peer['ipv4'] === $controlPlaneIp) {
                    $path = MeshPath::tryFrom($peer['connection']);
                }
            }
        }

        $state = MeshMemberState::fromReported($status['state']);

        return new self(
            $state,
            $status['ipv4'] ?? $server->mesh_ip,
            $path,
            $state === MeshMemberState::Connected ? null : $status['message'],
            $status['checkedAt'] !== null ? CarbonImmutable::parse($status['checkedAt']) : null,
        );
    }
}
