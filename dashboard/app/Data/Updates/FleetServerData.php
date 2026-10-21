<?php

declare(strict_types=1);

namespace App\Data\Updates;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Daemon\ProtocolVersions;
use App\Support\Semver;
use Spatie\LaravelData\Data;

/**
 * A server's launchroomd version on Settings → Updates. `outdated`: older
 * than this dashboard; `newer`: newer (never downgraded); `compatible`:
 * speaks a protocol version the dashboard supports (docs/03 §10).
 */
final class FleetServerData extends Data
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $roles,
        public ServerStatus $status,
        public bool $isControlPlane,
        public ?string $daemonVersion,
        public ?string $protocolVersion,
        public bool $outdated,
        public bool $newer,
        public bool $compatible,
    ) {}

    public static function fromServer(Server $server, string $dashboardVersion): self
    {
        $compare = $server->daemon_version === null ? null : Semver::compare($server->daemon_version, $dashboardVersion);

        return new self(
            $server->public_id,
            $server->name,
            array_values($server->roles->map(static fn (ServerRole $role): string => $role->value)->all()),
            $server->status,
            $server->is_control_plane,
            $server->daemon_version,
            $server->protocol_version,
            $compare === -1,
            $compare === 1,
            ProtocolVersions::isSupported($server->protocol_version),
        );
    }
}
