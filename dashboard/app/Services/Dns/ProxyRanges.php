<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Support\Network\IpClassifier;

/**
 * Published address ranges of reverse proxies / CDNs that sit in front of a
 * domain (docs/05 §5): when a record resolves into them, the A/AAAA values
 * never match the edge servers, but the traffic can still reach them.
 * Cloudflare: https://www.cloudflare.com/ips/ (checked 2026-10).
 */
final class ProxyRanges
{
    /** @var array<string, list<string>> */
    private const array PROVIDERS = [
        'Cloudflare' => [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
            '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
            '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
            '2a06:98c0::/29', '2c0f:f248::/32',
        ],
    ];

    /**
     * The proxy every address belongs to, or null (none, or a mix).
     *
     * @param  list<string>  $addresses
     */
    public static function provider(array $addresses): ?string
    {
        if ($addresses === []) {
            return null;
        }

        foreach (self::PROVIDERS as $name => $ranges) {
            $all = array_all($addresses, static fn (string $ip): bool => array_any($ranges, static fn (string $cidr): bool => IpClassifier::inCidr($ip, $cidr)));

            if ($all) {
                return $name;
            }
        }

        return null;
    }
}
