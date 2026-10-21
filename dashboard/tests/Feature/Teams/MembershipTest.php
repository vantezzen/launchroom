<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Team;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->developer = memberOf($this->team, TeamRole::Developer);
});

function membershipOf(object $user, Team $team): Membership
{
    return Membership::query()->where('team_id', $team->id)->where('user_id', $user->id)->sole();
}

test('the team settings page lists members', function (): void {
    $this->actingAs($this->admin)->get(route('team.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/team')->has('members', 3)->where('canManageMembers', true));
});

test('admins change roles up to their own and the change is audited', function (): void {
    $membership = membershipOf($this->developer, $this->team);

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->patch(route('team.members.update', $membership->public_id), ['role' => 'admin'])
        ->assertSessionHasNoErrors();

    expect($membership->refresh()->role)->toBe(TeamRole::Admin)
        ->and(AuditEvent::query()->where('action', AuditAction::MemberRoleChanged->value)->sole()->meta)->toEqual(['from' => 'developer', 'to' => 'admin']);
});

test('admins cannot promote to owner or touch owners', function (): void {
    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->patch(route('team.members.update', membershipOf($this->developer, $this->team)->public_id), ['role' => 'owner'])
        ->assertSessionHasErrors('role');

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->patch(route('team.members.update', membershipOf($this->owner, $this->team)->public_id), ['role' => 'viewer'])
        ->assertForbidden();
});

test('role changes require a recently confirmed password', function (): void {
    $this->actingAs($this->admin)
        ->patch(route('team.members.update', membershipOf($this->developer, $this->team)->public_id), ['role' => 'viewer'])
        ->assertRedirect(route('password.confirm'));
});

test('developers cannot change roles', function (): void {
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($this->developer)->withSession(passwordConfirmed())
        ->patch(route('team.members.update', membershipOf($viewer, $this->team)->public_id), ['role' => 'developer'])
        ->assertForbidden();
});

test('memberships of other teams are not found', function (): void {
    $other = Team::factory()->create();
    $outsider = memberOf($other, TeamRole::Developer);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->patch(route('team.members.update', membershipOf($outsider, $other)->public_id), ['role' => 'viewer'])
        ->assertNotFound();
});

test('the last owner can neither leave nor be removed', function (): void {
    $this->actingAs($this->owner)->delete(route('team.members.destroy', membershipOf($this->owner, $this->team)->public_id))
        ->assertSessionHasErrors('member');

    expect(membershipOf($this->owner, $this->team)->exists)->toBeTrue();
});

test('admins remove members and members can leave', function (): void {
    $this->actingAs($this->admin)->delete(route('team.members.destroy', membershipOf($this->developer, $this->team)->public_id))->assertRedirect();
    expect($this->developer->fresh()?->belongsToTeam($this->team))->toBeFalse()
        ->and($this->developer->fresh()?->current_team_id)->toBeNull();

    $this->actingAs($this->admin)->delete(route('team.members.destroy', membershipOf($this->admin, $this->team)->public_id))->assertRedirect(route('dashboard'));
    expect(Membership::query()->where('user_id', $this->admin->id)->exists())->toBeFalse();
});

test('users switch only to their own teams', function (): void {
    $second = Team::factory()->withMember($this->developer, TeamRole::Viewer)->create();
    $foreign = Team::factory()->create();

    $this->actingAs($this->developer)->put(route('teams.current', $second->public_id))->assertRedirect(route('dashboard'));
    expect($this->developer->fresh()?->current_team_id)->toBe($second->id);

    $this->actingAs($this->developer)->put(route('teams.current', $foreign->public_id))->assertNotFound();
});

test('the shared team context lists the user’s teams and current permissions', function (): void {
    $this->actingAs($this->developer)->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page
            ->where('teams.current.id', $this->team->public_id)
            ->where('teams.current.role', 'developer')
            ->has('teams.all', 1)
            ->where('teams.permissions', ['projects.manage', 'deployments.create', 'variables.write', 'resources.view']));
});
