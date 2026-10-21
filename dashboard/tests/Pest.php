<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Feature tests boot the app and run against Postgres (see phpunit.xml).
| Unit tests boot the app too (config, container) but don't touch the DB.
| Arch tests need neither.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A verified user with 2FA who is a member of $team with $role.
 */
function memberOf(Team $team, TeamRole $role): User
{
    $user = User::factory()->withTwoFactor()->create();

    Membership::query()->create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => $role]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return $user->refresh();
}

/**
 * Session state for a recently confirmed password (docs/09 R10).
 *
 * @return array<string, int>
 */
function passwordConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}
