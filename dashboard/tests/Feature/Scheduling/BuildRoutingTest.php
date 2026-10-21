<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Daemons\ReleaseStuckBuildPins;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Servers\HostFactsData;
use App\Data\Servers\ServerRuntimeData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\ServerRole;
use App\Enums\Servers\DockerEngine;
use App\Enums\Servers\DockerMode;
use App\Enums\Servers\HostOs;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Services\Builds\BuildRouting;
use App\Support\ServerPlatform;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;

/*
| Build routing (docs/04 §5): sticky per service, least-loaded otherwise,
| platform-aware, and pins released when the pinned server can't take them.
*/

const BR_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);

    $pkt = static fn (string $line): string => sprintf('%04x', strlen($line) + 4).$line;
    Http::fake([
        'http://git-fixtures:8080/*' => static fn (): PromiseInterface => Http::response($pkt("# service=git-upload-pack\n").'0000'.$pkt(BR_SHA." HEAD\0multi_ack side-band-64k\n").$pkt(BR_SHA." refs/heads/main\n").'0000'),
    ]);
});

function brApp(Project $project): Service
{
    return resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'api',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
}

/**
 * @param  list<ServerRole>  $roles
 */
function brServer(Team $team, string $name, array $roles, string $arch = 'arm64', int $cpus = 4): Server
{
    [$server] = Projects::server($team, $roles);
    $server->forceFill(['name' => $name, 'facts' => new HostFactsData('host', 'linux', $arch, $cpus, 8192)])->save();

    return $server->refresh();
}

function brBuildTask(Service $service): DaemonTask
{
    $deployment = Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();

    return DaemonTask::query()->where('deployment_id', $deployment->id)->where('type', DaemonTaskType::Build->value)->latest('id')->firstOrFail();
}

function brDeploy(Project $project): void
{
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
}

test('builds go to the least-loaded build server; the sticky one wins while it has a free slot', function (): void {
    $team = Team::factory()->create();
    brServer($team, 'cp', [ServerRole::Edge, ServerRole::Runtime]);
    $busy = brServer($team, 'build-a', [ServerRole::Build]);
    $idle = brServer($team, 'build-b', [ServerRole::Build]);
    $running = DaemonTask::query()->create(['team_id' => $team->id, 'server_id' => $busy->id, 'type' => DaemonTaskType::Build, 'required_role' => ServerRole::Build, 'spec' => (object) [], 'max_attempts' => 1, 'timeout_seconds' => 60]);
    $running->forceFill(['status' => DaemonTaskStatus::Leased])->save();
    $project = Projects::create($team, 'shop');
    $app = brApp($project);

    brDeploy($project);
    $task = brBuildTask($app);

    expect($task->server_id)->toBe($idle->id)
        ->and($task->pinned_to_server)->toBeTrue()
        ->and($task->spec->platform)->toBe('linux/arm64');

    // Sticky: the server that built the service last, while it has a free slot.
    $routing = resolve(BuildRouting::class);
    expect($routing->serverFor($team->id, 'linux/arm64', $busy->id))->toBe($idle->id);

    $running->forceFill(['status' => DaemonTaskStatus::Succeeded])->save();
    expect($routing->serverFor($team->id, 'linux/arm64', $busy->id))->toBe($busy->id);
});

test('builds target the runtime servers\' platform; no builder for it fails clearly', function (): void {
    $team = Team::factory()->create();
    brServer($team, 'cp', [ServerRole::Edge, ServerRole::Runtime], 'amd64');
    brServer($team, 'run-2', [ServerRole::Runtime], 'amd64');
    $armBuilder = brServer($team, 'home', [ServerRole::Build], 'arm64');
    $project = Projects::create($team, 'shop');
    $app = brApp($project);

    brDeploy($project);
    $deployment = Deployment::query()->latest('id')->firstOrFail();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::ConfigError)
        ->and($deployment->failure_message)->toContain('No online build server can build for linux/amd64');

    $amdBuilder = brServer($team, 'vps-build', [ServerRole::Build], 'amd64');
    resolve(CreateVariable::class)->handle($project, $app, VariableInput::from([
        'key' => 'X', 'value' => '1', 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($project->production()->sole()->public_id)],
    ]), null, AuditActor::system());
    brDeploy($project);
    $task = brBuildTask($app);

    expect($task->spec->platform)->toBe('linux/amd64')
        ->and($task->server_id)->toBe($amdBuilder->id)
        ->and($task->server_id)->not->toBe($armBuilder->id);
});

