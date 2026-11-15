<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Something a user opened for themselves (a session, a remote access):
 * lists show the user's own, or everyone's to those the model's policy lets
 * `oversee` the team.
 */
trait OpenedByUser
{
    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user, int $teamId): void
    {
        if (Gate::forUser($user)->denies('oversee', [static::class, $teamId])) {
            $query->where('user_id', $user->id);
        }
    }
}
