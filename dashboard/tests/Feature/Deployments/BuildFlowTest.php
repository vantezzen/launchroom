<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployCommit;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\RollbackDeployment;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Git\CommitData;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskLease;
use App\Data\Services\CreateServiceData;
use App\Data\Services\ServicePlanData;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Specs\ServiceSpec;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\ServerRole;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Services\Registry\RegistryTokenIssuer;
use App\Services\Registry\RegistryTokenKeyFile;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\PublicId;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

const BF_SHA_A = '4015b57a143aec5156fd1444a017a32137a3fd0f';
const BF_SHA_B = '9f2c8d1e0b7a6c5d4e3f2a1b0c9d8e7f6a5b4c3d';

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    bfGitHead(BF_SHA_A);
    bfFakeGitRemote();
});

function bfPkt(string $line): string
{
    return sprintf('%04x', strlen($line) + 4).$line;
}

/**
 * The fake fixture remote advertises $branch at $sha (changeable per test).
 */
function bfGitHead(string $sha, string $branch = 'main'): void
{
    app()->instance('test.git-head', [$sha, $branch]);
}

function bfFakeGitRemote(): void
{
    Http::fake([
        'http://git-fixtures:8080/*' => static function (): PromiseInterface {
            /** @var array{string, string} $head */
            $head = resolve('test.git-head');
            // Transaction depth of every remote call (lookups must not run under a lock).
            app()->instance('test.git-levels', [...(app()->bound('test.git-levels') ? resolve('test.git-levels') : []), DB::transactionLevel()]);

            return Http::response(bfPkt("# service=git-upload-pack\n").'0000'.bfPkt("{$head[0]} HEAD\0multi_ack side-band-64k\n").bfPkt("{$head[0]} refs/heads/{$head[1]}\n").'0000');
        },
    ]);
}

function bfGitApp(Project $project, string $name = 'api', array $extra = []): Service
{
    return resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => $name,
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
        ...$extra,
    ]), null, AuditActor::system());
}

function bfVariable(Project $project, Service $service, Environment $environment, string $key, string $value, VariableType $type = VariableType::Config): void
{
    resolve(CreateVariable::class)->handle($project, $service, VariableInput::from([
        'key' => $key, 'value' => $value, 'type' => $type, 'targets' => [VariableTargetData::environment($environment->public_id)],
    ]), null, AuditActor::system());
}

/**
 * @return list<Task>
 */
function bfLease(Server $server, array $types = ['build', 'run_once']): array
{
    return resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 16, 'types' => $types]));
}

function bfComplete(Server $server, string $taskId, array $completion): void
{
    $task = DaemonTask::query()->where('public_id', $taskId)->sole();

    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, ...$completion,
    ]));
}

function bfImage(Service $service): string
{
    return '127.0.0.1:5000/apps/'.strtolower($service->public_id).'@sha256:'.str_repeat('d', 64);
}

/**
 * @param  array<string, mixed>  $summary
 */
function bfBuilt(Service $service, array $summary = []): array
{
    return ['outcome' => 'succeeded', 'build' => [
        'image' => bfImage($service),
        'durationMs' => 42000,
        'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'node', 'port' => 3000, ...$summary],
        'secretsHash' => str_repeat('e', 64),
        'attestations' => ['sbom', 'provenance'],
    ]];
}

/**
 * @return array<string, mixed>
 */
function bfOpen(string $sealed, string $keypair): array
{
    $plain = sodium_crypto_box_seal_open((string) base64_decode($sealed, true), $keypair);
    expect($plain)->toBeString();

    return json_decode((string) $plain, true, 64, JSON_THROW_ON_ERROR);
}

function bfDeploy(Environment $environment): void
{
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
}

function bfLatest(Service $service): Deployment
{
    return Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $service->id))->latest('id')->firstOrFail();
}

/**
 * @return array{Team, Server, string, Project, Environment, Service, Service}
 */
