<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Support\Logs\LogSearchQuery;
use Illuminate\Contracts\Database\Query\Builder;
use Tests\Support\DeploymentUi;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;

/*
| Cross-service log search (docs/08 §12): server-side search over an
| environment, every environment of a project, or the whole team (owners
| and admins), with histogram buckets and match counts.
*/

beforeEach(function (): void {
    Projects::useTestKek();
});

/**
 * @param  list<string>  $texts
 */
function searchLines(DeploymentUi $world, Deployment $deployment, array $texts, bool $runtime = true, int $seq = 1): void
{
    $placement = Placement::query()->where('deployment_id', $deployment->id)->first();

    LogChunk::query()->create([
        'team_id' => $deployment->team_id, 'server_id' => $world->server->id, 'deployment_id' => $deployment->id,
        'source_kind' => $runtime && $placement instanceof Placement ? LogSourceKind::Workload : LogSourceKind::Deployment,
        'source_id' => $runtime && $placement instanceof Placement ? $placement->workload_id : $deployment->public_id,
        'seq' => $seq,
        'lines' => array_map(static fn (string $text): array => ['time' => now()->subMinutes(3)->utc()->format('Y-m-d\TH:i:s.v\Z'), 'stream' => 'stdout', 'text' => $text], $texts),
        'created_at' => now()->subMinutes(3),
    ]);
}

function stagingOf(DeploymentUi $world): Environment
{
    $staging = resolve(CreateEnvironment::class)->handle($world->project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($staging, null, AuditActor::system());

    for ($i = 0; $i < 4; $i++) {
        Projects::report($world->server);
    }

    return $staging;
}

function latestIn(Environment $environment, string $service): Deployment
{
    return Deployment::query()->where('environment_id', $environment->id)
        ->whereHas('serviceInstance.service', static fn (Builder $q) => $q->where('name', $service))
        ->latest('id')->firstOrFail();
}

test('the query language parses like the client', function (): void {
    $q = LogSearchQuery::parse('service:api -level:debug level:err "Connection Refused" timeout environment:"my env" nope:x');

    expect($q->positive('service'))->toBe(['api'])
        ->and($q->negative('level'))->toBe(['debug'])
        ->and($q->positive('level'))->toBe(['error'])
        ->and($q->positive('environment'))->toBe(['my env'])
        ->and($q->text)->toBe(['connection refused', 'timeout', 'nope:x']);
});

test('text search, filters, histogram and counts run on the server', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $db = $world->latest($world->postgres);
    searchLines($world, $web, ['GET / 200', 'Error: connect ECONNREFUSED 10.0.0.1:5432', 'GET /health 200', 'WARN 100% of 50_000 used']);
    searchLines($world, $db, ['checkpoint complete', 'FATAL: password authentication failed']);
    $viewer = $world->member(TeamRole::Viewer);
    $url = route('projects.logs.entries', $world->envArgs());

    $page = $this->actingAs($viewer)->getJson($url.'?q='.urlencode('econnrefused'))->assertOk()->json();
    expect(collect($page['entries'])->pluck('message')->all())->toBe(['Error: connect ECONNREFUSED 10.0.0.1:5432'])
        ->and($page['matches'])->toBe(1)
        ->and($page['buckets'])->toHaveCount(60)
        ->and(collect($page['buckets'])->sum('total'))->toBe(1)
        ->and(collect($page['buckets'])->sum('errors'))->toBe(1);

    // Filters: level, service, negation; LIKE wildcards are literal.
    $errors = $this->actingAs($viewer)->getJson($url.'?q='.urlencode('level:error'))->json();
    expect(collect($errors['entries'])->pluck('message')->sort()->values()->all())->toBe(['Error: connect ECONNREFUSED 10.0.0.1:5432', 'FATAL: password authentication failed'])
        ->and($errors['matches'])->toBe(2)
        // The histogram shows every level (errors stacked); matches follow the filter.
        ->and(collect($errors['buckets'])->sum('total'))->toBe(6);
    expect(collect($this->actingAs($viewer)->getJson($url.'?q='.urlencode('service:postgres -level:error'))->json('entries'))->pluck('message')->all())->toBe(['checkpoint complete'])
        ->and(collect($this->actingAs($viewer)->getJson($url.'?q='.urlencode('"100%"'))->json('entries'))->pluck('message')->all())->toBe(['WARN 100% of 50_000 used'])
        ->and($this->actingAs($viewer)->getJson($url.'?q='.urlencode('1%0'))->json('entries'))->toBe([])
        ->and($this->actingAs($viewer)->getJson($url.'?q='.urlencode('5__0'))->json('entries'))->toBe([])
        ->and($this->actingAs($viewer)->getJson($url.'?q='.urlencode('service:nope'))->json('entries'))->toBe([]);

    // Without a query: all lines, histogram from chunk counts.
    $all = $this->actingAs($viewer)->getJson($url)->json();
    expect($all['matches'])->toBe(6)->and(collect($all['buckets'])->sum('total'))->toBe(6)->and(collect($all['buckets'])->sum('errors'))->toBe(2);

    $this->actingAs($viewer)->getJson($url.'?q='.str_repeat('x', 513))->assertUnprocessable();
});

