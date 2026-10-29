<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\PhpExtension;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskLease;
use App\Data\Services\CreateServiceData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\ServiceKind;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\BuildKey;
use App\Support\CanonicalJson;
use App\Support\Changes\SpecDiff;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

/*
| PHP database drivers for connected databases (docs/04 §3.1, D71): a PHP app
| written for SQLite, connected to Postgres, gets pdo_pgsql in its build spec,
| its build key changes, and the staged change says why.
*/

const PX_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    $pkt = static fn (string $line): string => sprintf('%04x', strlen($line) + 4).$line;
    Http::fake([
        'http://git-fixtures:8080/*' => static fn (): PromiseInterface => Http::response($pkt("# service=git-upload-pack\n").'0000'.$pkt(PX_SHA." HEAD\0multi_ack side-band-64k\n").$pkt(PX_SHA." refs/heads/main\n").'0000'),
    ]);
});

/**
 * @return array{Server, Project, Environment, Service}
 */
function pxProject(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team, 'shop');
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'api',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/laravel-sqlite', 'cloneUrl' => 'http://git-fixtures:8080/laravel-sqlite.git', 'branch' => 'main'],
    ]), null, AuditActor::system());

    return [$server, $project, $project->production()->sole(), $app];
}

function pxConnect(Service $from, Service $to, ConnectionStyle $style): void
{
    resolve(CreateConnection::class)->handle($from, $to, ConnectionMode::Private, $style, null, AuditActor::system());
}

/**
 * @return list<array{name: string, for: string}>
 */
function pxExtensions(Environment $environment, Service $service): array
{
    $spec = resolve(CurrentEnvironmentSpec::class)->get($environment)->service($service->public_id);

    return array_map(static fn (PhpExtension $extension): array => $extension->toArray(), $spec->phpExtensions ?? []);
}

/**
 * @return list<Task>
 */
function pxLease(Server $server): array
{
    return resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 16, 'types' => ['build']]));
}

test('a Laravel app connected to Postgres builds with pdo_pgsql, only on daemons that know the field', function (): void {
    [$server, $project, $production, $app] = pxProject();
    pxConnect($app, Projects::postgres($project), ConnectionStyle::Laravel);

    expect(pxExtensions($production, $app))->toBe([['name' => 'pdo_pgsql', 'for' => 'postgres']]);

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $deployment = Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $app->id))->latest('id')->firstOrFail();
    $task = DaemonTask::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->revision?->spec->service($app->public_id)?->phpExtensions[0])->toBeInstanceOf(PhpExtension::class)
        ->and(json_decode((string) json_encode($task->spec), true)['builder']['phpExtensions'])->toEqual([['name' => 'pdo_pgsql', 'for' => 'postgres']])
        // An older launchroomd would refuse the whole lease (strict decoding).
        ->and(pxLease($server))->toBe([]);

    $server->forceFill(['daemon_features' => ['phpExtensions']])->save();
    $tasks = pxLease($server);

    expect($tasks)->toHaveCount(1)
        ->and(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease($tasks))))->toBe([]);
});

test('a PHP app detected by its plan gets one driver per connected engine; other apps get none', function (): void {
    [, $project, $production, $app] = pxProject();
    $redis = Projects::database($project, 'redis', '7', 'cache');
    $valkey = Projects::database($project, 'valkey', '8', 'sessions');
    pxConnect($app, Projects::postgres($project), ConnectionStyle::Url);
    pxConnect($app, $redis, ConnectionStyle::Url);
    pxConnect($app, $valkey, ConnectionStyle::Url);

    // Url names and no plan yet: not known to be PHP.
    expect(pxExtensions($production, $app))->toBe([]);

    $instance = ServiceInstance::query()->where('environment_id', $production->id)->where('service_id', $app->id)->sole();
    $instance->forceFill(['plan' => ['status' => 'ready', 'summary' => ['builder' => 'railpack', 'builderReason' => 'railpack', 'provider' => 'node']]])->save();
    expect(pxExtensions($production, $app))->toBe([]);

    $instance->forceFill(['plan' => ['status' => 'ready', 'summary' => ['builder' => 'railpack', 'builderReason' => 'railpack', 'provider' => 'php']]])->save();
    expect(pxExtensions($production, $app))->toBe([
        ['name' => 'pdo_pgsql', 'for' => 'postgres'],
        ['name' => 'redis', 'for' => 'redis'],
    ]);
});

