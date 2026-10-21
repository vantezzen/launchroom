<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\StartDatabaseBackup;
use App\Actions\Backups\TakeSafetySnapshot;
use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Services\RemoveService;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskLease;
use App\Enums\Backups\BackupHealth;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\DestinationStatus;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Backups\BackupStatuses;
use Carbon\CarbonImmutable;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

function leaseBackupTask(Server $server, DaemonTaskType $type = DaemonTaskType::Backup): DaemonTask
{
    $tasks = resolve(LeaseTasks::class)->handle($server, LeaseRequest::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => [$type->value],
    ]));

    expect($tasks)->toHaveCount(1);

    // LeaseTasks returns protocol Task data; the tests work with the row.
    return DaemonTask::query()->where('public_id', $tasks[0]->id)->sole();
}

/**
 * @param  array<string, mixed>  $extra
 */
function completeBackupTask(Server $server, DaemonTask $task, string $outcome, array $extra = []): void
{
    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, 'outcome' => $outcome, ...$extra,
    ]));
}

test('a destination turns on daily, staggered backups for existing production databases', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);

    Backups::destination($team, $owner);

    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();

    expect($schedule->enabled)->toBeTrue()
        ->and($schedule->cron)->toMatch('/^\d{1,2} [1-6] \* \* \*$/')
        ->and($schedule->retention->toProtocol())->toBe(['keepDaily' => 7, 'keepWeekly' => 4, 'keepMonthly' => 3]);
});

test('a backup task is pinned to the database server, schema-valid and carries credentials sealed to that task', function (): void {
    [$team, $server, $keypair, $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $run = resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    $task = $run->daemonTask()->sole();

    expect($task->pinned_to_server)->toBeTrue()
        ->and($task->server_id)->toBe($server->id)
        ->and($task->type)->toBe(DaemonTaskType::Backup)
        ->and($task->max_attempts)->toBe(1);

    $leased = leaseBackupTask($server);
    $json = ProtocolJson::encode(new TaskLease([Task::fromTask($leased)]));
    expect(ProtocolSchemas::errors('task-lease', $json))->toBe([]);

    $spec = json_decode(json_encode($leased->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['target']['engine'])->toBe('postgres')
        ->and($spec['target']['workload'])->toStartWith('wl_')
        ->and($spec['destination']['endpoint'])->toBe('https://s3.example.com')
        ->and($spec['destination']['prefix'])->toBe('team-a')
        ->and($spec['tags'])->toContain('kind:database', 'reason:manual', 'service:'.$instance->service->public_id, 'server:'.$server->public_id);

    // No secret appears anywhere in the stored spec.
    expect($json)->not->toContain('super-secret-access-key-value')->not->toContain('AKIATESTKEY000001');

    $sealed = Backups::openTaskEnv($spec['destination']['credentials']['sealed'], $keypair);
    expect($sealed['serverId'])->toBe($server->public_id)
        ->and($sealed['taskId'])->toBe($leased->public_id)
        ->and(array_keys($sealed['env']))->toBe(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'RESTIC_PASSWORD'])
        ->and($sealed['env']['AWS_SECRET_ACCESS_KEY'])->toBe('super-secret-access-key-value')
        ->and(strlen($sealed['env']['RESTIC_PASSWORD']))->toBeGreaterThanOrEqual(40);
});

test('a successful backup records the snapshot and the canvas status becomes ok', function (): void {
    [$team, $server, , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $run = resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    $task = leaseBackupTask($server);
    resolve(RunBackupTick::class)->handle();
    expect($run->refresh()->status)->toBe(BackupRunStatus::Running);

    completeBackupTask($server, $task, 'succeeded', ['backup' => [
        'snapshotId' => str_repeat('ab', 32), 'sizeBytes' => 4096, 'addedBytes' => 512, 'durationMs' => 1200,
    ]]);

    $run->refresh();
    expect($run->status)->toBe(BackupRunStatus::Succeeded)
        ->and($run->snapshot_id)->toBe(str_repeat('ab', 32))
        ->and($run->size_bytes)->toBe(4096)
        ->and($run->destination()->sole()->status)->toBe(DestinationStatus::Ok);

    $statuses = resolve(BackupStatuses::class)->forEnvironment($instance->environment);
    expect($statuses[$instance->service->public_id]->status)->toBe(BackupHealth::Ok)
        ->and($statuses[$instance->service->public_id]->lastSuccessfulAt)->not->toBeNull();
});

test('a failed backup task fails the run and shows the database as failing', function (): void {
    [$team, $server, , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $run = resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    completeBackupTask($server, leaseBackupTask($server), 'failed', ['error' => 'backup: pg_dump exited with code 1: connection refused']);

    expect($run->refresh()->status)->toBe(BackupRunStatus::Failed)
        ->and($run->error)->toContain('pg_dump exited with code 1');

    $status = resolve(BackupStatuses::class)->forInstance($instance);
    expect($status->status)->toBe(BackupHealth::Failing)->and($status->lastError)->toContain('pg_dump');
});

test('only one backup per database is in flight', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $first = resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    $second = resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));

    expect($second->id)->toBe($first->id)
        ->and(DaemonTask::query()->where('type', DaemonTaskType::Backup->value)->count())->toBe(1);
});

test('a backup needs a destination and a running database', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);

    expect(fn () => resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner)))
        ->toThrow(DomainRuleViolation::class, 'Add a backup destination');

    expect(resolve(TakeSafetySnapshot::class)->handle($instance, BackupReason::PreDelete, $owner, AuditActor::user($owner)))->toBeNull();
});

test('due schedules start backups once per minute', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();
    $schedule->forceFill(['cron' => '30 2 * * *'])->save();

    $at = CarbonImmutable::parse('2026-10-19 02:30:10', 'UTC');
    CarbonImmutable::setTestNow($at);
    resolve(RunBackupTick::class)->handle($at);
    resolve(RunBackupTick::class)->handle($at->addSeconds(20));
    CarbonImmutable::setTestNow();

    expect(BackupRun::query()->where('service_instance_id', $instance->id)->where('reason', 'scheduled')->count())->toBe(1);
});

test('removing a database service takes a safety snapshot first', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    resolve(RemoveService::class)->handle($instance, AuditActor::user($owner));

    $run = BackupRun::query()->where('service_instance_id', $instance->id)->sole();
    expect($run->reason)->toBe(BackupReason::PreDelete)
        ->and($run->daemonTask()->sole()->status)->toBe(DaemonTaskStatus::Pending);
});

test('a lost task without completion fails the run on the next tick', function (): void {
    [$team, $server, , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $run = resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    $task = leaseBackupTask($server);
    $task->forceFill(['error' => 'lease expired']);
    $task->transitionTo(DaemonTaskStatus::Failed);

    resolve(RunBackupTick::class)->handle();

    expect($run->refresh()->status)->toBe(BackupRunStatus::Failed)->and($run->error)->toBe('lease expired');
});
