<?php

declare(strict_types=1);

use App\Services\GitHub\AppJwt;
use Tests\Support\GitHubFake;

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}
 */
function ghDecodeJwt(string $jwt): array
{
    [$header, $payload, $signature] = explode('.', $jwt);
    $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);

    $valid = openssl_verify("{$header}.{$payload}", $decode($signature), GitHubFake::publicKey(), OPENSSL_ALGO_SHA256) === 1;

    return [json_decode($decode($header), true), json_decode($decode($payload), true), $valid];
}

test('issues an RS256 app JWT backdated 60 s that expires within 9 minutes', function (): void {
    $now = new DateTimeImmutable('2026-10-18 12:00:00.123456 UTC');

    [$header, $claims, $valid] = ghDecodeJwt(AppJwt::issue(4242, GitHubFake::privateKey(), $now, 540));

    expect($valid)->toBeTrue()
        ->and($header['alg'])->toBe('RS256')
        ->and($claims['iss'])->toBe('4242')
        // Integers, not floats: GitHub rejects fractional timestamps.
        ->and($claims['iat'])->toBe($now->getTimestamp() - 60)
        ->and($claims['exp'])->toBe($now->getTimestamp() + 540)
        ->and($claims['exp'] - $now->getTimestamp())->toBeLessThanOrEqual(9 * 60);
});

test('refuses lifetimes GitHub would reject', function (int $ttl): void {
    AppJwt::issue(1, GitHubFake::privateKey(), new DateTimeImmutable, $ttl);
})->with([30, 600, 3600])->throws(InvalidArgumentException::class);

test('refuses an empty key', function (): void {
    AppJwt::issue(1, '', new DateTimeImmutable, 540);
})->throws(InvalidArgumentException::class);
