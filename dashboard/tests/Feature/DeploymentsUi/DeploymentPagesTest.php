<?php

declare(strict_types=1);

use App\Actions\Deployments\RedeployService;
use App\Data\Audit\AuditActor;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Models\ServiceInstance;
use Illuminate\Contracts\Database\Query\Builder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('a build failure explains the missing variable from its logs and names the build server', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $deployment->forceFill([
        'status' => DeploymentStatus::Failed, 'failure_class' => DeploymentFailureClass::BuildError,
        'failure_message' => 'failed to solve: process npm run build exited with code 1',
        'build_server_id' => $world->server->id,
    ])->save();
    $task = DaemonTask::query()->create([
        'team_id' => $world->team->id, 'server_id' => $world->server->id,
        'type' => DaemonTaskType::Build, 'deployment_id' => $deployment->id,
        'spec' => new stdClass, 'timeout_seconds' => 7200, 'max_attempts' => 1,
    ]);
    LogChunk::query()->create([
        'team_id' => $world->team->id, 'server_id' => $world->server->id,
        'daemon_task_id' => $task->id, 'source_kind' => LogSourceKind::Task,
        'source_id' => $task->public_id, 'seq' => 1,
        'lines' => [['time' => now()->toIso8601String(), 'stream' => 'stderr', 'text' => '#17 Error: AUTH_SECRET environment variable is not set. It is required for encryption/decryption.']],
    ]);

    $this->actingAs($world->member(TeamRole::Developer))
        ->get(route('deployments.show', $world->deploymentArgs($deployment)))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('detail.failureExplanation.key', 'missing_variable')
            ->where('detail.failureExplanation.params.name', 'AUTH_SECRET')
            ->where('detail.failureExplanation.params.buildTime', '1')
            ->where('detail.buildServer.id', $world->server->public_id));

    $deployment->forceFill(['status' => DeploymentStatus::Building, 'build_server_id' => null])->save();
    $task->forceFill(['status' => DaemonTaskStatus::Leased])->save();
    $this->get(route('deployments.show', $world->deploymentArgs($deployment)))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('detail.buildServer.id', $world->server->public_id)
            ->where('detail.buildServer.name', $world->server->name));
});

test('the deployment page shows the summary, steps, config and env names, never values', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->postgres);
    $world->logs($deployment, ['booting', "\e[31merror\e[0m: nope"]);

    $response = $this->actingAs($world->member(TeamRole::Viewer))
        ->get(route('deployments.show', $world->deploymentArgs($deployment)))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/deployments/show')
            ->where('project.id', $world->project->public_id)
            ->where('detail.deployment.id', $deployment->public_id)
            ->where('detail.deployment.status', 'active')
            ->where('detail.deployment.isActive', true)
            ->where('detail.canRedeploy', false)
            ->where('detail.canCancel', false)
            ->where('detail.steps.0.name', 'rollout')
            ->has('detail.config')
            ->where('logs.lines.1.number', 2)
            ->where('logs.lines.1.section', 'rollout')
            ->where('logs.hasMore', false));

    $names = collect($response->viewData('page')['props']['detail']['env'])->pluck('name');

    expect($names)->toContain('POSTGRES_PASSWORD')
        ->and($names)->toContain('LAUNCHROOM_DEPLOYMENT_ID')
        // The generated password (32 alphanumerics) never appears in the page.
        ->and(preg_match('/POSTGRES_PASSWORD[^}]*"[A-Za-z0-9]{32}"/', (string) $response->getContent()))->toBe(0)
        ->and(collect($response->viewData('page')['props']['detail']['env'])->every(fn (array $entry): bool => strlen($entry['hash']) === 12))->toBeTrue();
});

test('nested ids must belong together and to the user’s team', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make();
    $user = $world->member(TeamRole::Admin);
    $deployment = $world->latest($world->web);

    $this->actingAs($user)->get(route('deployments.show', [...$world->deploymentArgs($deployment), 'project' => $other->project->public_id]))->assertNotFound();
    $this->actingAs($user)->get(route('deployments.show', $other->deploymentArgs($other->latest($other->web))))->assertNotFound();
    $this->actingAs($user)->get(route('deployments.logs', $other->deploymentArgs($other->latest($other->web))))->assertNotFound();
    $this->actingAs($user)->get(route('deployments.service', $other->serviceArgs()))->assertNotFound();
});