function bfProject(): array
{
    $team = Team::factory()->create();
    [$server, $keypair] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    $app = bfGitApp($project);
    resolve(CreateConnection::class)->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());

    return [$team, $server, $keypair, $project, $project->production()->sole(), $postgres, $app];
}

test('a git app is built by a build task sealed at lease time, released, rolled out with the summary defaults and routed', function (): void {
    [, $server, $keypair, $project, $production, , $app] = bfProject();
    bfVariable($project, $app, $production, 'NPM_TOKEN', 'npm_secret_value', VariableType::Secret);
    bfVariable($project, $app, $production, 'NEXT_PUBLIC_API', 'https://api.example.com');

    bfDeploy($production);
    $deployment = bfLatest($app);
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Building)
        ->and($deployment->commit_sha)->toBe(BF_SHA_A)
        ->and($deployment->git_ref)->toBe('refs/heads/main')
        ->and($task->type)->toBe(DaemonTaskType::Build)
        ->and($task->required_role)->toBe(ServerRole::Build)
        ->and($task->max_attempts)->toBe(2)
        ->and($task->timeout_seconds)->toBe(7200)
        ->and($task->spec->repo)->toEqual((object) ['provider' => 'git', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'sha' => BF_SHA_A])
        ->and($task->spec->platform)->toBe('linux/arm64')
        ->and($task->spec->image)->toBe('127.0.0.1:5000/apps/'.strtolower($app->public_id))
        ->and($task->spec->builder)->toEqual((object) ['kind' => 'auto', 'railpackVersion' => '0.42.0'])
        ->and($task->spec->buildArgs)->toBe(['NEXT_PUBLIC_API'])
        ->and(json_encode($task->spec))->not->toContain('npm_secret_value')
        ->and(isset($task->spec->env))->toBeFalse();

    // Leasing seals the build variables to this server and task.
    $leased = bfLease($server);
    expect($leased)->toHaveCount(1)
        ->and(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease($leased))))->toBe([]);

    $env = bfOpen($leased[0]->spec->env->sealed, $keypair);
    expect($env)->toBe(['serverId' => $server->public_id, 'taskId' => $task->public_id, 'env' => ['NEXT_PUBLIC_API' => 'https://api.example.com', 'NPM_TOKEN' => 'npm_secret_value']])
        ->and(json_encode(DaemonTask::query()->find($task->id)?->spec))->not->toContain('sealed');

    bfComplete($server, $task->public_id, bfBuilt($app, ['releaseCommand' => ['node', 'migrate.js'], 'healthPath' => '/health', 'runtimeEnv' => ['NODE_ENV' => 'production']]));

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Releasing)
        ->and($deployment->image)->toBe(bfImage($app))
        ->and($deployment->build_server_id)->toBe($server->id)
        ->and($deployment->plan['summary']['port'] ?? null)->toBe(3000)
        ->and($deployment->plan['secretsHash'] ?? null)->toBe(str_repeat('e', 64));

    // The release command (from the build summary) runs as a pinned run_once task.
    $release = DaemonTask::query()->where('deployment_id', $deployment->id)->where('type', DaemonTaskType::RunOnce->value)->sole();
    expect($release->purpose)->toBe('release')
        ->and($release->pinned_to_server)->toBeTrue()
        // Railpack images have a `bash -c` ENTRYPOINT: the argv replaces it.
        ->and($release->spec->command)->toBe(['node', 'migrate.js'])
        ->and(isset($release->spec->args))->toBeFalse()
        ->and($release->spec->image)->toBe(bfImage($app))
        ->and($release->spec->network->name)->toBe($production->networkName());

    $leased = bfLease($server);
    expect(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease($leased))))->toBe([]);
    $releaseEnv = bfOpen($leased[0]->spec->env->sealed, $keypair)['env'];
    expect($releaseEnv['PORT'])->toBe('3000')
        ->and($releaseEnv['NODE_ENV'])->toBe('production')
        ->and($releaseEnv['DATABASE_URL'])->toStartWith('postgresql://');

    bfComplete($server, $release->public_id, ['outcome' => 'succeeded', 'runOnce' => ['exitCode' => 0, 'durationMs' => 1200]]);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::RollingOut);

    $state = Projects::nodeState($server);
    $workload = collect($state['workloads'])->firstWhere('deployment', $deployment->public_id);
    $workloadEnv = bfOpen($workload['env']['sealed'], $keypair)['env'];
    expect($workload['image'])->toBe(bfImage($app))
        ->and($workload['port'])->toBe(3000)
        ->and($workload['health']['path'])->toBe('/health')
        ->and($workloadEnv['PORT'])->toBe('3000')
        ->and($workloadEnv['LAUNCHROOM_GIT_COMMIT_SHA'])->toBe(BF_SHA_A);

    for ($i = 0; $i < 4; $i++) {
        Projects::report($server);
    }

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Active);
    $route = collect(Projects::nodeState($server)['routes'])->first(fn (array $route): bool => $route['upstreams'][0]['workload'] === $workload['id']);
    expect($route['upstreams'][0]['port'])->toBe(3000);
});

