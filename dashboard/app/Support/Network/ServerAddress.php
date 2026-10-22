<?php

declare(strict_types=1);

namespace App\Support\Network;

/**
 * Which address of a server DNS records may point at (docs/05 §5). The
 * enrollment request's source address is often not one: the control-plane
 * daemon reaches the dashboard through a Docker port publish and arrives
 * from the bridge gateway (172.18.0.1), and servers behind NAT or on a
 * private network arrive from a private address. launchroomd reports the
 * public addresses IP echo services see (`Report.host.publicAddresses`).
 *
 * Refused: private (RFC 1918, which covers Docker's default pools), CGNAT
 * (100.64.0.0/10, also the mesh), loopback, link-local, "this network",
 * unique-local and IPv4-mapped IPv6. Local dev's fake servers only have
 * private addresses, so `launchroom.server_health.private_addresses`
 * (on in APP_ENV=local) lets those through.
 */
final class ServerAddress
{
    private const array LOCAL_V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/4', '240.0.0.0/4'];

    private const array LOCAL_V6 = ['::/128', '::1/128', '::ffff:0:0/96', 'fc00::/7', 'fe80::/10', 'ff00::/8'];

    /**
     * True when $ip can be shown as "point your DNS here".
     */
    public static function usable(?string $ip): bool
    {
        if ($ip === null || ! IpClassifier::isIp($ip)) {
            return false;
        }

        if (config()->boolean('launchroom.server_health.private_addresses')) {
            return true;
        }

        $ranges = str_contains($ip, ':') ? self::LOCAL_V6 : self::LOCAL_V4;

        return array_all($ranges, static fn (string $cidr): bool => ! IpClassifier::inCidr($ip, $cidr));
    }

    /**
     * $ip if it is an IPv4 address that {@see usable()} accepts.
     */
    public static function ipv4(?string $ip): ?string
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && self::usable($ip) ? $ip : null;
    }

    /**
     * $ip (canonical) if it is an IPv6 address that {@see usable()} accepts.
     */
    public static function ipv6(?string $ip): ?string
    {
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false || ! self::usable($ip)) {
            return null;
        }

        $packed = @inet_pton($ip);

        return $packed === false ? null : (string) inet_ntop($packed);
    }
}