test('logs page forwards from a cursor with stable line numbers', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $world->logs($deployment, ['one', 'two'], 1);
    $world->logs($deployment, ['three'], 2, 'health');
    $user = $world->member(TeamRole::Viewer);

    $first = $this->actingAs($user)->getJson(route('deployments.logs', $world->deploymentArgs($deployment)))->assertOk()->json();

    expect($first['lines'])->toHaveCount(3)
        ->and($first['lines'][2])->toMatchArray(['number' => 3, 'text' => 'three', 'section' => 'health', 'step' => 'health'])
        ->and($first['hasMore'])->toBeFalse();

    $world->logs($deployment, ['four', 'five'], 3);
    $cursor = $first['cursor'];

    $next = $this->actingAs($user)->getJson(route('deployments.logs', $world->deploymentArgs($deployment)).'?after='.$cursor)->assertOk()->json();

    expect(array_column($next['lines'], 'number'))->toBe([4, 5])
        ->and($next['cursor'])->toBeGreaterThan($cursor);

    $this->actingAs($user)->getJson(route('deployments.logs', $world->deploymentArgs($deployment)).'?after=-1')->assertUnprocessable();
});

test('service deployments: active card, history and permissions', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);

    $viewer = $this->actingAs($world->member(TeamRole::Viewer))->getJson(route('deployments.service', $world->serviceArgs()))->assertOk()->json();

    expect($viewer['active']['id'])->toBe($active->public_id)
        ->and($viewer['active']['urls'])->toBe(['https://web-production-shop.203-0-113-7.sslip.io'])
        ->and($viewer['deployments'])->toHaveCount(1)
        ->and($viewer['canDeploy'])->toBeFalse()
        ->and(json_encode($viewer))->not->toContain('env_snapshot');

    $developer = $this->actingAs($world->member(TeamRole::Developer))->getJson(route('deployments.service', $world->serviceArgs()))->json();
    expect($developer['canDeploy'])->toBeTrue();
});

test('redeploy: developers create a manual deployment of the last revision, viewers cannot', function (): void {
    $world = DeploymentUi::make();
    $active = $world->latest($world->web);

    $this->actingAs($world->member(TeamRole::Viewer))->post(route('deployments.service.redeploy', $world->serviceArgs()))->assertForbidden();
    $this->actingAs($world->member(TeamRole::Viewer))->post(route('deployments.redeploy', $world->deploymentArgs($active)))->assertForbidden();

    $response = $this->actingAs($world->member(TeamRole::Developer))->post(route('deployments.service.redeploy', $world->serviceArgs()));
    $created = $world->latest($world->web);

    $response->assertRedirect(route('deployments.show', $world->deploymentArgs($created)));
    expect($created->id)->not->toBe($active->id)
        ->and($created->trigger)->toBe(DeploymentTrigger::Manual)
        ->and($created->revision_id)->toBe($active->revision_id);
});

test('redeploy of a failed deployment lands on the new deployment with its own logs', function (): void {
    $world = DeploymentUi::make();
    $failed = $world->latest($world->web);
    $failed->forceFill(['status' => DeploymentStatus::Failed, 'failure_class' => DeploymentFailureClass::Transient, 'failure_message' => 'Build failed (setup): buildkitd: not ready'])->save();
    $world->logs($failed, ['Connecting to BuildKit', 'error: buildkitd: not ready']);
    $developer = $world->member(TeamRole::Developer);

    $response = $this->actingAs($developer)->post(route('deployments.redeploy', $world->deploymentArgs($failed)));
    $created = $world->latest($world->web);

    expect($created->id)->not->toBe($failed->id);
    $response->assertRedirect(route('deployments.show', $world->deploymentArgs($created)));

    // The page Inertia renders after the redirect is the new deployment's:
    // its detail and its first log page, none of the failed one's lines.
    $page = $this->actingAs($developer)
        ->get(route('deployments.show', $world->deploymentArgs($created)))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/deployments/show')
            ->where('detail.deployment.id', $created->public_id));

    $lines = $page->viewData('page')['props']['logs']['lines'];
    expect(collect($lines)->pluck('text')->all())->not->toContain('error: buildkitd: not ready');

    $world->logs($created, ['Waiting for BuildKit to start…']);
    $next = $this->actingAs($developer)->getJson(route('deployments.logs', $world->deploymentArgs($created)))->assertOk()->json();
    expect(array_column($next['lines'], 'text'))->toBe(['Waiting for BuildKit to start…']);
});

