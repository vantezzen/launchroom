<?php

declare(strict_types=1);

use App\Actions\Commands\PruneCommandRuns;
use App\Actions\Daemons\StoreLogBatch;
use App\Data\Protocol\V1\LogBatch;
use App\Enums\ApiAbility;
use App\Enums\AuditAction;
use App\Enums\Commands\CommandRunStatus;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\Commands\CommandRun;
use App\Models\DaemonTask;
use App\Models\LogChunk;
use App\Models\Membership;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

// One-off commands (D105, docs/03 §7.5, docs/08 §22).

beforeEach(function (): void {
    Projects::useTestKek();
    $this->world = DeploymentUi::make(deploy: false);
    $this->world->variable('API_TOKEN', 'tok_live_9f8e7d6c5b4a', VariableType::Secret);
    $this->world->deploy();
    $this->world->server->forceFill(['daemon_features' => ['commandRuns', 'releaseMounts', 'preparedVolumes']])->save();
    $this->instance = commandInstance($this->world);
});

function commandInstance(DeploymentUi $world): ServiceInstance
{
    return ServiceInstance::query()->where('service_id', $world->web->id)->where('environment_id', $world->production->id)->sole();
}

function commandsUrl(DeploymentUi $world): string
{
    return "/commands/p/{$world->project->public_id}/{$world->production->public_id}/s/{$world->web->public_id}";
}

function runCommand(DeploymentUi $world, TeamRole|User $who, array $body = ['argv' => ['php', 'artisan', 'migrate:status']]): TestResponse
{
    $user = $who instanceof TeamRole ? $world->member($who) : $who;

    return test()->actingAs($user)->postJson(commandsUrl($world), $body);
}

function commandTask(string $runId): DaemonTask
{
    return DaemonTask::query()->findOrFail(CommandRun::query()->where('public_id', $runId)->sole()->daemon_task_id);
}

test('the permission follows the Console: owners, admins and developers with the exec grant', function (string $role, bool $grant, bool $allowed): void {
    $user = $this->world->member(TeamRole::from($role));

    if ($grant) {
        Membership::query()->where('team_id', $this->world->team->id)->where('user_id', $user->id)->update(['permissions' => json_encode(['containers.exec'])]);
    }

    runCommand($this->world, $user->refresh())->assertStatus($allowed ? 201 : 403);
    $this->actingAs($user)->getJson(commandsUrl($this->world))->assertStatus($allowed ? 200 : 403);
})->with([
    'owner' => ['owner', false, true],
    'admin' => ['admin', false, true],
    'developer' => ['developer', false, false],
    'developer with exec' => ['developer', true, true],
    'viewer' => ['viewer', false, false],
]);

test('other teams’ services and runs are not found', function (): void {
    $id = runCommand($this->world, TeamRole::Owner)->assertCreated()->json('id');
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    runCommand($this->world, $outsider)->assertNotFound();
    $this->actingAs($outsider)->getJson('/commands/runs/'.$id)->assertNotFound();
    $this->actingAs($outsider)->getJson('/commands/runs/'.$id.'/output')->assertNotFound();
    $this->actingAs($outsider)->postJson('/commands/runs/'.$id.'/cancel')->assertNotFound();
});

test('a run is a pinned run_once task in the live image with bounded output, audited without env values', function (): void {
    $response = runCommand($this->world, TeamRole::Owner, ['argv' => ['php', 'artisan', 'migrate:status'], 'timeoutSeconds' => 120])->assertCreated();
    $run = CommandRun::query()->where('public_id', $response->json('id'))->sole();
    $task = DaemonTask::query()->findOrFail($run->daemon_task_id);
    $live = $this->instance->refresh()->activeDeployment;

    expect($response->json())->toMatchArray(['status' => 'queued', 'finished' => false, 'argv' => ['php', 'artisan', 'migrate:status'], 'timeoutSeconds' => 120, 'viaApi' => false])
        ->and($task->type)->toBe(DaemonTaskType::RunOnce)
        ->and($task->purpose)->toBe(DaemonTaskPurpose::CommandRun)
        ->and($task->deployment_id)->toBeNull()
        ->and($task->pinned_to_server)->toBeTrue()
        ->and($task->server_id)->toBe($this->world->server->id)
        ->and($task->max_attempts)->toBe(1)
        ->and($task->required_features)->toContain('commandRuns')
        ->and((array) $task->spec)->toMatchArray(['deployment' => $live?->public_id, 'image' => $live?->image, 'timeout' => '120s', 'outputLimit' => 5 * 1024 * 1024])
        ->and(array_merge((array) ($task->spec->command ?? []), (array) ($task->spec->args ?? [])))->toBe(['php', 'artisan', 'migrate:status'])
        ->and(json_encode($task->spec))->not->toContain('tok_live_9f8e7d6c5b4a');

    $audit = AuditEvent::query()->where('action', AuditAction::CommandStarted->value)->sole();
    expect($audit->meta)->toMatchArray(['run' => $run->public_id, 'argv' => ['php', 'artisan', 'migrate:status'], 'timeoutSeconds' => 120])
        ->and(json_encode($audit->meta))->not->toContain('tok_live_9f8e7d6c5b4a');
});

