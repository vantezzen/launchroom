<?php

declare(strict_types=1);

use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\CanvasLayout;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Team;
use App\Queries\DeploymentStats;
use App\Queries\ProjectSummaries;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('projects get palette slots in creation order, reusing the least-used slot', function (): void {
    $team = Team::factory()->create();
    $slots = [];

    for ($i = 0; $i < 9; $i++) {
        $slots[] = Projects::create($team, "p{$i}")->color_slot;
    }

    expect($slots)->toBe([0, 1, 2, 3, 4, 5, 6, 7, 0]);

    // Slot 3 is freed (its project moved to 1): the next project takes 3.
    Project::query()->where('team_id', $team->id)->where('color_slot', 3)->update(['color_slot' => 1]);
    expect(Projects::create($team, 'next')->color_slot)->toBe(3)
        // Another team starts at 0.
        ->and(Projects::create(Team::factory()->create(), 'other')->color_slot)->toBe(0);
});

test('the projects page carries the card data: slot, domain, last deploy, 14 days and a mini canvas', function (): void {
    $world = DeploymentUi::make();
    CanvasLayout::query()->where('environment_id', $world->production->id)->update(['positions' => json_encode([
        $world->web->public_id => ['x' => 0, 'y' => 0],
        $world->postgres->public_id => ['x' => 400, 'y' => 200],
    ])]);
    $latest = Deployment::query()->whereIn('environment_id', [$world->production->id])->latest('id')->firstOrFail();

    $this->actingAs($world->member(TeamRole::Viewer))
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/index')
            ->where('projects.0.id', $world->project->public_id)
            ->where('projects.0.colorSlot', 0)
            ->where('projects.0.lastDeployment.id', $latest->public_id)
            ->where('projects.0.lastDeployment.environmentId', $world->production->public_id)
            ->has('projects.0.deploys', 14)
            ->where('projects.0.deploys.13.date', now('UTC')->format('Y-m-d'))
            ->where('projects.0.deploys.13.total', 2)
            ->where('projects.0.deploys.13.failed', 0)
            ->where('projects.0.deploys.0.total', 0)
            ->where('projects.0.canvas.positioned', true)
            ->has('projects.0.canvas.nodes', 2)
            ->where('projects.0.canvas.edges.0.type', 'connection')
            ->where('projects.0.canvas.edges.0.source', $world->web->public_id)
            ->where('projects.0.canvas.edges.0.target', $world->postgres->public_id)
            ->etc());

    $summary = resolve(ProjectSummaries::class)->for($world->team)[0];
    $nodes = collect($summary->canvas?->nodes)->keyBy('id');

    // Centres normalised to 0–1 within the laid-out box (incl. node size).
    expect($nodes[$world->web->public_id]->x)->toBeLessThan($nodes[$world->postgres->public_id]->x)
        ->and($nodes[$world->web->public_id]->y)->toBeGreaterThan(0.0)
        ->and($nodes[$world->postgres->public_id]->x)->toBeLessThan(1.0)
        ->and($nodes[$world->web->public_id]->name)->toBe($world->web->name)
        ->and($summary->primaryDomain)->toBe($world->web->instances()->first()?->domains()->first()?->hostname);
});

test('a project without services or deployments has empty card data', function (): void {
    $team = Team::factory()->create();
    Projects::create($team, 'empty');

    $summary = resolve(ProjectSummaries::class)->for($team)[0];

    expect($summary->lastDeployment)->toBeNull()
        ->and($summary->canvas)->toBeNull()
        ->and($summary->primaryDomain)->toBeNull()
        ->and($summary->deploys)->toHaveCount(14)
        ->and(collect($summary->deploys)->sum('total'))->toBe(0);
});

test('project summaries use a fixed number of queries', function (): void {
    $world = DeploymentUi::make();
    $count = function () use ($world): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        resolve(ProjectSummaries::class)->for($world->team);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $one = $count();

    foreach (['two', 'three', 'four'] as $name) {
        $project = Projects::create($world->team, $name);
        Projects::image($project, 'web');
        Projects::postgres($project);
    }

    expect($count())->toBe($one);
});

test('deployment stats count the week, failures and medians per service', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $stats = resolve(DeploymentStats::class);

    $summary = $stats->summary($world->team->id, [$world->production->id]);

    expect($summary->weekTotal)->toBe(2)
        ->and($summary->weekFailed)->toBe(0)
        ->and($summary->medianDurationSeconds)->not->toBeNull()
        ->and($summary->services)->toHaveCount(2);

    Deployment::query()->whereKey($web->id)->update(['status' => DeploymentStatus::Failed->value, 'failure_class' => DeploymentFailureClass::BuildError->value]);
    $summary = $stats->summary($world->team->id, [$world->production->id], $world->web->id);

    expect($summary->weekTotal)->toBe(1)
        ->and($summary->weekFailed)->toBe(1)
        ->and($summary->medianDurationSeconds)->toBeNull()
        ->and($summary->services[0]->serviceId)->toBe($world->web->public_id)
        ->and($stats->summary($world->team->id, [])->weekTotal)->toBe(0)
        // Other teams' environments are never counted.
        ->and($stats->summary(Team::factory()->create()->id, [$world->production->id])->weekTotal)->toBe(0);
});

test('build history lists finished deployments oldest first with build durations', function (): void {
    $world = DeploymentUi::make();
    $first = $world->latest($world->web);
    $first->forceFill(['plan' => ['durationMs' => 81234]])->save();

    $history = resolve(DeploymentStats::class)->buildHistory($first->serviceInstance);

    expect($history)->not->toBeEmpty()
        ->and($history[0]->id)->toBe($first->public_id)
        ->and($history[0]->buildDurationMs)->toBe(81234)
        ->and(resolve(DeploymentStats::class)->buildHistory($world->latest($world->postgres)->serviceInstance)[0]->buildDurationMs)->toBeNull();
});

test('the deployment pages expose stats, build history and explained failures', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    Deployment::query()->whereKey($deployment->id)->update([
        'status' => DeploymentStatus::Failed->value,
        'failure_class' => DeploymentFailureClass::HealthTimeout->value,
        'failure_message' => 'The new version did not become healthy in time: connection refused; the app listens on 5173, not 80',
    ]);
    $viewer = $world->member(TeamRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('deployments.show', $world->deploymentArgs($deployment)))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('detail.failureExplanation.key', 'port_mismatch')
            ->where('detail.failureExplanation.action', 'set_port')
            ->where('detail.failureExplanation.params.port', 5173)
            ->where('detail.buildHistory.0.id', $deployment->public_id)
            ->etc());

    $this->actingAs($viewer)
        ->get(route('deployments.project', ['project' => $world->project->public_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('stats.weekTotal', 2)
            ->where('stats.weekFailed', 1)
            ->has('stats.services', 2)
            ->etc());

    // A healthy deployment has no explanation.
    $this->actingAs($viewer)
        ->get(route('deployments.show', $world->deploymentArgs($world->latest($world->postgres))))
        ->assertInertia(fn (Assert $page): Assert => $page->where('detail.failureExplanation', null)->etc());
});
