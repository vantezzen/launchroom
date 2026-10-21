<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
});

test('the projects list shows the team’s projects with per-environment status', function (): void {
    $project = Projects::create($this->team, 'Shop');
    Projects::postgres($project);
    Projects::create(Team::factory()->create(), 'Theirs');

    $this->actingAs($this->viewer)->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/index')
            ->has('projects', 1)
            ->where('projects.0.id', $project->public_id)
            ->where('projects.0.name', 'Shop')
            ->where('projects.0.logos', ['postgres'])
            ->where('projects.0.environments.0.name', 'production')
            ->where('projects.0.environments.0.services', 1)
            ->where('projects.0.environments.0.status.state', 'idle')
            ->where('projects.0.environments.0.status.label', 'Not deployed')
            ->where('canCreate', false));

    $this->actingAs($this->developer)->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page->where('canCreate', true));
});

test('developers create projects; viewers can’t', function (): void {
    $this->actingAs($this->viewer)->post(route('projects.store'), ['name' => 'Shop'])->assertForbidden();
    $this->actingAs($this->developer)->post(route('projects.store'), ['name' => ''])->assertSessionHasErrors('name');

    $response = $this->actingAs($this->developer)->post(route('projects.store'), ['name' => 'My Shop']);

    $project = Project::query()->sole();
    $response->assertRedirect(route('projects.show', $project));
    expect($project->team_id)->toBe($this->team->id);

    $this->get(route('projects.show', $project))
        ->assertRedirect(route('projects.canvas', [$project, $project->production()->sole()]));
});

test('project settings: everyone in the team views, developers rename and add environments', function (): void {
    $project = Projects::create($this->team, 'Shop');
    $production = $project->production()->sole();

    $this->actingAs($this->viewer)->get(route('projects.settings', ['project' => $project, 'section' => 'environments']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/settings')
            ->where('section', 'environments')
            ->where('project.id', $project->public_id)
            ->where('environment.id', $production->public_id)
            ->has('allEnvironments', 1)
            ->has('projects', 1)
            ->where('abilities.manage', false)
            ->where('canCreateEnvironment', false));
    $this->actingAs($this->viewer)->get("/p/{$project->public_id}/settings/nope")->assertNotFound();
    $this->actingAs($this->viewer)->patch(route('projects.settings.update', $project), ['name' => 'X'])->assertForbidden();
    $this->actingAs($this->viewer)->post(route('projects.environments.store', $project), ['name' => 'staging'])->assertForbidden();

    $this->actingAs($this->developer)->patch(route('projects.settings.update', $project), ['name' => 'Renamed'])->assertRedirect();
    expect($project->refresh()->name)->toBe('Renamed')
        ->and($project->slug)->toBe('shop')
        ->and(AuditEvent::query()->where('action', AuditAction::ProjectRenamed->value)->exists())->toBeTrue();

    $this->actingAs($this->developer)->post(route('projects.environments.store', $project), ['name' => 'staging', 'baseEnvironmentId' => $production->public_id])
        ->assertRedirect();
    expect($project->environments()->where('name', 'staging')->exists())->toBeTrue();

    // A base environment of another project is not found.
    $other = Projects::create($this->team, 'Other')->production()->sole();
    $this->actingAs($this->developer)->post(route('projects.environments.store', $project), ['name' => 'qa', 'baseEnvironmentId' => $other->public_id])
        ->assertNotFound();
});

test('projects of other teams are not found', function (): void {
    $foreign = Projects::create(Team::factory()->create(), 'Theirs');
    $production = $foreign->production()->sole();

    $this->actingAs($this->developer)->get(route('projects.show', $foreign))->assertNotFound();
    $this->actingAs($this->developer)->get(route('projects.canvas', [$foreign, $production]))->assertNotFound();
    $this->actingAs($this->developer)->get(route('projects.settings', [$foreign]))->assertNotFound();
    $this->actingAs($this->developer)->patch(route('projects.settings.update', $foreign), ['name' => 'Mine'])->assertNotFound();
    expect(Environment::query()->count())->toBe(1);
});
