<?php

declare(strict_types=1);

use App\Services\Dns\ProxyRanges;
use App\Support\Network\ServerAddress;

beforeEach(function (): void {
    config(['launchroom.server_health.private_addresses' => false]);
});

test('private, CGNAT and Docker addresses are never where DNS points', function (string $ip): void {
    expect(ServerAddress::usable($ip))->toBeFalse()
        ->and(ServerAddress::ipv4($ip))->toBeNull()
        ->and(ServerAddress::ipv6($ip))->toBeNull();
})->with([
    'Docker bridge gateway' => '172.18.0.1',
    'Docker default bridge' => '172.17.0.2',
    'RFC 1918 10/8' => '10.0.0.5',
    'RFC 1918 192.168/16' => '192.168.1.20',
    'CGNAT / mesh' => '100.64.0.7',
    'CGNAT upper end' => '100.127.255.254',
    'loopback' => '127.0.0.1',
    'link-local / metadata' => '169.254.169.254',
    'this network' => '0.0.0.0',
    'IPv6 loopback' => '::1',
    'IPv6 unique local' => 'fd7a:115c:a1e0::1',
    'IPv6 link-local' => 'fe80::1',
    'IPv4-mapped IPv6' => '::ffff:172.18.0.1',
    'not an IP' => 'example.com',
]);

test('public addresses are kept, IPv6 in canonical form', function (): void {
    expect(ServerAddress::ipv4('93.184.216.34'))->toBe('93.184.216.34')
        ->and(ServerAddress::ipv4('100.128.0.1'))->toBe('100.128.0.1')
        ->and(ServerAddress::ipv4('2606:4700::1'))->toBeNull()
        ->and(ServerAddress::ipv6('2606:4700:0:0:0:0:0:1'))->toBe('2606:4700::1')
        ->and(ServerAddress::ipv6('93.184.216.34'))->toBeNull()
        ->and(ServerAddress::ipv4(null))->toBeNull();
});

test('local dev may show private addresses of its fake servers', function (): void {
    config(['launchroom.server_health.private_addresses' => true]);

    expect(ServerAddress::ipv4('172.18.0.1'))->toBe('172.18.0.1')
        ->and(ServerAddress::usable('not an ip'))->toBeFalse();
});

test('Cloudflare proxy addresses are recognised, a mix with anything else is not', function (): void {
    expect(ProxyRanges::provider(['172.67.185.103', '104.21.76.25', '2606:4700:3030::6815:4c19']))->toBe('Cloudflare')
        ->and(ProxyRanges::provider(['172.67.185.103', '93.184.216.34']))->toBeNull()
        ->and(ProxyRanges::provider(['93.184.216.34']))->toBeNull()
        ->and(ProxyRanges::provider([]))->toBeNull();
});
