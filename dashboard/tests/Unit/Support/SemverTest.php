<?php

declare(strict_types=1);

use App\Support\ImageReference;
use App\Support\Semver;

test('versions compare by semver precedence', function (string $a, string $b, int $expected): void {
    expect(Semver::compare($a, $b))->toBe($expected)
        ->and(Semver::compare($b, $a))->toBe(-$expected);
})->with([
    ['1.0.0', '1.0.0', 0],
    ['v1.0.0', '1.0.0', 0],
    ['1.0.0+build.5', '1.0.0', 0],
    ['1.0.1', '1.0.0', 1],
    ['1.10.0', '1.9.9', 1],
    ['2.0.0', '1.99.99', 1],
    ['1.0.0', '1.0.0-rc.1', 1],
    ['1.0.0-rc.1', '1.0.0-beta.11', 1],
    ['1.0.0-beta.11', '1.0.0-beta.2', 1],
    ['1.0.0-beta', '1.0.0-alpha.1', 1],
    ['1.0.0-alpha.1', '1.0.0-alpha', 1],
    ['1.0.0-alpha.beta', '1.0.0-alpha.1', 1],
]);

test('invalid versions never compare', function (): void {
    expect(Semver::compare('1.0', '1.0.0'))->toBeNull()
        ->and(Semver::compare('01.0.0', '1.0.0'))->toBeNull()
        ->and(Semver::newer('latest', '0.1.0'))->toBeFalse()
        ->and(Semver::parse('v1.2.3-rc.1+abc')?->normalized())->toBe('1.2.3-rc.1');
});

test('image references must be pinned by digest without a tag', function (): void {
    expect(ImageReference::isDigestPinned('ghcr.io/vantezzen/launchroomd@sha256:'.str_repeat('a', 64)))->toBeTrue()
        ->and(ImageReference::isDigestPinned('127.0.0.1:5000/apps/x@sha256:'.str_repeat('a', 64)))->toBeTrue()
        ->and(ImageReference::isDigestPinned('ghcr.io/vantezzen/launchroomd:0.1.0'))->toBeFalse()
        ->and(ImageReference::isDigestPinned('ghcr.io/vantezzen/launchroomd:0.1.0@sha256:'.str_repeat('a', 64)))->toBeFalse()
        ->and(ImageReference::isDigestPinned('launchroomd@sha256:'.str_repeat('a', 64)))->toBeFalse();
});
