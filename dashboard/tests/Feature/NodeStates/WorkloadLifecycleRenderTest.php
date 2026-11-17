<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\DeploymentPlanData;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Protocol\V1\ImageFacts;
use App\Data\Services\AddVolumeData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\NodeStates\NodeStateValidator;
use App\Support\PublicId;
use App\Support\Variables\SystemVariables;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;

/*
 * How workloads stop, are probed and run (docs/04 §8): stop timeouts for
 * every kind (G30), the probe's Host and implicit expectation (OD3), the
 * liveness restart (OD2), capabilities for root images (OD10) and the
 * owner of a fresh volume (G76).
 */

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{Server, Service, ServiceInstance}
 */
function lifecycleImage(array $features = ['stopSpec', 'probeHost', 'livenessRestart', 'volumeInitOwner']): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $server->forceFill(['daemon_features' => $features])->save();
    $project = Projects::create($team, 'shop-'.bin2hex(random_bytes(3)));
    $web = Projects::image($project, 'web', port: 8000);
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();

    return [$server->refresh(), $web, $instance];
}

function lifecycleDeploy(ServiceInstance $instance, ?Server $server = null): void
{
    resolve(DeployEnvironment::class)->handle($instance->environment, null, AuditActor::system());

    // Live before the next change (a queued deployment waits for this one).
    for ($i = 0; $server instanceof Server && $i < 4; $i++) {
        Projects::report($server);
    }
}

/**
 * @param  array<string, mixed>  $deploy
 */
function lifecycleSettings(ServiceInstance $instance, array $deploy): void
{
    $instance->refresh();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['deploy' => array_replace_recursive($instance->deploy->toArray(), $deploy)]));
}

/**
 * @return array<string, mixed>
 */
function lifecycleWorkload(Server $server, Service $service): array
{
    resolve(RenderNodeState::class)->handle($server->refresh());
    $latest = Deployment::query()->whereHas('serviceInstance', fn (Builder $q) => $q->where('service_id', $service->id))->latest('id')->firstOrFail();

    return collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', $latest->public_id);
}

test('a web service keeps launchroomd\'s default stop; its own timeout and signal are rendered', function (): void {
    [$server, $web, $instance] = lifecycleImage();
    lifecycleDeploy($instance, $server);

    expect(lifecycleWorkload($server, $web))->not->toHaveKey('stop');

    lifecycleSettings($instance, ['stopTimeoutSeconds' => 45, 'stopSignal' => 'SIGINT']);
    lifecycleDeploy($instance, $server);

    expect(lifecycleWorkload($server, $web)['stop'])->toBe(['signal' => 'SIGINT', 'timeout' => '45s'])
        ->and(resolve(NodeStateValidator::class)->problems(Projects::latestDocument($server)))->toBe([]);
});

test('servers without stopSpec get no stop spec', function (): void {
    [$server, $web, $instance] = lifecycleImage([]);
    lifecycleSettings($instance, ['stopTimeoutSeconds' => 45]);
    lifecycleDeploy($instance, $server);

    expect(lifecycleWorkload($server, $web))->not->toHaveKey('stop');
});

test('workers stop after 30 s, or after the build\'s hint for their process', function (): void {
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    Http::fake();
    [$server, $web, $instance] = lifecycleImage();
    $project = $instance->environment->project;
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'api', 'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $app->public_id]), null, AuditActor::system());
    lifecycleDeploy($instance, $server);

    // The build is faked: the worker's deployment gets an image and a summary, and a placement here.
    $deployment = Deployment::query()->whereHas('serviceInstance', fn (Builder $q) => $q->where('service_id', $worker->id))->latest('id')->firstOrFail();
    $summary = fn (?string $hint): DeploymentPlanData => new DeploymentPlanData(summary: BuildSummary::from([
        'builder' => 'railpack', 'builderReason' => 'preset', 'provider' => 'node',
        'processes' => [['name' => 'queue', 'args' => ['node', 'worker.js'], ...($hint === null ? [] : ['stopTimeout' => $hint])]],
    ]));
    $deployment->forceFill(['image' => '127.0.0.1:5000/apps/api@'.Projects::DIGEST, 'plan' => $summary(null)])->save();
    Placement::query()->create([
        'team_id' => $deployment->team_id, 'service_instance_id' => $deployment->service_instance_id, 'deployment_id' => $deployment->id,
        'server_id' => $server->id, 'slot' => 0, 'workload_id' => PublicId::generate('wl'), 'memory_mb' => 256, 'cpus' => 0.5,
    ]);

    expect(lifecycleWorkload($server, $worker)['stop'])->toBe(['signal' => 'SIGTERM', 'timeout' => '30s']);

    $deployment->forceFill(['plan' => $summary('2m')])->save();
    expect(lifecycleWorkload($server, $worker)['stop'])->toBe(['signal' => 'SIGTERM', 'timeout' => '120s']);
});

