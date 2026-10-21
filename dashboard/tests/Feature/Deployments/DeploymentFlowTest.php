<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\RollbackDeployment;
use App\Actions\Deployments\SweepDeployments;
use App\Actions\Domains\AddDomain;
use App\Actions\Domains\RemoveDomain;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Servers\RenderNodeState;
use App\Actions\Services\RemoveService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\UpdateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\DeployResult;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Data\Variables\VariableUpdate;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ServerRole;
use App\Enums\VariableType;
use App\Enums\WorkloadRole;
use App\Events\DeploymentStatusChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Placement;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Variable;
use App\Models\Volume;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\Changes\SpecDiff;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Event;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

beforeEach(fn () => Projects::useTestKek());

/**
 * @return array{Team, Server, string, Project, Environment, Service, Service}
 */
function deployableProject(): array
{
    $team = Team::factory()->create();
    [$server, $keypair] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    $web = Projects::image($project, 'web');

    resolve(CreateConnection::class)->handle($web, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    return [$team, $server, $keypair, $project, $project->production()->sole(), $postgres, $web];
}

function deployEnvironment(Environment $environment, ?string $message = null): DeployResult
{
    return resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system(), $message);
}

function deploymentOf(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

function setVariable(Project $project, Service $service, string $key, string $value, Environment $environment): Variable
{
    return resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
        'key' => $key, 'value' => $value, 'type' => VariableType::Config, 'targets' => [VariableTargetData::environment($environment->public_id)],
    ]), null, AuditActor::system());
}

/**
 * Drives the fake daemon until nothing changes: apply the NodeState, report
 * every workload healthy.
 */
function converge(Server $server, int $rounds = 4): void
{
    for ($i = 0; $i < $rounds; $i++) {
        Projects::report($server);
    }
}

