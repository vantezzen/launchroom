<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use App\Enums\Servers\CleanupTrigger;
use Spatie\LaravelData\Data;

/**
 * The periodic health snapshot of Report.host.health. A group is null
 * unless launchroomd reported all of its numbers.
 */
final class HostHealthData extends Data
{
    /** Volumes kept of a disk breakdown (the largest). */
    public const int MAX_VOLUMES = 64;

    public function __construct(
        public ?string $collectedAt = null,
        public ?DiskUsageData $dataDisk = null,
        public ?DiskUsageData $dockerDisk = null,
        public ?MemoryUsageData $memory = null,
        public ?LoadAverageData $load = null,
        public ?InotifyLimitsData $inotify = null,
        public ?NetworkCountData $networks = null,
        public ?DockerUsageData $docker = null,
        public ?int $buildCacheMb = null,
        public ?int $clockSkewMs = null,
        public ?DiskCleanupData $cleanup = null,
        public ?DiskBreakdownData $disk = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $health  validated by App\Data\Protocol\V1\HostStatus::rules()
     */
    public static function fromProtocol(array $health): self
    {
        $cleanup = $health['cleanup'] ?? null;
        $ints = self::ints(...);
        $load = self::group($health['load'] ?? null, ['load1', 'load5', 'load15'], static fn (mixed $v): ?float => is_int($v) || is_float($v) ? (float) $v : null);

        return new self(
            collectedAt: is_string($health['collectedAt'] ?? null) ? $health['collectedAt'] : null,
            dataDisk: self::disk($health['dataDisk'] ?? null),
            dockerDisk: self::disk($health['dockerDisk'] ?? null),
            memory: MemoryUsageData::optional($ints($health['memory'] ?? null, ['totalMb', 'availableMb'])),
            load: LoadAverageData::optional($load),
            inotify: InotifyLimitsData::optional($ints($health['inotify'] ?? null, ['maxUserWatches', 'maxUserInstances', 'maxQueuedEvents'])),
            networks: NetworkCountData::optional($ints($health['networks'] ?? null, ['total', 'managed'])),
            docker: DockerUsageData::optional($ints($health['docker'] ?? null, ['imagesMb', 'imagesReclaimableMb', 'containersMb'])),
            buildCacheMb: is_int($health['buildCacheMb'] ?? null) ? $health['buildCacheMb'] : null,
            clockSkewMs: is_int($health['clockSkewMs'] ?? null) ? $health['clockSkewMs'] : null,
            cleanup: is_array($cleanup) && is_string($cleanup['at'] ?? null) && is_string($cleanup['trigger'] ?? null) ? new DiskCleanupData(
                at: $cleanup['at'],
                trigger: CleanupTrigger::from($cleanup['trigger']),
                removedImages: is_int($cleanup['removedImages'] ?? null) ? $cleanup['removedImages'] : 0,
                removedContainers: is_int($cleanup['removedContainers'] ?? null) ? $cleanup['removedContainers'] : 0,
                reclaimedMb: is_int($cleanup['reclaimedMb'] ?? null) ? $cleanup['reclaimedMb'] : 0,
                error: is_string($cleanup['error'] ?? null) ? mb_substr($cleanup['error'], 0, 1024) : null,
            ) : null,
            disk: self::breakdown($health['disk'] ?? null),
        );
    }

    private static function disk(mixed $group): ?DiskUsageData
    {
        $sizes = self::ints($group, ['totalMb', 'freeMb']);

        if ($sizes === null || ! is_array($group)) {
            return null;
        }

        $inodes = self::ints($group, ['inodesTotal', 'inodesFree']);

        return new DiskUsageData((int) $sizes['totalMb'], (int) $sizes['freeMb'], isset($inodes['inodesTotal']) ? (int) $inodes['inodesTotal'] : null, isset($inodes['inodesFree']) ? (int) $inodes['inodesFree'] : null);
    }

    /**
     * Volumes are kept largest first, at most MAX_VOLUMES of them.
     */
    private static function breakdown(mixed $disk): ?DiskBreakdownData
    {
        if (! is_array($disk) || ! is_string($disk['measuredAt'] ?? null)) {
            return null;
        }

        $volumes = [];

        foreach (is_array($disk['volumes'] ?? null) ? $disk['volumes'] : [] as $volume) {
            if (is_array($volume) && is_string($volume['id'] ?? null) && is_int($volume['sizeMb'] ?? null)) {
                $volumes[] = new VolumeUsageData($volume['id'], max(0, $volume['sizeMb']), ($volume['retired'] ?? false) === true);
            }
        }

        usort($volumes, static fn (VolumeUsageData $a, VolumeUsageData $b): int => $b->sizeMb <=> $a->sizeMb);

        return new DiskBreakdownData(
            measuredAt: $disk['measuredAt'],
            volumes: array_slice($volumes, 0, self::MAX_VOLUMES),
            walBacklogMb: is_int($disk['walBacklogMb'] ?? null) ? $disk['walBacklogMb'] : null,
            registryMb: is_int($disk['registryMb'] ?? null) ? $disk['registryMb'] : null,
        );
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
}
