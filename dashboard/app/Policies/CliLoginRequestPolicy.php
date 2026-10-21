<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CliLoginStatus;
use App\Models\ApiToken;
use App\Models\CliLoginRequest;
use App\Models\User;

/**
 * CLI sign-in requests (docs/16 §4): any signed-in member who has the user
 * code may look at a pending request and answer it (into their own teams
 * only, checked by the action). Never from an API token.
 */
final class CliLoginRequestPolicy
{
    public function view(User $user, CliLoginRequest $request): bool
    {
        return ! $user->currentApiToken() instanceof ApiToken
            && ($request->status === CliLoginStatus::Pending || $request->user_id === $user->id);
    }

    public function answer(User $user, CliLoginRequest $request): bool
    {
        return ! $user->currentApiToken() instanceof ApiToken
            && $request->status === CliLoginStatus::Pending
            && ! $request->isExpired()
            && $user->teams()->exists();
    }
}