test('an unchanged commit reuses the image; a failing release command keeps the old version serving', function (): void {
    [, $server, , $project, $production, , $app] = bfProject();
    bfDeploy($production);
    $first = bfLatest($app);
    bfComplete($server, bfLease($server)[0]->id, bfBuilt($app));

    for ($i = 0; $i < 4; $i++) {
        Projects::report($server);
    }

    expect($first->refresh()->status)->toBe(DeploymentStatus::Active);

    resolve(UpdateServiceSettings::class)->handle(
        ServiceInstance::query()->where('service_id', $app->id)->sole(),
        UpdateServiceSettingsData::from(['deploy' => ['releaseCommand' => ['node', 'migrate.js']]]),
    );
    bfDeploy($production);
    $second = bfLatest($app);

    expect($second->id)->not->toBe($first->id)
        ->and($second->image)->toBe($first->image)
        ->and($second->plan['reusedFrom'] ?? null)->toBe($first->public_id)
        ->and(DaemonTask::query()->where('deployment_id', $second->id)->where('type', DaemonTaskType::Build->value)->exists())->toBeFalse()
        ->and($second->status)->toBe(DeploymentStatus::Releasing);

    $release = bfLease($server)[0];
    bfComplete($server, $release->id, ['outcome' => 'succeeded', 'runOnce' => ['exitCode' => 3, 'durationMs' => 10]]);

    expect($second->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($second->failure_class)->toBe(DeploymentFailureClass::ReleaseError)
        ->and($second->failure_message)->toContain('exited with code 3')
        ->and(ServiceInstance::query()->where('service_id', $app->id)->sole()->active_deployment_id)->toBe($first->id);
});

test('build failures map the protocol failure class; transient ones get one more task', function (): void {
    [, $server, , , $production, , $app] = bfProject();
    bfDeploy($production);
    $deployment = bfLatest($app);

    bfComplete($server, bfLease($server)[0]->id, ['outcome' => 'failed', 'error' => 'registry unavailable', 'failure' => ['class' => 'transient', 'step' => 'push', 'retryable' => true]]);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Building)
        ->and(DaemonTask::query()->where('deployment_id', $deployment->id)->count())->toBe(2);

    bfComplete($server, bfLease($server)[0]->id, ['outcome' => 'failed', 'error' => 'railpack could not plan', 'failure' => ['class' => 'plan_error', 'step' => 'plan', 'retryable' => false, 'summary' => ['builder' => 'railpack', 'builderReason' => 'railpack']]]);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::PlanError)
        ->and($deployment->failure_message)->toBe('Build failed (plan): railpack could not plan')
        ->and($deployment->plan['summary']['builderReason'] ?? null)->toBe('railpack');
});

