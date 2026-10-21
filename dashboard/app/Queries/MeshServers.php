<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;

/**
 * The servers that belong in the mesh: approved and not revoked, across all
 * teams of the instance (one install = one tailnet, docs/05 §2).
 */
final class MeshServers
{
    /**
     * @return Builder<Server>
     */
    public static function query(): Builder
    {
        return Server::query()
            ->whereNull('revoked_at')
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value]);
    }

    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, self::query()->orderBy('id')->pluck('id')->all()));
    }
}
