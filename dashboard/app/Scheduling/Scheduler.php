<?php

declare(strict_types=1);

namespace App\Scheduling;

use App\Enums\PlacementStrategy;
use App\Enums\ServerRole;

/**
 * Placement scheduler (docs/02 §2, D8). Pure: no IO, no facades (docs/10 §2.2
 * rule 7), exhaustively unit-tested (tests/Unit/Scheduling).
 *
 * Filters (every one must hold, else the server is rejected with a reason):
 * - online, with the `runtime` role and every required label;
 * - the allow-list (when set) names it and the deny-list doesn't;
 * - previews only on servers allowing previews, production only on servers
 *   allowing production;
 * - the image's platform matches (built images are single-platform);
 * - stateful services run one replica, on the server holding their volume
 *   once pinned (never moved automatically, docs/02 §6);
 * - a server being moved away from (draining, docs/14 §13.2) only keeps
 *   services pinned to it by their volumes.
 *
 * Capacity: memory limits fit `total × memoryOvercommit − reserved −
 * allocated` when the server's memory is known; CPU limits fit `cores ×
 * cpuOvercommit − allocated` when CPU checking is on and cores are known.
 * Replicas of the set that stay running (`existingServerIds`) count.
 *
 * Choice per replica slot, lowest key wins:
 * - spread (default): replicas on the server, then replicas in its failure
 *   domain (`zone`, else `provider` label), then servers of the previous
 *   deployment (image already pulled), then more free memory, then lower id;
 * - pack: servers already running replicas first, then the previous
 *   deployment's servers, then the least free memory that still fits (best
 *   fit), then lower id.
 * Deterministic: the result doesn't depend on the order of $servers.
 */
final class Scheduler
{
    /**
     * @param  list<SchedulableServer>  $servers
     *
     * @throws PlacementFailed
     */
    public static function place(array $servers, PlacementRequest $request, float $memoryOvercommit = 1.0, ?float $cpuOvercommit = null): PlacementPlan
    {
        $replicas = $request->stateful ? min(1, $request->replicas) : $request->replicas;

        if ($replicas <= 0) {
            return new PlacementPlan([]);
        }

        $eligible = self::eligible($servers, $request);

        /** @var array<int, int> $memory */
        $memory = [];
        /** @var array<int, float> $cpu */
        $cpu = [];
        /** @var array<int, int> $counts */
        $counts = [];
        /** @var array<string, int> $domains */
        $domains = [];

        foreach ($servers as $server) {
            $memory[$server->id] = $server->allocatedMemoryMb;
            $cpu[$server->id] = $server->allocatedCpus;
            $counts[$server->id] = 0;
            $domains[$server->failureDomain()] ??= 0;
        }

        $byId = [];

        foreach ($servers as $server) {
            $byId[$server->id] = $server;
        }

        foreach ($request->existingServerIds as $id) {
            if (! isset($byId[$id])) {
                continue;
            }

            $memory[$id] += $request->memoryMb;
            $cpu[$id] += $request->cpus;
            $counts[$id]++;
            $domains[$byId[$id]->failureDomain()]++;
        }

        $slots = [];

        for ($slot = 0; $slot < $replicas; $slot++) {
            $best = null;
            $bestKey = null;

            foreach ($eligible as $server) {
                $free = self::freeMemory($server, $memory[$server->id], $memoryOvercommit);

                if ($free !== null && $free < $request->memoryMb) {
                    continue;
                }

                $freeCpu = $cpuOvercommit === null ? null : self::freeCpus($server, $cpu[$server->id], $cpuOvercommit);

                if ($freeCpu !== null && $freeCpu + 1e-9 < $request->cpus) {
                    continue;
                }

                $previous = in_array($server->id, $request->previousServerIds, true) ? 0 : 1;

                $key = $request->strategy === PlacementStrategy::Pack
                    ? [$counts[$server->id] > 0 ? 0 : 1, $previous, $free ?? PHP_INT_MAX, $server->id]
                    : [$counts[$server->id], $domains[$server->failureDomain()], $previous, -($free ?? PHP_INT_MAX), $server->id];

                if ($bestKey === null || $key < $bestKey) {
                    $best = $server;
                    $bestKey = $key;
                }
            }

            if ($best === null) {
                throw PlacementFailed::insufficientCapacity($request->memoryMb, $request->cpus);
            }

            $slots[] = $best->id;
            $memory[$best->id] += $request->memoryMb;
            $cpu[$best->id] += $request->cpus;
            $counts[$best->id]++;
            $domains[$best->failureDomain()]++;
        }

        return new PlacementPlan($slots);
    }

    /**
     * The servers that may run the request at all (capacity aside).
     *
     * @param  list<SchedulableServer>  $servers
     * @return list<SchedulableServer>
     *
     * @throws PlacementFailed when none may
     */
    public static function eligible(array $servers, PlacementRequest $request): array
    {
        $eligible = [];
        $rejected = [];
        $names = [];

        foreach ($servers as $server) {
            $names[$server->id] = $server->displayName();
            $reason = self::rejection($server, $request);

            if ($reason === null) {
                $eligible[] = $server;
            } elseif ($reason !== '') {
                $rejected[$server->id] = $reason;
            }
        }

        if ($request->stateful && $request->pinnedServerId !== null) {
            $eligible = array_values(array_filter($eligible, static fn (SchedulableServer $server): bool => $server->id === $request->pinnedServerId));

            if ($eligible === []) {
                throw PlacementFailed::pinnedUnavailable();
            }
        }

        if ($eligible === []) {
            ksort($rejected);

            throw PlacementFailed::noEligibleServers($rejected, $names);
        }

        usort($eligible, static fn (SchedulableServer $a, SchedulableServer $b): int => $a->id <=> $b->id);

        return $eligible;
    }

    /**
     * Why $server can't run the request: null when it can, '' when it isn't
     * a runtime server at all (not worth mentioning), else a short reason.
     */
    public static function rejection(SchedulableServer $server, PlacementRequest $request, bool $checkOnline = true): ?string
    {
        if (! $server->hasRole(ServerRole::Runtime)) {
            return '';
        }

        $missing = [];

        foreach ($request->requiredLabels as $key => $value) {
            if (($server->labels[(string) $key] ?? null) !== $value) {
                $missing[] = "{$key}={$value}";
            }
        }

        return match (true) {
            $request->allowServerIds !== null && ! in_array($server->id, $request->allowServerIds, true) => 'not in the allowed servers',
            in_array($server->id, $request->denyServerIds, true) => 'excluded by this service',
            $missing !== [] => 'missing label '.implode(', ', $missing),
            $request->preview && ! $server->allowPreviews => 'doesn\'t allow previews',
            $request->production && ! $server->allowProduction => 'doesn\'t allow production',
            $request->platform !== null && $server->platform !== null && $server->platform !== $request->platform => "runs {$server->platform}, the image is {$request->platform}",
            $checkOnline && ! $server->online => 'offline',
            $server->draining && $request->pinnedServerId !== $server->id => 'being moved to another server',
            default => null,
        };
    }

    private static function freeMemory(SchedulableServer $server, int $allocated, float $overcommit): ?int
    {
        if ($server->memoryMb === null || $server->memoryMb <= 0) {
            return null;
        }

        return (int) floor($server->memoryMb * $overcommit) - $server->reservedMemoryMb - $allocated;
    }

    private static function freeCpus(SchedulableServer $server, float $allocated, float $overcommit): ?float
    {
        if ($server->cpus === null || $server->cpus <= 0) {
            return null;
        }

        return $server->cpus * $overcommit - $allocated;
    }
}
