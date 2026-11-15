<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Mesh\MeshClientState;
use App\Enums\Mesh\MeshPath;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * `servers.mesh_status`: the mesh client's latest Report.host.mesh. Only
 * known keys with their types are kept (docs/09 R12: whitelist).
 */
final class MeshReportData extends Data
{
    /**
     * @param  list<MeshReportPeerData>  $peers
     */
    public function __construct(
        public MeshClientState $state,
        public ?string $ipv4 = null,
        public ?string $ipv6 = null,
        public ?string $message = null,
        public ?string $checkedAt = null,
        #[DataCollectionOf(MeshReportPeerData::class)]
        public array $peers = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $mesh  validated by App\Data\Protocol\V1\HostStatus::rules()
     */
    public static function fromProtocol(array $mesh): self
    {
        $peers = [];

        foreach (is_array($mesh['peers'] ?? null) ? $mesh['peers'] : [] as $peer) {
            $ip = is_array($peer) ? ($peer['ipv4'] ?? null) : null;

            if (! is_array($peer) || ! self::ip($ip, FILTER_FLAG_IPV4)) {
                continue;
            }

            $peers[] = new MeshReportPeerData(
                ipv4: $ip,
                online: ($peer['online'] ?? false) === true,
                connection: is_string($peer['connection'] ?? null) ? (MeshPath::tryFrom($peer['connection']) ?? MeshPath::Idle) : MeshPath::Idle,
                relay: is_string($peer['relay'] ?? null) ? mb_substr($peer['relay'], 0, 128) : null,
                lastHandshake: is_string($peer['lastHandshake'] ?? null) ? $peer['lastHandshake'] : null,
            );
        }

        return new self(
            state: is_string($mesh['state'] ?? null) ? (MeshClientState::tryFrom($mesh['state']) ?? MeshClientState::Error) : MeshClientState::Error,
            ipv4: self::ip($mesh['ipv4'] ?? null, FILTER_FLAG_IPV4) ? $mesh['ipv4'] : null,
            ipv6: self::ip($mesh['ipv6'] ?? null, FILTER_FLAG_IPV6) ? $mesh['ipv6'] : null,
            message: is_string($mesh['message'] ?? null) ? mb_substr($mesh['message'], 0, 1024) : null,
            checkedAt: is_string($mesh['checkedAt'] ?? null) ? $mesh['checkedAt'] : null,
            peers: array_slice($peers, 0, 256),
        );
    }

    public function peer(?string $ipv4): ?MeshReportPeerData
    {
        foreach ($this->peers as $peer) {
            if ($ipv4 !== null && $peer->ipv4 === $ipv4) {
                return $peer;
            }
        }

        return null;
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function ip(mixed $value, int $flag): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, $flag) !== false;
    }
}
