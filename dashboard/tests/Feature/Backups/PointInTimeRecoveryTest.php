<?php

declare(strict_types=1);

use App\Actions\Backups\EvaluateBackupAlerts;
use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\StartDatabaseBackup;
use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\Report;
use App\Data\Protocol\V1\TaskCompletion;
use App\Enums\Backups\BackupAlertKind;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Backups\VerificationStatus;
use App\Enums\Backups\WalArchiveState;
use App\Enums\DaemonTaskType;
use App\Enums\TeamRole;
use App\Events\Backups\BackupAlertRaised;
use App\Events\Backups\BackupAlertResolved;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\NodeStates\NodeStateValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    Cache::flush();
});

/**
 * @return array{Server, string, ServiceInstance, User}
 */
function pitrPostgres(): array
{
    [$team, $server, $keypair, $instance] = Backups::runningPostgres();
    $server->forceFill(['daemon_features' => ['pointInTime', 'verifyRestore']])->save();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    return [$server, $keypair, $instance, $owner];
}

function leaseTask(Server $server, DaemonTaskType $type): DaemonTask
{
    $tasks = resolve(LeaseTasks::class)->handle($server, LeaseRequest::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 8, 'types' => [$type->value],
    ]));
    expect($tasks)->toHaveCount(1);

    return DaemonTask::query()->where('public_id', $tasks[0]->id)->sole();
}

/**
 * @param  array<string, mixed>  $extra
 */
function completeTask(Server $server, DaemonTask $task, array $extra, string $outcome = 'succeeded'): void
{
    resolve(CompleteTask::class)->handle($server, $task, TaskCompletion::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task->attempt, 'outcome' => $outcome, ...$extra,
    ]));
}

/**
 * @return array<string, mixed>
 */
function taskSpec(DaemonTask $task): array
{
    return json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true);
}

/**
 * @param  array<string, mixed>  $status
 */
function reportWal(Server $server, array $status): void
{
    $server->refresh();
    resolve(RecordReport::class)->handle($server, Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'seq' => (int) (microtime(true) * 1_000_000), 'full' => true,
        'appliedVersion' => $server->desired_version, 'workloads' => [], 'walArchives' => [$status],
    ]));
}

function enablePitr(ServiceInstance $instance, User $owner): void
{
    test()->actingAs($owner)->putJson(route('backups.services.point-in-time', $instance), ['enabled' => true, 'keepDays' => 7])
        ->assertOk()
        ->assertJsonPath('pointInTime.enabled', true)
        ->assertJsonPath('pointInTime.keepDays', 7);
}

/**
 * A succeeded base backup (task completed with a snapshot).
 */
function baseBackupDone(Server $server, string $snapshot): BackupRun
{
    $task = leaseTask($server, DaemonTaskType::Backup);
    expect(taskSpec($task)['kind'])->toBe('base_backup');
    completeTask($server, $task, ['backup' => ['snapshotId' => $snapshot, 'sizeBytes' => 50_000_000, 'durationMs' => 4200]]);

    return BackupRun::query()->where('daemon_task_id', $task->id)->sole();
}

