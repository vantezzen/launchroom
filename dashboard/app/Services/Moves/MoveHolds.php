<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Data\Services\DatabaseSource;
use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Models\Moves\ServerMoveItem;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\RestoreHolds;
use App\Services\NodeStates\NodeStateContent;

/**
 * Services held while a stateful service moves (docs/14 §13.2), the same
 * way restores and imports hold them ({@see RestoreHolds}): their workloads
 * leave their servers' desired state, so launchroomd stops them, and come
 * back when the hold ends.
 *
 * - dump (Postgres, MySQL, MongoDB): the services connected to the
 *   database in its environment, from "stopping" until it is imported on
 *   the target (or back on the source). The database itself keeps running:
 *   the dump is read from it and imported into it.
 * - files (a service with volumes, or Redis/Valkey/ClickHouse): the service
 *   itself until its volumes are copied (its volume must not change while
 *   it is read), and for a database its connected services until it runs
 *   on the target again.
 *
 * While a files item is "preparing" or "importing" its volumes are declared
 * on the target without the workload ({@see preparedVolumes}), so
 * launchroomd creates them for the import.
 */
final class MoveHolds
{
    private const array HOLD_SELF = [MoveItemStatus::Stopping, MoveItemStatus::Exporting, MoveItemStatus::Preparing, MoveItemStatus::Importing];

    /**
     * @return array<int, true> held service instance ids
     */
    public static function instanceIds(): array
    {
        $held = [];
        $items = ServerMoveItem::query()
            ->whereIn('status', array_map(static fn (MoveItemStatus $status): string => $status->value, array_filter(MoveItemStatus::cases(), static fn (MoveItemStatus $status): bool => $status->holdsWriters())))
            ->where('kind', '!=', MoveItemKind::Stateless->value)
            ->get(['id', 'kind', 'status', 'service_instance_id']);

        foreach ($items as $item) {
            foreach (self::heldBy($item) as $id) {
                $held[$id] = true;
            }
        }

        return $held;
    }

    /**
     * The instances $item holds in its current step.
     *
     * @return list<int>
     */
    public static function heldBy(ServerMoveItem $item): array
    {
        if (! $item->status->holdsWriters() || $item->kind === MoveItemKind::Stateless) {
            return [];
        }

        $instance = ServiceInstance::query()->whereKey($item->service_instance_id)->with('service')->first();

        if (! $instance instanceof ServiceInstance) {
            return [];
        }

        $database = DatabaseWorkloads::source($instance) instanceof DatabaseSource;
        $dependents = $database ? RestoreHolds::dependentIds($instance) : [];

        if ($item->kind === MoveItemKind::Dump) {
            return $dependents;
        }

        return in_array($item->status, self::HOLD_SELF, true) ? [$instance->id, ...$dependents] : $dependents;
    }

    /**
     * Volumes of held files items that must exist on $serverId although
     * their service doesn't run there yet ({@see NodeStateContent}).
     *
     * @return list<Volume>
     */
    public static function preparedVolumes(int $serverId): array
    {
        $instances = ServerMoveItem::query()
            ->where('kind', MoveItemKind::Files->value)
            ->whereIn('status', [MoveItemStatus::Preparing->value, MoveItemStatus::Importing->value])
            ->pluck('service_instance_id');

        if ($instances->isEmpty()) {
            return [];
        }

        return array_values(Volume::query()
            ->whereIn('service_instance_id', $instances)
            ->where('server_id', $serverId)
            ->orderBy('id')
            ->get()
            ->all());
    }
}
