<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Deployments\PromoteStagedDeployment;
use App\Actions\Deployments\SweepDeployments;
use App\Actions\Transfers\AdvanceImports;
use App\Actions\Transfers\SweepDatabasePreparations;
use App\Data\Audit\AuditActor;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\WorkloadRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Transfers\DataImportRun;
use App\Models\User;
use App\Services\Transfers\DatabasePreparations;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\Transfers;
use Tests\Support\VolumeServices;

/*
| Importing into a database before its first deploy (docs/14 §12.9): the
| database runs alone (a staged deployment of only that service), the import
| runs, the database stops again with its volume kept, and the first deploy
| starts it on the same volume with the same credentials. Apps never start.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    $this->store = Transfers::useTempStore();
});

/**
 * An undeployed project like Umami from a template: an app and its Postgres.
 *
 * @return array{Team, Server, string, ServiceInstance, ServiceInstance, User}
 */
function undeployedUmami(?Team $team = null): array
{
    $team ??= Team::factory()->create();
    [$server, $keypair] = Projects::server($team);
    $server->forceFill(['daemon_features' => [DaemonFeature::DataTransfer->value]])->save();
    $project = Projects::create($team, 'umami-'.bin2hex(random_bytes(3)));
    $postgres = Projects::postgres($project, 'db');
    $app = Projects::image($project, 'umami');
    $environment = $project->production()->sole();
    $db = ServiceInstance::query()->where('service_id', $postgres->id)->where('environment_id', $environment->id)->with('service')->sole();
    $web = ServiceInstance::query()->where('service_id', $app->id)->where('environment_id', $environment->id)->sole();

    return [$team, $server->refresh(), $keypair, $db, $web, memberOf($team, TeamRole::Owner)];
}

/**
 * The env launchroomd gets for a workload of $instance on $server.
 *
 * @return array<string, string>
 */
function workloadEnv(Server $server, string $keypair, string $workload): array
{
    $spec = collect(Projects::nodeState($server)['workloads'])->firstWhere('id', $workload);

    return Projects::openEnv((string) $spec['env']['sealed'], $keypair);
}

