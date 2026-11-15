<?php

declare(strict_types=1);

use App\Support\Images\AppImage;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryLayout;
use App\Support\Protocol\Grammar;

test('built images are named, recognised and parsed under the registry apps prefix', function (): void {
    $digest = 'sha256:'.str_repeat('a', 64);
    $built = AppImage::parse('127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n3@'.$digest);

    expect(AppImage::nameFor('svc_01J9Z8X7W6V5T4S3R2Q1P0N9N3'))->toBe('127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n3')
        ->and($built?->service)->toBe('svc_01j9z8x7w6v5t4s3r2q1p0n9n3')
        ->and($built?->digest)->toBe($digest)
        ->and($built?->repository())->toBe('apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n3')
        ->and($built?->belongsTo('svc_01J9Z8X7W6V5T4S3R2Q1P0N9N3'))->toBeTrue()
        ->and($built?->belongsTo('svc_01J9Z8X7W6V5T4S3R2Q1P0N9N4'))->toBeFalse()
        ->and(AppImage::parse('127.0.0.1:5000/apps/svc_x:latest'))->toBeNull()
        ->and(AppImage::parse('127.0.0.1:5000/mirror/docker.io/library/nginx@'.$digest))->toBeNull()
        ->and(AppImage::isBuilt('127.0.0.1:5000/apps/anything'))->toBeTrue()
        ->and(AppImage::isBuilt('127.0.0.1:5000/mirror/docker.io/library/nginx@'.$digest))->toBeFalse();
});

test('both registry prefixes start with the protocol registry address', function (): void {
    $reference = ImageReference::tryParse('ghcr.io/acme/tool:1') ?? throw new RuntimeException('unparsable');

    expect(RegistryLayout::mirrorRepository($reference))->toBe('mirror/ghcr.io/acme/tool')
        ->and(RegistryLayout::mirroredList())->toBe('docker.io and ghcr.io')
        ->and(RegistryLayout::registry())->toBe(Grammar::REGISTRY_ADDRESS.'/')
        ->and(RegistryLayout::appsPrefix())->toBe(RegistryLayout::registry().'apps/')
        ->and(RegistryLayout::mirrorPrefix())->toBe(RegistryLayout::registry().'mirror');
});
