<?php

declare(strict_types=1);

use App\Data\Protocol\V1\WorkspaceInfo;
use App\Support\Git\WatchPaths;

test('a monorepo app without its own watch paths watches its folder, its workspace packages and the root files', function (): void {
    $workspace = new WorkspaceInfo('@acme/web', 'pnpm', tool: 'turbo', dependsOn: ['packages/ui', 'packages/config/']);

    expect(WatchPaths::effective([], 'apps/web', $workspace))->toBe([
        'apps/web', 'packages/ui', 'packages/config', 'package.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml', 'turbo.json',
    ]);
});

test('a nested workspace root prefixes its root files', function (): void {
    $workspace = new WorkspaceInfo('web', 'npm', root: 'js', dependsOn: ['js/shared']);

    expect(WatchPaths::effective([], 'js/apps/web/', $workspace))->toBe(['js/apps/web', 'js/shared', 'js/package.json', 'js/package-lock.json']);
});

test('own watch paths, no workspace or an app at the repository root keep the watch paths as they are', function (array $own, string $root, ?WorkspaceInfo $workspace, array $want): void {
    expect(WatchPaths::effective($own, $root, $workspace))->toBe($want);
})->with([
    'own paths win' => [['apps/web/src'], 'apps/web', new WorkspaceInfo('web', 'pnpm', dependsOn: ['packages/ui']), ['apps/web/src']],
    'no workspace' => [[], 'apps/web', null, []],
    'repository root' => [[], '', new WorkspaceInfo('web', 'npm'), []],
]);

test('the defaults decide which pushes concern the app', function (array $files, bool $deploys): void {
    $paths = WatchPaths::effective([], 'apps/web', new WorkspaceInfo('@acme/web', 'pnpm', dependsOn: ['packages/ui']));

    expect(WatchPaths::matches($paths, $files))->toBe($deploys);
})->with([
    'the app' => [['apps/web/src/page.tsx'], true],
    'a package it uses' => [['packages/ui/button.tsx'], true],
    'the lockfile' => [['pnpm-lock.yaml'], true],
    'another app' => [['apps/admin/src/page.tsx'], false],
    'a package it does not use' => [['packages/billing/index.ts'], false],
]);