test('first deploy: revision, deployments, placements and a schema-valid NodeState with sealed env', function (): void {
    [, $server, $keypair, , $production, $postgres, $web] = deployableProject();

    $result = deployEnvironment($production, 'first');

    expect($result->revision->number)->toBe(1)
        ->and($result->revision->message)->toBe('first')
        ->and($result->deployments)->toHaveCount(2)
        ->and($production->refresh()->last_applied_revision_id)->toBe($result->revision->id)
        ->and(deploymentOf($postgres)->status)->toBe(DeploymentStatus::HealthChecking)
        ->and(deploymentOf($web)->status)->toBe(DeploymentStatus::HealthChecking)
        ->and(deploymentOf($postgres)->image)->toStartWith('127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:')
        ->and(deploymentOf($web)->image)->toBe('127.0.0.1:5000/mirror/docker.io/library/nginx@'.Projects::DIGEST)
        ->and(Volume::query()->sole()->server_id)->toBe($server->id);

    $document = Projects::latestDocument($server);
    $state = json_decode($document, true);

    expect(ProtocolSchemas::errors('node-state', $document))->toBe([])
        ->and($state['networks'])->toBe([['id' => $production->networkId(), 'name' => $production->networkName(), 'subnet' => '10.231.0.0/24']])
        ->and($state['volumes'])->toHaveCount(1)
        ->and($state['workloads'])->toHaveCount(2)
        // Not routed before it is healthy.
        ->and($state['routes'])->toBe([]);

    $byService = collect($state['workloads'])->keyBy('service');
    $db = $byService[$postgres->public_id];
    $app = $byService[$web->public_id];

    expect($db['mounts'])->toBe([['volume' => Volume::query()->sole()->public_id, 'target' => '/var/lib/postgresql']])
        ->and($db['capabilities'])->toBe(['CHOWN', 'SETUID', 'SETGID', 'DAC_OVERRIDE', 'FOWNER'])
        ->and($db['health'])->toBe(['type' => 'tcp', 'startPeriod' => '60s', 'interval' => '10s', 'timeout' => '3s', 'failureThreshold' => 5])
        ->and($db['networks'])->toBe([['network' => $production->networkId(), 'aliases' => ['postgres']]])
        ->and($app['port'])->toBe(80)
        ->and($app['health']['type'])->toBe('http')
        ->and($app)->not->toHaveKey('capabilities')
        ->and($document)->not->toContain('POSTGRES_PASSWORD');

    $dbEnv = Projects::openEnv($db['env']['sealed'], $keypair);
    $appEnv = Projects::openEnv($app['env']['sealed'], $keypair);

    expect($dbEnv['POSTGRES_PASSWORD'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($appEnv['DATABASE_URL'])->toBe("postgresql://app:{$dbEnv['POSTGRES_PASSWORD']}@postgres:5432/app")
        ->and($appEnv['PORT'])->toBe('80')
        ->and($appEnv['PRIVATE_HOST'])->toBe('web')
        ->and($appEnv['PUBLIC_URL'])->toBe('https://web-production-shop.203-0-113-7.sslip.io')
        ->and($appEnv['LAUNCHROOM_DEPLOYMENT_ID'])->toBe(deploymentOf($web)->public_id)
        ->and($appEnv['LAUNCHROOM_ENVIRONMENT'])->toBe('production');
});

test('healthy reports route traffic, and active waits for the edge to apply the new routes', function (): void {
    [, $server, , , , $postgres, $web] = deployableProject();
    deployEnvironment($web->project->production()->sole());

    // Healthy, but the daemon hasn't applied the routing NodeState yet.
    Projects::report($server);
    $routingVersion = $server->refresh()->desired_version;

    expect(deploymentOf($web)->status)->toBe(DeploymentStatus::Routing)
        ->and(ServiceInstance::query()->where('service_id', $web->id)->sole()->active_deployment_id)->toBe(deploymentOf($web)->id);

    Projects::report($server, appliedVersion: $routingVersion - 1);
    expect(deploymentOf($web)->status)->toBe(DeploymentStatus::Routing);

    Projects::report($server);
    $state = Projects::nodeState($server);

    expect(deploymentOf($web)->status)->toBe(DeploymentStatus::Active)
        ->and(deploymentOf($postgres)->status)->toBe(DeploymentStatus::Active)
        ->and(deploymentOf($web)->steps()->pluck('status', 'name')->map->value->all())->toEqual(['rollout' => 'succeeded', 'health' => 'succeeded', 'route' => 'succeeded'])
        ->and(Projects::domainRoutes($server))->toHaveCount(1)
        ->and(Projects::domainRoutes($server)[0]['hosts'])->toBe(['web-production-shop.203-0-113-7.sslip.io'])
        ->and(Projects::domainRoutes($server)[0]['tls'])->toBe('acme')
        ->and(Projects::domainRoutes($server)[0]['upstreams'])->toBe([['workload' => Placement::query()->where('deployment_id', deploymentOf($web)->id)->sole()->workload_id, 'port' => 80]])
        // Production is indexable.
        ->and(Projects::domainRoutes($server)[0])->not->toHaveKey('headers')
        ->and(ProtocolSchemas::errors('node-state', Projects::latestDocument($server)))->toBe([]);
});

test('nothing to deploy without staged changes', function (): void {
    [, $server, , , $production] = deployableProject();
    deployEnvironment($production);
    converge($server);

    expect(fn (): DeployResult => deployEnvironment($production))->toThrow(DomainRuleViolation::class, 'Nothing to deploy');
});

test('a runtime change rolls out next to the old workload, drains it and supersedes the old deployment', function (): void {
    [, $server, $keypair, $project, $production, $postgres, $web] = deployableProject();
    deployEnvironment($production);
    converge($server);
    $first = deploymentOf($web);
    $firstWorkload = Placement::query()->where('deployment_id', $first->id)->sole()->workload_id;

    setVariable($project, $web, 'GREETING', 'hello', $production);
    $result = deployEnvironment($production);

    // Only web restarts; postgres is untouched.
    expect($result->deployments)->toHaveCount(1)
        ->and($result->revision->number)->toBe(2);

    $second = deploymentOf($web);
    $workloads = collect(Projects::nodeState($server)['workloads'])->where('service', $web->public_id);

    expect($workloads)->toHaveCount(2)
        ->and($workloads->pluck('role')->all())->toBe(['serving', 'serving']);

    Projects::report($server);
    $state = Projects::nodeState($server);
    $old = collect($state['workloads'])->firstWhere('id', $firstWorkload);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Routing)
        ->and($old['role'])->toBe('draining')
        ->and($old['drainUntil'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
        // The drain countdown waits for the edge to route to the new version.
        ->and(Placement::query()->where('workload_id', $firstWorkload)->sole()->drain_until?->greaterThan(now()->addSeconds(60)))->toBeTrue()
        ->and(collect(Projects::domainRoutes($server)[0]['upstreams'])->pluck('workload')->all())->toContain($firstWorkload);

    Projects::report($server);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and(Placement::query()->where('workload_id', $firstWorkload)->sole()->drain_until?->lessThanOrEqualTo(now()->addSeconds(10)))->toBeTrue()
        ->and($first->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($first->superseded_by_id)->toBe($second->id)
        ->and(Projects::openEnv(collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', $second->public_id)['env']['sealed'], $keypair)['GREETING'])->toBe('hello');

    // After the drain period the old workload leaves the desired state.
    $this->travel(30)->seconds();
    Projects::report($server);
    resolve(SweepDeployments::class)->handle();

    expect(Placement::query()->where('workload_id', $firstWorkload)->exists())->toBeFalse()
        ->and(collect(Projects::nodeState($server)['workloads'])->pluck('id')->all())->not->toContain($firstWorkload);
});

test('stateful services stop the old container before starting the new one', function (): void {
    [, $server, , , $production, $postgres] = deployableProject();
    deployEnvironment($production);
    converge($server);
    $first = deploymentOf($postgres);
    $oldWorkload = Placement::query()->where('deployment_id', $first->id)->sole()->workload_id;
    $instance = ServiceInstance::query()->where('service_id', $postgres->id)->sole();

    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(memoryMb: 1024)]));
    deployEnvironment($production);
    $second = deploymentOf($postgres);

    $state = Projects::nodeState($server);
    $dbWorkloads = collect($state['workloads'])->where('service', $postgres->public_id);

    // The old one drains immediately; the new one doesn't exist yet.
    expect($second->status)->toBe(DeploymentStatus::RollingOut)
        ->and($dbWorkloads->pluck('id')->all())->toBe([$oldWorkload])
        ->and($dbWorkloads->first()['role'])->toBe('draining')
        ->and($second->placements()->count())->toBe(0);

    // Still running: keep waiting.
    Projects::report($server, phases: [$oldWorkload => 'draining']);
    expect($second->refresh()->status)->toBe(DeploymentStatus::RollingOut);

    // Stopped: the new container starts on the volume's server.
    Projects::report($server, phases: [$oldWorkload => 'exited']);
    expect($second->refresh()->status)->toBe(DeploymentStatus::HealthChecking)
        ->and($second->placements()->sole()->server_id)->toBe($server->id);

    converge($server);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and($first->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and(collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', $second->public_id)['resources']['memoryMb'])->toBe(1024);
});

test('a failing new version is removed and the old one keeps serving', function (): void {
    [, $server, , $project, $production, , $web] = deployableProject();
    deployEnvironment($production);
    converge($server);
    $first = deploymentOf($web);

    setVariable($project, $web, 'BROKEN', 'yes', $production);
    deployEnvironment($production);
    $second = deploymentOf($web);
    $newWorkload = $second->placements()->sole()->workload_id;

    Event::fake([DeploymentStatusChanged::class]);
    Projects::report($server, phases: [$newWorkload => 'failed']);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($second->failure_class)->toBe(DeploymentFailureClass::RuntimeError)
        ->and($second->placements()->count())->toBe(0)
        ->and($first->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and(ServiceInstance::query()->where('service_id', $web->id)->sole()->active_deployment_id)->toBe($first->id)
        ->and(collect(Projects::nodeState($server)['workloads'])->pluck('deployment')->all())->not->toContain($second->public_id);

    Event::assertDispatched(DeploymentStatusChanged::class, static fn (DeploymentStatusChanged $event): bool => $event->deploymentId === $second->id && $event->to === DeploymentStatus::Failed);
});

test('a stateful predecessor stopped for a failed rollout is started again', function (): void {
    [, $server, , , $production, $postgres] = deployableProject();
    deployEnvironment($production);
    converge($server);
    $first = deploymentOf($postgres);
    $oldWorkload = $first->placements()->sole()->workload_id;

    resolve(UpdateServiceSettings::class)->handle(ServiceInstance::query()->where('service_id', $postgres->id)->sole(), UpdateServiceSettingsData::from(['scaling' => new ScalingSettings(memoryMb: 768)]));
    deployEnvironment($production);
    Projects::report($server, phases: [$oldWorkload => 'exited']);
    $second = deploymentOf($postgres);

    Projects::report($server, phases: [$second->placements()->sole()->workload_id => 'failed']);

    $restored = $first->placements()->sole();

    expect($second->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($restored->role)->toBe(WorkloadRole::Serving)
        ->and($restored->workload_id)->not->toBe($oldWorkload)
        ->and($first->refresh()->status)->toBe(DeploymentStatus::Active);
});

test('health checks time out', function (): void {
    [, $server, , , $production, , $web] = deployableProject();
    deployEnvironment($production);
    Projects::report($server, 'starting');

    $this->travel(2)->hours();
    resolve(SweepDeployments::class)->handle();

    expect(deploymentOf($web)->status)->toBe(DeploymentStatus::Failed)
        ->and(deploymentOf($web)->failure_class)->toBe(DeploymentFailureClass::HealthTimeout);
});

test('rollback re-points to the previous image without a rebuild and pauses production auto-deploy', function (): void {
    [, $server, $keypair, $project, $production, , $web] = deployableProject();
    deployEnvironment($production);
    converge($server);
    $first = deploymentOf($web);

    setVariable($project, $web, 'VERSION', '2', $production);
    deployEnvironment($production);
    converge($server);

    $rollback = resolve(RollbackDeployment::class)->handle($first->refresh(), null, AuditActor::system());
    converge($server);

    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', $rollback->public_id);

    expect($rollback->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and($rollback->trigger)->toBe(DeploymentTrigger::Rollback)
        ->and($rollback->rollback_of_id)->toBe($first->id)
        ->and($rollback->image)->toBe($first->image)
        ->and($production->refresh()->auto_deploy_paused)->toBeTrue()
        ->and(Projects::openEnv($workload['env']['sealed'], $keypair))->not->toHaveKey('VERSION')
        ->and(Projects::openEnv($workload['env']['sealed'], $keypair)['LAUNCHROOM_DEPLOYMENT_ID'])->toBe($rollback->public_id)
        ->and(fn () => resolve(RollbackDeployment::class)->handle($rollback, null, AuditActor::system()))->toThrow(DomainRuleViolation::class);
});

test('a newer deploy supersedes queued deployments of the same service', function (): void {
    [, $server, , $project, $production, , $web] = deployableProject();
    deployEnvironment($production);
    // In flight (health checking): newer deployments queue behind it.
    $inFlight = deploymentOf($web);

    $variable = setVariable($project, $web, 'A', '1', $production);
    deployEnvironment($production);
    $queued = deploymentOf($web);

    resolve(UpdateVariable::class)->handle($variable, VariableUpdate::from(['value' => '2']), null, AuditActor::system());
    deployEnvironment($production);
    $latest = deploymentOf($web);

    expect($queued->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($queued->superseded_by_id)->toBe($latest->id)
        ->and($latest->status)->toBe(DeploymentStatus::Queued);

    converge($server, 8);

    expect($inFlight->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($latest->refresh()->status)->toBe(DeploymentStatus::Active);
});

test('broken references block the deploy and list every offender', function (): void {
    [, , , $project, $production, , $web] = deployableProject();
    setVariable($project, $web, 'CACHE', '${{redis.REDIS_URL}}', $production);
    setVariable($project, $web, 'LOOP', '${{LOOP}}', $production);

    try {
        deployEnvironment($production);
        $this->fail('expected a refusal');
    } catch (DomainRuleViolation $e) {
        expect($e->field)->toBe('variables')
            ->and($e->getMessage())->toContain('web.CACHE: ${{redis.REDIS_URL}} refers to a service')
            ->and($e->getMessage())->toContain('web.LOOP: ${{LOOP}} is part of a reference cycle');
    }

    expect(EnvironmentRevision::query()->count())->toBe(0)
        ->and(Deployment::query()->count())->toBe(0);
});

test('no runtime server: placement fails clearly', function (): void {
    $team = Team::factory()->create();
    Projects::server($team, [ServerRole::Edge]);
    $project = Projects::create($team);
    $web = Projects::image($project);

    deployEnvironment($project->production()->sole());

    expect(deploymentOf($web)->failure_class)->toBe(DeploymentFailureClass::PlacementError);
});

test('removing a service tears it down on deploy, volumes stay', function (): void {
    [, $server, , , $production, $postgres, $web] = deployableProject();
    deployEnvironment($production);
    converge($server);

    resolve(RemoveService::class)->handle(ServiceInstance::query()->where('service_id', $web->id)->sole(), AuditActor::system());
    deployEnvironment($production);

    expect(collect(Projects::nodeState($server)['workloads'])->pluck('service')->all())->toBe([$postgres->public_id])
        ->and(Projects::nodeState($server)['routes'])->toBe([])
        ->and(deploymentOf($web)->status)->toBe(DeploymentStatus::Superseded)
        ->and(Volume::query()->count())->toBe(1);
});

test('non-production routes carry noindex and localhost domains use the internal CA', function (): void {
    config(['launchroom.domains.localhost' => true]);
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    Projects::image($project, 'web');
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    deployEnvironment($staging);
    converge($server);
    $route = Projects::domainRoutes($server)[0];

    expect($route['hosts'])->toBe(['web-staging.shop.localhost'])
        ->and($route['tls'])->toBe('internal')
        ->and($route['headers'])->toBe(['X-Robots-Tag' => 'noindex'])
        ->and(Projects::nodeState($server)['networks'][0]['subnet'])->toBe($staging->network_subnet);
});

test('custom domains route with on-demand TLS and www redirects', function (): void {
    [, $server, , , $production, , $web] = deployableProject();
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    resolve(AddDomain::class)->handle($instance, 'Shop.Example.com', AuditActor::system());
    resolve(AddDomain::class)->handle($instance, 'www.shop.example.com', AuditActor::system(), 'shop.example.com');

    deployEnvironment($production);
    converge($server);
    $routes = collect(Projects::nodeState($server)['routes'])->keyBy(static fn (array $route): string => $route['hosts'][0]);

    expect($routes['shop.example.com']['hosts'])->toBe(['shop.example.com', 'www.shop.example.com'])
        ->and($routes['shop.example.com']['tls'])->toBe('on_demand')
        ->and($routes['shop.example.com']['redirectWwwTo'])->toBe('shop.example.com')
        ->and($routes['shop.example.com']['id'])->toStartWith('rt_')
        ->and(ProtocolSchemas::errors('node-state', Projects::latestDocument($server)))->toBe([]);
});

test('domain changes apply immediately: no deploy, no deployment, other changes stay staged', function (): void {
    [, $server, , , $production, , $web] = deployableProject();
    deployEnvironment($production);
    converge($server);
    $count = Deployment::query()->count();
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    $revision = $production->refresh()->last_applied_revision_id;

    // An unrelated staged change must survive the domain revision.
    setVariable($production->project, $web, 'FEATURE_FLAG', 'on', $production);
    resolve(AddDomain::class)->handle($instance, 'shop.example.com', AuditActor::system());
    resolve(RenderNodeState::class)->handle($server);

    $production->refresh();
    $staged = SpecDiff::between($production->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($production));

    expect(Deployment::query()->count())->toBe($count)
        ->and($production->last_applied_revision_id)->not->toBe($revision)
        ->and($production->lastAppliedRevision?->message)->toBe('Added shop.example.com')
        ->and(collect(Projects::nodeState($server)['routes'])->pluck('hosts')->flatten()->all())->toContain('shop.example.com')
        ->and(collect($staged->items)->pluck('path')->filter(fn (string $path): bool => str_contains($path, 'domains'))->all())->toBe([])
        ->and(collect($staged->items)->pluck('path')->filter(fn (string $path): bool => str_contains($path, 'variables'))->all())->not->toBe([]);

    resolve(RemoveDomain::class)->handle(Domain::query()->where('hostname', 'shop.example.com')->sole(), AuditActor::system());
    resolve(RenderNodeState::class)->handle($server);

    expect(collect(Projects::nodeState($server)['routes'])->pluck('hosts')->flatten()->all())->not->toContain('shop.example.com')
        ->and(Deployment::query()->count())->toBe($count);
});

test('domains of a never deployed environment wait for its first deploy', function (): void {
    [, , , , $production, , $web] = deployableProject();

    resolve(AddDomain::class)->handle(ServiceInstance::query()->where('service_id', $web->id)->sole(), 'shop.example.com', AuditActor::system());

    expect($production->refresh()->last_applied_revision_id)->toBeNull();
});

test('unchanged content keeps the NodeState version although sealing is randomized', function (): void {
    [, $server, , , $production] = deployableProject();
    deployEnvironment($production);
    $version = $server->refresh()->desired_version;

    resolve(RenderNodeState::class)->handle($server);
    resolve(RenderNodeState::class)->handle($server);

    expect($server->refresh()->desired_version)->toBe($version);
});

test('reports are applied per workload in observation order and only for the reporting server', function (): void {
    [$team, $server, , , $production, , $web] = deployableProject();
    deployEnvironment($production);
    [$other] = Projects::server($team, ip: '203.0.113.8');
    $placement = deploymentOf($web)->placements()->sole();

    // Another server can't report on this workload: it is simply unknown there.
    Projects::report($other, 'failed', [$placement->workload_id => 'failed']);
    expect($placement->refresh()->phase->value)->toBe('pending');

    Projects::report($server, 'starting');
    expect($placement->refresh()->phase->value)->toBe('starting')
        ->and($placement->container_id)->toBe(str_repeat('c', 64));
});

test('registry pull failures are transient', function (): void {
    [, $server, , , $production, , $web] = deployableProject();
    deployEnvironment($production);
    $workload = deploymentOf($web)->placements()->sole()->workload_id;

    Projects::report($server, phases: [$workload => 'failed'], messages: [$workload => 'pull failed: connection refused']);

    expect(deploymentOf($web)->failure_class)->toBe(DeploymentFailureClass::Transient)
        ->and(deploymentOf($web)->failure_message)->toBe('pull failed: connection refused');
});
