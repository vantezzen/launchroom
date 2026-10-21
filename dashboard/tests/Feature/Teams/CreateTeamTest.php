<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Models\User;

beforeEach(function (): void {
    $this->team = Team::factory()->create(['name' => 'Acme']);
});

test('an owner or admin creates a team, owns it and switches to it', function (TeamRole $role): void {
    $user = memberOf($this->team, $role);

    $this->actingAs($user)->post(route('teams.store'), ['name' => 'Client work'])
        ->assertRedirect(route('projects.index'))
        ->assertSessionHasNoErrors();

    $team = Team::query()->where('name', 'Client work')->sole();
    $user->refresh();

    expect($user->current_team_id)->toBe($team->id)
        ->and($user->roleIn($team))->toBe(TeamRole::Owner)
        ->and($team->personal)->toBeFalse()
        // Still a member of the old team with the old role.
        ->and($user->roleIn($this->team))->toBe($role)
        ->and(AuditEvent::query()->where('action', AuditAction::TeamCreated->value)->where('team_id', $team->id)->count())->toBe(1);

    // The switcher shows both, with the new one current.
    $this->get(route('projects.index'))->assertInertia(fn ($page) => $page
        ->where('teams.current.name', 'Client work')
        ->where('teams.current.role', 'owner')
        ->has('teams.all', 2)
        ->where('teams.canCreateTeam', true));
})->with([TeamRole::Owner, TeamRole::Admin]);

test('instance owners may always create teams', function (): void {
    $owner = memberOf($this->team, TeamRole::Viewer);
    $owner->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($owner)->post(route('teams.store'), ['name' => 'Ops'])->assertRedirect(route('projects.index'));

    expect(Team::query()->where('name', 'Ops')->exists())->toBeTrue();
});

test('developers and viewers cannot create teams and see no entry for it', function (TeamRole $role): void {
    $user = memberOf($this->team, $role);

    $this->actingAs($user)->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->where('teams.canCreateTeam', false));

    $this->actingAs($user)->post(route('teams.store'), ['name' => 'Mine'])->assertForbidden();

    expect(Team::query()->count())->toBe(1);
})->with([TeamRole::Developer, TeamRole::Viewer]);

test('the name is required, bounded and free of control characters', function (array $input, string $field): void {
    $this->actingAs(memberOf($this->team, TeamRole::Owner))
        ->post(route('teams.store'), $input)
        ->assertSessionHasErrors($field);

    expect(Team::query()->count())->toBe(1);
})->with([
    'missing' => [[], 'name'],
    'blank' => [['name' => '   '], 'name'],
    'too long' => [['name' => str_repeat('a', 65)], 'name'],
    'control character' => [['name' => "Acme\u{0007}"], 'name'],
]);

test('guests cannot create teams', function (): void {
    $this->post(route('teams.store'), ['name' => 'x'])->assertRedirect(route('login'));
    expect(User::query()->count())->toBe(0);
});