test('turning point-in-time recovery on archives WAL through basic_archive and ships it with sealed credentials', function (): void {
    [$server, $keypair, $instance, $owner] = pitrPostgres();

    $developer = memberOf($instance->team, TeamRole::Developer);
    $this->actingAs($developer)->putJson(route('backups.services.point-in-time', $instance), ['enabled' => true, 'keepDays' => 7])->assertForbidden();

    enablePitr($instance, $owner);
    resolve(RenderNodeState::class)->handle($server);

    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('service', $instance->service->public_id);
    $wal = $workload['walArchive'];

    // No shell anywhere: Postgres' own archive library, a fixed directory in the volume.
    expect($workload['args'])->toBe(['postgres', '-c', 'archive_mode=on', '-c', 'archive_library=basic_archive',
        '-c', 'basic_archive.archive_directory=/var/lib/postgresql/launchroom-wal', '-c', 'archive_timeout=60'])
        ->and($wal['directory'])->toBe('/var/lib/postgresql/launchroom-wal')
        ->and($wal['interval'])->toBe('30s')
        ->and($wal['tags'])->toContain('kind:wal', 'service:'.$instance->service->public_id)
        ->and(Projects::openEnv($wal['destination']['credentials']['sealed'], $keypair))->toBe([
            'AWS_ACCESS_KEY_ID' => 'AKIATESTKEY000001', 'AWS_SECRET_ACCESS_KEY' => 'super-secret-access-key-value',
            'RESTIC_PASSWORD' => Projects::openEnv($wal['destination']['credentials']['sealed'], $keypair)['RESTIC_PASSWORD'],
        ])
        ->and(resolve(NodeStateValidator::class)->problems(Projects::latestDocument($server)))->toBe([]);

    // A re-render with nothing changed needs no new version (the sealed box is randomized).
    $version = $server->refresh()->desired_version;
    resolve(RenderNodeState::class)->handle($server);
    expect($server->refresh()->desired_version)->toBe($version);

    // Servers whose launchroomd doesn't know the feature get no walArchive.
    $server->forceFill(['daemon_features' => []])->save();
    resolve(RenderNodeState::class)->handle($server);
    $workload = collect(Projects::nodeState($server)['workloads'])->firstWhere('service', $instance->service->public_id);
    expect($workload)->not->toHaveKey('walArchive')
        ->and($workload['args'] ?? null)->toBeNull();
});

test('reports move the WAL state forward and the first base backup starts once shipping is active', function (): void {
    [$server, , $instance, $owner] = pitrPostgres();
    enablePitr($instance, $owner);
    $workload = $instance->placements()->sole()->workload_id;

    resolve(RunBackupTick::class)->handle();
    expect(BackupRun::query()->where('subject', BackupSubject::BaseBackup->value)->count())->toBe(0);

    reportWal($server, ['workload' => $workload, 'state' => 'active', 'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'lastArchivedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'lastSegment' => '000000010000000000000003', 'pendingSegments' => 0]);
    // An older report changes nothing; a restarted launchroomd without lastArchivedAt keeps it.
    reportWal($server, ['workload' => $workload, 'state' => 'failing', 'checkedAt' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'), 'pendingSegments' => 9]);
    reportWal($server, ['workload' => $workload, 'state' => 'active', 'checkedAt' => now()->addSecond()->utc()->format('Y-m-d\TH:i:s\Z'), 'pendingSegments' => 0, 'lastSegment' => '000000010000000000000002']);

    $archive = WalArchive::query()->sole();
    expect($archive->state)->toBe(WalArchiveState::Active)
        ->and($archive->last_archived_at)->not->toBeNull()
        ->and($archive->last_segment)->toBe('000000010000000000000003');

    resolve(RunBackupTick::class)->handle();
    $base = baseBackupDone($server, str_repeat('b', 64));
    expect($base->status)->toBe(BackupRunStatus::Succeeded)
        ->and($base->duration_ms)->toBe(4200);

    // A base backup is not offered as a snapshot to restore directly; the window starts with it.
    $data = $this->actingAs($owner)->getJson(route('backups.services.show', $instance))->assertOk();
    expect(collect($data->json('snapshots'))->pluck('id'))->not->toContain(str_repeat('b', 64))
        ->and($data->json('pointInTime.windowStart'))->not->toBeNull()
        ->and($data->json('trend.0.durationMs'))->toBe(4200);
});

test('restoring to a point in time as a new service replays the base backup with the copy’s own credentials', function (): void {
    [$server, $keypair, $instance, $owner] = pitrPostgres();
    enablePitr($instance, $owner);
    WalArchive::query()->update(['state' => 'active', 'last_archived_at' => now()]);
    resolve(RunBackupTick::class)->handle();
    $base = baseBackupDone($server, str_repeat('b', 64));
    $base->forceFill(['finished_at' => now()->subHour()])->save();

    $at = CarbonImmutable::now()->subMinutes(10)->startOfSecond();
    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['pointInTime' => true, 'at' => $at->toIso8601ZuluString(), 'mode' => 'new_service'])
        ->assertSuccessful()
        ->assertJsonPath('restores.0.pointInTime', true);

    // Before the window: refused.
    RestoreRun::query()->update(['status' => 'failed']);
    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['pointInTime' => true, 'at' => now()->subDays(3)->toIso8601ZuluString(), 'mode' => 'new_service'])
        ->assertStatus(422);
    RestoreRun::query()->update(['status' => 'waiting_for_target']);

    $restore = RestoreRun::query()->sole();
    expect($restore->subject)->toBe(BackupSubject::BaseBackup)
        ->and($restore->snapshot_id)->toBe(str_repeat('b', 64))
        ->and($restore->volume_id)->not->toBeNull();

    Projects::report($server);
    Projects::report($server);
    resolve(RunBackupTick::class)->handle();
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring);

    $task = leaseTask($server, DaemonTaskType::Restore);
    $spec = taskSpec($task);
    $target = ServiceInstance::query()->whereKey($restore->target_service_instance_id)->sole();
    $env = Backups::openTaskEnv($spec['runtime']['env']['sealed'], $keypair);

    expect($spec['kind'])->toBe('base_backup')
        ->and($spec['target'])->toBe(['engine' => 'postgres', 'volume' => $target->volumes()->sole()->public_id])
        ->and($spec['pointInTime'])->toStartWith($at->format('Y-m-d\TH:i:s'))
        ->and($spec['walTags'])->toBe(['kind:wal', 'environment:'.$instance->environment->public_id, 'service:'.$instance->service->public_id])
        ->and($spec['runtime']['dataDirectory'])->toBe('/var/lib/postgresql/18/docker')
        ->and($spec['runtime'])->not->toHaveKey('archiveDirectory')
        ->and(array_keys($env['env']))->toEqualCanonicalizing(['POSTGRES_USER', 'POSTGRES_PASSWORD', 'POSTGRES_DB'])
        ->and($env['taskId'])->toBe($task->public_id);

    // The copy is held while its volume is replaced.
    resolve(RenderNodeState::class)->handle($server);
    expect(collect(Projects::nodeState($server)['workloads'])->pluck('service'))->not->toContain($target->service->public_id);

    completeTask($server, $task, ['restore' => ['sizeBytes' => 10, 'durationMs' => 7, 'restoredTo' => $at->subSeconds(3)->format('Y-m-d\TH:i:s.u\Z')]]);
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Succeeded)
        ->and($restore->restored_to?->toImmutable()->equalTo($at->subSeconds(3)))->toBeTrue();
});