test('the probe sends the primary domain, accepts 4xx on the implicit path and restarts after 5 minutes', function (): void {
    [$server, $web, $instance] = lifecycleImage();
    lifecycleDeploy($instance, $server);

    $spec = $instance->environment->refresh()->lastAppliedRevision?->spec->service($web->public_id);
    $health = lifecycleWorkload($server, $web)['health'];

    expect($health['type'])->toBe('http')
        ->and($health['path'])->toBe('/')
        ->and($health['expectStatus'])->toBe('2xx-4xx')
        ->and($health['host'] ?? null)->toBe($spec === null ? null : SystemVariables::primaryDomain($spec))
        ->and($health['restartAfter'])->toBe('300s')
        ->and(resolve(NodeStateValidator::class)->problems(Projects::latestDocument($server)))->toBe([]);

    // Older servers get neither.
    $server->forceFill(['daemon_features' => ['stopSpec']])->save();
    expect(lifecycleWorkload($server, $web)['health'])->not->toHaveKey('host')
        ->and(lifecycleWorkload($server, $web)['health'])->not->toHaveKey('restartAfter');
    $server->forceFill(['daemon_features' => ['stopSpec', 'probeHost', 'livenessRestart']])->save();

    // An explicit path keeps 2xx-3xx; 0 never restarts.
    lifecycleSettings($instance, ['healthcheck' => ['path' => '/health', 'restartAfterSeconds' => 0]]);
    lifecycleDeploy($instance, $server);
    $health = lifecycleWorkload($server, $web)['health'];

    expect($health['expectStatus'])->toBe('2xx-3xx')
        ->and($health)->not->toHaveKey('restartAfter');

    // TCP probes carry no host.
    lifecycleSettings($instance, ['healthcheck' => ['type' => 'tcp', 'restartAfterSeconds' => 600]]);
    lifecycleDeploy($instance, $server);
    $health = lifecycleWorkload($server, $web)['health'];

    expect($health)->not->toHaveKey('host')
        ->and($health)->not->toHaveKey('expectStatus')
        ->and($health['restartAfter'])->toBe('600s');
});

test('databases are never restarted for a failing probe', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $server->forceFill(['daemon_features' => ['livenessRestart']])->save();
    $project = Projects::create($team, 'db');
    $postgres = Projects::postgres($project);
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    expect(lifecycleWorkload($server, $postgres)['health'])->not->toHaveKey('restartAfter');
});

test('an image that starts as root gets the capability allow-list; a non-root one owns its fresh volumes', function (): void {
    [$server, $web, $instance] = lifecycleImage();
    $volume = resolve(AddServiceVolume::class)->handle($instance, new AddVolumeData('/data'), AuditActor::system());
    lifecycleDeploy($instance, $server);
    $deployment = Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail();

    // Unknown facts: nothing changes.
    $workload = lifecycleWorkload($server, $web);
    expect($workload)->not->toHaveKey('capabilities')
        ->and($workload['mounts'])->toBe([['volume' => $volume->public_id, 'target' => '/data']]);

    expect($deployment->recordImageFacts(new ImageFacts(user: '')))->toBeTrue()
        ->and($deployment->recordImageFacts(new ImageFacts(user: '')))->toBeFalse();
    expect(lifecycleWorkload($server, $web)['capabilities'])->toEqualCanonicalizing(['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'NET_BIND_SERVICE', 'SETGID', 'SETUID']);

    $deployment->recordImageFacts(new ImageFacts(user: '1000'));
    $workload = lifecycleWorkload($server, $web);
    expect($workload)->not->toHaveKey('capabilities')
        ->and($workload['mounts'])->toBe([['volume' => $volume->public_id, 'target' => '/data', 'initOwner' => ['uid' => 1000, 'gid' => 1000]]]);

    // A user name can't be resolved here.
    $deployment->recordImageFacts(new ImageFacts(user: 'node'));
    expect(lifecycleWorkload($server, $web)['mounts'][0])->not->toHaveKey('initOwner');
});

test('a new deployment of the same image keeps the reported facts', function (): void {
    [$server, $web, $instance] = lifecycleImage();
    lifecycleDeploy($instance, $server);
    Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->recordImageFacts(new ImageFacts(user: 'root'));

    lifecycleSettings($instance, ['drainSeconds' => 20]);
    lifecycleDeploy($instance, $server);

    expect(Deployment::query()->where('service_instance_id', $instance->id)->latest('id')->firstOrFail()->image_facts?->user)->toBe('root')
        ->and(lifecycleWorkload($server, $web)['capabilities'])->toHaveCount(6);
});
