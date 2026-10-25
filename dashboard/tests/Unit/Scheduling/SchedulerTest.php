<?php

declare(strict_types=1);

use App\Enums\ServerRole;
use App\Scheduling\PlacementFailed;
use App\Scheduling\PlacementPlan;
use App\Scheduling\PlacementRequest;
use App\Scheduling\SchedulableServer;
use App\Scheduling\Scheduler;

function runtimeServer(int $id, ?int $memoryMb = 8192, array $labels = [], bool $online = true, int $allocated = 0, array $roles = [ServerRole::Runtime]): SchedulableServer
{
    return new SchedulableServer($id, $roles, $labels, $online, $memoryMb, $allocated);
}

test('places one replica on the eligible server with the most free memory', function (): void {
    $plan = Scheduler::place([runtimeServer(1, allocated: 6000), runtimeServer(2, allocated: 1000)], new PlacementRequest(1, 512));

    expect($plan->serverIds)->toBe([2]);
});

test('only online runtime servers are eligible', function (): void {
    $servers = [
        runtimeServer(1, online: false),
        runtimeServer(2, roles: [ServerRole::Edge, ServerRole::Build]),
        runtimeServer(3, roles: [ServerRole::Edge, ServerRole::Runtime]),
    ];

    expect(Scheduler::place($servers, new PlacementRequest(2, 256))->serverIds)->toBe([3, 3])
        ->and(fn (): PlacementPlan => Scheduler::place([runtimeServer(1, online: false)], new PlacementRequest(1, 256)))->toThrow(PlacementFailed::class);
});

test('required labels filter servers', function (): void {
    $servers = [runtimeServer(1, labels: ['region' => 'eu']), runtimeServer(2, labels: ['region' => 'us', 'gpu' => 'yes'])];

    expect(Scheduler::place($servers, new PlacementRequest(1, 256, requiredLabels: ['region' => 'us']))->serverIds)->toBe([2])
        ->and(fn (): PlacementPlan => Scheduler::place($servers, new PlacementRequest(1, 256, requiredLabels: ['region' => 'ap'])))->toThrow(PlacementFailed::class);
});

test('stateful services run one replica pinned to their volume server, never moved', function (): void {
    $servers = [runtimeServer(1), runtimeServer(2)];

    expect(Scheduler::place($servers, new PlacementRequest(3, 512, stateful: true, pinnedServerId: 2))->serverIds)->toBe([2])
        ->and(fn (): PlacementPlan => Scheduler::place([runtimeServer(1), runtimeServer(2, online: false)], new PlacementRequest(1, 512, stateful: true, pinnedServerId: 2)))
        ->toThrow(PlacementFailed::class, 'not moved automatically');
});

test('an unpinned stateful service is placed like any other', function (): void {
    expect(Scheduler::place([runtimeServer(1), runtimeServer(2, allocated: 100)], new PlacementRequest(1, 512, stateful: true))->serverIds)->toBe([1]);
});

test('capacity is respected, with overcommit', function (): void {
    $servers = [runtimeServer(1, memoryMb: 1024, allocated: 800)];

    expect(fn (): PlacementPlan => Scheduler::place($servers, new PlacementRequest(1, 512)))->toThrow(PlacementFailed::class)
        ->and(Scheduler::place($servers, new PlacementRequest(1, 512), 1.5)->serverIds)->toBe([1]);
});

test('unknown memory means no capacity check', function (): void {
    expect(Scheduler::place([runtimeServer(1, memoryMb: null, allocated: 99999)], new PlacementRequest(2, 4096))->serverIds)->toBe([1, 1]);
});

test('ties prefer the previous deployment\'s servers, then lower ids', function (): void {
    $servers = [runtimeServer(1), runtimeServer(2), runtimeServer(3)];

    expect(Scheduler::place($servers, new PlacementRequest(1, 512, previousServerIds: [3]))->serverIds)->toBe([3])
        ->and(Scheduler::place($servers, new PlacementRequest(1, 512))->serverIds)->toBe([1]);
});

