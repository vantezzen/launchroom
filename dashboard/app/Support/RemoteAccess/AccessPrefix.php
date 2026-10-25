<?php

declare(strict_types=1);

namespace App\Support\RemoteAccess;

use App\Support\Network\IpClassifier;

/**
 * Allowed source ranges of a temporary access (docs/09 R39), the same
 * grammar launchroomd checks (`state.AccessPrefix`): an address or a
 * canonical prefix, IPv4 no wider than /8, IPv6 no wider than /32, never
 * unspecified, link-local (metadata) or multicast.
 */
final class AccessPrefix
{
    private const array REFUSED_V4 = ['0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/3'];

    private const array REFUSED_V6 = ['fe80::/10', 'ff00::/8', '::/128', '::ffff:0:0/96'];

    /**
     * The canonical prefix for $value ("203.0.113.7" → "203.0.113.7/32"),
     * or null when it isn't allowed.
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 50 || preg_match('/^[0-9a-fA-F.:]+(\/[0-9]{1,3})?$/D', $value) !== 1) {
            return null;
        }

        $parts = explode('/', $value, 2);
        $ip = $parts[0];
        $bits = $parts[1] ?? null;
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $v6 = strlen($packed) === 16;
        $max = $v6 ? 128 : 32;
        $bits = $bits === null ? $max : (int) $bits;

        if ($bits > $max || $bits < ($v6 ? 32 : 8)) {
            return null;
        }

        // Host bits must be zero (canonical), like Go's netip.Prefix.Masked.
        $masked = self::mask($packed, $bits);

        if ($masked !== $packed) {
            return null;
        }

        $canonical = (string) inet_ntop($packed);

        foreach ($v6 ? self::REFUSED_V6 : self::REFUSED_V4 as $cidr) {
            if (self::overlaps($canonical, $bits, $cidr)) {
                return null;
            }
        }

        return $canonical.'/'.$bits;
    }

    /**
     * The canonical prefix of one client address (the requester's IP).
     */
    public static function forAddress(?string $ip): ?string
    {
        return $ip === null || ! IpClassifier::isIp($ip) ? null : self::normalize($ip);
    }

    private static function mask(string $packed, int $bits): string
    {
        $out = '';

        for ($i = 0, $n = strlen($packed); $i < $n; $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($packed[$i]) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $out;
    }

    private static function overlaps(string $ip, int $bits, string $cidr): bool
    {
        [$net, $netBits] = explode('/', $cidr);
        $common = min($bits, (int) $netBits);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);

        return $a !== false && $b !== false && strlen($a) === strlen($b) && self::mask($a, $common) === self::mask($b, $common);
    }
}
