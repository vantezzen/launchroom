<?php

declare(strict_types=1);

use App\Enums\AppUpdates\UpdateKind;
use App\Support\Images\ImageVersion;

/*
 * Image tags read as versions (docs/04 §14.1): channels keep variants and
 * precision apart, pre-releases are marked, kinds come from the first
 * number that differs.
 */

test('tags parse into prefix, numbers and suffix family', function (string $tag, string $channel, bool $prerelease): void {
    $version = ImageVersion::parse($tag);

    expect($version)->not->toBeNull()
        ->and($version?->channel())->toBe($channel)
        ->and($version?->prerelease)->toBe($prerelease);
})->with([
    'v-prefixed' => ['v6.2.0', 'v|3|', false],
    'alpine variant' => ['6.71.0-alpine', '|3|alpine', false],
    'pinned alpine' => ['6.71.0-alpine3.20', '|3|alpine-#', false],
    'rootless' => ['28.2.0-rootless', '|3|rootless', false],
    'four numbers' => ['v0.64.2.8', 'v|4|', false],
    'calendar with build hash' => ['2026.10.12-sha-3699278', '|3|sha-#', false],
    'release candidate' => ['7.0.0-rc.1', '|3|rc-#', true],
    'beta' => ['2.0.0-beta2', '|3|beta', true],
    'major only' => ['6', '|1|', false],
]);

test('words are not versions', function (string $tag): void {
    expect(ImageVersion::parse($tag))->toBeNull();
})->with(['latest', 'stable', 'alpine', 'lts-alpine', 'main', '']);

test('variants of one family compare by numbers, then by the suffix', function (): void {
    $older = ImageVersion::parse('6.71.0-alpine3.20');
    $newer = ImageVersion::parse('6.71.0-alpine3.21');

    expect($newer?->isNewerThan($older))->toBeTrue()
        ->and(ImageVersion::parse('6.10.0')?->isNewerThan(ImageVersion::parse('6.9.9')))->toBeTrue();
});

test('the first number that differs decides the kind', function (string $from, string $to, UpdateKind $kind): void {
    expect(ImageVersion::parse($from)?->kindTo(ImageVersion::parse($to)))->toBe($kind);
})->with([
    ['v4.1.0', 'v5.0.2', UpdateKind::Major],
    ['v6.2.0', 'v6.3.0', UpdateKind::Minor],
    ['v6.2.0', 'v6.2.4', UpdateKind::Patch],
    ['v0.64.2.8', 'v0.64.2.9', UpdateKind::Patch],
    ['6', '7', UpdateKind::Major],
]);

test('versions read for people without their variant', function (): void {
    expect(ImageVersion::parse('6.71.0-alpine')?->display())->toBe('6.71.0')
        ->and(ImageVersion::parse('v6.2.0')?->display())->toBe('v6.2.0');
});
