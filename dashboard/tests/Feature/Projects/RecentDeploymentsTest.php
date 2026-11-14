<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Deployment;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('the projects page lists the team\'s last three deployments, deferred', function (): void {
    $world = DeploymentUi::make();
    // Two more deploys of a changed web: more than three deployments in the team.
    $world->variable('GREETING', 'hi');
    $world->deploy();
    $world->variable('FAREWELL', 'bye');
    $world->deploy();
    expect(Deployment::query()->where('team_id', $world->team->id)->count())->toBeGreaterThan(3);
    $newest = Deployment::query()->where('team_id', $world->team->id)->latest('id')->limit(3)->pluck('public_id')->all();

    $this->actingAs($world->member(TeamRole::Viewer))
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/index')
            ->missing('recentDeployments')
            ->loadDeferredProps('recent', fn (Assert $reload): Assert => $reload
                ->has('recentDeployments', 3)
                ->where('recentDeployments.0.deployment.id', $newest[0])
                ->where('recentDeployments.1.deployment.id', $newest[1])
                ->where('recentDeployments.2.deployment.id', $newest[2])
                ->where('recentDeployments.0.projectName', 'shop')
                ->where('recentDeployments.0.deployment.projectId', $world->project->public_id)
                ->etc())
            ->etc());
});

test('recent deployments never show another team\'s deployments', function (): void {
    $mine = DeploymentUi::make();
    // Another team deploys afterwards: its deployments are the newest overall.
    $other = DeploymentUi::make();
    $theirs = Deployment::query()->where('team_id', $other->team->id)->pluck('public_id')->all();
    $ours = Deployment::query()->where('team_id', $mine->team->id)->pluck('public_id')->all();

    expect($theirs)->not->toBeEmpty();

    $this->actingAs($mine->member(TeamRole::Viewer))
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->loadDeferredProps('recent', function (Assert $reload) use ($ours, $theirs): Assert {
                $ids = array_column(array_column($reload->toArray()['props']['recentDeployments'], 'deployment'), 'id');

                expect($ids)->toHaveCount(count($ours))
                    ->and(array_diff($ids, $ours))->toBe([])
                    ->and(array_intersect($ids, $theirs))->toBe([]);

                return $reload->etc();
            })
            ->etc());
});