test('restoring to a point in time in place needs the typed name, a safety snapshot and holds the database', function (): void {
    [$server, , $instance, $owner] = pitrPostgres();
    enablePitr($instance, $owner);
    WalArchive::query()->update(['state' => 'active', 'last_archived_at' => now()]);
    resolve(RunBackupTick::class)->handle();
    baseBackupDone($server, str_repeat('b', 64))->forceFill(['finished_at' => now()->subHour()])->save();

    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['pointInTime' => true, 'mode' => 'in_place', 'confirmation' => 'nope'])->assertStatus(422);
    $developer = memberOf($instance->team, TeamRole::Developer);
    $this->actingAs($developer)->postJson(route('backups.services.restore', $instance), ['pointInTime' => true, 'mode' => 'in_place', 'confirmation' => $instance->service->slug])->assertForbidden();

    $this->actingAs($owner)->postJson(route('backups.services.restore', $instance), ['pointInTime' => true, 'mode' => 'in_place', 'confirmation' => $instance->service->slug])
        ->assertSuccessful()
        ->assertJsonPath('restores.0.status', 'waiting_for_safety_backup');
    $restore = RestoreRun::query()->sole();
    expect($restore->point_in_time)->toBeNull();

    completeTask($server, leaseTask($server, DaemonTaskType::Backup), ['backup' => ['snapshotId' => str_repeat('d', 64), 'sizeBytes' => 5, 'durationMs' => 1]]);
    resolve(RunBackupTick::class)->handle();
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring);

    $spec = taskSpec(leaseTask($server, DaemonTaskType::Restore));
    expect($spec['mode'])->toBe('in_place')
        ->and($spec)->not->toHaveKey('pointInTime')
        // Archiving stays on during the replay: the new timeline is shipped too.
        ->and($spec['runtime']['archiveDirectory'])->toBe('/var/lib/postgresql/launchroom-wal');

    resolve(RenderNodeState::class)->handle($server);
    expect(collect(Projects::nodeState($server)['workloads'])->pluck('service'))->not->toContain($instance->service->public_id);
});

