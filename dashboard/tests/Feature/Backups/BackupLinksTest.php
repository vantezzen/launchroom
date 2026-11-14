<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

test('team backup rows link to the service\'s Settings → Backups', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $instance->loadMissing(['service', 'environment.project']);

    $this->actingAs($owner)->get(route('backups.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/backups')
            ->where('schedules.0.href', "/p/{$instance->environment->project->public_id}/{$instance->environment->public_id}/s/{$instance->service->public_id}/settings/backups"));
});
