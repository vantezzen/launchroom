<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Data\Servers\DeviceData;
use App\Enums\DeviceKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Support\ServerPlatform;
use Spatie\LaravelData\Data;

/**
 * A server in the move wizard (docs/14 §13): the source or a target.
 */
final class MoveServerData extends Data
{
    /**
     * @param  list<ServerRole>  $roles
     * @param  list<string>  $addresses  public IPv4/IPv6
     */
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public array $roles,
        public bool $isControlPlane,
        public ?string $platform,
        public array $addresses,
        public ?int $diskFreeMb,
        public DeviceKind $device,
    ) {}

    public static function fromServer(Server $server): self
    {
        return new self(
            $server->public_id,
            $server->name,
            $server->status,
            array_values($server->roles->all()),
            $server->is_control_plane,
            ServerPlatform::of($server),
            array_values(array_filter([$server->public_ipv4, $server->public_ipv6], is_string(...))),
            $server->host_status?->volumeDiskFreeMb(),
            DeviceData::fromServer($server)->kind,
        );
    }
}
