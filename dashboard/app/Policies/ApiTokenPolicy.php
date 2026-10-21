<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;

/**
 * Personal access tokens (docs/16 §1): members manage their own; a token is
 * created for a team the user belongs to. Never from a token (no API route
 * manages tokens).
 */
final class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->currentApiToken() instanceof ApiToken;
    }

    public function create(User $user, Team $team): bool
    {
        return ! $user->currentApiToken() instanceof ApiToken && $user->belongsToTeam($team);
    }

    public function delete(User $user, ApiToken $token): bool
    {
        return ! $user->currentApiToken() instanceof ApiToken
            && $token->tokenable_type === $user->getMorphClass()
            && $token->tokenable_id === $user->id;
    }
}