test('redeploy is refused before the environment was ever deployed', function (): void {
    $world = DeploymentUi::make(deploy: false);

    $this->actingAs($world->member(TeamRole::Developer))
        ->from('/somewhere')
        ->post(route('deployments.service.redeploy', $world->serviceArgs()))
        ->assertRedirect('/somewhere')
        ->assertSessionHasErrors('service');
});

test('rollback to a superseded deployment; not to the active one', function (): void {
    $world = DeploymentUi::make();
    $first = $world->latest($world->web);
    $world->variable('GREETING', 'hi');
    $world->deploy();
    $first->refresh();

    expect($first->status)->toBe(DeploymentStatus::Superseded);

    $developer = $world->member(TeamRole::Developer);
    $this->actingAs($developer)->post(route('deployments.rollback', $world->deploymentArgs($world->latest($world->web))))->assertForbidden();
    $this->actingAs($world->member(TeamRole::Viewer))->post(route('deployments.rollback', $world->deploymentArgs($first)))->assertForbidden();

    $this->actingAs($developer)->post(route('deployments.rollback', $world->deploymentArgs($first)))->assertRedirect();

    expect($world->latest($world->web)->trigger)->toBe(DeploymentTrigger::Rollback)
        ->and($world->production->refresh()->auto_deploy_paused)->toBeTrue();
});

test('cancel: queued deployments by deployers only', function (): void {
    $world = DeploymentUi::make();
    // Two redeploys: the second one queues behind the first (one in flight per instance).
    $instance = ServiceInstance::query()->where('service_id', $world->web->id)->sole();
    resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());
    $queued = resolve(RedeployService::class)->handle($instance, null, null, AuditActor::system());

    expect($queued->refresh()->status)->toBe(DeploymentStatus::Queued);

    $this->actingAs($world->member(TeamRole::Viewer))->post(route('deployments.cancel', $world->deploymentArgs($queued)))->assertForbidden();
    $this->actingAs($world->member(TeamRole::Developer))->post(route('deployments.cancel', $world->deploymentArgs($queued)))->assertRedirect();

    expect($queued->refresh()->status)->toBe(DeploymentStatus::Cancelled);

    // Active deployments can't be cancelled.
    $this->actingAs($world->member(TeamRole::Developer))
        ->post(route('deployments.cancel', $world->deploymentArgs(Deployment::query()->where('status', 'active')->whereHas('serviceInstance', fn (Builder $q) => $q->where('service_id', $world->postgres->id))->sole())))
        ->assertForbidden();
});

test('project deployments list with filters', function (): void {
    $world = DeploymentUi::make();
    $user = $world->member(TeamRole::Viewer);

    $this->actingAs($user)->get(route('deployments.project', $world->project->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/deployments/index')
            ->has('deployments', 2)
            ->has('environmentOptions', 1)
            ->has('serviceOptions', 2)
            ->where('environment.id', $world->production->public_id));

    $this->actingAs($user)->get(route('deployments.project', ['project' => $world->project->public_id, 'service' => $world->web->public_id]))
        ->assertInertia(fn (Assert $page): Assert => $page->has('deployments', 1)->where('deployments.0.serviceId', $world->web->public_id));

    $this->actingAs($user)->get(route('deployments.project', ['project' => $world->project->public_id, 'status' => 'failed']))
        ->assertInertia(fn (Assert $page): Assert => $page->has('deployments', 0));

    $this->actingAs($user)->get(route('deployments.project', ['project' => $world->project->public_id, 'status' => 'nope']))->assertSessionHasErrors('status');

    $outsider = DeploymentUi::make(deploy: false)->member(TeamRole::Owner);
    $this->actingAs($outsider)->get(route('deployments.project', $world->project->public_id))->assertNotFound();
});
