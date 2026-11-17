<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Services\AddVolumeData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\ServiceKind;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use Tests\Support\GitFixtures;
use Tests\Support\Projects;

/*
 * Ports (OD14, G74) and release commands with volumes (G62), on a git app
 * whose build is completed by hand.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    GitFixtures::fake('4015b57a143aec5156fd1444a017a32137a3fd0f');
});

/**
 * @return array{Server, Service, ServiceInstance}
 */
function paApp(array $features = []): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $server->forceFill(['daemon_features' => $features])->save();
    $project = Projects::create($team, 'shop-'.bin2hex(random_bytes(3)));
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'api', 'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());

    return [$server->refresh(), $app, ServiceInstance::query()->where('service_id', $app->id)->sole()];
}

/**
 * Deploys and completes the build with $summary (port 3000 unless set).
 *
 * @param  array<string, mixed>  $summary
 */
function paDeployBuilt(Server $server, Service $app, ServiceInstance $instance, array $summary = []): Deployment
{
    resolve(DeployEnvironment::class)->handle($instance->environment, null, AuditActor::system());
    $task = paLease($server, DaemonTaskType::Build);

    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, 'outcome' => 'succeeded',
        'build' => [
            'image' => '127.0.0.1:5000/apps/'.strtolower($app->public_id).'@sha256:'.str_repeat('d', 64),
            'durationMs' => 1000,
            'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'node', 'port' => 3000, ...$summary],
            'secretsHash' => str_repeat('e', 64),
        ],
    ]));

    return Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();
}

function paLease(Server $server, DaemonTaskType $type): DaemonTask
{
    $leased = resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 8, 'types' => [$type->value]]));

    return DaemonTask::query()->where('public_id', $leased[0]->id)->sole();
}

function paStamp(): string
{
    return now()->utc()->format('Y-m-d\TH:i:s\Z');
}

test('an app that listens on one other port gets that port and deploys again', function (): void {
    [$server, $app, $instance] = paApp();
    $deployment = paDeployBuilt($server, $app, $instance);
    $w = $deployment->placements()->sole()->workload_id;

    Projects::report($server, 'starting', fields: [$w => ['issue' => ['kind' => 'wrong_port', 'since' => paStamp(), 'port' => 3000, 'listening' => [8000]]]]);

    $deployment->refresh();
    $next = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::PortMismatch)
        ->and($deployment->failure_message)->toStartWith('Your app listens on 8000, so launchroom set api’s port to 8000 and deployed again.')
        ->and($instance->refresh()->deploy->port)->toBe(8000)
        ->and($next->id)->not->toBe($deployment->id)
        ->and($next->revision?->spec->service($app->public_id)?->deploy->port)->toBe(8000)
        ->and($next->revision?->message)->toBe('launchroom set api’s port to 8000, the port the app listens on');
});

test('never over an explicit port, and not with several listeners', function (array $settings, array $listening): void {
    [$server, $app, $instance] = paApp();

    if ($settings !== []) {
        resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), ...$settings]]));
    }

    $deployment = paDeployBuilt($server, $app, $instance);
    $w = $deployment->placements()->sole()->workload_id;
    Projects::report($server, 'starting', fields: [$w => ['issue' => ['kind' => 'wrong_port', 'since' => paStamp(), 'port' => 3000, 'listening' => $listening]]]);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->id)->toBe($deployment->id)
        ->and($instance->refresh()->deploy->port)->toBe($settings['port'] ?? null);
})->with([
    'explicit port' => [['port' => 3000], [8000]],
    'two listeners' => [[], [8000, 9000]],
]);

test('with other staged changes the port is only staged', function (): void {
    [$server, $app, $instance] = paApp();
    $deployment = paDeployBuilt($server, $app, $instance);
    resolve(UpdateServiceSettings::class)->handle($instance->refresh(), UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'drainSeconds' => 25]]));
    $w = $deployment->placements()->sole()->workload_id;

    Projects::report($server, 'starting', fields: [$w => ['issue' => ['kind' => 'wrong_port', 'since' => paStamp(), 'port' => 3000, 'listening' => [8000]]]]);

    expect($deployment->refresh()->failure_message)->toStartWith('Your app listens on 8000, so launchroom set api’s port to 8000. It deploys with your other staged changes.')
        ->and($instance->refresh()->deploy->port)->toBe(8000)
        ->and(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->id)->toBe($deployment->id);
});

test('a release command mounts the service\'s volumes on servers that support it', function (): void {
    // Servers that mount volumes into releases also declare volumes no workload mounts yet.
    [$server, $app, $instance] = paApp(['releaseMounts', 'preparedVolumes']);
    $volume = resolve(AddServiceVolume::class)->handle($instance, new AddVolumeData('/app/storage'), AuditActor::system());

    $deployment = paDeployBuilt($server, $app, $instance, ['releaseCommand' => ['php', 'artisan', 'migrate', '--force']]);
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->where('purpose', DaemonTaskPurpose::Release->value)->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Releasing)
        ->and($task->server_id)->toBe($server->id)
        ->and($volume->refresh()->server_id)->toBe($server->id)
        ->and(json_decode((string) json_encode($task->spec->mounts), true))->toEqual([['volume' => $volume->public_id, 'target' => '/app/storage']])
        ->and($task->required_features)->toContain('releaseMounts')
        // Declared on the server before the service's first workload.
        ->and(resolve(RenderNodeState::class)->handle($server->refresh())->failed())->toBeFalse()
        ->and(collect(Projects::nodeState($server)['volumes'])->pluck('id')->all())->toBe([$volume->public_id]);
});

test('without the feature the release command runs as before', function (): void {
    [$server, $app, $instance] = paApp();
    resolve(AddServiceVolume::class)->handle($instance, new AddVolumeData('/app/storage'), AuditActor::system());

    $deployment = paDeployBuilt($server, $app, $instance, ['releaseCommand' => ['php', 'artisan', 'migrate', '--force']]);
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->where('purpose', DaemonTaskPurpose::Release->value)->sole();

    expect($task->spec->mounts ?? null)->toBeNull()
        ->and($task->required_features)->not->toContain('releaseMounts');
});