test('an Apple Silicon Mac that emulates amd64 builds for the amd64 VPS and leases those builds (D53)', function (): void {
    $team = Team::factory()->create();
    $vps = brServer($team, 'vps', [ServerRole::Edge, ServerRole::Runtime], 'amd64');
    $mac = brServer($team, 'mac', [ServerRole::Build], 'arm64');
    $mac->forceFill(['runtime' => new ServerRuntimeData(DockerMode::Rootful, true, [], '2026-10-20T12:00:00Z', HostOs::Darwin, DockerEngine::OrbStack, ['linux/amd64', 'linux/arm64'])])->save();
    $project = Projects::create($team, 'shop');
    $app = brApp($project);

    brDeploy($project);
    $task = brBuildTask($app);

    expect($task->spec->platform)->toBe('linux/amd64')
        ->and($task->server_id)->toBe($mac->id);

    $lease = static fn (Server $server): array => resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['build']]));

    expect($lease($mac))->toHaveCount(1)
        ->and($task->refresh()->status)->toBe(DaemonTaskStatus::Leased);

    // Without emulation the same Mac leases no amd64 build.
    $task->forceFill(['status' => DaemonTaskStatus::Pending->value, 'server_id' => null, 'pinned_to_server' => false])->save();
    $mac->forceFill(['runtime' => new ServerRuntimeData(DockerMode::Rootful, true, [], '2026-10-20T12:00:00Z', HostOs::Darwin, DockerEngine::OrbStack, ['linux/arm64'])])->save();

    expect($lease($mac))->toBe([]);

    // The build-only Mac gets no replicas, and the platform comes from the VPS it builds for.
    expect(Placement::query()->where('server_id', $mac->id)->exists())->toBeFalse()
        ->and(ServerPlatform::of($vps))->toBe($task->spec->platform);
});

test('a built image records its platform and only runs on servers of it', function (): void {
    $team = Team::factory()->create();
    $amd = brServer($team, 'cp', [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build], 'amd64');
    $arm = brServer($team, 'pi', [ServerRole::Runtime], 'arm64');
    $project = Projects::create($team, 'shop');
    $app = brApp($project);
    $instance = $app->instances()->sole();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(replicas: 2)]));

    brDeploy($project);
    $task = brBuildTask($app);
    expect($task->spec->platform)->toBe('linux/amd64');

    $leased = resolve(LeaseTasks::class)->handle($amd->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['build']]));
    expect($leased)->toHaveCount(1);

    resolve(CompleteTask::class)->handle($amd, $task->refresh(), TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, 'outcome' => 'succeeded',
        'build' => ['image' => '127.0.0.1:5000/apps/'.strtolower($app->public_id).'@sha256:'.str_repeat('d', 64), 'durationMs' => 1000, 'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'node', 'port' => 3000]],
    ]));

    $deployment = Deployment::query()->latest('id')->firstOrFail();

    expect($deployment->plan['platform'])->toBe('linux/amd64')
        ->and(Placement::query()->where('deployment_id', $deployment->id)->pluck('server_id')->unique()->values()->all())->toBe([$amd->id])
        ->and(Placement::query()->where('server_id', $arm->id)->exists())->toBeFalse();
});

test('a build pinned to a server that went away, or that nobody leased in time, returns to the pool', function (): void {
    $team = Team::factory()->create();
    brServer($team, 'cp', [ServerRole::Edge, ServerRole::Runtime]);
    $builder = brServer($team, 'home', [ServerRole::Build]);
    $other = brServer($team, 'vps', [ServerRole::Build]);
    $project = Projects::create($team, 'shop');
    $app = brApp($project);
    brDeploy($project);
    $task = brBuildTask($app);
    expect($task->pinned_to_server)->toBeTrue();
    $pinnedTo = Server::query()->findOrFail($task->server_id);

    // A release task pinned to the same server must stay there.
    $release = DaemonTask::query()->create(['team_id' => $team->id, 'server_id' => $pinnedTo->id, 'pinned_to_server' => true, 'type' => DaemonTaskType::RunOnce, 'spec' => (object) [], 'max_attempts' => 1, 'timeout_seconds' => 60]);

    expect(resolve(ReleaseStuckBuildPins::class)->handle())->toBe(0);

    $pinnedTo->forceFill(['status' => ServerStatus::Offline])->save();
    expect(resolve(ReleaseStuckBuildPins::class)->handle())->toBe(1);

    $task->refresh();
    expect($task->pinned_to_server)->toBeFalse()
        ->and($task->server_id)->toBeNull()
        ->and($release->refresh()->pinned_to_server)->toBeTrue();

    $survivor = $pinnedTo->is($builder) ? $other : $builder;
    $leased = resolve(LeaseTasks::class)->handle($survivor->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['build']]));
    expect($leased)->toHaveCount(1);

    // A pin nobody leases for longer than the release timeout goes back too.
    $pinnedTo->forceFill(['status' => ServerStatus::Online])->save();
    $stale = DaemonTask::query()->create(['team_id' => $team->id, 'server_id' => $pinnedTo->id, 'pinned_to_server' => true, 'required_role' => ServerRole::Build, 'type' => DaemonTaskType::Build, 'spec' => (object) ['platform' => 'linux/arm64'], 'max_attempts' => 1, 'timeout_seconds' => 60]);
    expect(resolve(ReleaseStuckBuildPins::class)->handle())->toBe(0);

    $this->travel(config('launchroom.builds.pin_release_seconds') + 1)->seconds();
    expect(resolve(ReleaseStuckBuildPins::class)->handle())->toBe(1)
        ->and($stale->refresh()->pinned_to_server)->toBeFalse();
});
