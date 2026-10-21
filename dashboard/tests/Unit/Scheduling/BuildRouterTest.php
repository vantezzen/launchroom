<?php

declare(strict_types=1);

use App\Scheduling\BuildCandidate;
use App\Scheduling\BuildRouter;
use App\Scheduling\BuildRoutingFailed;

test('the sticky build server wins while it has a free slot', function (): void {
    $builders = [new BuildCandidate(1, 'linux/amd64'), new BuildCandidate(2, 'linux/amd64', slots: 4)];

    expect(BuildRouter::server($builders, 'linux/amd64', 1))->toBe(1)
        ->and(BuildRouter::server($builders, 'linux/amd64'))->toBe(1);
});

test('a busy or offline sticky server gives way to the least-loaded one of the platform', function (): void {
    $builders = [
        new BuildCandidate(1, 'linux/amd64', slots: 1, running: 1),
        new BuildCandidate(2, 'linux/amd64', slots: 4, running: 2),
        new BuildCandidate(3, 'linux/amd64', slots: 2, running: 0),
        new BuildCandidate(4, 'linux/arm64', slots: 8, running: 0),
        new BuildCandidate(5, 'linux/amd64', slots: 8, running: 0, online: false),
    ];

    expect(BuildRouter::server($builders, 'linux/amd64', 1))->toBe(3)
        ->and(BuildRouter::server($builders, 'linux/amd64', 5))->toBe(3)
        ->and(BuildRouter::server($builders, 'linux/arm64', 1))->toBe(4);
});

test('when every build server is busy the task stays with the sticky one, else in the pool', function (): void {
    $builders = [new BuildCandidate(1, 'linux/amd64', running: 1), new BuildCandidate(2, 'linux/amd64', running: 1)];

    expect(BuildRouter::server($builders, 'linux/amd64', 2))->toBe(2)
        ->and(BuildRouter::server($builders, 'linux/amd64'))->toBeNull()
        ->and(BuildRouter::server([], 'linux/amd64'))->toBeNull();
});

test('a builder of unknown platform builds anything', function (): void {
    expect(BuildRouter::server([new BuildCandidate(7, null)], 'linux/arm64'))->toBe(7)
        ->and(BuildRouter::platform(['linux/arm64' => 1], [new BuildCandidate(7, null)]))->toBe('linux/arm64');
});

test('the platform follows the runtime servers: current one kept, else the most common buildable one', function (): void {
    $both = [new BuildCandidate(1, 'linux/amd64'), new BuildCandidate(2, 'linux/arm64')];

    expect(BuildRouter::platform(['linux/arm64' => 1, 'linux/amd64' => 3], $both))->toBe('linux/amd64')
        ->and(BuildRouter::platform(['linux/arm64' => 2, 'linux/amd64' => 2], $both))->toBe('linux/arm64')
        ->and(BuildRouter::platform(['linux/arm64' => 1, 'linux/amd64' => 3], $both, 'linux/arm64'))->toBe('linux/arm64')
        // The current platform's servers are gone.
        ->and(BuildRouter::platform(['linux/amd64' => 3], $both, 'linux/arm64'))->toBe('linux/amd64')
        // Most servers are arm64, but only amd64 can be built.
        ->and(BuildRouter::platform(['linux/arm64' => 5, 'linux/amd64' => 1], [new BuildCandidate(1, 'linux/amd64')]))->toBe('linux/amd64')
        // No runtime server known: whatever a builder has.
        ->and(BuildRouter::platform([], [new BuildCandidate(1, 'linux/arm64')]))->toBe('linux/arm64')
        ->and(BuildRouter::platform([], []))->toBe('linux/amd64');
});

test('no builder for any target platform is refused with a clear message', function (): void {
    expect(fn (): string => BuildRouter::platform(['linux/arm64' => 2], [new BuildCandidate(1, 'linux/amd64'), new BuildCandidate(2, 'linux/arm64', online: false)]))
        ->toThrow(BuildRoutingFailed::class, 'No online build server can build for linux/arm64');
});

test('a Mac that emulates amd64 builds for amd64 servers, but native builders come first (D53)', function (): void {
    $mac = new BuildCandidate(1, 'linux/arm64', slots: 2, emulated: ['linux/amd64']);
    $vps = new BuildCandidate(2, 'linux/amd64', slots: 1);

    // Only the Mac builds: amd64 runtime servers get amd64 images from it.
    expect(BuildRouter::platform(['linux/amd64' => 1], [$mac]))->toBe('linux/amd64')
        ->and(BuildRouter::server([$mac], 'linux/amd64'))->toBe(1)
        ->and(BuildRouter::server([$mac], 'linux/arm64'))->toBe(1);

    // A native amd64 builder is preferred, also over a sticky emulating one.
    expect(BuildRouter::server([$mac, $vps], 'linux/amd64', stickyId: 1))->toBe(2);

    // While the native one is busy the build waits in the pool rather than being pinned to the slow path.
    $busy = new BuildCandidate(2, 'linux/amd64', slots: 1, running: 1);
    expect(BuildRouter::server([$mac, $busy], 'linux/amd64'))->toBeNull();

    // Without emulation, an arm64 Mac can't build for amd64 servers.
    expect(fn (): string => BuildRouter::platform(['linux/amd64' => 1], [new BuildCandidate(1, 'linux/arm64')]))
        ->toThrow(BuildRoutingFailed::class, 'No online build server can build for linux/amd64');
});

test('property: the chosen build server is online, of the platform and never more loaded than a free alternative', function (): void {
    mt_srand(5150);

    for ($run = 0; $run < 2000; $run++) {
        $builders = [];

        for ($id = 1; $id <= mt_rand(0, 6); $id++) {
            $builders[] = new BuildCandidate($id, [null, 'linux/amd64', 'linux/arm64'][mt_rand(0, 2)], mt_rand(1, 4), mt_rand(0, 4), mt_rand(0, 4) !== 0);
        }

        $platform = ['linux/amd64', 'linux/arm64'][mt_rand(0, 1)];
        $sticky = mt_rand(0, 1) === 1 ? mt_rand(1, 6) : null;
        $chosen = BuildRouter::server($builders, $platform, $sticky);
        $fits = array_values(array_filter($builders, static fn (BuildCandidate $b): bool => $b->online && ($b->platform === null || $b->platform === $platform)));
        $free = array_values(array_filter($fits, static fn (BuildCandidate $b): bool => $b->hasFreeSlot()));

        if ($chosen === null) {
            expect($free)->toBe([]);

            continue;
        }

        $builder = array_values(array_filter($fits, static fn (BuildCandidate $b): bool => $b->id === $chosen))[0] ?? null;
        expect($builder)->not->toBeNull();

        if ($builder->hasFreeSlot() && $chosen !== $sticky) {
            foreach ($free as $other) {
                expect($builder->load())->toBeLessThanOrEqual($other->load());
            }
        }

        if (! $builder->hasFreeSlot()) {
            // Only the sticky server is kept while busy, and only when nothing is free.
            expect($chosen)->toBe($sticky)->and($free)->toBe([]);
        }
    }
});
