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

test('the Backups settings page exists for databases only', function (): void {
    $service = $this->database->service;
    $page = fn ($target): string => route('projects.services.section', [$this->project, $this->environment, $target, 'backups']);

    $this->actingAs($this->viewer)->get($page($service))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('panel.tab', 'settings')->where('panel.section', 'backups'));

    $this->actingAs($this->viewer)->get($page($this->web))
        ->assertNotFound();

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->get($page($service))
        ->assertNotFound();
});

test('links to the former Backups tab redirect to the settings page with their query', function (): void {
    $service = $this->database->service;
    $base = "/p/{$this->project->public_id}/{$this->environment->public_id}/s/{$service->public_id}";
    $page = route('projects.services.section', [$this->project, $this->environment, $service, 'backups']);

    $this->actingAs($this->viewer)->get("{$base}/backups?before=2026-10-20T10%3A00%3A00Z")
        ->assertRedirect("{$page}?before=2026-10-20T10%3A00%3A00Z");
    $this->actingAs($this->viewer)->get("{$base}?tab=backups")
        ->assertRedirect($page);

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->get("{$base}/backups")->assertNotFound();
});
