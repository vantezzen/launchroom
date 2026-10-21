<?php

declare(strict_types=1);

namespace App\Support\Network;

/**
 * Classifies IP addresses for SSRF protection (docs/09-security.md R5).
 *
 * We keep an explicit block list instead of relying on FILTER_FLAG_NO_PRIV_RANGE
 * / FILTER_FLAG_NO_RES_RANGE, which miss CGNAT (100.64.0.0/10), benchmarking,
 * NAT64 and several IPv6 transition ranges.
 */
final class IpClassifier
{
    /**
     * Non-public IPv4 ranges (IANA special-purpose registry, RFC 6890 et al.).
     */
    private const array BLOCKED_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private (RFC 1918)
        '100.64.0.0/10',      // CGNAT / shared address space (RFC 6598); also Tailscale
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, incl. cloud metadata 169.254.169.254
        '172.16.0.0/12',      // private (RFC 1918)
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation (TEST-NET-1)
        '192.88.99.0/24',     // 6to4 relay anycast (deprecated)
        '192.168.0.0/16',     // private (RFC 1918)
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation (TEST-NET-2)
        '203.0.113.0/24',     // documentation (TEST-NET-3)
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, incl. broadcast 255.255.255.255
    ];

    /**
     * Non-public IPv6 ranges. Ranges that embed an IPv4 address (mapped,
     * compatible, NAT64, 6to4, Teredo) are rejected outright rather than
     * unwrapped: there is no legitimate reason for a user URL to use them.
     */
    private const array BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::/96',              // IPv4-compatible (deprecated)
        '::ffff:0:0/96',      // IPv4-mapped
        '64:ff9b::/96',       // NAT64 well-known prefix
        '64:ff9b:1::/48',     // local-use NAT64
        '100::/64',           // discard-only
        '2001::/23',          // IETF protocol assignments, incl. Teredo 2001::/32
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        '3fff::/20',          // documentation
        '5f00::/16',          // SRv6 SIDs
        'fc00::/7',           // unique local (ULA)
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
    ];

    public static function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * True only for syntactically valid, globally routable unicast addresses.
     */
    public static function isPublic(string $ip): bool
    {
        return self::isIp($ip) && self::blockedRange($ip) === null;
    }

    /**
     * The blocked CIDR range containing $ip, or null if the IP is public.
     * Invalid input is reported as blocked ("invalid").
     */
    public static function blockedRange(string $ip): ?string
    {
        // filter_var first: inet_pton is platform-dependent and accepts some
        // non-canonical input (e.g. zone IDs like "fe80::1%eth0" on macOS).
        $packed = self::isIp($ip) ? @inet_pton($ip) : false;

        if ($packed === false) {
            return 'invalid';
        }

        $ranges = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;

        foreach ($ranges as $cidr) {
            if (self::inRange($packed, $cidr)) {
                return $cidr;
            }
        }

        return null;
    }

    private static function inRange(string $packedIp, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $packedNetwork = inet_pton($network);

        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if (strncmp($packedIp, $packedNetwork, $fullBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
    }
}