test('a worker\'s connections count for the image of its app', function (): void {
    [, $project, $production, $app] = pxProject();
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $app->public_id]), null, AuditActor::system());
    pxConnect($worker, Projects::database($project, 'mysql', '8.4', 'mysql'), ConnectionStyle::Laravel);

    expect(pxExtensions($production, $app))->toBe([['name' => 'pdo_mysql', 'for' => 'mysql']])
        ->and(pxExtensions($production, $worker))->toBe([]);
});

test('connecting a database to a deployed PHP app is a rebuild that says why, with a new build key', function (): void {
    [, $project, $production, $app] = pxProject();
    pxConnect($app, Projects::postgres($project), ConnectionStyle::Laravel);
    $before = resolve(CurrentEnvironmentSpec::class)->get($production);

    pxConnect($app, Projects::database($project, 'redis', '7', 'cache'), ConnectionStyle::Laravel);
    $after = resolve(CurrentEnvironmentSpec::class)->get($production);
    $item = collect(SpecDiff::between($before, $after)->items)->firstWhere('path', "services.{$app->public_id}.phpExtensions");

    expect($item?->label)->toBe('api: adds the Redis driver (redis)')
        ->and($item?->effect->value)->toBe('rebuild')
        ->and(SpecDiff::between($before, $after)->effectFor($app->public_id)->value)->toBe('rebuild');

    $oldKey = BuildKey::compute($before->service($app->public_id), PX_SHA, 'mac');
    $newKey = BuildKey::compute($after->service($app->public_id), PX_SHA, 'mac');
    $service = $after->service($app->public_id);
    $service->phpExtensions = [];
    // Services without extensions keep the key they had before D71.
    $legacy = hash('sha256', CanonicalJson::encode(['v' => 1, 'commit' => PX_SHA, 'source' => $service->source, 'build' => $service->build->toArray(), 'branch' => null, 'secrets' => 'mac']));

    expect($newKey)->not->toBe($oldKey)
        ->and(BuildKey::compute($service, PX_SHA, 'mac'))->toBe($legacy);
});

test('only allow-listed extensions and engines are accepted', function (): void {
    PhpExtension::validateAndCreate(['name' => 'xdebug', 'for' => 'postgres']);
})->throws(ValidationException::class);

test('workloads of Railpack PHP images may bind like FrankenPHP needs; other apps keep no capabilities', function (string $provider, ?array $capabilities): void {
    [$server, $project, $production, $app] = pxProject();
    pxConnect($app, Projects::postgres($project), ConnectionStyle::Url);

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $lease = pxLease($server);
    $task = DaemonTask::query()->where('public_id', $lease[0]->id)->sole();
    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, 'outcome' => 'succeeded',
        'build' => [
            'image' => '127.0.0.1:5000/apps/'.strtolower($app->public_id).'@sha256:'.str_repeat('d', 64),
            'durationMs' => 42000,
            'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => $provider, 'port' => 8080],
            'secretsHash' => str_repeat('e', 64),
            'attestations' => ['sbom', 'provenance'],
        ],
    ]));

    $deployment = Deployment::query()->whereHas('serviceInstance', static fn (Builder $query) => $query->where('service_id', $app->id))->latest('id')->firstOrFail();
    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('deployment', $deployment->public_id);

    expect($workload)->not->toBeNull()
        ->and($workload['capabilities'] ?? null)->toBe($capabilities);
})->with([
    'php' => ['php', ['NET_BIND_SERVICE']],
    'node' => ['node', null],
]);
