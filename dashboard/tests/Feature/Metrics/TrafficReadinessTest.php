<?php

declare(strict_types=1);

use App\Actions\Services\StopService;
use App\Data\Audit\AuditActor;
use App\Enums\TeamRole;
use App\Models\Team;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{0: DeploymentUi, 1: string}
 */
function readinessWorld(): array
{
    $world = DeploymentUi::make();

    return [$world, route('projects.analytics-page', [$world->project, $world->production])];
}

test('a live service with an address means Analytics is waiting for requests, with the address to visit', function (): void {
    [$world, $url] = readinessWorld();
    $hostname = $world->web->instances()->sole()->domains()->firstOrFail()->hostname;

    $this->actingAs($world->member(TeamRole::Viewer))->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('readiness.reason', 'waiting')
            ->where('readiness.address', $hostname)
            // The database has no address and is never a cause.
            ->where('readiness.publicServices', [$world->web->public_id])
            ->where('readiness.notServing', []));
});

test('a stopped service is named as the cause, with its status and remedy', function (): void {
    [$world, $url] = readinessWorld();
    $owner = $world->member(TeamRole::Owner);
    resolve(StopService::class)->handle($world->web->instances()->sole(), false, $owner, AuditActor::user($owner));

    $this->actingAs($owner)->get($url)
        ->assertInertia(fn ($page) => $page
            ->where('readiness.reason', 'not_serving')
            ->where('readiness.address', null)
            ->where('readiness.notServing.0.serviceId', $world->web->public_id)
            ->where('readiness.notServing.0.name', 'web')
            ->where('readiness.notServing.0.status.reason', 'stopped')
            ->where('readiness.notServing.0.status.remedy', 'start')
            ->where('readiness.moreNotServing', 0));
});

test('services without an address are their own cause', function (): void {
    [$world, $url] = readinessWorld();
    $world->web->instances()->sole()->domains()->delete();

    $this->actingAs($world->member(TeamRole::Owner))->get($url)
        ->assertInertia(fn ($page) => $page->where('readiness.reason', 'no_address')->where('readiness.publicServices', []));
});

test('team Analytics names the cause across its production environments only', function (): void {
    [$world] = readinessWorld();
    $owner = $world->member(TeamRole::Owner);
    resolve(StopService::class)->handle($world->web->instances()->sole(), false, $owner, AuditActor::user($owner));

    $this->actingAs($owner)->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('readiness.reason', 'not_serving')
            ->where('readiness.notServing.0.projectName', 'shop')
            ->where('readiness.notServing.0.environmentName', 'production'));
});

test('readiness never includes another team\'s services', function (): void {
    [$world] = readinessWorld();
    $other = Team::factory()->create();
    $stranger = memberOf($other, TeamRole::Owner);

    $this->actingAs($stranger)->get(route('analytics.index'))
        ->assertInertia(fn ($page) => $page
            ->where('readiness.reason', 'no_address')
            ->where('readiness.publicServices', [])
            ->where('readiness.notServing', []));
    $this->actingAs($stranger)->get(route('projects.analytics-page', [$world->project, $world->production]))->assertNotFound();
});
