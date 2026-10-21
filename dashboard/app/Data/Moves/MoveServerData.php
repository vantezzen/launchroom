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
            self::diskFreeMb($server),
            DeviceData::fromServer($server)->kind,
        );
    }

    /**
     * Free space where Docker keeps volumes (the Docker disk), else the data disk.
     */
    public static function diskFreeMb(Server $server): ?int
    {
        $status = $server->host_status ?? [];
        $health = is_array($status['health'] ?? null) ? $status['health'] : [];

        foreach ([$health['dockerDisk'] ?? null, $health['dataDisk'] ?? null] as $disk) {
            if (is_array($disk) && is_int($disk['freeMb'] ?? null)) {
                return $disk['freeMb'];
            }
        }

        return is_int($status['diskFreeMb'] ?? null) ? $status['diskFreeMb'] : null;
    }
}
