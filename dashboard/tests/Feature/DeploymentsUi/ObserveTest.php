<?php

declare(strict_types=1);

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Queries\LogTails;
use App\Support\Logs\LogLevel;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeploymentUi;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;

/*
| The project log explorer and its live tails, the
| one-click fix of an explained failure and the team activity feed.
*/

beforeEach(function (): void {
    Projects::useTestKek();
});

/**
 * @param  list<string>  $texts
 */
function obsTaskLogs(DeploymentUi $world, Deployment $deployment, DaemonTaskType $type, array $texts, ?string $workload = null): DaemonTask
{
    $task = DaemonTask::query()->create([
        'team_id' => $deployment->team_id, 'server_id' => $world->server->id, 'pinned_to_server' => true,
        'type' => $type, 'spec' => (object) ($workload === null ? [] : ['workload' => $workload, 'tail' => 0, 'follow' => true]),
        'deployment_id' => $deployment->id,
    ]);

    LogChunk::query()->create([
        'team_id' => $deployment->team_id, 'server_id' => $world->server->id, 'daemon_task_id' => $task->id,
        'source_kind' => LogSourceKind::Task, 'source_id' => $task->public_id, 'seq' => 1,
        'lines' => array_map(static fn (string $text): array => ['time' => now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:s.v\Z'), 'stream' => 'stdout', 'text' => $text], $texts),
        'created_at' => now(),
    ]);

    return $task;
}

test('the log explorer returns build and runtime lines of the environment with levels', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $placement = Placement::query()->where('deployment_id', $web->id)->firstOrFail();
    obsTaskLogs($world, $web, DaemonTaskType::Build, ['$ pnpm build', 'ERR_PNPM_OUTDATED_LOCKFILE Cannot install']);
    obsTaskLogs($world, $web, DaemonTaskType::Logs, ['GET / 200', 'WARN slow query'], $placement->workload_id);
    $world->logs($web, ['Health check passed on replica 1'], 9, 'health');
    $viewer = $world->member(TeamRole::Viewer);
    $url = route('projects.logs.entries', $world->envArgs());

    $page = $this->actingAs($viewer)->getJson($url)->assertOk()->json();
    $texts = collect($page['entries'])->pluck('message');

    expect($texts)->toContain('ERR_PNPM_OUTDATED_LOCKFILE Cannot install')
        ->and($texts)->toContain('GET / 200')
        ->and(collect($page['entries'])->firstWhere('message', 'ERR_PNPM_OUTDATED_LOCKFILE Cannot install'))->toMatchArray(['level' => 'error', 'source' => 'build', 'service' => 'web'])
        ->and(collect($page['entries'])->firstWhere('message', 'GET / 200'))->toMatchArray(['level' => 'info', 'source' => 'runtime', 'replica' => '1'])
        ->and(collect($page['entries'])->firstWhere('message', 'WARN slow query')['level'])->toBe('warning')
        ->and($page['markers'])->not->toBeEmpty();

    // The Logs tab asks for runtime: build output stays on its deployment,
    // rollout and health events of the deployment stay in.
    $runtime = $this->actingAs($viewer)->getJson($url.'?source=runtime')->json('entries');
    expect(collect($runtime)->pluck('source')->unique()->all())->toBe(['runtime'])
        ->and(collect($runtime)->pluck('message'))->not->toContain('ERR_PNPM_OUTDATED_LOCKFILE Cannot install')
        ->and(collect($runtime)->pluck('message'))->toContain('Health check passed on replica 1')
        ->and(collect($runtime)->pluck('message'))->toContain('GET / 200');
    $build = $this->actingAs($viewer)->getJson($url.'?source=build')->json('entries');
    expect(collect($build)->pluck('message')->all())->toBe(['$ pnpm build', 'ERR_PNPM_OUTDATED_LOCKFILE Cannot install']);

    $postgres = $this->actingAs($viewer)->getJson($url.'?service='.$world->postgres->public_id)->json('entries');
    expect(collect($postgres)->pluck('service')->unique()->all())->not->toContain('web');

    // Live polling: nothing newer than the cursor, then only the new chunk.
    expect($this->actingAs($viewer)->getJson($url.'?after='.$page['cursor'])->json('entries'))->toBe([]);
    obsTaskLogs($world, $web, DaemonTaskType::Build, ['later']);
    expect(collect($this->actingAs($viewer)->getJson($url.'?after='.$page['cursor'])->json('entries'))->pluck('message')->all())->toBe(['later']);

    $this->actingAs($viewer)->getJson($url.'?range=1y')->assertUnprocessable();
});

test('the log explorer of another team’s environment is not reachable', function (): void {
    $world = DeploymentUi::make();
    $outsider = DeploymentUi::make()->member(TeamRole::Owner);

    $this->actingAs($outsider)->getJson(route('projects.logs.entries', $world->envArgs()))->assertNotFound();
    $this->actingAs($outsider)->postJson(route('projects.logs.tail', $world->envArgs()))->assertNotFound();
    expect($this->getJson(route('projects.logs.entries', $world->envArgs()))->status())->toBeIn([401, 404]);
});

test('live tails follow each running workload, renew and stop per viewer', function (): void {
    $world = DeploymentUi::make();
    $viewer = $world->member(TeamRole::Viewer);
    $other = $world->member(TeamRole::Developer);
    // postgres and web, one replica each, both live.
    $workloads = 2;
    expect(Placement::query()->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('environment_id', $world->production->id))->count())->toBe($workloads);

    $this->actingAs($viewer)->postJson(route('projects.logs.tail', $world->envArgs()))->assertOk()->assertJson(['following' => $workloads]);
    $tasks = DaemonTask::query()->where('type', DaemonTaskType::Logs->value)->where('purpose', 'tail:'.$viewer->id)->get();

    expect($tasks)->toHaveCount($workloads)
        ->and($tasks->every(fn (DaemonTask $task): bool => $task->pinned_to_server && $task->server_id === $world->server->id && $task->spec->follow === true && $task->spec->tail === 200 && $task->deployment_id !== null))->toBeTrue();

    // Renewing a fresh tail keeps it; an old one is replaced by a tail that starts now.
    $this->actingAs($viewer)->postJson(route('projects.logs.tail', $world->envArgs()))->assertOk();
    expect(DaemonTask::query()->where('purpose', 'tail:'.$viewer->id)->count())->toBe($workloads);

    $this->travel(LogTails::SECONDS)->seconds();
    Projects::report($world->server);
    $this->actingAs($viewer)->postJson(route('projects.logs.tail', $world->envArgs()))->assertOk();
    $renewed = DaemonTask::query()->where('purpose', 'tail:'.$viewer->id)->where('status', DaemonTaskStatus::Pending->value)->get();
    expect($renewed)->toHaveCount($workloads)
        ->and($renewed->every(fn (DaemonTask $task): bool => $task->spec->tail === 0 && isset($task->spec->since)))->toBeTrue();

    // One service only.
    $this->actingAs($other)->postJson(route('projects.logs.tail', $world->envArgs()), ['service' => $world->web->public_id])->assertOk()->assertJson(['following' => 1]);

    // Stopping cancels this viewer's tails only.
    $this->actingAs($viewer)->deleteJson(route('projects.logs.tail.stop', $world->envArgs()))->assertOk();
    expect(LogTails::open($world->production, $viewer)->count())->toBe(0)
        ->and(LogTails::open($world->production, $other)->count())->toBe(1)
        ->and($this->actingAs($other)->getJson(route('projects.logs.entries', $world->envArgs()))->json('tailing'))->toBe(1);
});

