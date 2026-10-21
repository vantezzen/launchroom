<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Normalizes a validated Report.host.mesh into what `servers.mesh_status`
 * stores: known keys with their types only (docs/09 R12: whitelist).
 */
final class MeshStatusFacts
{
    private const array STATES = ['starting', 'needs_login', 'connected', 'error'];

    private const array CONNECTIONS = ['direct', 'relay', 'idle'];

    /**
     * @param  array<array-key, mixed>  $mesh
     * @return array{state: string, ipv4: string|null, ipv6: string|null, message: string|null, checkedAt: string|null, peers: list<array{ipv4: string, online: bool, connection: string, relay: string|null, lastHandshake: string|null}>}
     */
    public static function fromProtocol(array $mesh): array
    {
        $state = is_string($mesh['state'] ?? null) && in_array($mesh['state'], self::STATES, true) ? $mesh['state'] : 'error';
        $peers = [];

        foreach (is_array($mesh['peers'] ?? null) ? $mesh['peers'] : [] as $peer) {
            $ip = is_array($peer) ? ($peer['ipv4'] ?? null) : null;

            if (! is_array($peer) || ! is_string($ip) || ! self::ip($ip, FILTER_FLAG_IPV4)) {
                continue;
            }

            $connection = $peer['connection'] ?? null;

            $peers[] = [
                'ipv4' => $ip,
                'online' => ($peer['online'] ?? false) === true,
                'connection' => is_string($connection) && in_array($connection, self::CONNECTIONS, true) ? $connection : 'idle',
                'relay' => is_string($peer['relay'] ?? null) ? mb_substr($peer['relay'], 0, 128) : null,
                'lastHandshake' => is_string($peer['lastHandshake'] ?? null) ? $peer['lastHandshake'] : null,
            ];
        }

        return [
            'state' => $state,
            'ipv4' => self::ipOrNull($mesh['ipv4'] ?? null, FILTER_FLAG_IPV4),
            'ipv6' => self::ipOrNull($mesh['ipv6'] ?? null, FILTER_FLAG_IPV6),
            'message' => is_string($mesh['message'] ?? null) ? mb_substr($mesh['message'], 0, 1024) : null,
            'checkedAt' => is_string($mesh['checkedAt'] ?? null) ? $mesh['checkedAt'] : null,
            'peers' => array_slice($peers, 0, 256),
        ];
    }

    private static function ipOrNull(mixed $value, int $flag): ?string
    {
        return is_string($value) && self::ip($value, $flag) ? $value : null;
    }

    private static function ip(mixed $value, int $flag): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, $flag) !== false;
    }
}
