<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\Permission;
use Spatie\LaravelData\Data;

/**
 * Shared Inertia prop: the current team, the switcher list and what the user
 * may do in the current team (UI hints only; the server always re-authorizes).
 */
final class TeamContextData extends Data
{
    /**
     * @param  list<TeamData>  $all
     * @param  list<Permission>  $permissions
     */
    public function __construct(
        public ?TeamData $current,
        public array $all,
        public array $permissions,
        /** May create another team (TeamPolicy::create). */
        public bool $canCreateTeam = false,
    ) {}
}
