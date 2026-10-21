<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use Tests\Support\Projects;

/*
| Shell v2: the home redirect, the team Activity page in the sidebar, the
| project Logs tab and the dev-only design sheet.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->project = Projects::create($this->team, 'Shop');
    $this->production = $this->project->production()->sole();
});

test('the home page sends people to the dashboard', function (): void {
    $this->get(route('home'))->assertRedirect('/dashboard');
});

test('team activity lists only the current team’s projects', function (): void {
    $other = Team::factory()->create();
    Projects::create($other, 'Elsewhere');

    $this->actingAs($this->viewer)->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('activity')
            ->has('projects', 1)
            ->where('projects.0.id', $this->project->public_id)
            ->where('projects.0.name', 'Shop'));
});

test('team activity requires a session', function (): void {
    $this->get(route('activity.index'))->assertRedirect(route('login'));
});

test('the logs tab renders with the project shell for team members', function (): void {
    $this->actingAs($this->viewer)->get(route('projects.logs', [$this->project, $this->production]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/logs')
            ->where('project.id', $this->project->public_id)
            ->where('environment.id', $this->production->public_id));
});

test('the logs tab of another team’s project is not reachable', function (): void {
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)->get(route('projects.logs', [$this->project, $this->production]))
        ->assertNotFound();
});

test('an environment of another project is not found under this project', function (): void {
    $other = Projects::create($this->team, 'Other')->production()->sole();

    $this->actingAs($this->viewer)->get(route('projects.logs', [$this->project, $other]))->assertNotFound();
});

test('the design sheet only exists in local development', function (): void {
    $this->actingAs($this->viewer)->get('/dev/design')->assertNotFound();
});

test('the settings dot appears only for instance owners when a release is cached', function (): void {
    $this->actingAs($this->viewer)->get(route('activity.index'))
        ->assertInertia(fn ($page) => $page->where('updateAvailable', false));
});
