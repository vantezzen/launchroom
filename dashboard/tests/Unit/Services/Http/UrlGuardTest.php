<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\UrlGuard;

/**
 * @param  array<string, list<string>>  $records
 */
function fakeResolver(array $records): DnsResolver
{
    return new readonly class($records) implements DnsResolver
    {
        /** @param array<string, list<string>> $records */
        public function __construct(private array $records) {}

        public function resolve(string $hostname): array
        {
            return $this->records[$hostname] ?? [];
        }
    };
}

function guard(array $records = [], array $schemes = ['https']): UrlGuard
{
    return new UrlGuard(fakeResolver($records), $schemes);
}

test('resolves a public https URL once and pins its IP', function (): void {
    $target = guard(['example.com' => ['93.184.216.34']])->check('https://example.com/path?q=1');

    expect($target->host)->toBe('example.com')
        ->and($target->port)->toBe(443)
        ->and($target->ip)->toBe('93.184.216.34')
        ->and($target->url)->toBe('https://example.com/path?q=1')
        ->and($target->curlResolveEntry())->toBe('example.com:443:93.184.216.34');
});

test('pins IPv6 addresses in brackets and honours explicit ports', function (): void {
    $target = guard(['v6.example.com' => ['2606:4700:4700::1111']])->check('https://v6.example.com:8443/');

    expect($target->curlResolveEntry())->toBe('v6.example.com:8443:[2606:4700:4700::1111]');
});

test('accepts public IP literals without resolving', function (): void {
    expect(guard()->check('https://1.1.1.1/')->ip)->toBe('1.1.1.1')
        ->and(guard()->check('https://[2606:4700:4700::1111]/')->ip)->toBe('2606:4700:4700::1111');
});

test('rejects disallowed schemes', function (string $url): void {
    guard(['example.com' => ['93.184.216.34']])->check($url);
})->with([
    'http by default' => 'http://example.com/',
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://example.com/',
    'ftp' => 'ftp://example.com/',
    'dict' => 'dict://example.com:11211/',
    'javascript' => 'javascript:alert(1)',
])->throws(UnsafeUrl::class);

test('http can be allowed explicitly', function (): void {
    expect(guard(['example.com' => ['93.184.216.34']], ['https', 'http'])->check('http://example.com/')->port)->toBe(80);
});

test('rejects private and reserved destinations', function (string $url, array $records): void {
    guard($records)->check($url);
})->with([
    'loopback literal' => ['https://127.0.0.1/', []],
    'metadata literal' => ['https://169.254.169.254/latest/meta-data/', []],
    'v6 loopback literal' => ['https://[::1]/', []],
    'v4-mapped literal' => ['https://[::ffff:127.0.0.1]/', []],
    'CGNAT literal' => ['https://100.100.100.100/', []],
    'host resolving to private' => ['https://internal.example.com/', ['internal.example.com' => ['10.0.0.5']]],
    'host resolving to ULA' => ['https://ula.example.com/', ['ula.example.com' => ['fd00::1']]],
    'mixed public and private records' => ['https://rebind.example.com/', ['rebind.example.com' => ['93.184.216.34', '127.0.0.1']]],
    'decimal IP host' => ['https://2130706433/', ['2130706433' => ['127.0.0.1']]],
    'short IP host' => ['https://127.1/', ['127.1' => ['127.0.0.1']]],
    'hex IP host' => ['https://0x7f000001/', ['0x7f000001' => ['127.0.0.1']]],
    'unresolvable' => ['https://nxdomain.example.com/', []],
])->throws(UnsafeUrl::class);

test('rejects malformed URLs and credentials', function (string $url): void {
    guard(['example.com' => ['93.184.216.34']])->check($url);
})->with([
    'empty' => '',
    'relative' => '/path',
    'no host' => 'https:///path',
    'credentials' => 'https://user:pass@example.com/',
    'user only' => 'https://user@example.com/',
    'whitespace' => 'https://example.com/ path',
    'newline' => "https://example.com/\r\nHost: evil",
    'null byte' => "https://example.com\0.evil.com/",
    'port zero' => 'https://example.com:0/',
    'too long' => 'https://example.com/'.str_repeat('a', 2100),
    'underscore host' => 'https://exa_mple.com/',
])->throws(UnsafeUrl::class);

test('the hostname check is case and trailing-dot insensitive', function (): void {
    expect(guard(['example.com' => ['93.184.216.34']])->check('https://EXAMPLE.com./')->host)->toBe('example.com');
});

test('trailing-dot and mixed-case hosts are rewritten so the URL and the IP pin name the same host (L11)', function (): void {
    $target = guard(['example.com' => ['93.184.216.34']])->check('https://EXAMPLE.com.:8443/a.b/?q=example.com.#x');

    expect($target->url)->toBe('https://example.com:8443/a.b/?q=example.com.#x')
        ->and($target->curlResolveEntry())->toBe('example.com:8443:93.184.216.34')
        ->and(parse_url($target->url, PHP_URL_HOST))->toBe($target->host);
});