test('a project-wide search spans its environments and says where each line is from', function (): void {
    $world = DeploymentUi::make();
    $staging = stagingOf($world);
    searchLines($world, latestIn($world->production, 'web'), ['timeout in production']);
    searchLines($world, latestIn($staging, 'web'), ['timeout in staging'], seq: 2);
    $viewer = $world->member(TeamRole::Viewer);

    $url = route('projects.logs.search', ['project' => $world->project->public_id]);
    $entries = collect($this->actingAs($viewer)->getJson($url.'?q=timeout')->assertOk()->json('entries'));
    expect($entries->pluck('environment', 'message')->all())->toBe(['timeout in production' => 'production', 'timeout in staging' => 'staging'])
        ->and(collect($this->actingAs($viewer)->getJson($url.'?q='.urlencode('timeout environment:staging'))->json('entries'))->pluck('message')->all())->toBe(['timeout in staging']);

    // The environment's own Logs tab stays in its environment.
    expect(collect($this->actingAs($viewer)->getJson(route('projects.logs.entries', $world->envArgs()).'?q=timeout')->json('entries'))->pluck('message')->all())->toBe(['timeout in production']);
});

test('team-wide search is for owners and admins and never leaves the team', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make();
    searchLines($world, $world->latest($world->web), ['needle in shop']);
    searchLines($other, $other->latest($other->web), ['needle elsewhere']);
    $url = route('logs.entries');

    $admin = $world->member(TeamRole::Admin);
    $entries = collect($this->actingAs($admin)->getJson($url.'?q=needle')->assertOk()->json('entries'));
    expect($entries->pluck('message')->all())->toBe(['needle in shop'])
        ->and($entries->first()['project'])->toBe('shop');
    $this->actingAs($admin)->get(route('logs.index'))->assertOk();

    foreach ([TeamRole::Developer, TeamRole::Viewer] as $role) {
        $member = $world->member($role);
        $this->actingAs($member)->getJson($url)->assertForbidden();
        $this->actingAs($member)->get(route('logs.index'))->assertForbidden();
    }

    // Another team's project and environment are not reachable.
    $outsider = $other->member(TeamRole::Owner);
    $this->actingAs($outsider)->getJson(route('projects.logs.search', ['project' => $world->project->public_id]))->assertNotFound();
    $this->app['auth']->forgetGuards();
    expect($this->getJson($url)->status())->toBeIn([401, 404]);
});

test('ingested chunks carry their scope, runtime flag, counts and line levels', function (): void {
    $world = DeploymentUi::make();
    $daemon = new FakeDaemon;
    $world->server->forceFill(['signing_public_key' => $daemon->signingKey()])->save();
    $daemon->as($world->server->public_id);
    $web = $world->latest($world->web);
    $placement = Placement::query()->where('deployment_id', $web->id)->firstOrFail();

    $daemon->send($this, 'POST', '/daemon/v1/logs', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
        'source' => ['kind' => 'workload', 'id' => $placement->workload_id], 'seq' => 1_700_000_000_000_000,
        'lines' => [
            ['time' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'), 'stream' => 'stdout', 'text' => 'GET / 200'],
            ['time' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'), 'stream' => 'stderr', 'text' => 'Unhandled exception: boom'],
        ],
    ]]])->assertNoContent();

    $chunk = LogChunk::query()->where('source_id', $placement->workload_id)->sole();
    expect($chunk->runtime)->toBeTrue()
        ->and($chunk->project_id)->toBe($world->project->id)
        ->and($chunk->environment_id)->toBe($world->production->id)
        ->and($chunk->service_id)->toBe($world->web->id)
        ->and($chunk->line_count)->toBe(2)
        ->and($chunk->error_count)->toBe(1)
        ->and($chunk->bytes)->toBeGreaterThan(0)
        ->and(array_column($chunk->lines, 'level'))->toBe(['info', 'error']);

    $entries = $this->actingAs($world->member(TeamRole::Viewer))->getJson(route('projects.logs.entries', $world->envArgs()).'?source=runtime&q=boom')->json('entries');
    expect($entries)->toHaveCount(1)->and($entries[0])->toMatchArray(['level' => 'error', 'source' => 'runtime', 'service' => 'web', 'replica' => '1', 'stream' => 'stderr']);
});
