<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
| Role × own/other team for every policy ability (docs/10 §2.4). Expected
| values come from the matrix in docs/09 R9.
*/

dataset('roles', [
    'owner' => [TeamRole::Owner],
    'admin' => [TeamRole::Admin],
    'developer' => [TeamRole::Developer],
    'viewer' => [TeamRole::Viewer],
]);

function abilitiesFor(User $user, Team $team): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $pending = Server::factory()->create(['team_id' => $team->id]);
    $online = Server::factory()->online()->create(['team_id' => $team->id]);
    $enrollment = ServerEnrollment::query()->create(['team_id' => $team->id, 'token_hash' => hash('sha256', random_bytes(8)), 'roles' => [], 'expires_at' => now()->addHour()]);
    $developerMembership = memberOf($team, TeamRole::Developer)->memberships()->sole();
    $invitation = TeamInvitation::query()->create(['team_id' => $team->id, 'email' => fake()->email(), 'role' => TeamRole::Viewer, 'token_hash' => hash('sha256', random_bytes(8)), 'expires_at' => now()->addDay()]);

    $gate = Gate::forUser($user);

    return [
        'team.view' => $gate->allows('view', $team),
        'team.update' => $gate->allows('update', $team),
        'team.manageMembers' => $gate->allows('manageMembers', $team),
        'team.switchTo' => $gate->allows('switchTo', $team),
        'membership.update' => $gate->allows('update', $developerMembership),
        'membership.delete' => $gate->allows('delete', $developerMembership),
        'invitation.create' => $gate->allows('create', [TeamInvitation::class, $team]),
        'invitation.delete' => $gate->allows('delete', $invitation),
        'server.viewAny' => $gate->allows('viewAny', [Server::class, $team]),
        'server.view' => $gate->allows('view', $server),
        'server.create' => $gate->allows('create', [Server::class, $team]),
        'server.approve' => $pending->status === ServerStatus::PendingApproval && $gate->allows('approve', $pending),
        'server.revoke' => $gate->allows('revoke', $online),
        'server.updateRoles' => $gate->allows('updateRoles', $online),
        'enrollment.view' => $gate->allows('view', $enrollment),
    ];
}

test('own team: abilities follow the role matrix', function (TeamRole $role): void {
    $team = Team::factory()->create();
    $user = memberOf($team, $role);

    $manage = in_array($role, [TeamRole::Owner, TeamRole::Admin], true);
    // Developers outrank the developer target only if they could manage members, which they can't.
    $expected = [
        'team.view' => true,
        'team.update' => $role === TeamRole::Owner,
        'team.manageMembers' => $manage,
        'team.switchTo' => true,
        'membership.update' => $manage,
        'membership.delete' => $manage,
        'invitation.create' => $manage,
        'invitation.delete' => $manage,
        'server.viewAny' => true,
        'server.view' => true,
        'server.create' => $manage,
        'server.approve' => $manage,
        'server.revoke' => $manage,
        'server.updateRoles' => $manage,
        'enrollment.view' => $manage,
    ];

    expect(abilitiesFor($user, $team))->toBe($expected);
})->with('roles');

test('other team: every ability is denied whatever the role', function (TeamRole $role): void {
    $user = memberOf(Team::factory()->create(), $role);

    expect(array_filter(abilitiesFor($user, Team::factory()->create())))->toBe([]);
})->with('roles');

test('the role matrix grants exactly the documented permissions', function (): void {
    expect(TeamRole::Owner->permissions())->toBe(Permission::cases())
        ->and(TeamRole::Admin->can(Permission::ManageTeam))->toBeFalse()
        ->and(TeamRole::Admin->can(Permission::ManageServers))->toBeTrue()
        ->and(TeamRole::Developer->can(Permission::Deploy))->toBeTrue()
        ->and(TeamRole::Developer->can(Permission::RevealSecrets))->toBeFalse()
        ->and(TeamRole::Developer->can(Permission::ManageServers))->toBeFalse()
        ->and(TeamRole::Viewer->permissions())->toBe([Permission::View]);
});

test('nobody can grant a role above their own', function (): void {
    expect(TeamRole::Admin->canGrant(TeamRole::Owner))->toBeFalse()
        ->and(TeamRole::Admin->canGrant(TeamRole::Admin))->toBeTrue()
        ->and(TeamRole::Owner->canGrant(TeamRole::Owner))->toBeTrue()
        ->and(TeamRole::Developer->canGrant(TeamRole::Viewer))->toBeFalse();
});

test('members cannot edit their own membership but may leave', function (): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);
    $own = Membership::query()->where('user_id', $admin->id)->sole();

    expect(Gate::forUser($admin)->allows('update', $own))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $own))->toBeTrue();
});
