<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\StartDatabaseBackup;
use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DaemonTaskType;
use App\Enums\TeamRole;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

function leaseOneOf(Server $server, DaemonTaskType $type): DaemonTask
{
    $tasks = resolve(LeaseTasks::class)->handle($server, LeaseRequest::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => [$type->value],
    ]));
    expect($tasks)->toHaveCount(1);

    return DaemonTask::query()->where('public_id', $tasks[0]->id)->sole();
}

/**
 * @param  array<string, mixed>  $extra
 */
function finishTask(Server $server, DaemonTask $task, string $outcome, array $extra = []): void
{
    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, 'outcome' => $outcome, ...$extra,
    ]));
}

/**
 * A running Postgres with one successful backup.
 *
 * @return array{Team, Server, ServiceInstance, User, string}
 */
function backedUpPostgres(): array
{
    [$team, $server, , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    $snapshot = str_repeat('c', 64);
    finishTask($server, leaseOneOf($server, DaemonTaskType::Backup), 'succeeded', ['backup' => ['snapshotId' => $snapshot, 'sizeBytes' => 10, 'durationMs' => 5]]);

    return [$team, $server, $instance, $owner, $snapshot];
}

test('the backups tab shows snapshots and actions for team members only', function (): void {
    [$team, , $instance, $owner, $snapshot] = backedUpPostgres();
    $instance->load('service', 'environment.project');

    $response = $this->actingAs($owner)->getJson(route('backups.services.lookup', [$instance->environment->project, $instance->environment, $instance->service]))
        ->assertOk()
        ->assertJsonPath('serviceInstanceId', $instance->public_id)
        ->assertJsonPath('supported', true)
        ->assertJsonPath('restoreSupported', true)
        ->assertJsonPath('status.status', 'ok')
        ->assertJsonPath('snapshots.0.id', $snapshot)
        ->assertJsonPath('canRestoreInPlace', true)
        ->assertJsonPath('urls.restore', route('backups.services.restore', $instance));

    expect($response->getContent())->not->toContain('super-secret');

    $viewer = memberOf($team, TeamRole::Viewer);
    $this->actingAs($viewer)->getJson(route('backups.services.show', $instance))
        ->assertOk()
        ->assertJsonPath('canBackup', false)
        ->assertJsonPath('canRestoreAsNew', false)
        ->assertJsonPath('canRestoreInPlace', false);
    $this->actingAs($viewer)->postJson(route('backups.services.backup', $instance))->assertForbidden();

    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($stranger)->getJson(route('backups.services.show', $instance))->assertNotFound();
    $this->actingAs($stranger)->postJson(route('backups.services.restore', $instance), ['snapshot' => $snapshot, 'mode' => 'new_service'])->assertNotFound();
});

test('restore as a new service adds the copy to this environment only, deploys it and restores once it runs', function (): void {
    [$team, $server, $instance, $owner, $snapshot] = backedUpPostgres();
    $developer = memberOf($team, TeamRole::Developer);

    $this->actingAs($developer)->postJson(route('backups.services.restore', $instance), ['snapshot' => substr($snapshot, 0, 8), 'mode' => 'new_service'])
        ->assertSuccessful()
        ->assertJsonPath('restores.0.status', 'waiting_for_target');

    $restore = RestoreRun::query()->sole();
    $target = ServiceInstance::query()->whereKey($restore->target_service_instance_id)->with('service')->sole();

    expect($restore->snapshot_id)->toBe($snapshot)
        ->and($target->service->slug)->toStartWith('postgres-restored-')
        ->and(ServiceInstance::query()->where('service_id', $target->service_id)->count())->toBe(1)
        ->and($target->environment_id)->toBe($instance->environment_id)
        ->and($target->deployments()->count())->toBe(1);

    // Not running yet: nothing happens.
    resolve(RunBackupTick::class)->handle();
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::WaitingForTarget);

    Projects::report($server);
    Projects::report($server);
    resolve(RunBackupTick::class)->handle();

    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring);
    $task = leaseOneOf($server, DaemonTaskType::Restore);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true);
    expect($spec['mode'])->toBe('new_service')
        ->and($spec['snapshot'])->toBe($snapshot)
        ->and($spec['target']['workload'])->toBe($target->placements()->sole()->workload_id);

    finishTask($server, $task, 'succeeded', ['restore' => ['sizeBytes' => 10, 'durationMs' => 7]]);
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Succeeded);
});