test('lines a live tail ships through the daemon endpoint reach the explorer, blank lines included', function (): void {
    $world = DeploymentUi::make();
    $daemon = new FakeDaemon;
    $world->server->forceFill(['signing_public_key' => $daemon->signingKey()])->save();
    $daemon->as($world->server->public_id);
    $viewer = $world->member(TeamRole::Viewer);
    $url = route('projects.logs.entries', $world->envArgs());
    $cursor = $this->actingAs($viewer)->getJson($url)->json('cursor');

    $this->actingAs($viewer)->postJson(route('projects.logs.tail', $world->envArgs()), ['service' => $world->web->public_id])->assertOk()->assertJson(['following' => 1]);
    $task = LogTails::open($world->production, $viewer)->sole();
    $now = now()->utc()->format('Y-m-d\TH:i:s.u\Z');

    // What Docker hands launchroomd for `npm start`: blank lines are output too
    // (one used to fail validation and with it every batch of the tail).
    $daemon->send($this, 'POST', '/daemon/v1/logs', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
        'source' => ['kind' => 'task', 'id' => $task->public_id], 'seq' => 1,
        'lines' => array_map(static fn (string $text): array => ['time' => $now, 'stream' => 'stdout', 'text' => $text], ['', '> node server.js', '', 'listening on :3000']),
    ]]])->assertNoContent();

    $entries = $this->actingAs($viewer)->getJson($url.'?after='.$cursor)->json('entries');
    expect(collect($entries)->pluck('message')->all())->toBe(['', '> node server.js', '', 'listening on :3000'])
        ->and(collect($entries)->pluck('source')->unique()->all())->toBe(['runtime'])
        ->and(collect($entries)->pluck('service')->unique()->all())->toBe(['web']);
});

