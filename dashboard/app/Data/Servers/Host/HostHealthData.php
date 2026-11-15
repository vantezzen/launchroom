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
            dataDisk: DiskUsageData::optional($ints($health['dataDisk'] ?? null, ['totalMb', 'freeMb'])),
            dockerDisk: DiskUsageData::optional($ints($health['dockerDisk'] ?? null, ['totalMb', 'freeMb'])),
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
