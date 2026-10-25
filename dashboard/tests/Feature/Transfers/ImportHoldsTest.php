<?php

declare(strict_types=1);

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Services\CreateService;
use App\Actions\Transfers\AdvanceImports;
use App\Actions\Transfers\SweepTransfers;
use App\Data\Audit\AuditActor;
use App\Data\Services\CreateServiceData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\ServiceKind;
use App\Enums\TeamRole;
use App\Enums\Transfers\ImportRunStatus;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Transfers\DataImportRun;
use App\Models\User;
use App\Services\Backups\RestoreHolds;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\Transfers;
use Tests\Support\VolumeServices;

/*
| Imports into a database whose app failed to deploy while its worker runs
| (docs/14 §12.5): the worker is held like its app, a service that never
| ran counts as stopped, the wait ends with an honest error, and the run
| can be cancelled. An import never stays "Stopping the services that use it".
*/

const IH_SHA = '4015b57a143aec5156fd1444a017a32137a3fd0f';

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    $this->store = Transfers::useTempStore();
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    $pkt = static fn (string $line): string => sprintf('%04x', strlen($line) + 4).$line;
    Http::fake([
        'http://git-fixtures:8080/*' => static fn (): PromiseInterface => Http::response($pkt("# service=git-upload-pack\n").'0000'.$pkt(IH_SHA." HEAD\0multi_ack side-band-64k\n").$pkt(IH_SHA." refs/heads/main\n").'0000'),
    ]);
});

/**
 * An app whose container exits right away (its deployment fails), its
 * worker running, and a Postgres the app is connected to (the worker has no
 * connection of its own).
 *
 * @return array{Team, Server, User, ServiceInstance, ServiceInstance, ServiceInstance}
 */
function ihFailedAppWithWorker(): array
{
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $server->forceFill(['daemon_features' => [DaemonFeature::DataTransfer->value]])->save();
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    $app = resolve(CreateService::class)->handle($project, CreateServiceData::from([
        'name' => 'api',
        'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main'],
    ]), null, AuditActor::system());
    $worker = resolve(CreateService::class)->handle($project, CreateServiceData::from(['name' => 'queue', 'kind' => ServiceKind::Worker, 'parentServiceId' => $app->public_id, 'startCommand' => ['node', 'worker.js']]), null, AuditActor::system());
    resolve(CreateConnection::class)->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    $production = $project->production()->sole();

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $build = VolumeServices::lease($server->refresh(), DaemonTaskType::Build);
    VolumeServices::finish($server, $build, 'succeeded', ['build' => [
        'image' => '127.0.0.1:5000/apps/'.strtolower($app->public_id).'@sha256:'.str_repeat('d', 64),
        'durationMs' => 42000,
        'summary' => ['builder' => 'railpack', 'builderReason' => 'preset', 'railpackVersion' => '0.42.0', 'provider' => 'node', 'port' => 3000],
        'secretsHash' => str_repeat('e', 64),
        'attestations' => ['sbom', 'provenance'],
    ]]);

    $instance = static fn (Service $service): ServiceInstance => ServiceInstance::query()->where('service_id', $service->id)->where('environment_id', $production->id)->sole();
    [$apiInstance, $workerInstance, $postgresInstance] = [$instance($app), $instance($worker), $instance($postgres)];

    // The app's container exits; the worker and the database run.
    $crashing = Placement::query()->where('service_instance_id', $apiInstance->id)->pluck('workload_id')->mapWithKeys(static fn (string $id): array => [$id => 'exited'])->all();
    Projects::report($server, 'healthy', $crashing);
    Projects::report($server, 'healthy', $crashing);

    $latest = static fn (ServiceInstance $i): ?Deployment => Deployment::query()->where('service_instance_id', $i->id)->latest('id')->first();
    expect($latest($apiInstance)?->status)->toBe(DeploymentStatus::Failed)
        ->and($latest($workerInstance)?->status)->toBe(DeploymentStatus::Active)
        ->and($latest($postgresInstance)?->status)->toBe(DeploymentStatus::Active)
        ->and(RestoreHolds::running([$apiInstance->id]))->toBe([]);

    return [$team, $server->refresh(), memberOf($team, TeamRole::Owner), $apiInstance, $workerInstance, $postgresInstance];
}

/**
 * Workload ids in the server's latest NodeState.
 *
 * @return list<string>
 */
function ihWorkloads(Server $server, ServiceInstance $instance): array
{
    $ids = Placement::query()->where('service_instance_id', $instance->id)->pluck('workload_id')->all();

    return array_values(array_intersect(array_column(Projects::nodeState($server)['workloads'], 'id'), $ids));
}

function ihImport(mixed $test, User $owner, ServiceInstance $postgres): DataImportRun
{
    [$transfer] = Transfers::upload($test, $owner, $postgres, Transfers::pgCustom('15.8', '15.8', 4096));
    $test->postJson(route('transfers.services.imports.store', $postgres), ['transfer' => $transfer->public_id, 'stopDependents' => true])->assertSuccessful();

    return DataImportRun::query()->latest('id')->firstOrFail();
}

