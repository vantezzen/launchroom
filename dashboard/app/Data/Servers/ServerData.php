<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Support\Servers\ServerLocations;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A server row in lists and the server card (docs/design/direction.md §9.2):
 * device and provider (detected or picked), location (confirmed or a
 * suggestion to confirm) and the spec line. Usage series come from
 * `GET /servers/-/metrics` (App\Data\Metrics\FleetMetricsData).
 */
final class ServerData extends Data
{
    /**
     * @param  list<ServerRole>  $roles
     */
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public array $roles,
        public bool $isControlPlane,
        public ?string $daemonVersion,
        public ?CarbonInterface $lastSeenAt,
        public ?ServerMeshData $mesh = null,
        /** Replicas placed on it (serving and draining). */
        public int $workloads = 0,
        public ?DeviceData $device = null,
        public ?LocationData $location = null,
        public ?ServerSpecsData $specs = null,
    ) {}

    public static function fromServer(Server $server, ?ServerMeshData $mesh = null, int $workloads = 0): self
    {
        return new self(
            $server->public_id,
            $server->name,
            $server->status,
            array_values($server->roles->all()),
            $server->is_control_plane,
            $server->daemon_version,
            $server->last_seen_at,
            $mesh,
            $workloads,
            DeviceData::fromServer($server),
            ServerLocations::for($server),
            ServerSpecsData::fromServer($server),
        );
    }
}