test('a listed command runs by name; unknown names and a shell string are refused', function (): void {
    $live = $this->instance->refresh()->activeDeployment;
    $live->forceFill(['plan' => ['summary' => [
        'builder' => 'railpack', 'builderReason' => 'preset', 'framework' => 'laravel',
        'commands' => [['name' => 'migrate', 'label' => 'Run migrations', 'description' => 'Runs the migrations that haven\'t run yet.', 'args' => ['php', 'artisan', 'migrate', '--force'], 'danger' => true]],
    ]]])->save();

    $id = runCommand($this->world, TeamRole::Owner, ['name' => 'migrate'])->assertCreated()->assertJsonPath('name', 'migrate')->json('id');
    expect(CommandRun::query()->where('public_id', $id)->sole()->argv)->toBe(['php', 'artisan', 'migrate', '--force']);

    runCommand($this->world, TeamRole::Owner, ['name' => 'tinker'])->assertUnprocessable()->assertJsonValidationErrors('name');
    runCommand($this->world, TeamRole::Owner, ['argv' => 'php artisan migrate'])->assertUnprocessable();
    runCommand($this->world, TeamRole::Owner, ['argv' => ['php'], 'name' => 'migrate'])->assertUnprocessable();
    runCommand($this->world, TeamRole::Owner, [])->assertUnprocessable();

    $tab = $this->actingAs($this->world->member(TeamRole::Owner))->getJson(commandsUrl($this->world))->assertOk();
    expect($tab->json('catalogue.framework'))->toBe('Laravel')
        ->and($tab->json('catalogue.commands.0'))->toMatchArray(['name' => 'migrate', 'danger' => true, 'source' => 'framework'])
        ->and($tab->json('blocked'))->toBeNull()
        ->and($tab->json('production'))->toBeTrue();
});

test('commands need a live version, a server that can run them and a free slot', function (): void {
    $owner = $this->world->member(TeamRole::Owner);

    // A server without the feature.
    $this->world->server->forceFill(['daemon_features' => []])->save();
    runCommand($this->world, $owner)->assertConflict()->assertJsonPath('message', "Update launchroom on {$this->world->server->name} to run commands there.");
    $this->world->server->forceFill(['daemon_features' => ['commandRuns']])->save();

    // Databases have none.
    $postgres = "/commands/p/{$this->world->project->public_id}/{$this->world->production->public_id}/s/{$this->world->postgres->public_id}";
    $this->actingAs($owner)->postJson($postgres, ['argv' => ['ls']])->assertConflict();

    // Three open runs per service.
    foreach (range(1, 3) as $i) {
        runCommand($this->world, $owner)->assertCreated();
    }
    runCommand($this->world, $owner)->assertConflict();

    // Not deployed.
    $this->instance->forceFill(['active_deployment_id' => null])->save();
    runCommand($this->world, $owner)->assertConflict()->assertJsonPath('message', 'Commands run in the live version. Deploy this service first.');
    expect($this->actingAs($owner)->getJson(commandsUrl($this->world))->json('blocked'))->toBe('Commands run in the live version. Deploy this service first.');
});

