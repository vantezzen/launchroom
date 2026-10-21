<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, , , $this->database] = Backups::runningPostgres();
    $this->environment = $this->database->environment;
    $this->project = $this->environment->project;
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->web = Projects::image($this->project, 'web');
});

test('database nodes carry their backup health; other nodes don’t', function (): void {
    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->environment]))
        ->assertInertia(fn ($page) => $page
            ->where('nodes.0.kind', 'database')
            ->where('nodes.0.backup.status', 'unconfigured')
            ->where('nodes.1.kind', 'image')
            ->where('nodes.1.backup', null));

    $owner = memberOf($this->team, TeamRole::Owner);
    Backups::destination($this->team, $owner, makeDefault: true);

    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->environment]))
        ->assertInertia(fn ($page) => $page
            ->where('nodes.0.backup.status', 'none')
            ->where('nodes.0.backup.lastSuccessfulAt', null)
            ->where('nodes.0.backup.nextRunAt', fn ($next): bool => is_string($next)));
});

test('the Backups panel tab exists for databases only', function (): void {
    $service = $this->database->service;

    $this->actingAs($this->viewer)->get(route('projects.services.show', [$this->project, $this->environment, $service, 'backups']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('panel.tab', 'backups')->where('panel.settings', null));

    $this->actingAs($this->viewer)->get(route('projects.services.show', [$this->project, $this->environment, $this->web, 'backups']))
        ->assertNotFound();

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->get(route('projects.services.show', [$this->project, $this->environment, $service, 'backups']))
        ->assertNotFound();
});
