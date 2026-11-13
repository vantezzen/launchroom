<?php

declare(strict_types=1);

namespace App\Services\Volumes;

use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Models\VolumeMount;
use Illuminate\Database\Eloquent\Collection;

/**
 * The volumes a service instance mounts: its own and those of other
 * services of its environment it shares (D73). A Docker volume lives on one
 * server, so every service that mounts it runs there: all of them pin the
 * instance (docs/02 §6), and they must not be pinned apart.
 */
final class MountedVolumes
{
    /**
     * Own volumes (staged removals left out) and shared ones whose volume
     * isn't removed.
     *
     * @return Collection<int, Volume>
     */
    public static function for(ServiceInstance $instance): Collection
    {
        $instance->loadMissing(['volumes', 'volumeMounts.volume']);
        $shared = $instance->volumeMounts
            ->map(static fn (VolumeMount $mount): Volume => $mount->volume)
            ->filter(static fn (Volume $volume): bool => $volume->removed_at === null && $volume->service_instance_id !== $instance->id);

        return new Collection([...$instance->volumes->all(), ...$shared->values()->all()]);
    }

    /**
     * The volumes of other services the instance mounts.
     *
     * @return Collection<int, Volume>
     */
    public static function shared(ServiceInstance $instance): Collection
    {
        return self::for($instance)->filter(static fn (Volume $volume): bool => $volume->service_instance_id !== $instance->id)->values();
    }

    /**
     * The server holding the instance's volumes, if any is placed.
     */
    public static function pinnedServerId(ServiceInstance $instance): ?int
    {
        return self::for($instance)->first(static fn (Volume $volume): bool => $volume->server_id !== null)?->server_id;
    }

    /**
     * Why the instance can't run next to all its volumes, or null: they are
     * kept on different servers.
     */
    public static function apart(ServiceInstance $instance): ?string
    {
        $servers = self::for($instance)->pluck('server_id')->filter()->unique()->values();

        if ($servers->count() < 2) {
            return null;
        }

        $names = Server::query()->whereKey($servers->all())->orderBy('name')->get()->map(static fn (Server $server): string => $server->name)->all();
        $instance->loadMissing('service');

        return "{$instance->service->name} uses volumes that are kept on different servers (".implode(' and ', $names).'). A volume stays on one server, so every service that mounts it runs there. Detach one of the shared volumes on the canvas.';
    }

    /**
     * Instances of the volume's environment that mount it besides its owner.
     *
     * @return list<int>
     */
    public static function borrowerIds(Volume $volume): array
    {
        /** @var list<int> $ids */
        $ids = VolumeMount::query()
            ->where('volume_id', $volume->id)
            ->orderBy('service_instance_id')
            ->pluck('service_instance_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $ids;
    }

    /**
     * Instances that mount any of the given volumes besides their owners.
     *
     * @param  array<int, int>  $volumeIds
     * @return list<int>
     */
    public static function borrowersOf(array $volumeIds): array
    {
        if ($volumeIds === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = VolumeMount::query()
            ->whereIn('volume_id', array_values($volumeIds))
            ->distinct()
            ->orderBy('service_instance_id')
            ->pluck('service_instance_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $ids;
    }
}