test('restore into this service needs the typed name and waits for a safety snapshot', function (): void {
    [$team, $server, $instance, $owner, $snapshot] = backedUpPostgres();
    $instance->load('service');

    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['snapshot' => $snapshot, 'mode' => 'in_place', 'confirmation' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('confirmation');

    $developer = memberOf($team, TeamRole::Developer);
    $this->actingAs($developer)->postJson(route('backups.services.restore', $instance), ['snapshot' => $snapshot, 'mode' => 'in_place', 'confirmation' => $instance->service->slug])
        ->assertForbidden();

    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['snapshot' => $snapshot, 'mode' => 'in_place', 'confirmation' => $instance->service->slug])
        ->assertSuccessful();

    $restore = RestoreRun::query()->sole();
    $safety = BackupRun::query()->whereKey($restore->safety_backup_run_id)->sole();
    expect($restore->status)->toBe(RestoreRunStatus::WaitingForSafetyBackup)
        ->and($safety->reason)->toBe(BackupReason::PreRestore);

    resolve(RunBackupTick::class)->handle();
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::WaitingForSafetyBackup);

    finishTask($server, leaseOneOf($server, DaemonTaskType::Backup), 'succeeded', ['backup' => ['snapshotId' => str_repeat('d', 64), 'sizeBytes' => 10, 'durationMs' => 5]]);
    resolve(RunBackupTick::class)->handle();

    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring);
    $spec = json_decode(json_encode(leaseOneOf($server, DaemonTaskType::Restore)->spec, JSON_THROW_ON_ERROR), true);
    expect($spec['mode'])->toBe('in_place')->and($spec['snapshot'])->toBe($snapshot);
});

test('a failed safety snapshot cancels the restore and leaves the database alone', function (): void {
    [, $server, $instance, $owner, $snapshot] = backedUpPostgres();
    $instance->load('service');

    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['snapshot' => $snapshot, 'mode' => 'in_place', 'confirmation' => $instance->service->slug])
        ->assertSuccessful();
    finishTask($server, leaseOneOf($server, DaemonTaskType::Backup), 'failed', ['error' => 'restic backup failed (exit 1)']);
    resolve(RunBackupTick::class)->handle();

    $restore = RestoreRun::query()->sole();
    expect($restore->status)->toBe(RestoreRunStatus::Failed)
        ->and($restore->error)->toContain('unchanged')
        ->and(DaemonTask::query()->where('type', DaemonTaskType::Restore->value)->count())->toBe(0);
});

test('only snapshots of this database can be restored', function (): void {
    [, , $instance, $owner] = backedUpPostgres();

    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['snapshot' => str_repeat('e', 64), 'mode' => RestoreMode::NewService->value])
        ->assertStatus(422)
        ->assertJsonValidationErrors('snapshot');
    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['snapshot' => '../../etc', 'mode' => 'new_service'])
        ->assertStatus(422);
});

test('refreshing snapshots lists them from the repository through a daemon task', function (): void {
    [, $server, $instance, $owner] = backedUpPostgres();

    $this->actingAs($owner)->postJson(route('backups.services.snapshots', $instance))
        ->assertSuccessful()
        ->assertJsonPath('listingStatus', 'pending');

    $task = leaseOneOf($server, DaemonTaskType::BackupSnapshots);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true);
    expect($spec['tags'])->toContain('kind:database', 'service:'.$instance->service->public_id);

    $older = str_repeat('f', 64);
    finishTask($server, $task, 'succeeded', ['snapshots' => ['snapshots' => [
        ['id' => $older, 'time' => '2026-01-02T03:04:05Z', 'tags' => ['kind:database', 'reason:scheduled'], 'sizeBytes' => 99],
    ]]]);

    $this->actingAs($owner)->getJson(route('backups.services.show', $instance))
        ->assertOk()
        ->assertJsonPath('listingStatus', 'ready')
        ->assertJsonPath('snapshots.1.id', $older)
        ->assertJsonPath('snapshots.1.source', 'repository');

    // A repository-only snapshot is restorable once listed.
    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['snapshot' => $older, 'mode' => 'new_service'])
        ->assertSuccessful();
});
