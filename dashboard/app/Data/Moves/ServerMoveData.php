<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Enums\Moves\MoveItemStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A server move and its services (docs/14 §13).
 */
final class ServerMoveData extends Data
{
    /**
     * @param  list<ServerMoveItemData>  $items
     */
    public function __construct(
        public string $id,
        public ServerMoveStatus $status,
        public MoveServerData $source,
        public MoveServerData $target,
        public bool $controlPlane,
        public bool $edgeAdded,
        public bool $backupFirst,
        public array $items,
        public int $done,
        public int $failed,
        public ?string $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $readyAt,
        public ?CarbonInterface $finishedAt,
    ) {}

    public static function fromMove(ServerMove $move): self
    {
        $move->loadMissing(['source', 'target', 'items.serviceInstance.service', 'items.serviceInstance.environment.project']);
        $running = $move->status === ServerMoveStatus::Running || $move->status === ServerMoveStatus::Ready;

        return new self(
            $move->public_id,
            $move->status,
            MoveServerData::fromServer($move->source),
            MoveServerData::fromServer($move->target),
            $move->control_plane,
            $move->edge_added,
            $move->backupFirst(),
            array_values($move->items->map(static fn (ServerMoveItem $item): ServerMoveItemData => ServerMoveItemData::fromItem($item, $running))->all()),
            $move->items->filter(static fn (ServerMoveItem $item): bool => $item->status->isSettled())->count(),
            $move->items->filter(static fn (ServerMoveItem $item): bool => $item->status === MoveItemStatus::Failed)->count(),
            $move->error,
            $move->created_at,
            $move->ready_at,
            $move->finished_at,
        );
    }
}
