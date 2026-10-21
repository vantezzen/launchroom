<?php

declare(strict_types=1);

use App\Enums\PlacementStrategy;
use App\Enums\ServerRole;
use App\Scheduling\PlacementFailed;
use App\Scheduling\PlacementPlan;
use App\Scheduling\PlacementRequest;
use App\Scheduling\SchedulableServer;
use App\Scheduling\Scheduler;

/**
 * @param  array<string, mixed>  $overrides
 */
function schedServer(int $id, array $overrides = []): SchedulableServer
{
    return new SchedulableServer(...[
        'id' => $id,
        'roles' => [ServerRole::Runtime],
        'labels' => [],
        'online' => true,
        'memoryMb' => 8192,
        'allocatedMemoryMb' => 0,
        'cpus' => 4,
        'allocatedCpus' => 0.0,
        'name' => "s{$id}",
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function schedRequest(int $replicas, array $overrides = []): PlacementRequest
{
    return new PlacementRequest(...['replicas' => $replicas, 'memoryMb' => 512, 'cpus' => 0.5, ...$overrides]);
}

test('spread prefers other failure domains (zone, then provider) once every server has as many replicas', function (): void {
    $servers = [
        schedServer(1, ['labels' => ['zone' => 'hel']]),
        schedServer(2, ['labels' => ['zone' => 'hel']]),
        schedServer(3, ['labels' => ['zone' => 'fra']]),
    ];

    expect(Scheduler::place($servers, schedRequest(2))->serverIds)->toBe([1, 3])
        ->and(Scheduler::place($servers, schedRequest(3))->serverIds)->toBe([1, 3, 2]);

    $providers = [schedServer(1, ['labels' => ['provider' => 'hetzner']]), schedServer(2, ['labels' => ['provider' => 'hetzner']]), schedServer(3, ['labels' => ['provider' => 'ovh']])];

    expect(Scheduler::place($providers, schedRequest(2))->serverIds)->toBe([1, 3]);
});

test('pack fills one server before opening the next (best fit)', function (): void {
    $servers = [schedServer(1, ['memoryMb' => 2048]), schedServer(2, ['memoryMb' => 4096, 'allocatedMemoryMb' => 2560]), schedServer(3, ['memoryMb' => 8192])];

    // Server 2 has 1536 MB free: the tightest fit, filled first (3 × 512).
    expect(Scheduler::place($servers, schedRequest(5, ['strategy' => PlacementStrategy::Pack]))->serverIds)->toBe([2, 2, 2, 1, 1]);
});

test('allow-list and deny-list restrict servers, with reasons when nothing is left', function (): void {
    $servers = [schedServer(1), schedServer(2), schedServer(3)];

    expect(Scheduler::place($servers, schedRequest(2, ['allowServerIds' => [2, 3]]))->serverIds)->toBe([2, 3])
        ->and(Scheduler::place($servers, schedRequest(2, ['denyServerIds' => [1]]))->serverIds)->toBe([2, 3])
        ->and(fn (): PlacementPlan => Scheduler::place($servers, schedRequest(1, ['allowServerIds' => [1], 'denyServerIds' => [1]])))
        ->toThrow(PlacementFailed::class, 's1: excluded by this service; s2: not in the allowed servers; s3: not in the allowed servers');
});

test('previews only land on servers allowing previews; production only on servers allowing production', function (): void {
    $servers = [schedServer(1, ['allowPreviews' => false]), schedServer(2, ['allowProduction' => false])];

    expect(Scheduler::place($servers, schedRequest(2, ['preview' => true]))->serverIds)->toBe([2, 2])
        ->and(Scheduler::place($servers, schedRequest(2, ['production' => true]))->serverIds)->toBe([1, 1])
        ->and(Scheduler::place($servers, schedRequest(2))->serverIds)->toBe([1, 2])
        ->and(fn (): PlacementPlan => Scheduler::place([schedServer(1, ['allowPreviews' => false])], schedRequest(1, ['preview' => true])))
        ->toThrow(PlacementFailed::class, 's1: doesn\'t allow previews');
});

test('built images only run on servers of their platform; unknown platforms match', function (): void {
    $servers = [schedServer(1, ['platform' => 'linux/arm64']), schedServer(2, ['platform' => 'linux/amd64']), schedServer(3)];

    expect(Scheduler::place($servers, schedRequest(2, ['platform' => 'linux/amd64']))->serverIds)->toBe([2, 3])
        ->and(Scheduler::place($servers, schedRequest(3))->serverIds)->toBe([1, 2, 3])
        ->and(fn (): PlacementPlan => Scheduler::place([schedServer(1, ['platform' => 'linux/arm64'])], schedRequest(1, ['platform' => 'linux/amd64'])))
        ->toThrow(PlacementFailed::class, 'runs linux/arm64, the image is linux/amd64');
});

test('missing labels and offline servers are named in the error', function (): void {
    expect(fn (): PlacementPlan => Scheduler::place([schedServer(1, ['online' => false]), schedServer(2)], schedRequest(1, ['requiredLabels' => ['gpu' => 'yes']])))
        ->toThrow(PlacementFailed::class, 's1: missing label gpu=yes; s2: missing label gpu=yes')
        ->and(fn (): PlacementPlan => Scheduler::place([schedServer(1, ['online' => false])], schedRequest(1)))
        ->toThrow(PlacementFailed::class, 's1: offline')
        ->and(fn (): PlacementPlan => Scheduler::place([schedServer(1, ['roles' => [ServerRole::Build]])], schedRequest(1)))
        ->toThrow(PlacementFailed::class, 'no approved server with the runtime role');
});

test('CPU limits are reserved when CPU checking is on; reserved memory is kept free', function (): void {
    $servers = [schedServer(1, ['cpus' => 2, 'allocatedCpus' => 1.0]), schedServer(2, ['cpus' => 2])];

    // Server 1 has one core left: the third replica can only go to server 2.
    expect(Scheduler::place($servers, schedRequest(3, ['cpus' => 1.0]), 1.0, 1.0)->serverIds)->toBe([1, 2, 2])
        ->and(fn (): PlacementPlan => Scheduler::place($servers, schedRequest(4, ['cpus' => 1.0]), 1.0, 1.0))->toThrow(PlacementFailed::class, '1 CPU')
        ->and(Scheduler::place($servers, schedRequest(4, ['cpus' => 1.0]))->serverIds)->toHaveCount(4)
        ->and(fn (): PlacementPlan => Scheduler::place([schedServer(1, ['memoryMb' => 1024, 'reservedMemoryMb' => 768])], schedRequest(1)))->toThrow(PlacementFailed::class, '512 MB');
});

test('existing replicas of the set count for anti-affinity and capacity (rescheduling)', function (): void {
    $servers = [schedServer(1, ['memoryMb' => 1024]), schedServer(2), schedServer(3)];

    // Replicas stay on 2 and 3; the replacement goes to 1, which then is full.
    expect(Scheduler::place($servers, schedRequest(1, ['existingServerIds' => [2, 3]]))->serverIds)->toBe([1])
        ->and(Scheduler::place($servers, schedRequest(2, ['existingServerIds' => [1, 2]]))->serverIds)->toBe([3, 2]);
});

test('previous servers win ties, but never over anti-affinity', function (): void {
    $servers = [schedServer(1), schedServer(2), schedServer(3)];

    expect(Scheduler::place($servers, schedRequest(2, ['previousServerIds' => [3]]))->serverIds)->toBe([3, 1]);
});

/**
 * Random clusters for the properties below.
 *
 * @return list<SchedulableServer>
 */
function randomCluster(): array
{
    $servers = [];
    $count = mt_rand(1, 7);

    for ($id = 1; $id <= $count; $id++) {
        $roles = mt_rand(0, 5) === 0 ? [ServerRole::Build] : [ServerRole::Runtime, ...(mt_rand(0, 1) === 1 ? [ServerRole::Edge] : [])];
        $memory = mt_rand(0, 6) === 0 ? null : mt_rand(1, 16) * 512;
        $labels = [];

        if (mt_rand(0, 1) === 1) {
            $labels['zone'] = ['a', 'b', 'c'][mt_rand(0, 2)];
        }

        if (mt_rand(0, 2) === 0) {
            $labels['disk'] = 'ssd';
        }

        $servers[] = schedServer($id, [
            'roles' => $roles,
            'labels' => $labels,
            'online' => mt_rand(0, 6) !== 0,
            'memoryMb' => $memory,
            'allocatedMemoryMb' => mt_rand(0, 4) * 512,
            'cpus' => mt_rand(0, 5) === 0 ? null : mt_rand(1, 8),
            'allocatedCpus' => mt_rand(0, 8) * 0.5,
            'allowPreviews' => mt_rand(0, 3) !== 0,
            'allowProduction' => mt_rand(0, 3) !== 0,
            'platform' => [null, 'linux/amd64', 'linux/arm64'][mt_rand(0, 2)],
            'reservedMemoryMb' => mt_rand(0, 1) * 256,
        ]);
    }

    return $servers;
}

function randomRequest(int $servers): PlacementRequest
{
    $ids = range(1, $servers);

    return schedRequest(mt_rand(0, 8), [
        'memoryMb' => mt_rand(1, 4) * 256,
        'cpus' => mt_rand(1, 4) * 0.25,
        'requiredLabels' => mt_rand(0, 3) === 0 ? ['disk' => 'ssd'] : [],
        'strategy' => mt_rand(0, 3) === 0 ? PlacementStrategy::Pack : PlacementStrategy::Spread,
        'allowServerIds' => mt_rand(0, 4) === 0 ? array_values(array_filter($ids, static fn (): bool => mt_rand(0, 1) === 1)) : null,
        'denyServerIds' => mt_rand(0, 4) === 0 ? [mt_rand(1, $servers)] : [],
        'preview' => mt_rand(0, 3) === 0,
        'production' => mt_rand(0, 2) === 0,
        'platform' => [null, 'linux/amd64'][mt_rand(0, 1)],
        'previousServerIds' => mt_rand(0, 1) === 1 ? [mt_rand(1, $servers)] : [],
        'existingServerIds' => mt_rand(0, 3) === 0 ? [mt_rand(1, $servers)] : [],
    ]);
}

function schedEligible(SchedulableServer $server, PlacementRequest $request): bool
{
    return Scheduler::rejection($server, $request) === null;
}

/**
 * Room for one more replica on $server given $placed replicas of this set.
 */
function schedRoom(SchedulableServer $server, PlacementRequest $request, int $placed, float $overcommit, ?float $cpuOvercommit): bool
{
    $memoryOk = $server->memoryMb === null || $server->memoryMb <= 0
        || $server->allocatedMemoryMb + ($placed + 1) * $request->memoryMb <= (int) floor($server->memoryMb * $overcommit) - $server->reservedMemoryMb;
    $cpuOk = $cpuOvercommit === null || $server->cpus === null
        || $server->allocatedCpus + ($placed + 1) * $request->cpus <= $server->cpus * $cpuOvercommit + 1e-9;

    return $memoryOk && $cpuOk;
}

test('property: eligibility, capacity, anti-affinity, failure domains, pack and determinism hold for random clusters', function (): void {
    mt_srand(20261024);
    $placedRuns = 0;

    for ($run = 0; $run < 3000; $run++) {
        $servers = randomCluster();
        $request = randomRequest(count($servers));
        $overcommit = [1.0, 1.5][mt_rand(0, 1)];
        $cpuOvercommit = [null, 1.0, 4.0][mt_rand(0, 2)];

        try {
            $plan = Scheduler::place($servers, $request, $overcommit, $cpuOvercommit);
        } catch (PlacementFailed) {
            // Failing is only allowed when there is no way to place every replica:
            // at least one server must then be ineligible or full. Checked by the
            // greedy re-run: some slot had no eligible server with room.
            continue;
        }

        $placedRuns++;
        $byId = [];

        foreach ($servers as $server) {
            $byId[$server->id] = $server;
        }

        expect($plan->serverIds)->toHaveCount($request->replicas);

        // Determinism: the input order doesn't matter.
        $shuffled = $servers;
        shuffle($shuffled);
        expect(Scheduler::place($shuffled, $request, $overcommit, $cpuOvercommit)->serverIds)->toBe($plan->serverIds);

        $counts = array_count_values($plan->serverIds);
        $total = $counts;

        foreach ($request->existingServerIds as $id) {
            $total[$id] = ($total[$id] ?? 0) + 1;
        }

        foreach ($counts as $serverId => $count) {
            $server = $byId[$serverId];

            // Eligibility: every filter holds for every chosen server.
            expect(schedEligible($server, $request))->toBeTrue()
                ->and($server->online)->toBeTrue();

            // Capacity (existing replicas of the set included).
            expect(schedRoom($server, $request, $total[$serverId] - 1, $overcommit, $cpuOvercommit))->toBeTrue();
        }

        if ($total === []) {
            continue;
        }

        if ($request->strategy === PlacementStrategy::Spread) {
            // Anti-affinity: no eligible server with room for another replica
            // has two fewer than the fullest one.
            $max = max($total);

            foreach ($servers as $server) {
                $placed = $total[$server->id] ?? 0;

                if (schedEligible($server, $request) && schedRoom($server, $request, $placed, $overcommit, $cpuOvercommit)) {
                    expect($placed)->toBeGreaterThanOrEqual($max - 1);
                }
            }

            // Failure domains: with unlimited room and no existing replicas,
            // as many distinct domains as possible are used.
            $unlimited = array_all($servers, static fn (SchedulableServer $s): bool => $s->memoryMb === null && ($cpuOvercommit === null || $s->cpus === null));

            if ($unlimited && $request->existingServerIds === []) {
                $eligibleDomains = array_unique(array_map(static fn (SchedulableServer $s): string => $s->failureDomain(), array_filter($servers, static fn (SchedulableServer $s): bool => schedEligible($s, $request))));
                $usedDomains = array_unique(array_map(static fn (int $id): string => $byId[$id]->failureDomain(), $plan->serverIds));

                expect(count($usedDomains))->toBe(min(count($eligibleDomains), $request->replicas));
            }
        } else {
            // Pack: a new server is only opened when every server already in
            // use was full at that moment.
            $used = [];
            $placed = [];

            foreach ($request->existingServerIds as $id) {
                $placed[$id] = ($placed[$id] ?? 0) + 1;

                if (isset($byId[$id]) && schedEligible($byId[$id], $request)) {
                    $used[$id] = true;
                }
            }

            foreach ($plan->serverIds as $id) {
                if (! isset($used[$id])) {
                    foreach (array_keys($used) as $other) {
                        expect(schedRoom($byId[$other], $request, $placed[$other] ?? 0, $overcommit, $cpuOvercommit))->toBeFalse();
                    }
                }

                $used[$id] = true;
                $placed[$id] = ($placed[$id] ?? 0) + 1;
            }
        }
    }

    // The generator must exercise the success path a lot.
    expect($placedRuns)->toBeGreaterThan(800);
});

test('property: a failure means no eligible server had room, never a wrong refusal', function (): void {
    mt_srand(424242);

    for ($run = 0; $run < 2000; $run++) {
        $servers = randomCluster();
        $request = randomRequest(count($servers));

        try {
            Scheduler::place($servers, $request, 1.0, 1.0);
        } catch (PlacementFailed $e) {
            $eligible = array_values(array_filter($servers, static fn (SchedulableServer $s): bool => schedEligible($s, $request)));

            if ($eligible === []) {
                expect($e->getMessage())->toStartWith('No server');

                continue;
            }

            expect($e->getMessage())->toContain('room for another replica');

            // Greedy fill can't place every replica: total room across eligible servers is short.
            $room = 0;

            foreach ($eligible as $server) {
                $placed = count(array_keys($request->existingServerIds, $server->id, true));

                for ($k = $placed; $k < $placed + $request->replicas + 1 && schedRoom($server, $request, $k, 1.0, 1.0); $k++) {
                    $room++;
                }
            }

            expect($room)->toBeLessThan($request->replicas);
        }
    }
});

test('property: stateful sets run at most one replica and stay on their pinned server', function (): void {
    mt_srand(99);

    for ($run = 0; $run < 1000; $run++) {
        $servers = randomCluster();
        $pinned = mt_rand(0, 1) === 1 ? mt_rand(1, count($servers)) : null;
        $request = schedRequest(mt_rand(0, 4), ['stateful' => true, 'pinnedServerId' => $pinned, 'strategy' => mt_rand(0, 1) === 1 ? PlacementStrategy::Pack : PlacementStrategy::Spread]);

        try {
            $plan = Scheduler::place($servers, $request);
        } catch (PlacementFailed) {
            continue;
        }

        expect(count($plan->serverIds))->toBe(min(1, $request->replicas));

        if ($pinned !== null && $plan->serverIds !== []) {
            expect($plan->serverIds)->toBe([$pinned]);
        }
    }
});
