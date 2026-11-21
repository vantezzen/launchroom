<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

test('Backups page rows link to the service\'s Backups tab, where restoring happens', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $instance->loadMissing(['service', 'environment.project']);

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/backups')
            ->where('page.groups.1.items.0.restoreHref', "/p/{$instance->environment->project->public_id}/{$instance->environment->public_id}/s/{$instance->service->public_id}/settings/backups"));
});
