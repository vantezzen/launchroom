<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Data\Audit\AuditActor;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Tests\Support\Projects;

/*
 * Query budgets of the canvas and the service panel (docs/10 §2.5): the
 * count must not grow with the number of services (no N+1), stays under a
 * fixed budget, and opening a service (`only: ['panel']`) doesn't rebuild
 * the canvas. Raise a budget only with a reason.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    Projects::server($this->team);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = Projects::image($this->project, 'web');
    $this->postgres = Projects::postgres($this->project);
    resolve(CreateConnection::class)->handle($this->web, $this->postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
});

/**
 * The SQL of every query $request runs.
 *
 * @return list<string>
 */
function canvasQueries(Closure $request): array
{
    // Warm up first: the first request of a test creates singletons (instance settings).
    $request();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    DB::disableQueryLog();

    return array_values(array_map(static fn (array $query): string => $query['query'], DB::getQueryLog()));
}

/**
 * Headers of an Inertia partial reload of the canvas page.
 *
 * @return array<string, string>
 */
function panelReloadHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) resolve(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'projects/canvas',
        'X-Inertia-Partial-Data' => 'panel',
    ];
}

/**
 * How often the environment's current spec was built (CurrentEnvironmentSpec
 * reads the project's variables exactly once per build).
 *
 * @param  list<string>  $queries
 */
function specBuilds(array $queries): int
{
    return count(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select * from "variables" where "project_id" = ?')));
}

function addServices(mixed $test): void
{
    foreach (['api', 'worker', 'admin'] as $name) {
        $service = Projects::image($test->project, $name);
        resolve(CreateConnection::class)->handle($service, $test->postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    }

    Projects::postgres($test->project, 'analytics');
}

test('the canvas runs a fixed number of queries', function (): void {
    $url = route('projects.canvas', [$this->project, $this->production]);
    $before = canvasQueries(fn () => $this->actingAs($this->developer)->get($url)->assertOk());

    addServices($this);

    $after = canvasQueries(fn () => $this->actingAs($this->developer)->get($url)->assertOk()->assertInertia(fn ($page) => $page->has('nodes', 6)));

    expect(count($after))->toBe(count($before))
        ->and(count($after))->toBeLessThanOrEqual(48)
        ->and(specBuilds($after))->toBe(1);
});

test('the service panel runs a fixed number of queries and builds the environment spec once', function (string $tab): void {
    $url = $tab === 'settings'
        ? route('projects.services.section', [$this->project, $this->production, $this->web, 'general'])
        : route('projects.services.show', [$this->project, $this->production, $this->web, $tab]);
    $before = canvasQueries(fn () => $this->actingAs($this->developer)->get($url)->assertOk());

    addServices($this);

    $after = canvasQueries(fn () => $this->actingAs($this->developer)->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('panel.tab', $tab)));

    expect(count($after))->toBe(count($before))
        ->and(count($after))->toBeLessThanOrEqual($tab === 'settings' ? 68 : 55)
        ->and(specBuilds($after))->toBe(1);
})->with(['deployments', 'metrics', 'logs', 'settings']);

test('opening a service reloads the panel without rebuilding the canvas', function (): void {
    addServices($this);
    $url = route('projects.services.show', [$this->project, $this->production, $this->web, 'deployments']);

    $queries = canvasQueries(fn () => $this->actingAs($this->developer)
        ->withHeaders(panelReloadHeaders())
        ->get($url)
        ->assertOk()
        ->assertJsonPath('props.panel.serviceId', $this->web->public_id)
        ->assertJsonMissingPath('props.nodes')
        ->assertJsonMissingPath('props.projects'));

    $canvasOnly = array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"canvas_layouts"') || str_contains($sql, 'select "public_id", "name", "icon_path"'));

    expect($canvasOnly)->toBe([])
        ->and(specBuilds($queries))->toBe(1)
        ->and(count($queries))->toBeLessThanOrEqual(26);
});
