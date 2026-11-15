<?php

declare(strict_types=1);

use App\Services\Previews\PreviewAccess;
use App\Services\Previews\PreviewAddress;

test('it reads the host and path and splits the exchange token off', function (): void {
    $address = PreviewAddress::parse(' PR-12.Example.com:8443 ', '/docs?a=1&'.PreviewAccess::TOKEN_PARAMETER.'=tok%2Bx&b=2');

    expect($address->host)->toBe('pr-12.example.com')
        ->and($address->port)->toBe(8443)
        ->and($address->path)->toBe('/docs?a=1&b=2')
        ->and($address->token)->toBe('tok+x')
        ->and($address->origin())->toBe('https://pr-12.example.com:8443')
        ->and($address->hostWithPort())->toBe('pr-12.example.com:8443')
        ->and($address->urlWithToken('t1'))->toBe('https://pr-12.example.com:8443/docs?a=1&b=2&'.PreviewAccess::TOKEN_PARAMETER.'=t1');
});

test('port 443 is the default and a path without a query gets one for the token', function (): void {
    $address = PreviewAddress::parse('app.example.com:443', '/');

    expect($address->port)->toBeNull()
        ->and($address->token)->toBeNull()
        ->and($address->urlWithToken('t1'))->toBe('https://app.example.com/?'.PreviewAccess::TOKEN_PARAMETER.'=t1');
});

test('malformed hosts and unsafe paths are refused', function (string $host, string $uri, ?string $expectedHost, string $expectedPath): void {
    $address = PreviewAddress::parse($host, $uri);

    expect($address->host)->toBe($expectedHost)->and($address->path)->toBe($expectedPath);
})->with([
    'host with a path' => ['evil.com/x', '/', null, '/'],
    'port out of range' => ['app.example.com:70000', '/', null, '/'],
    'protocol-relative path' => ['app.example.com', '//evil.com', 'app.example.com', '/'],
    'absolute URL' => ['app.example.com', 'https://evil.com/', 'app.example.com', '/'],
    'backslash' => ['app.example.com', '/\\evil.com', 'app.example.com', '/'],
    'control character' => ['app.example.com', "/a\nb", 'app.example.com', '/'],
]);
