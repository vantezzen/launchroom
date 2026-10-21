<?php

declare(strict_types=1);

use App\Support\Images\ImageReference;

$digest = 'sha256:'.str_repeat('a', 64);

test('normalizes references like docker does', function (string $input, string $registry, string $repository, ?string $tag): void {
    $reference = ImageReference::tryParse($input);

    expect($reference)->not->toBeNull()
        ->and($reference->registry)->toBe($registry)
        ->and($reference->repository)->toBe($repository)
        ->and($reference->tag)->toBe($tag);
})->with([
    ['postgres', 'docker.io', 'library/postgres', null],
    ['postgres:18', 'docker.io', 'library/postgres', '18'],
    ['valkey/valkey:8-alpine', 'docker.io', 'valkey/valkey', '8-alpine'],
    ['ghcr.io/umami-software/umami:postgresql-latest', 'ghcr.io', 'umami-software/umami', 'postgresql-latest'],
    ['localhost:5000/app:1', 'localhost:5000', 'app', '1'],
    ['registry.example.com:8443/team/app', 'registry.example.com:8443', 'team/app', null],
    ['index.docker.io/library/nginx:1.27', 'docker.io', 'library/nginx', '1.27'],
]);

test('keeps digests and renders mirrored, lowercase, digest-pinned references', function () use ($digest): void {
    $reference = ImageReference::tryParse("postgres:18@{$digest}");

    expect($reference?->isPinned())->toBeTrue()
        ->and($reference?->mirrored('127.0.0.1:5000/mirror/'))->toBe("127.0.0.1:5000/mirror/docker.io/library/postgres@{$digest}")
        ->and(ImageReference::tryParse("ghcr.io/org/app@{$digest}")?->canonical())->toBe("ghcr.io/org/app@{$digest}");
});

test('rendering an unpinned image is a programming error', function (): void {
    expect(fn (): ?string => ImageReference::tryParse('postgres:18')?->mirrored('127.0.0.1:5000/mirror'))->toThrow(LogicException::class);
});

test('rejects malformed references', function (string $input): void {
    expect(ImageReference::tryParse($input))->toBeNull();
})->with([
    '',
    'UPPER/case',
    'foo bar',
    'postgres@sha256:short',
    'postgres:bad tag',
    'postgres:-tag',
    'host..com/app',
    '../../etc/passwd',
    'app;rm -rf /',
]);

test('withDigest pins a tag', function () use ($digest): void {
    expect(ImageReference::tryParse('postgres:18')?->withDigest($digest)->canonical())->toBe("docker.io/library/postgres@{$digest}");
});
