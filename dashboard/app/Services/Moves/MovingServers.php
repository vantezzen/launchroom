<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\Moves\ServerMoveStatus;
use App\Models\Moves\ServerMove;

/**
 * Servers that are being moved away from (docs/14 §13.2): the sources of
 * open server moves and their targets. The scheduler drains a source
 * (nothing new is placed there except services pinned by their volumes),
 * and an allow-list naming the source also allows its target, so a service
 * that may only run "there" moves along.
 */
final class MovingServers
{
    /**
     * @return array<int, int> source server id => target server id
     */
    public static function targets(?int $teamId = null): array
    {
        /** @var array<int, int> $map */
        $map = ServerMove::query()
            ->whereIn('status', ServerMoveStatus::values([ServerMoveStatus::Running, ServerMoveStatus::Ready]))
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->pluck('target_server_id', 'source_server_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        return $map;
    }

    public static function isDraining(int $serverId): bool
    {
        return ServerMove::query()
            ->where('source_server_id', $serverId)
            ->whereIn('status', ServerMoveStatus::values([ServerMoveStatus::Running, ServerMoveStatus::Ready]))
            ->exists();
    }
}
