<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Support\Crypto\PublicKeyFormat;
use App\Support\Servers\ServerLocations;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Server detail page. Keys are exposed as fingerprints only, so an admin can
 * compare them with the daemon's `identity ready` log line before approving.
 */
final class ServerDetailData extends Data
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
        public ?string $protocolVersion,
        public ?string $signingKeyFingerprint,
        public HostFactsData $facts,
        public int $desiredVersion,
        public int $observedVersion,
        public ?CarbonInterface $lastSeenAt,
        public ?CarbonInterface $enrolledAt,
        public ?CarbonInterface $approvedAt,
        public ?CarbonInterface $revokedAt,
        public bool $canApprove,
        public bool $canRevoke,
        public ?DeviceData $device = null,
        public ?LocationData $location = null,
        public ?ServerSpecsData $specs = null,
        /** May change "Looks like" and the location (PATCH /servers/{server}/appearance). */
        public bool $canUpdateAppearance = false,
    ) {}

    public static function fromServer(Server $server, bool $canApprove, bool $canRevoke, bool $canUpdateAppearance = false): self
    {
        return new self(
            $server->public_id,
            $server->name,
            $server->status,
            array_values($server->roles->all()),
            $server->is_control_plane,
            $server->daemon_version,
            $server->protocol_version,
            PublicKeyFormat::fingerprint($server->signing_public_key),
            $server->facts,
            $server->desired_version,
            $server->observed_version,
            $server->last_seen_at,
            $server->enrolled_at,
            $server->approved_at,
            $server->revoked_at,
            $canApprove,
            $canRevoke,
            DeviceData::fromServer($server),
            ServerLocations::for($server),
            ServerSpecsData::fromServer($server),
            $canUpdateAppearance,
        );
    }
}