test('verification restores the newest backup into a throwaway container and the tab shows the result', function (): void {
    [$server, , $instance, $owner] = pitrPostgres();
    resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    completeTask($server, leaseTask($server, DaemonTaskType::Backup), ['backup' => ['snapshotId' => str_repeat('c', 64), 'sizeBytes' => 10, 'durationMs' => 5]]);

    $viewer = memberOf($instance->team, TeamRole::Viewer);
    $this->actingAs($viewer)->postJson(route('backups.services.verify', $instance))->assertForbidden();

    $this->actingAs($owner)->postJson(route('backups.services.verify', $instance))
        ->assertSuccessful()
        ->assertJsonPath('verification.status', 'pending')
        ->assertJsonPath('canVerify', true);

    $task = leaseTask($server, DaemonTaskType::VerifyRestore);
    $spec = taskSpec($task);
    expect($spec)->toMatchArray(['kind' => 'database', 'snapshot' => str_repeat('c', 64), 'engine' => 'postgres', 'dataPath' => '/var/lib/postgresql'])
        ->and($spec['image'])->toContain('@sha256:')
        ->and($spec['resources'])->toEqual(['cpus' => 1, 'memoryMb' => 1024, 'pids' => 512]);

    completeTask($server, $task, ['verify' => ['passed' => true, 'durationMs' => 9000, 'tables' => 4, 'rows' => 120, 'checks' => [
        ['name' => 'restore', 'passed' => true, 'detail' => '1.2 MiB restored'],
        ['name' => 'query', 'passed' => true, 'detail' => '4 tables, 120 rows'],
        ['name' => 'amcheck', 'passed' => true],
    ]]]);

    $verification = BackupVerification::query()->sole();
    expect($verification->status)->toBe(VerificationStatus::Passed)
        ->and($verification->rows)->toBe(120);

    $this->actingAs($viewer)->getJson(route('backups.services.show', $instance))
        ->assertOk()
        ->assertJsonPath('verification.status', 'passed')
        ->assertJsonPath('verification.tables', 4)
        ->assertJsonPath('verification.checks.2.name', 'amcheck')
        ->assertJsonPath('canVerify', false);
});

test('with point-in-time recovery the newest base backup is verified together with the WAL', function (): void {
    [$server, , $instance, $owner] = pitrPostgres();
    enablePitr($instance, $owner);
    WalArchive::query()->update(['state' => 'active', 'last_archived_at' => now()]);
    resolve(RunBackupTick::class)->handle();
    baseBackupDone($server, str_repeat('b', 64));

    $this->actingAs($owner)->postJson(route('backups.services.verify', $instance))->assertSuccessful();
    $spec = taskSpec(leaseTask($server, DaemonTaskType::VerifyRestore));

    expect($spec)->toMatchArray([
        'kind' => 'base_backup', 'snapshot' => str_repeat('b', 64), 'dataDirectory' => '/var/lib/postgresql/18/docker',
        'databaseUser' => 'app', 'databaseName' => 'app',
    ])->and($spec['walTags'])->toContain('kind:wal');
});

test('scheduled verification runs weekly at the instance’s minute, once it has a backup', function (): void {
    [$server, , $instance, $owner] = pitrPostgres();
    resolve(RunBackupTick::class)->handle();
    resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    completeTask($server, leaseTask($server, DaemonTaskType::Backup), ['backup' => ['snapshotId' => str_repeat('c', 64), 'sizeBytes' => 10, 'durationMs' => 5]]);

    $minute = crc32($instance->public_id.'|verify') % 60;
    $other = CarbonImmutable::now()->utc()->setTime(12, ($minute + 1) % 60);
    resolve(RunBackupTick::class)->handle($other);
    expect(BackupVerification::query()->count())->toBe(0);

    $due = CarbonImmutable::now()->utc()->setTime(12, $minute);
    resolve(RunBackupTick::class)->handle($due);
    resolve(RunBackupTick::class)->handle($due);
    expect(BackupVerification::query()->count())->toBe(1);
});

