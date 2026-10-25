<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ServerRole;
use App\Models\Server;
use App\Support\Metrics\Cast;

/**
 * Ids of a team's edge servers (route settings changed: re-render them).
 */
final class TeamEdges
{
    /**
     * @return list<int>
     */
    public static function ids(int $teamId): array
    {
        return array_values(array_map(Cast::id(...), Server::query()->where('team_id', $teamId)->whereJsonContains('roles', ServerRole::Edge->value)->pluck('id')->all()));
    }
}
