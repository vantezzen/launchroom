<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;

/**
 * The API token behind the request and its team (docs/16 §1.1). Route
 * parameters are bound through the team-scoped bindings, narrowed to the
 * token's team; nested ones are scoped to each other by RouteScope.
 */
trait ScopesApiRoutes
{
    protected function token(User $user): ApiToken
    {
        return $user->currentApiToken() ?? throw new AuthenticationException;
    }

    protected function team(User $user): Team
    {
        $token = $this->token($user);
        $token->loadMissing('team');

        return $token->team;
    }
}
