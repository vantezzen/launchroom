<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Data\Protocol\V1\HostStatus;
use Spatie\LaravelData\Optional;

/**
 * Normalizes a validated Report.host into what `servers.host_status` stores:
 * only known keys with their types, so nothing a daemon adds ends up in the
 * database or the UI (docs/09 R12: whitelist, never pass through).
 */
final class HostStatusFacts
{
    /**
     * @return array<string, mixed>
     */
    public static function fromProtocol(HostStatus $host): array
    {
        return [
            'cpus' => $host->cpus,
            'memoryMb' => $host->memoryMb,
            'diskTotalMb' => $host->diskTotalMb,
            'diskFreeMb' => $host->diskFreeMb,
            'dockerVersion' => $host->dockerVersion instanceof Optional ? null : $host->dockerVersion,
            'daemonVersion' => $host->daemonVersion,
            'health' => is_array($host->health) ? self::health($host->health) : null,
            // Whether the server reaches the image registry (D68).
            'registry' => is_array($host->registry) ? [
                'reachable' => ($host->registry['reachable'] ?? false) === true,
                'checkedAt' => is_string($host->registry['checkedAt'] ?? null) ? $host->registry['checkedAt'] : null,
                'latencyMs' => is_int($host->registry['latencyMs'] ?? null) ? $host->registry['latencyMs'] : null,
                'error' => is_string($host->registry['error'] ?? null) ? mb_substr($host->registry['error'], 0, 512) : null,
            ] : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $health
     * @return array<string, mixed>
     */
    private static function health(array $health): array
    {
        return [
            'collectedAt' => is_string($health['collectedAt'] ?? null) ? $health['collectedAt'] : null,
            'dataDisk' => self::ints($health['dataDisk'] ?? null, ['totalMb', 'freeMb']),
            'dockerDisk' => self::ints($health['dockerDisk'] ?? null, ['totalMb', 'freeMb']),
            'memory' => self::ints($health['memory'] ?? null, ['totalMb', 'availableMb']),
            'load' => self::group($health['load'] ?? null, ['load1', 'load5', 'load15'], static fn (mixed $v): ?float => is_int($v) || is_float($v) ? (float) $v : null),
            'inotify' => self::ints($health['inotify'] ?? null, ['maxUserWatches', 'maxUserInstances', 'maxQueuedEvents']),
            'networks' => self::ints($health['networks'] ?? null, ['total', 'managed']),
            'docker' => self::ints($health['docker'] ?? null, ['imagesMb', 'imagesReclaimableMb', 'containersMb']),
            'buildCacheMb' => is_int($health['buildCacheMb'] ?? null) ? $health['buildCacheMb'] : null,
            'clockSkewMs' => is_int($health['clockSkewMs'] ?? null) ? $health['clockSkewMs'] : null,
            'cleanup' => self::cleanup($health['cleanup'] ?? null),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, int|float>|null
     */
    private static function ints(mixed $group, array $keys): ?array
    {
        return self::group($group, $keys, static fn (mixed $v): ?int => is_int($v) ? $v : null);
    }

    /**
     * @param  list<string>  $keys
     * @param  callable(mixed): (int|float|null)  $cast
     * @return array<string, int|float>|null null unless every key is present with the right type
     */
    private static function group(mixed $group, array $keys, callable $cast): ?array
    {
        if (! is_array($group)) {
            return null;
        }

        $out = [];

        foreach ($keys as $key) {
            $value = $cast($group[$key] ?? null);

            if ($value === null) {
                return null;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cleanup(mixed $cleanup): ?array
    {
        if (! is_array($cleanup) || ! is_string($cleanup['at'] ?? null) || ! is_string($cleanup['trigger'] ?? null)) {
            return null;
        }

        return [
            'at' => $cleanup['at'],
            'trigger' => $cleanup['trigger'],
            'removedImages' => is_int($cleanup['removedImages'] ?? null) ? $cleanup['removedImages'] : 0,
            'removedContainers' => is_int($cleanup['removedContainers'] ?? null) ? $cleanup['removedContainers'] : 0,
            'reclaimedMb' => is_int($cleanup['reclaimedMb'] ?? null) ? $cleanup['reclaimedMb'] : 0,
            'error' => is_string($cleanup['error'] ?? null) ? mb_substr($cleanup['error'], 0, 1024) : null,
        ];
    }
}