test('import before the first deploy: the database runs alone, imports, stops, and the first deploy finds it', function (): void {
    [$team, $server, $keypair, $db, $web, $owner] = undeployedUmami();

    $this->actingAs($owner)->getJson(route('transfers.services.show', $db))
        ->assertOk()
        ->assertJsonPath('preparation.state', 'needed')
        ->assertJsonPath('unavailableReason', null);

    // Start it alone.
    $this->postJson(route('transfers.services.prepare', $db))
        ->assertSuccessful()
        ->assertJsonPath('preparation.state', 'starting');
    $prepared = Deployment::query()->where('service_instance_id', $db->id)->sole();

    expect($prepared->hold_traffic)->toBeTrue()
        ->and(DatabasePreparations::is($prepared))->toBeTrue()
        ->and($db->environment->refresh()->last_applied_revision_id)->toBeNull()
        ->and(Placement::query()->where('service_instance_id', $web->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'data.import_prepared')->exists())->toBeTrue();

    Projects::report($server);
    Projects::report($server);
    expect($prepared->refresh()->status)->toBe(DeploymentStatus::Ready);
    $this->getJson(route('transfers.services.show', $db))
        ->assertJsonPath('preparation.state', 'ready')
        ->assertJsonPath('preparation.serverName', $server->name);

    // Only the database is in the server's desired state, on its volume.
    $state = Projects::nodeState($server);
    $placement = Placement::query()->where('deployment_id', $prepared->id)->sole();
    $volume = $db->volumes()->sole();
    expect(collect($state['workloads'])->pluck('id')->all())->toBe([$placement->workload_id])
        ->and(collect($state['volumes'])->pluck('id')->all())->toBe([$volume->public_id])
        ->and($volume->server_id)->toBe($server->id);
    $preparedEnv = workloadEnv($server, $keypair, $placement->workload_id);

    // The import runs against it (it was empty: no replace needed).
    [$transfer] = Transfers::upload($this, $owner, $db, Transfers::pgCustom('15.8', '15.8'));
    $this->postJson(route('transfers.services.imports.store', $db), ['transfer' => $transfer->public_id, 'replace' => false])->assertSuccessful();
    $run = DataImportRun::query()->sole();
    resolve(AdvanceImports::class)->handle();
    expect($run->refresh()->status)->toBe(ImportRunStatus::Importing);

    // Closing the sheet now changes nothing: the import keeps it.
    $this->deleteJson(route('transfers.services.unprepare', $db))->assertOk()->assertJsonPath('preparation.state', 'ready');

    $task = VolumeServices::lease($server, DaemonTaskType::DbImport);
    expect($task->spec->workload ?? null)->toBe($placement->workload_id);
    VolumeServices::finish($server, $task, 'succeeded', ['dataImport' => ['mode' => 'import', 'empty' => true, 'tables' => 9, 'sizeBytes' => 1024, 'serverVersion' => '18.1', 'format' => 'postgres_custom', 'sourceVersion' => 'PostgreSQL 15.8', 'readBytes' => 100, 'durationMs' => 900]]);

    // Done: it stops again (drains), the volume stays, nothing is deployed.
    expect($run->refresh()->status)->toBe(ImportRunStatus::Succeeded)
        ->and($prepared->refresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($placement->refresh()->role)->toBe(WorkloadRole::Draining)
        ->and($db->refresh()->active_deployment_id)->toBeNull();

    Projects::report($server);
    $this->travel(30)->seconds();
    resolve(SweepDeployments::class)->handle();
    expect(Placement::query()->where('deployment_id', $prepared->id)->exists())->toBeFalse()
        ->and(Projects::nodeState($server)['workloads'])->toBe([])
        ->and($volume->refresh()->server_id)->toBe($server->id);

    // The first deploy: the database on the same server and volume, same credentials; the app starts too.
    resolve(DeployEnvironment::class)->handle($db->environment, $owner, AuditActor::system());
    Projects::report($server);
    Projects::report($server);
    Projects::report($server);

    $db->refresh();
    $first = Placement::query()->where('deployment_id', $db->active_deployment_id)->sole();
    $env = workloadEnv($server, $keypair, $first->workload_id);
    expect($first->server_id)->toBe($server->id)
        ->and($first->workload_id)->not->toBe($placement->workload_id)
        ->and(collect(Projects::nodeState($server)['workloads'])->firstWhere('id', $first->workload_id)['mounts'][0]['volume'] ?? null)->toBe($volume->public_id)
        ->and($env['POSTGRES_PASSWORD'])->toBe($preparedEnv['POSTGRES_PASSWORD'])
        ->and($env['POSTGRES_USER'])->toBe($preparedEnv['POSTGRES_USER'])
        ->and($web->refresh()->active_deployment_id)->not->toBeNull();
});

test('closing the sheet without importing stops it; an abandoned one is swept', function (): void {
    [, $server, , $db, , $owner] = undeployedUmami();

    $this->actingAs($owner)->postJson(route('transfers.services.prepare', $db))->assertSuccessful();
    Projects::report($server);
    Projects::report($server);
    $this->deleteJson(route('transfers.services.unprepare', $db))->assertOk()->assertJsonPath('preparation.state', 'needed');
    expect(Deployment::query()->where('service_instance_id', $db->id)->sole()->status)->toBe(DeploymentStatus::Cancelled);

    // Again, then nobody keeps it: the tick stops it after the lease.
    $this->postJson(route('transfers.services.prepare', $db))->assertSuccessful();
    $second = Deployment::query()->where('service_instance_id', $db->id)->latest('id')->firstOrFail();
    $this->travel(DatabasePreparations::LEASE_SECONDS - 60)->seconds();
    // The open sheet keeps it.
    $this->postJson(route('transfers.services.prepare', $db))->assertSuccessful();
    $this->travel(120)->seconds();
    expect(resolve(SweepDatabasePreparations::class)->handle())->toBe(0);
    $this->travel(DatabasePreparations::LEASE_SECONDS)->seconds();
    expect(resolve(SweepDatabasePreparations::class)->handle())->toBe(1)
        ->and($second->refresh()->status->isTerminal())->toBeTrue();
});

test('deploying while it waits ends it and the deploy goes on; it is never promoted', function (): void {
    [, $server, , $db, , $owner] = undeployedUmami();

    $this->actingAs($owner)->postJson(route('transfers.services.prepare', $db))->assertSuccessful();
    Projects::report($server);
    Projects::report($server);
    $prepared = Deployment::query()->where('service_instance_id', $db->id)->sole();
    expect($prepared->status)->toBe(DeploymentStatus::Ready);

    expect(fn () => resolve(PromoteStagedDeployment::class)->handle($prepared, AuditActor::system()))->toThrow(DomainRuleViolation::class);

    resolve(DeployEnvironment::class)->handle($db->environment, $owner, AuditActor::system());
    expect($prepared->refresh()->status)->toBe(DeploymentStatus::Cancelled);

    Projects::report($server);
    Projects::report($server);
    Projects::report($server);
    expect($db->refresh()->active_deployment_id)->not->toBeNull();
});

test('a deployed database isn’t prepared; viewers can’t start one', function (): void {
    [$team, , , $postgres] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $viewer = memberOf($team, TeamRole::Viewer);

    $this->actingAs($owner)->getJson(route('transfers.services.show', $postgres))->assertJsonPath('preparation', null);
    $this->postJson(route('transfers.services.prepare', $postgres))->assertStatus(422);

    [, , , $db] = undeployedUmami($team);
    $this->actingAs($viewer)->postJson(route('transfers.services.prepare', $db))->assertForbidden();
    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->postJson(route('transfers.services.prepare', $db))->assertNotFound();
});
