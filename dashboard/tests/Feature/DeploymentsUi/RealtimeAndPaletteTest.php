<?php

declare(strict_types=1);

use App\Actions\Environments\CreateEnvironment;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\DeploymentStatus;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Events\Broadcasts\DeploymentLogsAppended;
use App\Events\Broadcasts\DeploymentUpdated;
use App\Events\DeploymentStatusChanged;
use App\Events\LogChunksStored;
use App\Listeners\BroadcastDeploymentLogs;
use App\Listeners\BroadcastDeploymentStatus;
use App\Models\Variable;
use App\Queries\ServiceVariablesView;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

/**
 * @return Closure(mixed...): bool
 */
function ui2Channel(string $name): Closure
{
    $channels = Broadcast::driver()->getChannels();

    return $channels[$name];
}

test('environment and deployment channels authorize members of the owning team only', function (): void {
    $world = DeploymentUi::make();
    $outsider = DeploymentUi::make(deploy: false)->member(TeamRole::Owner);
    $viewer = $world->member(TeamRole::Viewer);
    $deployment = $world->latest($world->web);

    $environments = ui2Channel('environments.{environment}');
    $deployments = ui2Channel('deployments.{deployment}');

    expect($environments($viewer, $world->production->public_id))->toBeTrue()
        ->and($environments($outsider, $world->production->public_id))->toBeFalse()
        ->and($environments($viewer, 'env_nope'))->toBeFalse()
        ->and($deployments($viewer, $deployment->public_id))->toBeTrue()
        ->and($deployments($outsider, $deployment->public_id))->toBeFalse()
        ->and($deployments($viewer, $world->production->public_id))->toBeFalse();
});

test('the broadcasting auth endpoint resolves channel ids team-scoped and asks the policy', function (): void {
    $world = DeploymentUi::make();
    $outsider = DeploymentUi::make(deploy: false)->member(TeamRole::Owner);
    $viewer = $world->member(TeamRole::Viewer);
    $deployment = $world->latest($world->web);

    // A signing driver (the test default is "null", which authorizes nothing).
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
    $auth = fn (string $channel): array => ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"];

    $this->actingAs($viewer)->post('/broadcasting/auth', $auth("environments.{$world->production->public_id}"))->assertOk()->assertJsonStructure(['auth']);
    $this->actingAs($viewer)->post('/broadcasting/auth', $auth("deployments.{$deployment->public_id}"))->assertOk();
    // Other teams' ids don't resolve: not found, like any team-scoped binding.
    $this->actingAs($outsider)->post('/broadcasting/auth', $auth("environments.{$world->production->public_id}"))->assertNotFound();
    $this->actingAs($outsider)->post('/broadcasting/auth', $auth("deployments.{$deployment->public_id}"))->assertNotFound();
    $this->actingAs($viewer)->post('/broadcasting/auth', $auth('environments.env_nope'))->assertNotFound();
    $this->post('/logout');
    $this->post('/broadcasting/auth', $auth("environments.{$world->production->public_id}"))->assertRedirect();
});

test('status changes and stored logs are broadcast as nudges without content', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    Queue::fake();

    resolve(BroadcastDeploymentStatus::class)->handle(new DeploymentStatusChanged($deployment->id, DeploymentStatus::Routing, DeploymentStatus::Active));
    resolve(BroadcastDeploymentLogs::class)->handle(new LogChunksStored($world->team->id, $world->server->id, [
        ['kind' => LogSourceKind::Deployment, 'id' => $deployment->public_id],
        ['kind' => LogSourceKind::Task, 'id' => 'tsk_01JABCDEFGHJKMNPQRSTVWXYZ0'],
    ]));

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof DeploymentUpdated
        && $job->event->deploymentId === $deployment->public_id
        && $job->event->status === 'active'
        && array_map(fn (PrivateChannel $channel): string => $channel->name, $job->event->broadcastOn()) === ["private-environments.{$world->production->public_id}", "private-deployments.{$deployment->public_id}"]);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof DeploymentLogsAppended && $job->event->deploymentId === $deployment->public_id);
});

test('logs of another team’s deployment are never nudged', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make();
    Queue::fake();

    resolve(BroadcastDeploymentLogs::class)->handle(new LogChunksStored($world->team->id, $world->server->id, [
        ['kind' => LogSourceKind::Deployment, 'id' => $other->latest($other->web)->public_id],
    ]));

    Queue::assertNotPushed(BroadcastEvent::class);
});

test('the command palette indexes the current team only', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make(deploy: false);
    $user = $world->member(TeamRole::Viewer);

    $data = $this->actingAs($user)->getJson(route('palette.index'))->assertOk()->json();

    expect(collect($data['projects'])->pluck('id')->all())->toBe([$world->project->public_id])
        ->and($data['projects'][0]['environmentId'])->toBe($world->production->public_id)
        ->and(collect($data['services'])->pluck('label')->sort()->values()->all())->toBe(['postgres', 'web'])
        ->and($data['deployments'])->toHaveCount(2)
        ->and($data['canDeploy'])->toBeFalse()
        ->and(json_encode($data))->not->toContain($other->project->public_id);
});

test('raw mode splits a row shared with another environment instead of changing it there', function (): void {
    $world = DeploymentUi::make();
    $staging = resolve(CreateEnvironment::class)->handle($world->project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    $shared = resolve(CreateVariable::class)->handle($world->project, $world->web, VariableInput::from([
        'key' => 'LOG_LEVEL',
        'value' => 'info',
        'type' => VariableType::Config,
        'targets' => [VariableTargetData::environment($world->production->public_id), VariableTargetData::environment($staging->public_id)],
    ]), null, AuditActor::system());

    $this->actingAs($world->member(TeamRole::Developer))
        ->putJson(route('variables.raw.update', $world->serviceArgs()), ['content' => "LOG_LEVEL=debug\n"])
        ->assertOk()
        ->assertJsonPath('updated', ['LOG_LEVEL']);

    $rows = Variable::query()->where('key', 'LOG_LEVEL')->with('targets')->get();

    expect($rows)->toHaveCount(2)
        ->and($shared->refresh()->targets()->pluck('environment_id')->all())->toBe([$staging->id])
        ->and(ServiceVariablesView::SECRET_PLACEHOLDER)->toBe('__LAUNCHROOM_SECRET_UNCHANGED__');
});
