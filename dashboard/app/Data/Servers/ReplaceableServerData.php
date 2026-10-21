<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A server in the "Replace server" wizard (docs/14 §5.3): the dead one, or
 * a candidate replacement.
 */
final class ReplaceableServerData extends Data
{
    /**
     * @param  list<ServerRole>  $roles
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public array $roles,
        public array $labels,
        public bool $isControlPlane,
        public ?CarbonInterface $lastSeenAt,
        public ?string $replacedBy,
        public ?CarbonInterface $replacedAt,
    ) {}

    public static function fromServer(Server $server, ?string $replacedBy = null): self
    {
        return new self(
            $server->public_id,
            $server->name,
            $server->status,
            array_values($server->roles->all()),
            $server->labels,
            $server->is_control_plane,
            $server->last_seen_at,
            $replacedBy,
            $server->replaced_at,
        );
    }
}