test('the worker of a failed app is held with it, and the import starts once the worker stopped', function (): void {
    [, $server, $owner, $api, $worker, $postgres] = ihFailedAppWithWorker();
    expect(ihWorkloads($server, $worker))->not->toBe([]);

    // The import sheet names both as the services that stop.
    $this->actingAs($owner)->getJson(route('transfers.services.show', $postgres))
        ->assertJsonPath('dependents.*.name', ['api', 'queue']);

    $run = ihImport($this, $owner, $postgres);

    expect($run->status)->toBe(ImportRunStatus::Holding)
        ->and(ihWorkloads($server, $worker))->toBe([]);
    $this->getJson(route('transfers.services.show', $postgres))
        ->assertJsonPath('imports.0.status', 'holding')
        ->assertJsonPath('imports.0.waitingFor', ['queue']);

    // Until launchroomd reports the worker stopped, nothing is imported.
    resolve(AdvanceImports::class)->handle();
    expect($run->refresh()->status)->toBe(ImportRunStatus::Holding);

    $workerWorkloads = Placement::query()->where('service_instance_id', $worker->id)->pluck('workload_id')->mapWithKeys(static fn (string $id): array => [$id => 'removed'])->all();
    Projects::report($server, 'healthy', $workerWorkloads);
    resolve(AdvanceImports::class)->handle();

    expect($run->refresh()->status)->toBe(ImportRunStatus::Importing);
    $task = VolumeServices::lease($server->refresh(), DaemonTaskType::DbImport);
    VolumeServices::finish($server, $task, 'succeeded', ['dataImport' => ['mode' => 'import', 'empty' => false, 'tables' => 3, 'sizeBytes' => 4096, 'serverVersion' => '18.1', 'format' => 'postgres_custom', 'sourceVersion' => 'PostgreSQL 15.8', 'readBytes' => 4096, 'durationMs' => 900]]);

    expect($run->refresh()->status)->toBe(ImportRunStatus::Succeeded)
        ->and(ihWorkloads($server, $worker))->not->toBe([]);
});

test('a service that doesn\'t stop ends the wait with an honest error, nothing is imported, and it starts again', function (): void {
    [, $server, $owner, , $worker, $postgres] = ihFailedAppWithWorker();
    $run = ihImport($this, $owner, $postgres);

    // launchroomd keeps reporting the worker up (an unreachable server can't confirm).
    Projects::report($server);
    $this->travel(config()->integer('launchroom.backups.restore_hold_wait_seconds') + 1)->seconds();
    resolve(AdvanceImports::class)->handle();
    expect($run->refresh()->status)->toBe(ImportRunStatus::Holding);

    $this->travel(config()->integer('launchroom.transfers.hold_timeout_seconds'))->seconds();
    resolve(AdvanceImports::class)->handle();

    expect($run->refresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($run->error)->toBe('queue didn’t stop within 5 minutes, so nothing was imported and postgres is unchanged. Check that its server is online and try again, or import without stopping it.')
        ->and(DaemonTask::query()->where('type', DaemonTaskType::DbImport->value)->exists())->toBeFalse()
        ->and(ihWorkloads($server, $worker))->not->toBe([]);
});

test('an import that waits can be cancelled by whoever may import; it starts nothing and the services run again', function (): void {
    [$team, $server, $owner, , $worker, $postgres] = ihFailedAppWithWorker();
    $run = ihImport($this, $owner, $postgres);
    $cancel = route('transfers.services.imports.cancel', [$postgres, $run->public_id]);

    $this->actingAs(memberOf($team, TeamRole::Developer))->postJson($cancel)->assertForbidden();
    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->postJson($cancel)->assertNotFound();
    // Only through the service it imports into.
    $this->actingAs($owner)->postJson(route('transfers.services.imports.cancel', [$worker, $run->public_id]))->assertNotFound();

    $this->postJson($cancel)
        ->assertSuccessful()
        ->assertJsonPath('imports.0.status', 'cancelled');

    expect($run->refresh()->status)->toBe(ImportRunStatus::Cancelled)
        ->and(ihWorkloads($server, $worker))->not->toBe([])
        ->and(AuditEvent::query()->where('action', 'data.import_cancelled')->sole()->meta['import'] ?? null)->toBe($run->public_id);
    $this->postJson($cancel)->assertStatus(409);

    // A new import can start right away; once it imports it can't be cancelled.
    $next = ihImport($this, $owner, $postgres);
    $next->forceFill(['holding_since' => now()->subMinute()])->save();
    $workerWorkloads = Placement::query()->where('service_instance_id', $worker->id)->pluck('workload_id')->mapWithKeys(static fn (string $id): array => [$id => 'removed'])->all();
    Projects::report($server, 'healthy', $workerWorkloads);
    resolve(AdvanceImports::class)->handle();
    expect($next->refresh()->status)->toBe(ImportRunStatus::Importing);
    $this->postJson(route('transfers.services.imports.cancel', [$postgres, $next->public_id]))->assertStatus(409);
});

test('an import stuck holding (from an older release, or a tick that failed) is failed, never imported late', function (bool $bySweep): void {
    [, $server, $owner, , $worker, $postgres] = ihFailedAppWithWorker();
    $run = ihImport($this, $owner, $postgres);

    // Hours later; the worker stopped long ago.
    $workerWorkloads = Placement::query()->where('service_instance_id', $worker->id)->pluck('workload_id')->mapWithKeys(static fn (string $id): array => [$id => 'removed'])->all();
    Projects::report($server, 'healthy', $workerWorkloads);
    $this->travel(3)->hours();

    $bySweep ? resolve(SweepTransfers::class)->handle() : resolve(AdvanceImports::class)->handle();

    expect($run->refresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($run->error)->toBe('The import couldn’t start within 5 minutes, so nothing was imported and postgres is unchanged. Try again.')
        ->and(DaemonTask::query()->where('type', DaemonTaskType::DbImport->value)->exists())->toBeFalse()
        ->and(ihWorkloads($server, $worker))->not->toBe([]);
})->with(['by the transfers tick' => false, 'by the sweep' => true]);