test('lease, completion and cancel move the run through its states', function (): void {
    $owner = $this->world->member(TeamRole::Owner);
    $server = $this->world->server;

    // Succeeded, then failed with its exit code.
    foreach ([[0, CommandRunStatus::Succeeded], [2, CommandRunStatus::Failed]] as [$exit, $status]) {
        $id = runCommand($this->world, $owner)->json('id');
        $task = VolumeServices::lease($server, DaemonTaskType::RunOnce);
        expect(CommandRun::query()->where('public_id', $id)->sole()->status)->toBe(CommandRunStatus::Running);

        VolumeServices::finish($server, $task, 'succeeded', ['runOnce' => ['exitCode' => $exit, 'durationMs' => 1234, 'outputTruncated' => true]]);
        $run = CommandRun::query()->where('public_id', $id)->sole();
        expect($run->status)->toBe($status)
            ->and($run->exit_code)->toBe($exit)
            ->and($run->duration_ms)->toBe(1234)
            ->and($run->output_truncated)->toBeTrue()
            ->and($run->finished_at)->not->toBeNull();
    }

    // Timed out.
    $id = runCommand($this->world, $owner)->json('id');
    VolumeServices::finish($server, VolumeServices::lease($server, DaemonTaskType::RunOnce), 'failed', ['error' => 'deadline exceeded']);
    expect(CommandRun::query()->where('public_id', $id)->sole()->status)->toBe(CommandRunStatus::TimedOut);

    // launchroomd restarted: never run again.
    $id = runCommand($this->world, $owner)->json('id');
    $task = VolumeServices::lease($server, DaemonTaskType::RunOnce);
    VolumeServices::finish($server, $task, 'released');
    $run = CommandRun::query()->where('public_id', $id)->sole();
    expect($task->refresh()->status)->toBe(DaemonTaskStatus::Failed)
        ->and($run->status)->toBe(CommandRunStatus::Error)
        ->and($run->error)->toContain('not run again');

    // Cancelled while queued.
    $id = runCommand($this->world, $owner)->json('id');
    $this->actingAs($owner)->postJson('/commands/runs/'.$id.'/cancel')->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelledBy', $owner->name);
    expect(commandTask($id)->status)->toBe(DaemonTaskStatus::Cancelled)
        ->and(AuditEvent::query()->where('action', AuditAction::CommandCancelled->value)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::CommandFinished->value)->count())->toBe(5);

    // Cancelled while running: the server confirms.
    $id = runCommand($this->world, $owner)->json('id');
    $task = VolumeServices::lease($server, DaemonTaskType::RunOnce);
    $this->actingAs($owner)->postJson('/commands/runs/'.$id.'/cancel')->assertOk()->assertJsonPath('status', 'running');
    expect($task->refresh()->cancel_requested_at)->not->toBeNull();
    VolumeServices::finish($server, $task, 'cancelled');
    expect(CommandRun::query()->where('public_id', $id)->sole()->status)->toBe(CommandRunStatus::Cancelled);
});

test('output is paged by cursor and masked against the live deployment’s secrets', function (): void {
    $owner = $this->world->member(TeamRole::Owner);
    $id = runCommand($this->world, $owner)->json('id');
    $task = VolumeServices::lease($this->world->server, DaemonTaskType::RunOnce);

    foreach ([1 => ['Migration table found.', 'token tok_live_9f8e7d6c5b4a'], 2 => ['2024_01_01_000000_create_users ... Ran']] as $seq => $texts) {
        resolve(StoreLogBatch::class)->handle($this->world->server, LogBatch::validateAndCreate(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
            'source' => ['kind' => 'task', 'id' => $task->public_id], 'seq' => $seq,
            'lines' => array_map(static fn (string $text): array => ['time' => '2026-11-20T12:00:00Z', 'stream' => 'stdout', 'text' => $text, 'step' => 'run'], $texts),
        ]]]));
    }

    expect(json_encode(LogChunk::query()->where('daemon_task_id', $task->id)->pluck('lines')))->not->toContain('tok_live_9f8e7d6c5b4a');

    $first = $this->actingAs($owner)->getJson('/commands/runs/'.$id.'/output')->assertOk();
    expect(array_column($first->json('lines'), 'text'))->toBe(['Migration table found.', 'token ***', '2024_01_01_000000_create_users ... Ran'])
        ->and(array_column($first->json('lines'), 'number'))->toBe([1, 2, 3])
        ->and($first->json('hasMore'))->toBeFalse();

    $after = LogChunk::query()->where('daemon_task_id', $task->id)->orderBy('id')->value('id');
    expect(array_column($this->actingAs($owner)->getJson('/commands/runs/'.$id.'/output?after='.$after)->json('lines'), 'number'))->toBe([3]);
});

