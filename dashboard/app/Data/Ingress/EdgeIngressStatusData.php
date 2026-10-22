<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use App\Models\Server;
use App\Support\Network\ServerAddress;
use Spatie\LaravelData\Data;

/**
 * One edge server's ingress as it last reported it (servers.ingress_status).
 */
final class EdgeIngressStatusData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $publicIpv4,
        /** `applied`, `pending`, `failed`, or null before the first report. */
        public ?string $state,
        public ?int $routes,
        /** Certificate storage: `local`, `shared`, `degraded`, or null. */
        public ?string $storage,
        public ?int $mirrored,
        public ?string $message,
        public ?string $reportedAt,
        public bool $controlPlane = false,
        /** Gets the install-wide certificate storage and DNS-01 token (an instance owner admitted it, docs/05 §5). */
        public bool $shared = false,
        public ?string $publicIpv6 = null,
    ) {}

    public static function fromServer(Server $server): self
    {
        $status = $server->ingress_status ?? [];
        $storage = is_array($status['storage'] ?? null) ? $status['storage'] : [];

        return new self(
            id: $server->public_id,
            name: $server->name,
            // Where DNS must point: never a private or Docker address (docs/05 §5).
            publicIpv4: ServerAddress::ipv4($server->public_ipv4),
            state: is_string($status['state'] ?? null) ? $status['state'] : null,
            routes: is_int($status['routes'] ?? null) ? $status['routes'] : null,
            storage: is_string($storage['state'] ?? null) ? $storage['state'] : null,
            mirrored: is_int($storage['mirrored'] ?? null) ? $storage['mirrored'] : null,
            message: is_string($storage['message'] ?? null) ? $storage['message'] : null,
            reportedAt: $server->ingress_status_at?->toIso8601ZuluString(),
            controlPlane: $server->is_control_plane,
            shared: $server->isSharedEdge(),
            publicIpv6: ServerAddress::ipv6($server->public_ipv6),
        );
    }
}