test('zero replicas place nothing', function (): void {
    expect(Scheduler::place([], new PlacementRequest(0, 512))->serverIds)->toBe([]);
});

test('property: anti-affinity, capacity and eligibility hold for random clusters', function (): void {
    mt_srand(7);

    for ($run = 0; $run < 500; $run++) {
        $servers = [];

        for ($id = 1; $id <= mt_rand(1, 6); $id++) {
            $roles = mt_rand(0, 4) === 0 ? [ServerRole::Build] : [ServerRole::Runtime];
            $servers[] = runtimeServer($id, mt_rand(0, 5) === 0 ? null : mt_rand(1, 16) * 512, mt_rand(0, 1) === 1 ? ['zone' => 'a'] : [], mt_rand(0, 6) !== 0, mt_rand(0, 4) * 512, $roles);
        }

        $request = new PlacementRequest(mt_rand(0, 8), mt_rand(1, 4) * 256, requiredLabels: mt_rand(0, 3) === 0 ? ['zone' => 'a'] : []);

        try {
            $plan = Scheduler::place($servers, $request);
        } catch (PlacementFailed) {
            continue;
        }

        $byId = [];

        foreach ($servers as $server) {
            $byId[$server->id] = $server;
        }

        expect($plan->serverIds)->toHaveCount($request->replicas);

        $counts = array_count_values($plan->serverIds);

        foreach ($counts as $serverId => $count) {
            $server = $byId[$serverId];

            // Eligibility.
            expect($server->online && $server->hasRole(ServerRole::Runtime) && $server->hasLabels($request->requiredLabels))->toBeTrue();

            // Capacity.
            if ($server->memoryMb !== null) {
                expect($server->allocatedMemoryMb + $count * $request->memoryMb)->toBeLessThanOrEqual($server->memoryMb);
            }
        }

        // Anti-affinity: a server only gets a second replica when every other
        // eligible server either has as many or has no room for another one.
        if (count($counts) > 0) {
            $max = max($counts);

            foreach ($servers as $server) {
                $eligible = $server->online && $server->hasRole(ServerRole::Runtime) && $server->hasLabels($request->requiredLabels);
                $placed = $counts[$server->id] ?? 0;
                $room = $server->memoryMb === null || $server->allocatedMemoryMb + ($placed + 1) * $request->memoryMb <= $server->memoryMb;

                if ($eligible && $room) {
                    expect($placed)->toBeGreaterThanOrEqual($max - 1);
                }
            }
        }
    }
});

test('property: stateful requests never leave their pinned server', function (): void {
    mt_srand(11);

    for ($run = 0; $run < 200; $run++) {
        $servers = array_map(static fn (int $id): SchedulableServer => runtimeServer($id, online: mt_rand(0, 3) !== 0), range(1, 5));
        $pinned = mt_rand(1, 5);

        try {
            $plan = Scheduler::place($servers, new PlacementRequest(mt_rand(1, 3), 256, stateful: true, pinnedServerId: $pinned));
            expect($plan->serverIds)->toBe([$pinned]);
        } catch (PlacementFailed) {
            expect($servers[$pinned - 1]->online)->toBeFalse();
        }
    }
});

test('a server being moved away from only keeps services pinned to it by their volumes (docs/14 §13.2)', function (): void {
    $draining = new SchedulableServer(1, [ServerRole::Runtime], memoryMb: 8192, draining: true);
    $target = runtimeServer(2);

    expect(Scheduler::place([$draining, $target], new PlacementRequest(2, 256))->serverIds)->toBe([2, 2])
        ->and(Scheduler::rejection($draining, new PlacementRequest(1, 256)))->toBe('being moved to another server')
        // A database still waiting for its turn stays where its volume is.
        ->and(Scheduler::place([$draining, $target], new PlacementRequest(1, 256, stateful: true, pinnedServerId: 1))->serverIds)->toBe([1])
        ->and(fn (): PlacementPlan => Scheduler::place([$draining], new PlacementRequest(1, 256)))->toThrow(PlacementFailed::class);
});
