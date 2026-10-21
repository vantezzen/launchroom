<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\GitHubFake;

/*
| Role × own/other team for every Git policy ability (docs/10 §2.4):
| GitHub App settings follow `servers.manage` (owner, admin), the repo picker
| follows `projects.manage` (owner, admin, developer), viewing follows
| `resources.view`.
*/

beforeEach(fn () => GitHubFake::setUp());

/**
 * @return array<string, bool>
 */
function gitAbilitiesFor(User $user, Team $team): array
{
    $app = GitHubFake::app($team, random_int(1, 1_000_000));
    $installation = GitHubFake::installation($app, random_int(1, 1_000_000));
    $repository = GitHubFake::repository($installation);
    $gate = Gate::forUser($user);

    return [
        'app.viewAny' => $gate->allows('viewAny', [GitHubApp::class, $team]),
        'app.view' => $gate->allows('view', $app),
        'app.create' => $gate->allows('create', [GitHubApp::class, $team]),
        'app.delete' => $gate->allows('delete', $app),
        'installation.view' => $gate->allows('view', $installation),
        'installation.create' => $gate->allows('create', [GitHubInstallation::class, $team]),
        'installation.refresh' => $gate->allows('refresh', $installation),
        'repository.viewAny' => $gate->allows('viewAny', [GitHubRepository::class, $team]),
        'repository.view' => $gate->allows('view', $repository),
    ];
}

dataset('git roles', [
    'owner' => [TeamRole::Owner],
    'admin' => [TeamRole::Admin],
    'developer' => [TeamRole::Developer],
    'viewer' => [TeamRole::Viewer],
]);

test('own team: Git abilities follow the role matrix', function (TeamRole $role): void {
    $team = Team::factory()->create();
    $user = memberOf($team, $role);

    $manage = in_array($role, [TeamRole::Owner, TeamRole::Admin], true);
    $projects = $role !== TeamRole::Viewer;

    expect(gitAbilitiesFor($user, $team))->toBe([
        'app.viewAny' => true,
        'app.view' => true,
        'app.create' => $manage,
        'app.delete' => $manage,
        'installation.view' => true,
        'installation.create' => $manage,
        'installation.refresh' => $manage,
        'repository.viewAny' => $projects,
        'repository.view' => $projects,
    ]);
})->with('git roles');

test('other team: nothing is allowed', function (TeamRole $role): void {
    $user = memberOf(Team::factory()->create(), $role);

    expect(array_filter(gitAbilitiesFor($user, Team::factory()->create())))->toBe([]);
})->with('git roles');

test('removed installations cannot be refreshed', function (): void {
    $team = Team::factory()->create();
    $installation = GitHubFake::installation(GitHubFake::app($team));
    $installation->forceFill(['removed_at' => now()])->save();

    expect(Gate::forUser(memberOf($team, TeamRole::Owner))->allows('refresh', $installation))->toBeFalse();
});
