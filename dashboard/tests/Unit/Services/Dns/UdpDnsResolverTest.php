<?php

declare(strict_types=1);

use App\Services\Dns\UdpDnsResolver;

function dnsName(string $name): string
{
    $out = '';

    foreach (explode('.', $name) as $label) {
        $out .= chr(strlen($label)).$label;
    }

    return $out."\0";
}

/**
 * A response for `www.example.com` A: CNAME to edge.example.net (compressed
 * owner names), then two A records.
 */
function dnsResponse(int $id, int $rcode = 0): string
{
    $question = dnsName('www.example.com').pack('nn', 1, 1);
    $header = pack('nnnnnn', $id, 0x8180 | $rcode, 1, $rcode === 0 ? 3 : 0, 0, 0);

    if ($rcode !== 0) {
        return $header.$question;
    }

    $cnameTarget = dnsName('edge.example.net');
    $answers = "\xC0\x0C".pack('nnNn', 5, 1, 300, strlen($cnameTarget)).$cnameTarget;
    // The A records' owner points at the CNAME target (offset of its rdata).
    $targetOffset = 12 + strlen($question) + 12;
    $pointer = pack('n', 0xC000 | $targetOffset);
    $answers .= $pointer.pack('nnNn', 1, 1, 300, 4).inet_pton('203.0.113.7');
    $answers .= $pointer.pack('nnNn', 1, 1, 300, 4).inet_pton('203.0.113.8');

    return $header.$question.$answers;
}

test('answers with CNAME chains and compressed names are parsed', function (): void {
    expect(UdpDnsResolver::parse(dnsResponse(4242), 4242))->toBe([
        'nxdomain' => false,
        'addresses' => ['203.0.113.7', '203.0.113.8'],
        'cnames' => ['edge.example.net'],
    ]);
});

test('NXDOMAIN, foreign ids and garbage are told apart', function (): void {
    expect(UdpDnsResolver::parse(dnsResponse(7, rcode: 3), 7))->toBe(['nxdomain' => true, 'addresses' => [], 'cnames' => []])
        // A response to someone else's query is not an answer.
        ->and(UdpDnsResolver::parse(dnsResponse(7), 8))->toBeNull()
        ->and(UdpDnsResolver::parse('short', 7))->toBeNull()
        ->and(UdpDnsResolver::parse(substr(dnsResponse(9), 0, -3), 9))->toBeNull()
        // SERVFAIL
        ->and(UdpDnsResolver::parse(dnsResponse(5, rcode: 2), 5))->toBeNull();
});

test('pointer loops do not hang the parser', function (): void {
    $packet = pack('nnnnnn', 1, 0x8180, 1, 0, 0, 0)."\xC0\x0C".pack('nn', 1, 1);

    expect(UdpDnsResolver::parse($packet, 1))->toBeNull();
});

test('invalid hostnames are never sent to a resolver', function (): void {
    config(['launchroom.dns.resolvers' => ['192.0.2.1']]);

    expect((new UdpDnsResolver)->lookup('not a host')->status)->toBe('error')
        ->and((new UdpDnsResolver)->lookup('http://169.254.169.254/')->status)->toBe('error');
});