test('a build reporting an image outside the service repository fails', function (): void {
    [, $server, , $project, $production, , $app] = bfProject();
    $other = bfGitApp($project, 'other');
    bfDeploy($production);

    $task = DaemonTask::query()->where('deployment_id', bfLatest($app)->id)->sole();
    bfLease($server);
    bfComplete($server, $task->public_id, ['outcome' => 'succeeded', 'build' => ['image' => bfImage($other), 'durationMs' => 1]]);

    expect(bfLatest($app)->status)->toBe(DeploymentStatus::Failed)
        ->and(bfLatest($app)->failure_message)->toContain('outside the service');
});

test('build tasks only go to build servers of the target platform', function (): void {
    [$team, $server, , , $production, , $app] = bfProject();
    [$amd] = Projects::server($team, [ServerRole::Build], ip: '203.0.113.8');
    $amd->forceFill(['facts' => ['hostname' => 'amd', 'os' => 'linux', 'arch' => 'x86_64', 'cpus' => 4, 'memoryMb' => 8192]])->save();

    bfDeploy($production);

    expect(bfLease($amd))->toBe([])
        ->and(bfLease($server))->toHaveCount(1);
});

test('a push of a newer commit supersedes the build of the older one and cancels its task', function (): void {
    [, $server, , , $production, , $app] = bfProject();
    bfDeploy($production);
    $first = bfLatest($app);
    $task = DaemonTask::query()->where('deployment_id', $first->id)->sole();
    bfLease($server);

    $instance = ServiceInstance::query()->where('service_id', $app->id)->sole();
    $second = resolve(DeployCommit::class)->handle($instance, new CommitData(BF_SHA_B, 'fix things', 'Ada', 'ada@example.com', 'ada'), 'refs/heads/main');

    expect($first->refresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($first->superseded_by_id)->toBe($second?->id)
        ->and($task->refresh()->cancel_requested_at)->not->toBeNull()
        ->and($second?->commit_sha)->toBe(BF_SHA_B)
        ->and($second?->commit_message)->toBe('fix things')
        ->and($second?->commit_author)->toBe('ada')
        ->and($second?->refresh()->status)->toBe(DeploymentStatus::Building);
});

test('workers run the image of their parent app', function (): void {
    [, $server, , $project, $production, , $app] = bfProject();
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $app->public_id]), null, AuditActor::system());

    bfDeploy($production);
    expect(bfLatest($worker)->status)->toBe(DeploymentStatus::Building);

    bfComplete($server, bfLease($server)[0]->id, bfBuilt($app, ['processes' => [['name' => 'queue', 'args' => ['node', 'worker.js']]]]));

    $deployment = bfLatest($worker);
    expect($deployment->image)->toBe(bfImage($app))
        ->and($deployment->status)->toBe(DeploymentStatus::RollingOut);

    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', $deployment->public_id);
    expect($workload['command'])->toBe(['node', 'worker.js'])
        ->and($workload)->not->toHaveKey('args');
});

test('a scheduled cron is created for launchroomd to start on its schedule; older daemons keep running it', function (bool $feature): void {
    [, $server, , $project, $production, , $app] = bfProject();
    $server->forceFill(['daemon_features' => $feature ? ['cronSchedule'] : []])->save();
    $cron = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'reports', 'kind' => ServiceKind::Cron, 'parentServiceId' => $app->public_id, 'startCommand' => ['php', 'artisan', 'reports:send']]), null, AuditActor::system());
    $instance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $cron->id)->sole();
    resolve(UpdateServiceSettings::class)->handle($instance, UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'schedule' => ['cron' => '30 3 * * 1-5', 'timeZone' => 'Europe/Berlin']]]));

    bfDeploy($production);
    bfComplete($server, bfLease($server)[0]->id, bfBuilt($app));

    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', bfLatest($cron)->public_id);
    expect($workload['args'] ?? $workload['command'])->toBe(['php', 'artisan', 'reports:send'])
        ->and($workload)->not->toHaveKey('port');

    if ($feature) {
        expect($workload['schedule'])->toBe(['cron' => '30 3 * * 1-5', 'timeZone' => 'Europe/Berlin'])
            ->and($workload['restart'])->toBe('no');
    } else {
        expect($workload)->not->toHaveKey('schedule')
            ->and($workload['restart'])->toBe('unless-stopped');
    }
})->with(['daemon with cronSchedule' => true, 'older daemon' => false]);

