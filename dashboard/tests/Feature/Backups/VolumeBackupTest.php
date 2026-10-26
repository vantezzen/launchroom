<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\StartServiceBackup;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskLease;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\Backups\VolumeConsistency;
use App\Enums\DaemonTaskType;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\DeletionRequest;
use App\Models\Backups\RestoreRun;
use App\Models\Placement;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Variable;
use App\Models\Volume;
use App\Services\Backups\BackupStatuses;
use App\Services\Variables\VariableStore;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;
use Tests\Support\VolumeServices;

/*
| Volume backups (docs/14 §2, §9.12): services with volumes (not databases)
| are backed up as restic snapshots of a tar stream of the volume, read by
| launchroomd through a helper container; restores replace the volume's
| content while the service is held out of the desired state.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, $this->server, $this->keypair, $this->svc] = VolumeServices::runningApp();
    $this->volume = Volume::query()->where('service_instance_id', $this->svc->id)->sole();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->svc->load('service', 'environment.project');
    $this->lookup = route('backups.services.lookup', [$this->svc->environment->project, $this->svc->environment, $this->svc->service]);
});

test('adding a volume pins the service to its server; mount paths are checked', function (): void {
    expect($this->volume->server_id)->toBe($this->server->id)
        ->and($this->volume->backup_consistency)->toBe(VolumeConsistency::Live);

    $state = Projects::nodeState($this->server->refresh());
    expect($state['volumes'])->toBe([['id' => $this->volume->public_id, 'name' => $this->volume->dockerName()]])
        ->and($state['workloads'][0]['mounts'])->toBe([['volume' => $this->volume->public_id, 'target' => '/data/uploads']]);

    $developer = memberOf($this->team, TeamRole::Developer);
    $url = route('projects.services.volumes.store', [$this->svc->environment->project, $this->svc->environment, $this->svc->service]);
    $this->actingAs($developer)->post($url, ['mountPath' => '/proc/self'])->assertSessionHasErrors('mountPath');
    $this->actingAs($developer)->post($url, ['mountPath' => '/data/../etc'])->assertSessionHasErrors('mountPath');
    $this->actingAs($developer)->post($url, ['mountPath' => '/data/uploads/inner'])->assertSessionHasErrors('mountPath');
    $this->actingAs($developer)->post($url, ['mountPath' => 'relative'])->assertSessionHasErrors('mountPath');
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))->post($url, ['mountPath' => '/srv/cache'])->assertForbidden();
    $this->actingAs($developer)->post($url, ['mountPath' => '/srv/cache'])->assertRedirect()->assertSessionHasNoErrors();

    expect(Volume::query()->where('service_instance_id', $this->svc->id)->pluck('mount_path')->sort()->values()->all())->toBe(['/data/uploads', '/srv/cache'])
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceVolumeAdded->value)->exists())->toBeTrue();
});

test('a default destination schedules volume services daily with the volume retention', function (): void {
    Backups::destination($this->team, $this->owner);

    $schedule = BackupSchedule::query()->where('service_instance_id', $this->svc->id)->sole();
    expect($schedule->subject)->toBe(BackupSubject::Volume)
        ->and($schedule->retention->toProtocol())->toBe(['keepDaily' => 7, 'keepWeekly' => 4]);
});

test('a volume backup is a pinned, schema-valid volume task with sealed credentials and volume tags', function (): void {
    Backups::destination($this->team, $this->owner);

    $runs = resolve(StartServiceBackup::class)->handle($this->svc, BackupReason::Manual, $this->owner, AuditActor::user($this->owner));
    expect($runs)->toHaveCount(1)
        ->and($runs[0]->subject)->toBe(BackupSubject::Volume)
        ->and($runs[0]->volume_id)->toBe($this->volume->id);

    $task = VolumeServices::lease($this->server);
    expect(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease([Task::fromTask($task)]))))->toBe([]);

    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['kind'])->toBe('volume')
        ->and($spec['target'])->toBe(['volume' => $this->volume->public_id, 'consistency' => 'live'])
        ->and($spec['tags'])->toContain('kind:volume', 'volume:'.$this->volume->public_id, 'service:'.$this->svc->service->public_id, 'reason:manual')
        ->and(array_keys(Backups::openTaskEnv($spec['destination']['credentials']['sealed'], $this->keypair)['env']))->toBe(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'RESTIC_PASSWORD']);

    // One backup per volume in flight.
    expect(resolve(StartServiceBackup::class)->handle($this->svc, BackupReason::Manual, $this->owner, AuditActor::user($this->owner))[0]->id)->toBe($runs[0]->id);

    VolumeServices::finish($this->server, $task, 'succeeded', ['backup' => ['snapshotId' => str_repeat('a1', 32), 'sizeBytes' => 4096, 'durationMs' => 7]]);

    expect($runs[0]->refresh()->status)->toBe(BackupRunStatus::Succeeded)
        ->and(resolve(BackupStatuses::class)->forEnvironment($this->svc->environment)[$this->svc->service->public_id]->status->value)->toBe('ok');

    $this->actingAs($this->owner)->getJson($this->lookup)
        ->assertOk()
        ->assertJsonPath('subject', 'volume')
        ->assertJsonPath('supported', true)
        ->assertJsonPath('restoreSupported', true)
        ->assertJsonPath('volumes.0.mountPath', '/data/uploads')
        ->assertJsonPath('volumes.0.consistency', 'live')
        ->assertJsonPath('snapshots.0.mountPath', '/data/uploads')
        ->assertJsonPath('snapshots.0.volumeId', $this->volume->public_id);
});

test('owners and admins choose the consistency; a paused volume backup says so in its task', function (): void {
    Backups::destination($this->team, $this->owner);
    $url = route('backups.services.volumes.update', ['serviceInstance' => $this->svc, 'volume' => $this->volume->public_id]);

    $this->actingAs(memberOf($this->team, TeamRole::Developer))->putJson($url, ['consistency' => 'pause'])->assertForbidden();
    $this->actingAs($this->owner)->putJson($url, ['consistency' => 'sometimes'])->assertUnprocessable();
    $this->actingAs($this->owner)->putJson(route('backups.services.volumes.update', ['serviceInstance' => $this->svc, 'volume' => 'vol_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ']), ['consistency' => 'pause'])->assertNotFound();
    $this->actingAs($this->owner)->putJson($url, ['consistency' => 'pause'])->assertOk()->assertJsonPath('volumes.0.consistency', 'pause');

    expect(AuditEvent::query()->where('action', AuditAction::BackupVolumeUpdated->value)->exists())->toBeTrue();

    $this->actingAs($this->owner)->postJson(route('backups.services.backup', $this->svc))->assertSuccessful();
    $spec = json_decode(json_encode(VolumeServices::lease($this->server)->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['target']['consistency'])->toBe('pause');
});

test('restoring into the service takes a safety snapshot, holds the service while the volume is replaced, then starts it again', function (): void {
    Backups::destination($this->team, $this->owner);
    resolve(StartServiceBackup::class)->handle($this->svc, BackupReason::Manual, $this->owner, AuditActor::user($this->owner));
    VolumeServices::backedUp($this->server, str_repeat('b2', 32));

    $this->actingAs($this->owner)->postJson(route('backups.services.restore', $this->svc), [
        'snapshot' => substr(str_repeat('b2', 32), 0, 12), 'mode' => 'in_place', 'confirmation' => 'wrong',
    ])->assertUnprocessable();
    $this->actingAs($this->owner)->postJson(route('backups.services.restore', $this->svc), [
        'snapshot' => substr(str_repeat('b2', 32), 0, 12), 'mode' => 'in_place', 'confirmation' => $this->svc->service->slug,
    ])->assertSuccessful();

    $restore = RestoreRun::query()->sole();
    expect($restore->subject)->toBe(BackupSubject::Volume)
        ->and($restore->volume_id)->toBe($this->volume->id)
        ->and($restore->status)->toBe(RestoreRunStatus::WaitingForSafetyBackup)
        ->and($restore->safetyBackup()->sole()->reason)->toBe(BackupReason::PreRestore);

    VolumeServices::backedUp($this->server, str_repeat('c3', 32));
    resolve(RunBackupTick::class)->handle();
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring);

    // Held: the workload and its volume left the desired state; launchroomd stops it.
    resolve(RenderNodeState::class)->handle($this->server);
    expect(Projects::nodeState($this->server->refresh())['workloads'] ?? [])->toBe([]);

    $task = VolumeServices::lease($this->server, DaemonTaskType::Restore);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['kind'])->toBe('volume')
        ->and($spec['mode'])->toBe('in_place')
        ->and($spec['snapshot'])->toBe(str_repeat('b2', 32))
        ->and($spec['target'])->toBe(['volume' => $this->volume->public_id]);

    VolumeServices::finish($this->server, $task, 'succeeded', ['restore' => ['sizeBytes' => 4096, 'durationMs' => 9]]);
    resolve(RenderNodeState::class)->handle($this->server);

    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Succeeded)
        ->and(Projects::nodeState($this->server->refresh())['workloads'])->toHaveCount(1);
});

test('restoring as a new service copies the service with its settings, variables and volume paths', function (): void {
    Backups::destination($this->team, $this->owner);
    $project = $this->svc->environment->project;
    resolve(VariableStore::class)->create($project, $this->svc->service, 'UPLOAD_LIMIT', '10m', VariableType::Config, [['environment_id' => $this->svc->environment_id, 'all_previews' => false, 'branch_pattern' => null]]);
    resolve(DeployEnvironment::class)->handle($this->svc->environment, null, AuditActor::system());
    Projects::report($this->server);
    Projects::report($this->server);

    resolve(StartServiceBackup::class)->handle($this->svc, BackupReason::Manual, $this->owner, AuditActor::user($this->owner));
    VolumeServices::backedUp($this->server, str_repeat('d4', 32));

    $this->actingAs($this->owner)->postJson(route('backups.services.restore', $this->svc), ['snapshot' => str_repeat('d4', 32), 'mode' => 'new_service'])->assertSuccessful();

    $restore = RestoreRun::query()->sole();
    $copy = ServiceInstance::query()->whereKey($restore->target_service_instance_id)->with('service', 'volumes')->sole();
    expect($copy->service->name)->toStartWith('uploads-restored-')
        ->and($copy->service->kind)->toBe($this->svc->service->kind)
        ->and($copy->volumes->pluck('mount_path')->all())->toBe(['/data/uploads'])
        ->and($restore->volume_id)->toBe($copy->volumes->first()?->id)
        ->and(Variable::query()->where('service_id', $copy->service_id)->where('key', 'UPLOAD_LIMIT')->exists())->toBeTrue()
        ->and($restore->status)->toBe(RestoreRunStatus::WaitingForTarget);

    // Deployed with the restore (nothing else was staged); the restore starts once it runs.
    Projects::report($this->server);
    Projects::report($this->server);
    resolve(RunBackupTick::class)->handle();

    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring);
    $spec = json_decode(json_encode(VolumeServices::lease($this->server, DaemonTaskType::Restore)->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['target']['volume'])->toBe($copy->volumes->first()?->public_id)
        ->and($spec['mode'])->toBe('new_service')
        ->and(Placement::query()->where('service_instance_id', $this->svc->id)->exists())->toBeTrue();
});

test('database volumes are never file-copied; services without data have nothing to back up', function (): void {
    [, , , $postgres] = Backups::runningPostgres($this->team);
    Backups::destination($this->team, $this->owner);

    $runs = resolve(StartServiceBackup::class)->handle($postgres, BackupReason::Manual, $this->owner, AuditActor::user($this->owner));
    expect($runs[0]->subject)->toBe(BackupSubject::Database)->and($runs[0]->volume_id)->toBeNull();

    $project = Projects::create($this->team, 'plain');
    $plain = ServiceInstance::query()->where('service_id', Projects::image($project, 'web')->id)->sole();
    expect(fn () => resolve(StartServiceBackup::class)->handle($plain, BackupReason::Manual, $this->owner, AuditActor::user($this->owner)))
        ->toThrow(DomainRuleViolation::class, 'This service has no data to back up');

    $this->actingAs($this->owner)
        ->get(route('projects.services.section', [$project, $project->production()->sole(), Service::query()->where('project_id', $project->id)->sole(), 'backups']))
        ->assertNotFound();
    $this->actingAs($this->owner)
        ->get(route('projects.services.section', [$this->svc->environment->project, $this->svc->environment, $this->svc->service, 'backups']))
        ->assertOk();
});

test('a staged removal of a service with backups waits for its final backup before the deploy tears it down', function (): void {
    Backups::destination($this->team, $this->owner, makeDefault: true);
    $developer = memberOf($this->team, TeamRole::Developer);

    $this->actingAs($developer)->delete(route('projects.services.destroy', [$this->svc->environment->project, $this->svc->environment, $this->svc->service]))->assertRedirect();

    $request = DeletionRequest::query()->sole();
    $final = BackupRun::query()->whereKey($request->backup_run_ids)->sole();
    expect($request->subject_type)->toBe('service_instance')
        ->and($final->reason)->toBe(BackupReason::PreDelete)
        ->and($final->volume_id)->toBe($this->volume->id);

    expect(fn () => resolve(DeployEnvironment::class)->handle($this->svc->environment, null, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class, 'is still running');
    expect(Placement::query()->where('service_instance_id', $this->svc->id)->exists())->toBeTrue();

    VolumeServices::backedUp($this->server, str_repeat('e5', 32));
    resolve(DeployEnvironment::class)->handle($this->svc->environment, null, AuditActor::system());

    expect(Placement::query()->where('service_instance_id', $this->svc->id)->exists())->toBeFalse()
        ->and($request->refresh()->status->value)->toBe('completed');
});
