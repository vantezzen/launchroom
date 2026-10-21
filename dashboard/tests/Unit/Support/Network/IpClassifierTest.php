<?php

declare(strict_types=1);

use App\Support\Network\IpClassifier;

test('public addresses are allowed', function (string $ip): void {
    expect(IpClassifier::isPublic($ip))->toBeTrue()
        ->and(IpClassifier::blockedRange($ip))->toBeNull();
})->with([
    '1.1.1.1',
    '8.8.8.8',
    '93.184.216.34',
    '100.63.255.255',  // just below CGNAT
    '100.128.0.0',     // just above CGNAT
    '172.15.255.255',  // just below 172.16/12
    '172.32.0.0',      // just above 172.16/12
    '192.167.255.255',
    '198.17.255.255',
    '223.255.255.255', // last unicast before multicast
    '2606:4700:4700::1111',
    '2a00:1450:4001:80b::200e',
    '2001:4860:4860::8888',
]);

test('non-public addresses are blocked', function (string $ip, string $range): void {
    expect(IpClassifier::isPublic($ip))->toBeFalse()
        ->and(IpClassifier::blockedRange($ip))->toBe($range);
})->with([
    'this network' => ['0.0.0.0', '0.0.0.0/8'],
    'private 10/8' => ['10.1.2.3', '10.0.0.0/8'],
    'CGNAT low' => ['100.64.0.1', '100.64.0.0/10'],
    'CGNAT high (Tailscale)' => ['100.127.255.254', '100.64.0.0/10'],
    'loopback' => ['127.0.0.1', '127.0.0.0/8'],
    'loopback other' => ['127.255.255.254', '127.0.0.0/8'],
    'cloud metadata' => ['169.254.169.254', '169.254.0.0/16'],
    'private 172.16/12 low' => ['172.16.0.1', '172.16.0.0/12'],
    'private 172.16/12 high' => ['172.31.255.255', '172.16.0.0/12'],
    'IETF assignments' => ['192.0.0.170', '192.0.0.0/24'],
    'TEST-NET-1' => ['192.0.2.1', '192.0.2.0/24'],
    '6to4 relay' => ['192.88.99.1', '192.88.99.0/24'],
    'private 192.168/16' => ['192.168.1.1', '192.168.0.0/16'],
    'benchmarking' => ['198.19.0.1', '198.18.0.0/15'],
    'TEST-NET-2' => ['198.51.100.7', '198.51.100.0/24'],
    'TEST-NET-3' => ['203.0.113.9', '203.0.113.0/24'],
    'multicast' => ['224.0.0.1', '224.0.0.0/4'],
    'reserved' => ['240.0.0.1', '240.0.0.0/4'],
    'broadcast' => ['255.255.255.255', '240.0.0.0/4'],
    'v6 unspecified' => ['::', '::/128'],
    'v6 loopback' => ['::1', '::1/128'],
    'v4-compatible' => ['::7f00:1', '::/96'],
    'v4-mapped loopback' => ['::ffff:127.0.0.1', '::ffff:0:0/96'],
    'v4-mapped public' => ['::ffff:8.8.8.8', '::ffff:0:0/96'],
    'v4-mapped hex' => ['::ffff:a9fe:a9fe', '::ffff:0:0/96'],
    'NAT64' => ['64:ff9b::a9fe:a9fe', '64:ff9b::/96'],
    'local NAT64' => ['64:ff9b:1::1', '64:ff9b:1::/48'],
    'discard' => ['100::1', '100::/64'],
    'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', '2001::/23'],
    'documentation' => ['2001:db8::1', '2001:db8::/32'],
    '6to4' => ['2002:7f00:1::1', '2002::/16'],
    'documentation 3fff' => ['3fff::1', '3fff::/20'],
    'ULA fc' => ['fc00::1', 'fc00::/7'],
    'ULA fd' => ['fd12:3456:789a::1', 'fc00::/7'],
    'link-local' => ['fe80::1', 'fe80::/10'],
    'site-local' => ['fec0::1', 'fec0::/10'],
    'multicast v6' => ['ff02::1', 'ff00::/8'],
]);

test('invalid input is never public', function (string $value): void {
    expect(IpClassifier::isPublic($value))->toBeFalse()
        ->and(IpClassifier::blockedRange($value))->toBe('invalid');
})->with([
    '',
    'localhost',
    '256.0.0.1',
    '1.2.3',
    '2130706433',
    '0x7f000001',
    '127.1',
    '1.1.1.1 ',
    'fe80::1%eth0',
]);