test('only cron services take a schedule, in launchroomd\'s cron grammar and an IANA zone', function (): void {
    [, , , $project, $production, , $app] = bfProject();
    $cron = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'reports', 'kind' => ServiceKind::Cron, 'parentServiceId' => $app->public_id, 'startCommand' => ['true']]), null, AuditActor::system());
    $instance = fn (Service $service): ServiceInstance => ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $service->id)->sole();
    $schedule = fn (Service $service, array $schedule) => resolve(UpdateServiceSettings::class)->handle($instance($service), UpdateServiceSettingsData::from(['deploy' => [...$instance($service)->deploy->toArray(), 'schedule' => $schedule]]));
    $fails = function (Service $service, array $value, string $message) use ($schedule): void {
        try {
            $schedule($service, $value);
            $this->fail('accepted');
        } catch (DomainRuleViolation|ValidationException $e) {
            expect($e->getMessage())->toContain($message);
        }
    };

    $fails($app, ['cron' => '0 3 * * *', 'timeZone' => 'UTC'], 'Only cron services run on a schedule.');
    $fails($cron, ['cron' => '0 3 * *', 'timeZone' => 'UTC'], 'five fields');
    $fails($cron, ['cron' => '60 3 * * *', 'timeZone' => 'UTC'], 'Minute 60 is outside 0-59.');
    $fails($cron, ['cron' => '0 3 * * MON', 'timeZone' => 'UTC'], 'use numbers');
    $fails($cron, ['cron' => '0 3 * * *', 'timeZone' => 'Mars/Olympus'], 'isn\'t a time zone');

    $schedule($cron, ['cron' => '*/5 8-18 * * 1-5', 'timeZone' => 'America/Argentina/Buenos_Aires']);
    expect($instance($cron)->deploy->schedule?->cron)->toBe('*/5 8-18 * * 1-5');
});

test('a task whose secrets cannot be sealed fails at lease time instead of being handed out', function (): void {
    [, $server, , , $production, , $app] = bfProject();
    bfDeploy($production);
    $deployment = bfLatest($app);
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->sole();
    $task->forceFill(['seal' => ['cloneToken' => ['source' => ['repositoryId' => 1, 'fullName' => 'octo-org/web']]]])->save();

    expect(bfLease($server))->toBe([])
        ->and($task->refresh()->status)->toBe(DaemonTaskStatus::Failed)
        ->and($deployment->refresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_class)->toBe(DeploymentFailureClass::ConfigError);
});

test('an unreachable branch fails the deployment with a clear message', function (): void {
    [, , , , $production, , $app] = bfProject();
    bfGitHead(BF_SHA_A, 'develop');

    bfDeploy($production);

    expect(bfLatest($app)->status)->toBe(DeploymentStatus::Failed)
        ->and(bfLatest($app)->failure_class)->toBe(DeploymentFailureClass::ConfigError)
        ->and(bfLatest($app)->failure_message)->toBe('Branch main does not exist in fixtures/node-hello.');
});