test('log levels are guessed from the text', function (string $text, string $level): void {
    expect(LogLevel::guess($text))->toBe($level);
})->with([
    ['GET / 200 in 4ms', 'info'],
    ["\e[31m ERR_PNPM_OUTDATED_LOCKFILE \e[39m", 'error'],
    ['SQLSTATE[08006] connection refused', 'error'],
    ['Uncaught Exception: boom', 'error'],
    ['WARN 2 deprecated subdependencies', 'warning'],
    ['[debug] cache hit', 'debug'],
    ['process did not complete successfully: exit code: 1', 'error'],
]);

test('fix and redeploy stages the port and deploys it when nothing else is staged', function (): void {
    Queue::fake();
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $web->forceFill(['status' => DeploymentStatus::Failed, 'failure_class' => DeploymentFailureClass::PortMismatch, 'failure_message' => 'listens on 5173'])->save();
    $url = route('deployments.fix', $world->deploymentArgs($web));

    $this->actingAs($world->member(TeamRole::Viewer))->post($url, ['action' => 'set_port', 'port' => 5173])->assertForbidden();
    $this->actingAs($world->member(TeamRole::Developer))->post($url, ['action' => 'set_port'])->assertSessionHasErrors('port');

    $response = $this->actingAs($world->member(TeamRole::Developer))->post($url, ['action' => 'set_port', 'port' => 5173]);
    $created = $world->latest($world->web);

    $response->assertRedirect(route('deployments.show', $world->deploymentArgs($created)));
    expect($created->id)->not->toBe($web->id)
        ->and(ServiceInstance::query()->whereKey($web->service_instance_id)->sole()->deploy->port)->toBe(5173);
});

test('fix with other staged changes only stages; the health timeout fix raises the start period', function (): void {
    Queue::fake();
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $web->forceFill(['status' => DeploymentStatus::Failed, 'failure_class' => DeploymentFailureClass::HealthTimeout])->save();
    $world->variable('OTHER', 'staged');

    $this->actingAs($world->member(TeamRole::Developer))
        ->post(route('deployments.fix', $world->deploymentArgs($web)), ['action' => 'raise_health_timeout', 'seconds' => 90])
        ->assertRedirect(route('staged-changes.show', $world->envArgs()));

    expect($world->latest($world->web)->id)->toBe($web->id)
        ->and(ServiceInstance::query()->whereKey($web->service_instance_id)->sole()->deploy->healthcheck->startPeriodSeconds)->toBe(90);

    $this->actingAs($world->member(TeamRole::Developer))
        ->post(route('deployments.fix', $world->deploymentArgs($web)), ['action' => 'add_variable'])
        ->assertSessionHasErrors('action');
});

test('team activity groups deploys, filters by project and kind and stays in the team', function (): void {
    $world = DeploymentUi::make();
    $outside = DeploymentUi::make();
    $viewer = $world->member(TeamRole::Viewer);

    $this->actingAs($viewer)->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('activity')
            ->has('projects', 1)
            ->where('entries', fn ($entries): bool => collect($entries)->pluck('projectId')->unique()->values()->all() === [$world->project->public_id])
            ->where('entries', fn ($entries): bool => collect($entries)->where('kind', 'deploy')->count() === 1));

    $this->actingAs($viewer)->get(route('activity.index', ['kind' => 'deploys']))
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.title', 'deployed postgres and web to production')
            ->where('entries.0.status', 'live')
            ->where('filters.kind', 'deploys'));

    // Another team's project id in the filter reads as no filter.
    $this->actingAs($viewer)->get(route('activity.index', ['project' => $outside->project->public_id]))
        ->assertInertia(fn ($page) => $page->where('filters.project', null)
            ->where('entries', fn ($entries): bool => collect($entries)->doesntContain('projectId', $outside->project->public_id)));

    $this->actingAs($viewer)->get(route('activity.index', ['kind' => 'nope']))->assertSessionHasErrors('kind');
});
