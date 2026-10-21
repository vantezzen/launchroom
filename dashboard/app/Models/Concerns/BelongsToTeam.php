<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant-owned model (docs/07 "Tenancy"): it has a `team_id`, and lookups
 * from user input go through the user's teams (App\Http\Routing\TeamScopedBindings),
 * never through a bare id (docs/09 R7).
 *
 * @phpstan-require-extends Model
 *
 * @property int $team_id
 * @property-read Team $team
 */
trait BelongsToTeam
{
    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