test('a service with volumes runs where they are, with them mounted, and never without them', function (): void {
    [$team, $server, , $instance] = VolumeServices::runningApp();
    $server->forceFill(['daemon_features' => ['commandRuns', 'releaseMounts', 'preparedVolumes']])->save();
    $owner = memberOf($team, TeamRole::Owner);
    $url = "/commands/p/{$instance->environment->project->public_id}/{$instance->environment->public_id}/s/{$instance->service->public_id}";

    $id = $this->actingAs($owner)->postJson($url, ['argv' => ['ls', '/data/uploads']])->assertCreated()->json('id');
    $volume = $instance->volumes()->sole();
    expect(commandTask($id)->spec->mounts)->toEqual([(object) ['volume' => $volume->public_id, 'target' => '/data/uploads']]);

    $server->forceFill(['status' => ServerStatus::Offline])->save();
    $this->actingAs($owner)->postJson($url, ['argv' => ['ls']])->assertConflict()->assertJsonPath('message', "{$server->name} holds this service’s volumes and is offline. Commands run where the volumes are.");
});

test('the API needs the opt-in run ability on top of the exec permission', function (): void {
    $owner = $this->world->member(TeamRole::Owner);
    $developer = $this->world->member(TeamRole::Developer);
    $path = "environments/{$this->world->production->public_id}/services/{$this->world->web->public_id}";

    Api::call('POST', $path.'/command-runs', Api::token($owner, $this->world->team, [ApiAbility::Read, ApiAbility::Deploy]), ['argv' => ['ls']])
        ->assertForbidden()->assertJsonPath('error.code', 'missing_ability')->assertJsonPath('error.ability', 'run');
    Api::call('POST', $path.'/command-runs', Api::rawToken($developer, $this->world->team, ['read', 'run']), ['argv' => ['ls']])->assertForbidden();

    $token = Api::token($owner, $this->world->team, [ApiAbility::Read, ApiAbility::Run]);
    $run = Api::call('POST', $path.'/command-runs', $token, ['argv' => ['ls', '-la']])->assertCreated()->assertJsonPath('data.viaApi', true)->json('data');

    Api::get($path.'/commands', $token)->assertOk()->assertJsonPath('data.commands', []);
    Api::get($path.'/command-runs', $token)->assertOk()->assertJsonPath('data.0.id', $run['id']);
    Api::get('command-runs/'.$run['id'], $token)->assertOk()->assertJsonPath('data.status', 'queued');
    Api::get('command-runs/'.$run['id'].'/output', $token)->assertOk()->assertJsonPath('meta.finished', false);

    expect(AuditEvent::query()->where('action', AuditAction::CommandStarted->value)->sole()->meta)->toHaveKey('via_token');

    $task = VolumeServices::lease($this->world->server, DaemonTaskType::RunOnce);
    VolumeServices::finish($this->world->server, $task, 'succeeded', ['runOnce' => ['exitCode' => 3, 'durationMs' => 10]]);

    $stream = Api::get('command-runs/'.$run['id'].'/output/stream', $token)->assertOk();
    expect($stream->streamedContent())->toContain('event: status')->toContain('event: end')->toContain('"exitCode":3');

    Api::call('POST', 'command-runs/'.$run['id'].'/cancel', $token)->assertOk()->assertJsonPath('data.status', 'failed');
});

test('ended runs and their output go after the retention period', function (): void {
    $owner = $this->world->member(TeamRole::Owner);
    $old = runCommand($this->world, $owner)->json('id');
    $task = VolumeServices::lease($this->world->server, DaemonTaskType::RunOnce);
    resolve(StoreLogBatch::class)->handle($this->world->server, LogBatch::validateAndCreate(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LogBatch', 'chunks' => [[
        'source' => ['kind' => 'task', 'id' => $task->public_id], 'seq' => 1, 'lines' => [['time' => '2026-11-20T12:00:00Z', 'stream' => 'stdout', 'text' => 'done']],
    ]]]));
    VolumeServices::finish($this->world->server, $task, 'succeeded', ['runOnce' => ['exitCode' => 0, 'durationMs' => 1]]);
    $open = runCommand($this->world, $owner)->json('id');
    CommandRun::query()->update(['created_at' => now()->subDays(40)]);

    expect(resolve(PruneCommandRuns::class)->handle())->toBe(1)
        ->and(CommandRun::query()->pluck('public_id')->all())->toBe([$open])
        ->and(LogChunk::query()->where('daemon_task_id', $task->id)->count())->toBe(0);
});
