<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * Page props that cost queries are built only when asked for (docs/10 §3):
 * live refreshes and panel visits are partial reloads, and a deep link to
 * the Deployments tab arrives with its list.
 */

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array<string, string>
 */
function lazyPropsHeaders(string $component, string $only): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) resolve(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $only,
    ];
}

/**
 * The SQL $request runs.
 *
 * @return list<string>
 */
function lazyPropsQueries(Closure $request): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    DB::disableQueryLog();

    return array_values(array_map(static fn (array $query): string => $query['query'], DB::getQueryLog()));
}

test('a live refresh of the deployment detail does not read its lines', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $world->logs($deployment, ['one', 'two']);
    $this->actingAs($world->member(TeamRole::Viewer));
    $url = route('deployments.show', $world->deploymentArgs($deployment));

    $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
        ->where('detail.deployment.id', $deployment->public_id)
        ->has('logs.lines', 2));

    $queries = lazyPropsQueries(fn () => $this->withHeaders(lazyPropsHeaders('projects/deployments/show', 'detail'))
        ->get($url)
        ->assertOk()
        ->assertJsonPath('props.detail.deployment.id', $deployment->public_id)
        ->assertJsonMissingPath('props.logs'));

    expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"log_chunks"')))->toBe([]);
});

test('a deep link to the Deployments tab arrives with the list the tab shows', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);
    $user = $world->member(TeamRole::Developer);
    $url = route('projects.services.show', [...$world->serviceArgs(), 'tab' => 'deployments']);

    $json = $this->actingAs($user)->getJson(route('deployments.service', $world->serviceArgs()))->assertOk()->json();

    $this->get($url)->assertInertia(fn (Assert $page): Assert => $page
        ->where('panel.tab', 'deployments')
        ->where('deployments.active.id', $active->public_id)
        ->where('deployments.current.id', $active->public_id)
        ->where('deployments.canDeploy', true)
        ->has('deployments.deployments', count($json['deployments'])));

    // The same shape as the endpoint the tab refetches from.
    $page = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) resolve(HandleInertiaRequests::class)->version(request())])
        ->get($url)->json('props.deployments');
    expect(array_keys($page))->toBe(array_keys($json))
        ->and($page['active'])->toBe($json['active']);
});

test('opening the panel from the canvas leaves the list to the client', function (): void {
    $world = DeploymentUi::make();
    $url = route('projects.services.show', [...$world->serviceArgs(), 'tab' => 'deployments']);

    $this->actingAs($world->member(TeamRole::Developer))
        ->withHeaders(lazyPropsHeaders('projects/canvas', 'panel'))
        ->get($url)
        ->assertOk()
        ->assertJsonPath('props.panel.tab', 'deployments')
        ->assertJsonMissingPath('props.deployments');
});

test('other tabs carry no list', function (): void {
    $world = DeploymentUi::make();
    $url = route('projects.services.show', [...$world->serviceArgs(), 'tab' => 'logs']);

    $this->actingAs($world->member(TeamRole::Developer))->get($url)
        ->assertInertia(fn (Assert $page): Assert => $page->where('deployments', null));
});

test('a live refresh of the canvas graph does not build the open panel', function (): void {
    $world = DeploymentUi::make();
    $url = route('projects.services.show', [...$world->serviceArgs(), 'tab' => 'deployments']);
    $this->actingAs($world->member(TeamRole::Developer));

    $queries = lazyPropsQueries(fn () => $this->withHeaders(lazyPropsHeaders('projects/canvas', 'nodes,edges'))
        ->get($url)
        ->assertOk()
        ->assertJsonMissingPath('props.panel'));

    expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"security_findings"')))->toBe([]);
});

test('the project switcher links straight to each production canvas', function (): void {
    $world = DeploymentUi::make();

    $this->actingAs($world->member(TeamRole::Viewer))
        ->get(route('projects.canvas', $world->envArgs()))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('projects.0.id', $world->project->public_id)
            ->where('projects.0.environmentId', $world->production->public_id));
});