test('failed backups, failed verifications and WAL lag raise alerts once and resolve on recovery', function (): void {
    Event::fake([BackupAlertRaised::class, BackupAlertResolved::class]);
    [$server, , $instance, $owner] = pitrPostgres();
    resolve(RunBackupTick::class)->handle();

    resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    completeTask($server, leaseTask($server, DaemonTaskType::Backup), [], 'failed');

    resolve(EvaluateBackupAlerts::class)->handle();
    resolve(EvaluateBackupAlerts::class)->handle();
    Event::assertDispatchedTimes(BackupAlertRaised::class, 1);
    Event::assertDispatched(BackupAlertRaised::class, fn (BackupAlertRaised $event): bool => $event->kind === BackupAlertKind::Failed
        && $event->subject === 'backup:'.$instance->public_id && ! str_contains($event->summary, 'super-secret'));

    resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    completeTask($server, leaseTask($server, DaemonTaskType::Backup), ['backup' => ['snapshotId' => str_repeat('c', 64), 'sizeBytes' => 10, 'durationMs' => 5]]);
    resolve(EvaluateBackupAlerts::class)->handle();
    Event::assertDispatched(BackupAlertResolved::class, fn (BackupAlertResolved $event): bool => $event->kind === BackupAlertKind::Failed);

    // Verification failed.
    $this->actingAs($owner)->postJson(route('backups.services.verify', $instance))->assertSuccessful();
    completeTask($server, leaseTask($server, DaemonTaskType::VerifyRestore), ['verify' => ['passed' => false, 'durationMs' => 9, 'checks' => [
        ['name' => 'restore', 'passed' => false, 'detail' => 'pg_restore exited with code 1'],
    ]]]);
    $failed = BackupVerification::query()->sole();
    expect($failed->status)->toBe(VerificationStatus::Failed)
        ->and($failed->error)->toBe('restore: pg_restore exited with code 1');
    resolve(EvaluateBackupAlerts::class)->handle();
    Event::assertDispatched(BackupAlertRaised::class, fn (BackupAlertRaised $event): bool => $event->kind === BackupAlertKind::VerificationFailed);

    // WAL lagging: on for a while, nothing shipped, segments waiting.
    enablePitr($instance, $owner);
    WalArchive::query()->update(['enabled_at' => now()->subHour(), 'state' => 'failing', 'failing_since' => now()->subMinutes(30), 'checked_at' => now(), 'pending_segments' => 4]);
    resolve(EvaluateBackupAlerts::class)->handle();
    Event::assertDispatched(BackupAlertRaised::class, fn (BackupAlertRaised $event): bool => $event->kind === BackupAlertKind::WalLagging && $event->subject === 'wal_archive:'.$instance->public_id);

    WalArchive::query()->update(['state' => 'active', 'failing_since' => null, 'pending_segments' => 0, 'last_archived_at' => now()]);
    resolve(EvaluateBackupAlerts::class)->handle();
    Event::assertDispatched(BackupAlertResolved::class, fn (BackupAlertResolved $event): bool => $event->kind === BackupAlertKind::WalLagging);
});

test('backup alerts reach the alerts API', function (): void {
    [$server, , $instance, $owner] = pitrPostgres();
    resolve(StartDatabaseBackup::class)->handle($instance, BackupReason::Manual, $owner, AuditActor::user($owner));
    completeTask($server, leaseTask($server, DaemonTaskType::Backup), [], 'failed');

    resolve(EvaluateBackupAlerts::class)->handle();

    $this->assertDatabaseHas('alerts', ['team_id' => $instance->team_id, 'rule' => 'backup_failed', 'subject_key' => 'backup:'.$instance->public_id, 'status' => 'firing']);
});
