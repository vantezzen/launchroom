<?php

declare(strict_types=1);

namespace App\Services\Holds;

use App\Data\Holds\Hold;
use App\Data\Services\DatabaseSource;
use App\Enums\HoldKind;
use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Models\Moves\ServerMoveItem;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;

/**
 * Services held while a stateful service moves (docs/14 §13.2). Moves
 * redeploy what they hold themselves ({@see HoldKind::blocksDeployments}).
 *
 * - dump (Postgres, MySQL, MongoDB): the database's dependents, from
 *   "stopping" until it is imported on the target (or back on the source).
 *   The database itself keeps running: the dump is read from it and
 *   imported into it.
 * - files (a service with volumes, or Redis/Valkey/ClickHouse): the service
 *   itself until its volumes are copied (its volume must not change while
 *   it is read), and for a database its dependents until it runs on the
 *   target again.
 *
 * While a files item is "preparing" or "importing" its volumes are declared
 * on the target without the workload ({@see preparedVolumes}), so
 * launchroomd creates them for the import.
 */
final class MoveHolds implements HoldSource
{
    private const array HOLD_SELF = [MoveItemStatus::Stopping, MoveItemStatus::Exporting, MoveItemStatus::Preparing, MoveItemStatus::Importing];

    public static function active(): array
    {
        $items = ServerMoveItem::query()
            ->whereIn('status', array_map(static fn (MoveItemStatus $status): string => $status->value, array_values(array_filter(MoveItemStatus::cases(), static fn (MoveItemStatus $status): bool => $status->holdsWriters()))))
            ->where('kind', '!=', MoveItemKind::Stateless->value)
            ->orderBy('id')
            ->get(['id', 'kind', 'status', 'service_instance_id']);

        $holds = [];

        foreach ($items as $item) {
            $instance = ServiceInstance::query()->whereKey($item->service_instance_id)->with('service')->first();

            if ($instance instanceof ServiceInstance) {
                $holds[] = new Hold(HoldKind::Move, self::holds($instance, $item->kind, in_array($item->status, self::HOLD_SELF, true)), "{$instance->service->name} is being moved", 'the move');
            }
        }

        return $holds;
    }

    /**
     * @return list<int>
     */
    public static function heldBy(ServerMoveItem $item): array
    {
        if (! $item->status->holdsWriters()) {
            return [];
        }

        $instance = ServiceInstance::query()->whereKey($item->service_instance_id)->with('service')->first();

        return $instance instanceof ServiceInstance ? self::holds($instance, $item->kind, in_array($item->status, self::HOLD_SELF, true)) : [];
    }

    /**
     * Every instance moving $instance as $kind holds at some point (the
     * move wizard names them before it starts).
     *
     * @return list<int>
     */
    public static function wouldHold(ServiceInstance $instance, MoveItemKind $kind): array
    {
        return self::holds($instance, $kind, true);
    }

    /**
     * Volumes of held files items that must exist on $serverId although
     * their service doesn't run there yet.
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

    /**
     * @return list<int>
     */
    private static function holds(ServiceInstance $instance, MoveItemKind $kind, bool $self): array
    {
        if ($kind === MoveItemKind::Stateless) {
            return [];
        }

        $dependents = DatabaseWorkloads::source($instance) instanceof DatabaseSource ? Holds::dependentsOf($instance) : [];

        return $kind === MoveItemKind::Files && $self ? [$instance->id, ...$dependents] : $dependents;
    }
}
