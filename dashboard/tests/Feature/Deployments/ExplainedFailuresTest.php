<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\FailureFixAction;
use App\Enums\HealthcheckType;
use App\Enums\TeamRole;
use App\Enums\WorkloadCapability;
use App\Enums\WorkloadFailureCode;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\DeploymentDetailView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * Every failure in words, with a fix (G5, G6, G11, G46): launchroomd's
 * exit facts and typed start failures land on the placement and in the
 * log in plain words, the failed step carries them to the catalogue, and
 * the new one-click fixes stage their setting or free disk space.
 */

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{0: Server, 1: Service, 2: Placement}
 */
function explainRollingOut(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $web = Projects::image($project, 'web', port: 8000);
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    return [$server, $web, explainLatestOf($web)->placements()->sole()];
}

function explainLatestOf(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

function explainStamp(): string
{
    return now()->utc()->format('Y-m-d\TH:i:s\Z');
}

/**
 * @return list<string>
 */
function explainLogLines(Deployment $deployment): array
{
    return LogChunk::query()->where('deployment_id', $deployment->id)->orderBy('id')->get()
        ->flatMap(static fn (LogChunk $chunk): array => array_column($chunk->lines, 'text'))->values()->all();
}

test('an exit keeps its signal and who stopped it, and the log says what 137 means', function (): void {
    [$server, $web, $placement] = explainRollingOut();

    Projects::report($server, 'starting', events: [[
        'seq' => 21, 'workload' => $placement->workload_id, 'at' => explainStamp(), 'kind' => 'exited', 'exitCode' => 137, 'signal' => 9,
        'output' => [['time' => explainStamp(), 'stream' => 'stderr', 'text' => 'still working']],
    ]]);

    $exit = $placement->refresh()->last_exit;
    expect($exit?->code)->toBe(137)
        ->and($exit?->signal)->toBe(9)
        ->and($exit?->oomKilled)->toBeFalse()
        ->and($exit?->byLaunchroom)->toBeFalse()
        ->and(implode("\n", explainLogLines(explainLatestOf($web))))->toContain('Container exited with code 137: it was killed (SIGKILL)')
        ->not->toContain('memory');
});

test('a typed start failure is kept on the placement and worded in the log', function (): void {
    [$server, $web, $placement] = explainRollingOut();

    Projects::report($server, 'starting', events: [[
        'seq' => 22, 'workload' => $placement->workload_id, 'at' => explainStamp(), 'kind' => 'create_failed', 'code' => 'wrong_platform',
        'message' => 'the image is built for linux/amd64; this server runs linux/arm64',
    ]]);

    // A wrong platform can't heal: the rollout fails at once, with the code on its step.
    $deployment = explainLatestOf($web);
    $step = $deployment->steps()->where('status', 'failed')->sole();

    expect($step->meta['failureCode'] ?? null)->toBe(WorkloadFailureCode::WrongPlatform->value)
        ->and(resolve(DeploymentDetailView::class)->get($deployment)->failureExplanation?->key)->toBe('wrong_platform')
        ->and(implode("\n", explainLogLines($deployment)))->toContain('the image is built for a different processor');
});

test('a health restart by launchroom is its own line', function (): void {
    [$server, $web, $placement] = explainRollingOut();

    Projects::report($server, 'starting', events: [[
        'seq' => 23, 'workload' => $placement->workload_id, 'at' => explainStamp(), 'kind' => 'restarted', 'reason' => 'health',
        'restarts' => 1, 'message' => 'health check failing for 5m0s: HTTP 503 (expected 2xx-3xx)',
    ]]);

    expect(implode("\n", explainLogLines(explainLatestOf($web))))->toContain('Restarted by launchroom: health check failing for 5m0s');
});

test('a failed rollout carries the exit facts to the explanation', function (): void {
    [$server, $web, $placement] = explainRollingOut();

    Projects::report($server, 'starting', events: [[
        'seq' => 24, 'workload' => $placement->workload_id, 'at' => explainStamp(), 'kind' => 'exited', 'exitCode' => 139,
        'output' => [['time' => explainStamp(), 'stream' => 'stderr', 'text' => 'Segmentation fault']],
    ]]);
    Projects::report($server, 'exited', fields: [$placement->workload_id => ['restartCount' => 1]]);

    $deployment = explainLatestOf($web);
    $detail = resolve(DeploymentDetailView::class)->get($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_message)->toContain('exited with code 139 before it became healthy: it crashed (segmentation fault).')
        ->and($detail->failureExplanation?->key)->toBe('crashed_signal');
});

test('the health check fixes stage their setting and redeploy', function (FailureFixAction $action, Closure $check): void {
    Queue::fake();
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $web->forceFill(['status' => DeploymentStatus::Failed, 'failure_class' => DeploymentFailureClass::HealthTimeout])->save();

    $this->actingAs($world->member(TeamRole::Developer))
        ->post(route('deployments.fix', $world->deploymentArgs($web)), ['action' => $action->value])
        ->assertRedirect();

    $check(ServiceInstance::query()->whereKey($web->service_instance_id)->sole());
    expect($world->latest($world->web)->id)->not->toBe($web->id);
})->with([
    'tcp' => [FailureFixAction::UseTcpHealthcheck, function (ServiceInstance $instance): void {
        expect($instance->deploy->healthcheck->type)->toBe(HealthcheckType::Tcp);
    }],
    '4xx' => [FailureFixAction::Accept4xx, function (ServiceInstance $instance): void {
        expect($instance->deploy->healthcheck->expectStatus)->toBe('2xx-4xx');
    }],
    'capabilities' => [FailureFixAction::AddCapabilities, function (ServiceInstance $instance): void {
        expect($instance->deploy->capabilities)->toBe(WorkloadCapability::values());
    }],
]);

test('free disk queues one cleanup on a server the deployment ran on', function (): void {
    Queue::fake();
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $web->forceFill(['status' => DeploymentStatus::Failed, 'failure_class' => DeploymentFailureClass::Transient, 'build_server_id' => $world->server->id])->save();
    $url = route('deployments.fix', $world->deploymentArgs($web));
    $developer = $world->member(TeamRole::Developer);

    $this->actingAs($developer)->post($url, ['action' => 'free_disk'])->assertSessionHasErrors('server');
    $this->actingAs($developer)->post($url, ['action' => 'free_disk', 'server' => $world->server->public_id])->assertSessionHasErrors();

    $world->server->forceFill(['daemon_features' => [DaemonFeature::CleanupTask->value]])->save();
    $this->actingAs($developer)->post($url, ['action' => 'free_disk', 'server' => $world->server->public_id])->assertSessionHasNoErrors();
    $this->actingAs($developer)->post($url, ['action' => 'free_disk', 'server' => $world->server->public_id])->assertSessionHasNoErrors();

    [$other] = Projects::server(Team::factory()->create());
    $this->actingAs($developer)->post($url, ['action' => 'free_disk', 'server' => $other->public_id])->assertSessionHasErrors('server');

    expect(DaemonTask::query()->where('type', DaemonTaskType::Cleanup->value)->where('server_id', $world->server->id)->count())->toBe(1)
        ->and(DaemonTask::query()->where('type', DaemonTaskType::Cleanup->value)->where('server_id', $other->id)->exists())->toBeFalse()
        ->and($world->latest($world->web)->id)->toBe($web->id);
});

test('the volume owner fix needs a numeric image user', function (): void {
    Queue::fake();
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $web->forceFill(['status' => DeploymentStatus::Failed, 'failure_class' => DeploymentFailureClass::RuntimeError, 'image_facts' => ['user' => 'app']])->save();
    $url = route('deployments.fix', $world->deploymentArgs($web));

    $this->actingAs($world->member(TeamRole::Developer))->post($url, ['action' => 'fix_volume_owner'])->assertSessionHasErrors('action');

    $web->forceFill(['image_facts' => ['user' => '1000']])->save();
    $this->actingAs($world->member(TeamRole::Developer))->post($url, ['action' => 'fix_volume_owner'])->assertRedirect();

    expect($world->latest($world->web)->id)->not->toBe($web->id);
});
