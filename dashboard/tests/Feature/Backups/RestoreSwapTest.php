<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\StartDatabaseBackup;
use App\Actions\Backups\StartServiceBackup;
use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\RedeployService;
use App\Actions\Deployments\SweepDeployments;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Environments\DiscardChanges;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Specs\ConnectionSpec;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\DomainKind;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Backups\RestoreRun;
use App\Models\Connection;
use App\Models\DaemonTask;
use App\Models\Domain;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Queries\CurrentEnvironmentSpec;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
| Swap after a restore as a new service (docs/14 §9.16) and holding a
| database's dependents while it is restored in place (docs/14 §5.1).
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

/**
 * A running Postgres with an app connected to it, both deployed, and one
 * successful backup of the database.
 *
 * @return array{Team, Server, ServiceInstance, ServiceInstance, string}
 */
function swapFixture(): array
{
    [$team, $server, , $postgres] = Backups::runningPostgres();
    $postgres->load('service', 'environment.project');
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $web = Projects::image($postgres->environment->project, 'web');
    resolve(CreateConnection::class)->handle($web, $postgres->service, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($postgres->environment, null, AuditActor::system());
    Projects::report($server);
    Projects::report($server);

    $app = ServiceInstance::query()->where('service_id', $web->id)->where('environment_id', $postgres->environment_id)->with('service')->sole();

    resolve(StartDatabaseBackup::class)->handle($postgres, BackupReason::Manual, $owner, AuditActor::user($owner));
    $snapshot = str_repeat('a7', 32);
    VolumeServices::backedUp($server, $snapshot);

    return [$team, $server, $postgres, $app, $snapshot];
}

/**
 * Restores $snapshot as a new service and lets the restore finish.
 */
function finishedRestoreAsNew(Server $server, ServiceInstance $source, string $snapshot): RestoreRun
{
    $owner = memberOf(Team::query()->findOrFail($source->team_id), TeamRole::Owner);
    test()->actingAs($owner)->postJson(route('backups.services.restore', $source), ['snapshot' => $snapshot, 'mode' => 'new_service'])->assertSuccessful();

    Projects::report($server);
    Projects::report($server);
    resolve(RunBackupTick::class)->handle();

    $restore = RestoreRun::query()->latest('id')->firstOrFail();
    expect($restore->status)->toBe(RestoreRunStatus::Restoring);
    VolumeServices::finish($server, VolumeServices::lease($server, DaemonTaskType::Restore), 'succeeded', ['restore' => ['sizeBytes' => 10, 'durationMs' => 7]]);

    return $restore->refresh();
}

test('a restored database is swapped in for the original in its environment only, staged and discardable', function (): void {
    [$team, $server, $postgres, $app, $snapshot] = swapFixture();
    $environment = $postgres->environment;

    // Staging runs both too (copied from production): it keeps its own database.
    $staging = resolve(CreateEnvironment::class)->handle($environment->project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    $restore = finishedRestoreAsNew($server, $postgres, $snapshot);
    expect($restore->status)->toBe(RestoreRunStatus::Succeeded);
    $copy = ServiceInstance::query()->whereKey($restore->target_service_instance_id)->with('service')->sole();

    $owner = memberOf($team, TeamRole::Owner);
    $developer = memberOf($team, TeamRole::Developer);
    $viewer = memberOf($team, TeamRole::Viewer);
    $url = route('backups.services.restores.swap', ['serviceInstance' => $postgres, 'restore' => $restore->public_id]);

    $this->actingAs($owner)->getJson(route('backups.services.show', $postgres))
        ->assertOk()
        ->assertJsonPath('restores.0.swapUrl', $url);
    $this->actingAs($viewer)->getJson(route('backups.services.show', $postgres))
        ->assertOk()
        ->assertJsonPath('restores.0.swapUrl', null);

    // Policy matrix: managing projects is enough, viewers and strangers can't.
    $this->actingAs($viewer)->postJson($url, ['confirmation' => 'postgres'])->assertForbidden();
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($stranger)->postJson($url, ['confirmation' => 'postgres'])->assertNotFound();
    $this->actingAs($developer)->postJson($url, ['confirmation' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
    $this->actingAs($developer)->postJson(route('backups.services.restores.swap', ['serviceInstance' => $postgres, 'restore' => 'rsr_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ']), ['confirmation' => 'postgres'])->assertNotFound();

    $this->actingAs($developer)->postJson($url, ['confirmation' => 'postgres'])
        ->assertSuccessful()
        ->assertJsonPath('restores.0.swapUrl', null);

    // Staged: the app is connected to the copy, the original's removal is staged.
    expect(Connection::query()->where('from_service_id', $app->service_id)->where('to_service_id', $copy->service_id)->exists())->toBeTrue()
        ->and($postgres->refresh()->removed_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::RestoreSwapped->value)->exists())->toBeTrue();

    $spec = resolve(CurrentEnvironmentSpec::class)->get($environment);
    $connections = array_values(array_map(static fn (ConnectionSpec $connection): string => $connection->toServiceId, $spec->connections));
    expect($connections)->toBe([$copy->service->public_id])
        ->and(collect($spec->service($app->service->public_id)->connectionVariables ?? [])->pluck('connectionId')->unique()->values()->all())
        ->toBe([Connection::query()->where('to_service_id', $copy->service_id)->sole()->public_id])
        ->and($spec->service($postgres->service->public_id))->toBeNull();

    $stagingSpec = resolve(CurrentEnvironmentSpec::class)->get($staging);
    expect(array_values(array_map(static fn (ConnectionSpec $connection): string => $connection->toServiceId, $stagingSpec->connections)))->toBe([$postgres->service->public_id]);

    // Placements are untouched until Deploy.
    expect(Placement::query()->where('service_instance_id', $postgres->id)->exists())->toBeTrue();

    // A second swap is refused.
    $this->actingAs($developer)->postJson($url, ['confirmation' => 'postgres'])->assertStatus(409);

    // Discard undoes it.
    resolve(DiscardChanges::class)->handle($environment, null, AuditActor::system());
    expect($postgres->refresh()->removed_at)->toBeNull()
        ->and(Connection::query()->where('to_service_id', $copy->service_id)->exists())->toBeFalse();

    // Swap again and deploy: the original is torn down, the app follows the copy.
    // The original has backups: the Deploy waits for its final backup (docs/14 §9.13).
    $this->actingAs($developer)->postJson($url, ['confirmation' => 'postgres'])->assertSuccessful();
    VolumeServices::backedUp($server, str_repeat('ab', 32));
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());

    expect(Placement::query()->where('service_instance_id', $postgres->id)->exists())->toBeFalse()
        ->and(Placement::query()->where('service_instance_id', $copy->id)->exists())->toBeTrue();
});

test('only a finished restore as a new service can be swapped', function (): void {
    [$team, $server, $postgres, , $snapshot] = swapFixture();
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)->postJson(route('backups.services.restore', $postgres), ['snapshot' => $snapshot, 'mode' => 'new_service'])->assertSuccessful();
    $restore = RestoreRun::query()->sole();

    $this->actingAs($owner)->getJson(route('backups.services.show', $postgres))->assertJsonPath('restores.0.swapUrl', null);
    $this->actingAs($owner)->postJson(route('backups.services.restores.swap', ['serviceInstance' => $postgres, 'restore' => $restore->public_id]), ['confirmation' => 'postgres'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('restore');
});

test('a restored copy of a service with a volume takes over its domains and connections; discard moves them back', function (): void {
    [$team, $server, , $svc] = VolumeServices::runningApp();
    $svc->load('service', 'environment.project');
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $environment = $svc->environment;

    // The app uses a database (its own connection) and has a custom domain.
    $db = Projects::postgres($environment->project, 'db');
    resolve(CreateConnection::class)->handle($svc->service, $db, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
    Domain::query()->create(['team_id' => $team->id, 'service_instance_id' => $svc->id, 'hostname' => 'files.example.com', 'kind' => DomainKind::Custom]);
    resolve(DeployEnvironment::class)->handle($environment, null, AuditActor::system());
    Projects::report($server);
    Projects::report($server);
    $hostnames = Domain::query()->where('service_instance_id', $svc->id)->orderBy('hostname')->pluck('hostname')->all();
    expect($hostnames)->toContain('files.example.com');

    resolve(StartServiceBackup::class)->handle($svc, BackupReason::Manual, $owner, AuditActor::user($owner));
    VolumeServices::backedUp($server, str_repeat('b8', 32));

    $restore = finishedRestoreAsNew($server, $svc, str_repeat('b8', 32));
    $copy = ServiceInstance::query()->whereKey($restore->target_service_instance_id)->with('service')->sole();
    $copyOwn = Domain::query()->where('service_instance_id', $copy->id)->pluck('hostname')->all();

    $this->actingAs($owner)->postJson(route('backups.services.restores.swap', ['serviceInstance' => $svc, 'restore' => $restore->public_id]), ['confirmation' => 'uploads'])
        ->assertSuccessful();

    expect(Domain::query()->where('service_instance_id', $copy->id)->orderBy('hostname')->pluck('hostname')->all())->toEqualCanonicalizing([...$hostnames, ...$copyOwn])
        ->and(Domain::query()->where('service_instance_id', $svc->id)->exists())->toBeFalse()
        ->and(Connection::query()->where('from_service_id', $copy->service_id)->where('to_service_id', $db->id)->exists())->toBeTrue()
        ->and($svc->refresh()->removed_at)->not->toBeNull();

    // Routes still come from the applied revision until Deploy.
    resolve(RenderNodeState::class)->handle($server->refresh());
    $routed = collect(Projects::domainRoutes($server->refresh()))->flatMap(static fn (array $route): array => $route['hosts'])->all();
    expect($routed)->toContain('files.example.com');

    resolve(DiscardChanges::class)->handle($environment, null, AuditActor::system());

    expect(Domain::query()->where('service_instance_id', $svc->id)->orderBy('hostname')->pluck('hostname')->all())->toBe($hostnames)
        ->and(Domain::query()->where('service_instance_id', $copy->id)->pluck('hostname')->all())->toBe($copyOwn)
        ->and(Connection::query()->where('from_service_id', $copy->service_id)->exists())->toBeFalse()
        ->and($svc->refresh()->removed_at)->toBeNull();
});

test('restoring into a database holds its dependents until they stopped, then restores and starts them again', function (): void {
    [$team, $server, $postgres, $app, $snapshot] = swapFixture();
    $owner = memberOf($team, TeamRole::Owner);
    $appWorkload = Placement::query()->where('service_instance_id', $app->id)->sole()->workload_id;
    $dbWorkload = Placement::query()->where('service_instance_id', $postgres->id)->where('deployment_id', $postgres->refresh()->active_deployment_id)->sole()->workload_id;

    $this->actingAs($owner)->postJson(route('backups.services.restore', $postgres), ['snapshot' => $snapshot, 'mode' => 'in_place', 'confirmation' => 'postgres'])
        ->assertSuccessful();
    $restore = RestoreRun::query()->sole();
    VolumeServices::backedUp($server, str_repeat('c9', 32));

    // Safety snapshot done: the app is held, the restore task waits for it to stop.
    resolve(RunBackupTick::class)->handle();
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring)
        ->and($restore->daemon_task_id)->toBeNull();

    resolve(RenderNodeState::class)->handle($server->refresh());
    $workloads = collect(Projects::nodeState($server->refresh())['workloads'])->pluck('id')->all();
    expect($workloads)->toContain($dbWorkload)->not->toContain($appWorkload);

    resolve(RunBackupTick::class)->handle();
    expect(DaemonTask::query()->where('type', DaemonTaskType::Restore->value)->exists())->toBeFalse();

    // launchroomd removed the app's container: the restore starts.
    Projects::report($server, phases: [$appWorkload => 'removed']);
    resolve(RunBackupTick::class)->handle();

    $task = VolumeServices::lease($server, DaemonTaskType::Restore);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['mode'])->toBe('in_place')
        ->and($spec['target']['workload'])->toBe($dbWorkload)
        ->and($restore->refresh()->daemon_task_id)->toBe($task->id);

    // Finished (also on failure): the app is back in the desired state.
    VolumeServices::finish($server, $task, 'failed', ['error' => 'pg_restore exited 1']);
    resolve(RenderNodeState::class)->handle($server->refresh());

    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Failed)
        ->and(collect(Projects::nodeState($server->refresh())['workloads'])->pluck('id')->all())->toContain($appWorkload);
});

test('a held dependent that never reports stopping delays the restore only up to the hold wait', function (): void {
    [$team, $server, $postgres, , $snapshot] = swapFixture();
    $owner = memberOf($team, TeamRole::Owner);

    $this->actingAs($owner)->postJson(route('backups.services.restore', $postgres), ['snapshot' => $snapshot, 'mode' => 'in_place', 'confirmation' => 'postgres'])
        ->assertSuccessful();
    VolumeServices::backedUp($server, str_repeat('d0', 32));
    resolve(RunBackupTick::class)->handle();

    $this->travel(config()->integer('launchroom.backups.restore_hold_wait_seconds') + 5)->seconds();
    Projects::report($server);
    resolve(RunBackupTick::class)->handle();

    expect(RestoreRun::query()->sole()->daemon_task_id)->not->toBeNull();
});

test('a database without dependents is restored in place right after its safety snapshot', function (): void {
    [$team, $server, , $postgres] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $postgres->load('service');
    resolve(StartDatabaseBackup::class)->handle($postgres, BackupReason::Manual, $owner, AuditActor::user($owner));
    VolumeServices::backedUp($server, str_repeat('e1', 32));

    $this->actingAs($owner)->postJson(route('backups.services.restore', $postgres), ['snapshot' => str_repeat('e1', 32), 'mode' => 'in_place', 'confirmation' => $postgres->service->slug])
        ->assertSuccessful();
    VolumeServices::backedUp($server, str_repeat('f2', 32));
    resolve(RunBackupTick::class)->handle();

    expect(RestoreRun::query()->sole()->daemon_task_id)->not->toBeNull()
        ->and(Service::query()->count())->toBe(1);
});

test('a restore in place wins over a rollout of a dependent, and a new deployment of it waits for the restore', function (): void {
    [$team, $server, $postgres, $app, $snapshot] = swapFixture();
    $owner = memberOf($team, TeamRole::Owner);

    // The app rolls out a new version (its container starts) when the restore begins.
    $rolling = resolve(RedeployService::class)->handle($app, null, null, AuditActor::system());
    Projects::report($server, phases: Placement::query()->where('deployment_id', $rolling->id)->pluck('workload_id')->mapWithKeys(static fn (string $id): array => [$id => 'starting'])->all());
    expect($rolling->refresh()->status)->toBe(DeploymentStatus::HealthChecking);

    $this->actingAs($owner)->postJson(route('backups.services.restore', $postgres), ['snapshot' => $snapshot, 'mode' => 'in_place', 'confirmation' => 'postgres'])
        ->assertSuccessful();
    VolumeServices::backedUp($server, str_repeat('c8', 32));
    resolve(RunBackupTick::class)->handle();

    expect(RestoreRun::query()->sole()->status)->toBe(RestoreRunStatus::Restoring)
        ->and($rolling->refresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($rolling->failure_message)->toBe('The deployment was cancelled because postgres is being restored. Deploy again once the restore has finished.')
        ->and(Placement::query()->where('deployment_id', $rolling->id)->exists())->toBeFalse();

    // Deployed while held: queued until the restore ended.
    $waiting = resolve(RedeployService::class)->handle($app, null, null, AuditActor::system());
    resolve(SweepDeployments::class)->handle();
    expect($waiting->refresh()->status)->toBe(DeploymentStatus::Queued);

    Projects::report($server, phases: Placement::query()->where('service_instance_id', $app->id)->pluck('workload_id')->mapWithKeys(static fn (string $id): array => [$id => 'removed'])->all());
    resolve(RunBackupTick::class)->handle();
    $task = VolumeServices::lease($server, DaemonTaskType::Restore);
    VolumeServices::finish($server, $task, 'failed', ['error' => 'pg_restore exited 1']);

    expect(RestoreRun::query()->sole()->status)->toBe(RestoreRunStatus::Failed)
        ->and($waiting->refresh()->status)->not->toBe(DeploymentStatus::Queued);
});
