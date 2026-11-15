<?php

declare(strict_types=1);

namespace App\Support\Network;

use App\Support\Protocol\Grammar;
use App\Support\Protocol\Limits;

/**
 * The private mesh's fixed facts (docs/05 §2–§3), as launchroomd states them
 * (protocol vocabulary): the address range servers join with, the port range
 * workloads are published on, the fixed service ports on mesh IPs and the
 * list caps of the NodeState fields that carry mesh addresses.
 */
final class Mesh
{
    public const string RANGE = Grammar::MESH_RANGE;

    public const int FIRST_PORT = Limits::MESH_PORT_MIN;

    public const int LAST_PORT = Limits::MESH_PORT_MAX;

    /** The registry the control plane serves on its mesh IP (launchroomd forwarder). */
    public const int REGISTRY_PORT = Limits::REGISTRY_MESH_PORT;

    /** The shared certificate storage (the control plane's Valkey, docs/05 §5). */
    public const int CERT_STORAGE_PORT = Limits::CERT_STORAGE_MESH_PORT;

    /** launchroomd's link probe listener on every server (docs/05 §2.1). */
    public const int LINK_PROBE_PORT = Limits::LINK_PROBE_MESH_PORT;

    /** Mesh IPs per allow-list (certificate storage, published ports). */
    public const int MAX_ALLOW_FROM = Limits::MESH_ALLOW_FROM;

    /** Mesh upstreams per route or ambassador listener. */
    public const int MAX_UPSTREAMS = Limits::ROUTE_UPSTREAMS;

    public const int MAX_LINK_PROBE_PEERS = Limits::LINK_PROBE_PEERS;

    /**
     * $ip if it is an IPv4 address inside the mesh range, else null.
     */
    public static function ip(?string $ip): ?string
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && IpClassifier::inCidr($ip, self::RANGE) ? $ip : null;
    }

    public static function isPublishedPort(?int $port): bool
    {
        return $port !== null && $port >= self::FIRST_PORT && $port <= self::LAST_PORT;
    }

    /**
     * `<mesh ip>:<published port>`, or null when either is outside the mesh.
     */
    public static function publishedAddress(?string $ip, ?int $port): ?string
    {
        $ip = self::ip($ip);

        return $ip === null || ! self::isPublishedPort($port) ? null : $ip.':'.$port;
    }
}