test('creating a git app plans it; the summary is recorded for the UI', function (): void {
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team);
    $app = bfGitApp($project);
    $instance = ServiceInstance::query()->where('service_id', $app->id)->sole();
    $task = DaemonTask::query()->where('type', DaemonTaskType::Plan->value)->sole();

    expect(ServicePlanData::forInstance($instance)?->status)->toBe('planning')
        ->and(isset($task->spec->deployment))->toBeFalse()
        ->and($task->spec->repo->sha)->toBe(BF_SHA_A);

    $leased = bfLease($server, ['plan']);
    expect(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease($leased))))->toBe([]);

    bfComplete($server, $task->public_id, ['outcome' => 'succeeded', 'plan' => ['durationMs' => 900, 'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'provider' => 'node', 'framework' => 'express', 'port' => 3000]]]);

    $plan = ServicePlanData::forInstance($instance->refresh());
    expect($plan?->status)->toBe('ready')
        ->and($plan?->commitSha)->toBe(BF_SHA_A)
        ->and($plan?->summary?->framework)->toBe('express')
        ->and($plan?->summary?->port)->toBe(3000);
});

test('plain git sources are refused outside local development and plain http only for allow-listed hosts', function (array $config, array $source): void {
    config($config);
    $project = Projects::create(Team::factory()->create());

    resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'api', 'kind' => ServiceKind::App, 'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'x/y', ...$source]]), null, AuditActor::system());
})->with([
    'not local' => [['launchroom.git.plain_sources' => false], ['cloneUrl' => 'https://git.example.com/y.git']],
    'plain http elsewhere' => [[], ['cloneUrl' => 'http://git.example.com/y.git']],
    'no url' => [[], []],
])->throws(DomainRuleViolation::class);

test('argv replaces the shell entrypoint of Railpack images only', function (): void {
    $spec = ServiceSpec::from([
        'id' => 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2', 'instanceId' => 'sin_01J9Z8X7W6V5T4S3R2Q1P0N9N2', 'slug' => 'api', 'name' => 'api', 'kind' => 'app',
        'parentId' => null, 'source' => ['type' => 'git', 'repositoryId' => 1, 'fullName' => 'a/b'], 'icon' => null, 'enabled' => true, 'branchOverride' => null,
        'build' => [], 'deploy' => ['startCommand' => ['php', 'artisan', 'serve']], 'scaling' => [],
    ]);
    $railpack = EffectiveDeploy::from($spec, BuildSummary::from(['builder' => 'railpack', 'builderReason' => 'preset', 'releaseCommand' => ['php', 'artisan', 'migrate', '--force']]));
    $dockerfile = BuildSummary::from(['builder' => 'dockerfile', 'builderReason' => 'dockerfile_detected']);

    expect($railpack->argv($railpack->startCommand))->toBe(['command' => ['php', 'artisan', 'serve'], 'args' => null])
        ->and($railpack->releaseCommand)->toBe(['php', 'artisan', 'migrate', '--force'])
        ->and(EffectiveDeploy::from($spec, $dockerfile)->argv(['x']))->toBe(['command' => null, 'args' => ['x']])
        ->and(EffectiveDeploy::from($spec, null)->argv(null))->toBe(['command' => null, 'args' => null]);
});

test('the branch head of a Deploy is resolved before the deployment row is locked', function (): void {
    [, , , , $production, , $app] = bfProject();
    app()->instance('test.git-levels', []);
    $outside = DB::transactionLevel();

    bfDeploy($production);

    $deployment = bfLatest($app);
    expect($deployment->status)->toBe(DeploymentStatus::Building)
        ->and($deployment->commit_sha)->toBe(BF_SHA_A)
        ->and(resolve('test.git-levels'))->not->toBeEmpty()
        ->and(resolve('test.git-levels'))->each->toBe($outside);
});

test('deployments whose images the registry pruned are no longer rollback targets', function (): void {
    $keyDir = sys_get_temp_dir().'/launchroom-registry-'.bin2hex(random_bytes(6));
    config([
        'launchroom.registry.token_key_path' => $keyDir.'/registry-token.es256.pem',
        'launchroom.registry.token_public_key_path' => $keyDir.'/public/registry-token.pub.pem',
        'launchroom.registry_lookup.url' => 'http://zot:5000',
    ]);
    RegistryTokenKeyFile::create(config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path'));

    [, $server, , , $production, , $app] = bfProject();
    bfDeploy($production);
    $first = bfLatest($app);
    bfLease($server);
    bfComplete($server, DaemonTask::query()->where('deployment_id', $first->id)->sole()->public_id, bfBuilt($app));
    $repository = 'apps/'.strtolower($app->public_id);
    $pruned = '127.0.0.1:5000/'.$repository.'@sha256:'.str_repeat('a', 64);
    $unknown = '127.0.0.1:5000/'.$repository.'@sha256:'.str_repeat('b', 64);

    // Two superseded deployments (one pruned, one the registry can't answer for) and the active one.
    $first->forceFill(['image' => $pruned, 'status' => DeploymentStatus::Superseded])->save();
    $second = $first->replicate(['public_id']);
    $second->forceFill(['image' => $unknown, 'status' => DeploymentStatus::Superseded, 'public_id' => PublicId::generate('dpl')])->save();
    $active = $first->replicate(['public_id']);
    $active->forceFill(['image' => bfImage($app), 'status' => DeploymentStatus::Active, 'public_id' => PublicId::generate('dpl')])->save();

    Http::fake([
        'http://zot:5000/v2/'.$repository.'/manifests/sha256:'.str_repeat('a', 64) => Http::response('', 404),
        'http://zot:5000/v2/'.$repository.'/manifests/sha256:'.str_repeat('b', 64) => Http::response('', 503),
        'http://zot:5000/*' => Http::response('', 200, ['Docker-Content-Digest' => 'sha256:'.str_repeat('d', 64)]),
    ]);
    $owner = User::factory()->create();
    Team::query()->findOrFail($app->team_id)->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
    expect(Gate::forUser($owner)->allows('rollback', $first->refresh()))->toBeTrue();

    $this->artisan('launchroom:registry:check-retained-images')->assertSuccessful();

    expect($first->refresh()->image_pruned_at)->not->toBeNull()
        ->and($second->refresh()->image_pruned_at)->toBeNull() // only a definite 404 counts
        ->and($active->refresh()->image_pruned_at)->toBeNull()
        ->and(Gate::forUser($owner)->allows('rollback', $first))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('rollback', $second))->toBeTrue()
        ->and(fn () => resolve(RollbackDeployment::class)->handle($first, null, AuditActor::system()))->toThrow(DomainRuleViolation::class);

    Http::assertSent(function (Request $request) use ($repository): bool {
        $token = substr($request->header('Authorization')[0] ?? '', strlen('Bearer '));
        $verified = resolve(RegistryTokenIssuer::class)->verify($token);

        return $request->method() === 'HEAD' && $verified !== null && $verified->scopes[0]->repository === $repository && $verified->scopes[0]->actions === ['pull'];
    });

    foreach ([config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path')] as $path) {
        @chmod($path, 0600);
        @unlink($path);
    }
});

test('the Next.js adapter report of a build is stored and shown in the Build settings', function (): void {
    [$team, $server, , $project, $production, , $app] = bfProject();
    bfDeploy($production);
    $deployment = bfLatest($app);
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->sole();
    bfLease($server);

    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/task-completion.build-next.json'), true);
    bfComplete($server, $task->public_id, bfBuilt($app, $fixture['build']['summary']));

    expect($deployment->refresh()->plan['summary']['next']['nextVersion'] ?? null)->toBe('16.4.2')
        ->and($deployment->plan['summary']['next']['routes']['isr'] ?? null)->toBe(1);

    $this->actingAs(memberOf($team, TeamRole::Viewer))
        ->get(route('projects.services.show', [$project, $production, $app, 'settings']))
        ->assertInertia(fn ($page) => $page
            ->where('panel.settings.detected.framework', 'Next.js')
            ->where('panel.settings.detected.frameworkVersion', '16.4.2')
            ->where('panel.settings.detected.next.serverEntry', '.next/standalone/server.js')
            ->where('panel.settings.detected.next.middleware', true)
            ->where('panel.settings.detected.next.routes.isr', 1)
            ->where('panel.settings.detected.next.isrRoutes', ['/isr']));
});
