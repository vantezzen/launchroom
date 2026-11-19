<?php

declare(strict_types=1);

use App\Actions\Backups\FailAbandonedControlPlaneBackups;
use App\Actions\Backups\StartControlPlaneBackup;
use App\Actions\Updates\AdvanceUpdates;
use App\Actions\Updates\CancelControlPlaneUpdate;
use App\Actions\Updates\CancelFleetUpdate;
use App\Actions\Updates\RunControlPlaneUpdate;
use App\Data\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\DaemonTaskStatus;
use App\Enums\TeamRole;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Enums\Updates\FleetUpdateStepStatus;
use App\Enums\Updates\UpdateScope;
use App\Jobs\Backups\RunControlPlaneBackupJob;
use App\Jobs\Updates\RunControlPlaneUpdateJob;
use App\Models\AuditEvent;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\DaemonTask;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\Updates\FleetUpdateStep;
use App\Models\User;
use App\Services\Backups\DestinationCredentials;
use App\Services\Backups\Process\BackupOutcome;
use App\Services\Backups\Process\ResticCli;
use App\Services\Backups\Process\ResticTarget;
use App\Services\Updates\SelfUpdateTasks;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Process\Process;
use Tests\Support\Backups;

/*
| An update never gets stuck in a way that needs a shell on the server
| (docs/11 §4.3): every state before launchroomd installs shows when it
| stopped making progress, can be cancelled and tried again from the page,
| and resumes by itself when the worker running it died. The v0.1.5-rc.1
| incident: the pre-upgrade backup's worker was killed by a Horizon
| scale-down, the backup run stayed `running` and the update `backing_up`.
*/

const RECOVERY_IMAGE = 'ghcr.io/vantezzen/launchroomd@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Backups::fakeEnvironment();
    config([
        'inertia.ssr.enabled' => false,
        'launchroom.version' => '0.1.0',
        'launchroom.updates.channel' => 'stable',
    ]);
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->cp = Server::factory()->online()->create(['team_id' => $this->team->id, 'is_control_plane' => true, 'name' => 'main']);
});

/**
 * An update in $status, last touched $quietFor seconds ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function stuckUpdate(Server $server, User $owner, ControlPlaneUpdateStatus $status, int $quietFor = 0, array $attributes = []): ControlPlaneUpdate
{
    $update = new ControlPlaneUpdate([
        'from_version' => '0.1.0', 'to_version' => '0.2.0', 'daemon_image' => RECOVERY_IMAGE,
        'server_id' => $server->id, 'actor_id' => $owner->id,
    ]);
    $at = now()->subSeconds($quietFor);
    $update->forceFill(['status' => $status, 'started_at' => $status === ControlPlaneUpdateStatus::Pending ? null : $at, ...$attributes]);
    $update->created_at = $at;
    $update->updated_at = $at;
    $update->timestamps = false;
    $update->save();
    $update->timestamps = true;

    return $update;
}

/** A control-plane backup run whose worker last beat $quietFor seconds ago. */
function controlPlaneRun(BackupDestination $destination, BackupRunStatus $status, int $quietFor = 0, BackupReason $reason = BackupReason::PreUpgrade): BackupRun
{
    $run = new BackupRun(['team_id' => null, 'destination_id' => $destination->id, 'subject' => BackupSubject::ControlPlane, 'reason' => $reason]);
    $run->forceFill([
        'status' => $status,
        'created_at' => now()->subSeconds($quietFor + 60),
        'started_at' => $status === BackupRunStatus::Pending ? null : now()->subSeconds($quietFor + 60),
        'heartbeat_at' => $status === BackupRunStatus::Running ? now()->subSeconds($quietFor) : null,
        'finished_at' => $status->isTerminal() ? now() : null,
    ])->save();

    return $run;
}

function withPassphrase(): void
{
    InstanceSettings::current()->forceFill(['recovery_public_key' => 'x25519:'.base64_encode(random_bytes(32)), 'recovery_kdf' => ['salt' => 'x']])->save();
}

function selfUpdateTask(Server $server): DaemonTask
{
    return resolve(SelfUpdateTasks::class)->create($server, '0.2.0', RECOVERY_IMAGE, UpdateScope::ControlPlane);
}

test('an update without a sign of life shows as stalled, can be cancelled, and the doctor offers the fix', function (): void {
    stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Pending, 240);

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('controlPlaneUpdate.status', 'pending')
            ->where('controlPlaneUpdate.stalled', true)
            ->where('controlPlaneUpdate.canCancel', true));

    Artisan::call('launchroom:doctor', ['--json' => true]);
    $checks = collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('id');

    expect($checks['update']['status'] ?? null)->toBe('fail')
        ->and($checks['update']['fix'] ?? null)->toBe('cancel-update');
});

test('a fresh update is not stalled', function (): void {
    stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Pending, 30);

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('controlPlaneUpdate.stalled', false));
});

test('a running backup that beats is progress, one that went silent is a stall', function (): void {
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    $run = controlPlaneRun($destination, BackupRunStatus::Running, 20);
    $run->forceFill(['progress_bytes' => 52_428_800])->save();
    stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 900, ['backup_run_id' => $run->id]);

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('controlPlaneUpdate.stalled', false)
            ->where('controlPlaneUpdate.backupBytes', 52_428_800));

    $run->forceFill(['heartbeat_at' => now()->subMinutes(4)])->save();

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('controlPlaneUpdate.stalled', true));
});

test('"Cancel update" works in every state before launchroomd takes the task, and nothing changes', function (string $case): void {
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    $run = null;
    $task = null;

    $update = match ($case) {
        'queued' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Pending, 600),
        'backing up, backup alive' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 60, ['backup_run_id' => ($run = controlPlaneRun($destination, BackupRunStatus::Running, 5))->id]),
        'backing up, backup silent' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 60, ['backup_run_id' => ($run = controlPlaneRun($destination, BackupRunStatus::Running, 400))->id]),
        'waiting for a queued backup' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 600, ['backup_run_id' => ($run = controlPlaneRun($destination, BackupRunStatus::Pending, 600))->id]),
        'before the task exists' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 600),
        'task not taken yet' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 600, ['daemon_task_id' => ($task = selfUpdateTask($this->cp))->id]),
    };

    $this->actingAs($this->owner)
        ->post(route('updates.control-plane.cancel', $update))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $update->refresh();

    expect($update->status)->toBe(ControlPlaneUpdateStatus::Cancelled)
        ->and($update->error)->toBe(CancelControlPlaneUpdate::CANCELLED)
        ->and($update->finished_at)->not->toBeNull()
        ->and(ControlPlaneUpdate::active())->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ControlPlaneUpdateFinished->value)->where('subject_id', $update->public_id)->exists())->toBeTrue();

    if ($task instanceof DaemonTask) {
        expect($task->refresh()->status)->toBe(DaemonTaskStatus::Cancelled);
    }

    if ($run instanceof BackupRun) {
        // A live backup keeps going (an ordinary backup now); a silent one is given up.
        expect($run->refresh()->status)->toBe(match ($case) {
            'backing up, backup silent' => BackupRunStatus::Failed,
            'waiting for a queued backup' => BackupRunStatus::Pending,
            default => BackupRunStatus::Running,
        });
    }

    $this->actingAs($this->owner)->get(route('updates.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('running', false)
            ->where('controlPlaneUpdate.status', 'cancelled')
            ->where('controlPlaneUpdate.canCancel', false));
})->with(['queued', 'backing up, backup alive', 'backing up, backup silent', 'waiting for a queued backup', 'before the task exists', 'task not taken yet']);

test('an update launchroomd is installing can’t be cancelled, and only instance owners can cancel', function (): void {
    $task = selfUpdateTask($this->cp);
    $task->forceFill(['lease_expires_at' => now()->addMinutes(5)]);
    $task->transitionTo(DaemonTaskStatus::Leased);
    $update = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 60, ['daemon_task_id' => $task->id]);

    $this->actingAs($this->owner)
        ->post(route('updates.control-plane.cancel', $update))
        ->assertSessionHasErrors('general');

    expect($update->refresh()->status)->toBe(ControlPlaneUpdateStatus::Updating)
        ->and($task->refresh()->status)->toBe(DaemonTaskStatus::Leased);

    $admin = memberOf($this->team, TeamRole::Admin);
    $pending = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Failed, 60);
    $this->actingAs($admin)->post(route('updates.control-plane.cancel', $pending))->assertForbidden();
});

test('"Try again" installs the release the cancelled update had pinned, without asking GitHub', function (): void {
    $update = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Cancelled, 60);
    Queue::fake();

    // No Http::fake: a request to GitHub would throw (preventStrayRequests).
    $this->actingAs($this->owner)
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'retryOf' => $update->public_id, 'skipBackup' => true])
        ->assertSessionHasNoErrors();

    $retry = ControlPlaneUpdate::query()->whereKeyNot($update->id)->sole();

    expect($retry->status)->toBe(ControlPlaneUpdateStatus::Pending)
        ->and($retry->to_version)->toBe('0.2.0')
        ->and($retry->daemon_image)->toBe(RECOVERY_IMAGE);
    Queue::assertPushed(RunControlPlaneUpdateJob::class);

    // Only an update that didn't go through, and only to its own version.
    $retry->forceFill(['status' => ControlPlaneUpdateStatus::Succeeded])->save();
    $this->actingAs($this->owner)
        ->post(route('updates.control-plane'), ['version' => '0.2.0', 'retryOf' => $retry->public_id, 'skipBackup' => true])
        ->assertSessionHasErrors('retryOf');
    $this->actingAs($this->owner)
        ->post(route('updates.control-plane'), ['version' => '0.3.0', 'retryOf' => $update->public_id, 'skipBackup' => true])
        ->assertSessionHasErrors('version');
});

test('the scheduler dispatches the job again for an update whose job died, in every state before the task', function (string $case): void {
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    Queue::fake();

    match ($case) {
        'queued' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Pending, 180),
        'backing up, no run yet' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 180),
        'backing up, run never started' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 180, ['backup_run_id' => controlPlaneRun($destination, BackupRunStatus::Pending, 180)->id]),
        'backup finished' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 30, ['backup_run_id' => controlPlaneRun($destination, BackupRunStatus::Succeeded)->id]),
        'before the task exists' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 180),
    };

    resolve(AdvanceUpdates::class)->handle();

    Queue::assertPushed(RunControlPlaneUpdateJob::class, 1);
})->with(['queued', 'backing up, no run yet', 'backing up, run never started', 'backup finished', 'before the task exists']);

test('nothing is dispatched while the update makes progress', function (string $case): void {
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    Queue::fake();

    match ($case) {
        'just queued' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Pending, 30),
        'backup beating' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 900, ['backup_run_id' => controlPlaneRun($destination, BackupRunStatus::Running, 10)->id]),
        'task created' => stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 900, ['daemon_task_id' => selfUpdateTask($this->cp)->id]),
    };

    resolve(AdvanceUpdates::class)->handle();

    Queue::assertNotPushed(RunControlPlaneUpdateJob::class);
})->with(['just queued', 'backup beating', 'task created']);

test('a re-dispatched job continues where the dead one stopped and creates the task once', function (): void {
    $update = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 180);

    // The queue runs jobs inline in tests: the advance dispatches and the job runs.
    resolve(AdvanceUpdates::class)->handle();
    resolve(AdvanceUpdates::class)->handle();

    $update->refresh();
    expect($update->status)->toBe(ControlPlaneUpdateStatus::Updating)
        ->and($update->daemon_task_id)->not->toBeNull()
        ->and(DaemonTask::query()->where('type', 'self_update')->count())->toBe(1);

    // Once the new version runs, a lost task is never created again.
    $update->forceFill(['status' => ControlPlaneUpdateStatus::Succeeded])->save();
    $lost = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 180);
    config(['launchroom.version' => '0.2.0']);
    expect(resolve(RunControlPlaneUpdate::class)->handle($lost)->daemon_task_id)->toBeNull()
        ->and(DaemonTask::query()->where('type', 'self_update')->count())->toBe(1);
});

test('the job’s unique lock expires, so a killed worker never blocks the re-dispatch', function (): void {
    foreach ([RunControlPlaneUpdateJob::class, RunControlPlaneBackupJob::class] as $class) {
        $uniqueFor = (new ReflectionClass($class)->getAttributes(UniqueFor::class)[0] ?? null)?->newInstance()->uniqueFor;

        expect($uniqueFor)->toBeGreaterThan(0)->toBeLessThanOrEqual(900);
    }
});

test('a control-plane backup whose worker died is given up, and the waiting update takes a new one', function (): void {
    Backups::fakeEnvironment();
    withPassphrase();
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    $dead = controlPlaneRun($destination, BackupRunStatus::Running, 601);
    $update = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 700, ['backup_run_id' => $dead->id]);

    // Jobs run inline: the advance gives the run up, dispatches the update,
    // which starts a new backup (it fails here: no restic in the test image).
    resolve(AdvanceUpdates::class)->handle();

    $update->refresh();
    $new = BackupRun::query()->whereKeyNot($dead->id)->sole();

    expect($dead->refresh()->status)->toBe(BackupRunStatus::Failed)
        ->and($dead->error)->toBe(BackupRun::ABANDONED)
        ->and($update->backup_attempts)->toBe(1)
        ->and($update->backup_run_id)->toBe($new->id)
        ->and($new->reason)->toBe(BackupReason::PreUpgrade)
        ->and($update->status)->toBe(ControlPlaneUpdateStatus::Failed)
        ->and($update->error)->toContain('pre-upgrade backup failed')
        ->and($update->daemon_task_id)->toBeNull();
});

test('a pre-upgrade backup that keeps losing its worker fails the update clearly after a few attempts', function (): void {
    withPassphrase();
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    $dead = controlPlaneRun($destination, BackupRunStatus::Failed);
    $dead->forceFill(['error' => BackupRun::ABANDONED])->save();
    $update = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::BackingUp, 60, ['backup_run_id' => $dead->id, 'backup_attempts' => 2]);

    resolve(AdvanceUpdates::class)->handle();

    $update->refresh();
    expect($update->status)->toBe(ControlPlaneUpdateStatus::Failed)
        ->and($update->error)->toContain('stopped without finishing 3 times')
        ->and($update->error)->toContain('nothing was changed')
        ->and(BackupRun::query()->count())->toBe(1);
});

test('a control-plane run whose worker died is failed; a live, a fresh or a launchroomd run is kept; a long-pending one is dispatched again', function (string $case): void {
    Queue::fake();
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    $run = match ($case) {
        'silent' => controlPlaneRun($destination, BackupRunStatus::Running, 601),
        'beating' => controlPlaneRun($destination, BackupRunStatus::Running, 30),
        'queued a while' => controlPlaneRun($destination, BackupRunStatus::Pending, 700),
        'never started' => controlPlaneRun($destination, BackupRunStatus::Pending, config()->integer('launchroom.backups.task_timeout_seconds') + 700),
    };
    // Runs on launchroomd follow their task's lease, never this heartbeat.
    $team = BackupRun::query()->create(['team_id' => $this->team->id, 'destination_id' => Backups::destination($this->team, $this->owner)->id, 'subject' => BackupSubject::Database, 'reason' => BackupReason::Manual]);
    $team->forceFill(['status' => BackupRunStatus::Running, 'started_at' => now()->subDay()])->save();

    $failed = resolve(FailAbandonedControlPlaneBackups::class)->handle();
    $run->refresh();

    expect($team->refresh()->status)->toBe(BackupRunStatus::Running);

    match ($case) {
        'silent', 'never started' => expect($failed)->toBe(1)
            ->and($run->status)->toBe(BackupRunStatus::Failed)
            ->and($run->error)->toBe(BackupRun::ABANDONED),
        'beating' => expect($failed)->toBe(0)->and($run->status)->toBe(BackupRunStatus::Running),
        'queued a while' => expect($failed)->toBe(0)->and($run->status)->toBe(BackupRunStatus::Pending),
    };

    if ($case === 'queued a while') {
        Queue::assertPushed(RunControlPlaneBackupJob::class, fn (RunControlPlaneBackupJob $job): bool => $job->runId === $run->id);
    } else {
        Queue::assertNotPushed(RunControlPlaneBackupJob::class);
    }
})->with(['silent', 'beating', 'queued a while', 'never started']);

test('a new control-plane backup never waits for one whose worker died', function (): void {
    Queue::fake();
    withPassphrase();
    $destination = Backups::destination(null, $this->owner, 'Control plane');
    $dead = controlPlaneRun($destination, BackupRunStatus::Running, 601);

    $run = resolve(StartControlPlaneBackup::class)->handle(BackupReason::Manual, $this->owner, AuditActor::system());

    expect($run->id)->not->toBe($dead->id)
        ->and($run->wasRecentlyCreated)->toBeTrue()
        ->and($dead->refresh()->error)->toBe(BackupRun::ABANDONED);
});

test('a self_update task launchroomd never takes is withdrawn and the update cancelled', function (): void {
    $task = selfUpdateTask($this->cp);
    $update = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Updating, 60, ['daemon_task_id' => $task->id]);
    DaemonTask::query()->whereKey($task->id)->update(['updated_at' => now()->subMinutes(31)]);

    resolve(AdvanceUpdates::class)->handle();

    expect($update->refresh()->status)->toBe(ControlPlaneUpdateStatus::Cancelled)
        ->and($update->error)->toBe(AdvanceUpdates::NOT_STARTED)
        ->and($task->refresh()->status)->toBe(DaemonTaskStatus::Cancelled);
});

test('the doctor fix cancels only a stalled update', function (): void {
    $update = stuckUpdate($this->cp, $this->owner, ControlPlaneUpdateStatus::Pending, 30);

    $this->artisan('launchroom:updates:cancel --stalled')->assertSuccessful()->expectsOutputToContain('making progress');
    expect($update->refresh()->status)->toBe(ControlPlaneUpdateStatus::Pending);

    $update->forceFill(['created_at' => now()->subMinutes(10)])->save();
    $this->artisan('launchroom:updates:cancel --stalled')->assertSuccessful()->expectsOutputToContain('was cancelled');
    expect($update->refresh()->status)->toBe(ControlPlaneUpdateStatus::Cancelled);
});

/**
 * @return array{FleetUpdate, list<FleetUpdateStep>, list<Server>}
 */
function fleetOf(Team $team, User $owner, int $servers): array
{
    $fleet = FleetUpdate::query()->create(['to_version' => '0.2.0', 'daemon_image' => RECOVERY_IMAGE, 'actor_id' => $owner->id]);
    $steps = [];
    $list = [];

    for ($i = 1; $i <= $servers; $i++) {
        $list[] = $server = Server::factory()->online()->create(['team_id' => $team->id, 'name' => "app-{$i}"]);
        $steps[] = FleetUpdateStep::query()->create(['fleet_update_id' => $fleet->id, 'server_id' => $server->id, 'position' => $i, 'server_name' => $server->name, 'from_version' => '0.1.0']);
    }

    return [$fleet, $steps, $list];
}

test('a server that never takes its update fails its step after the start time; the rest is skipped', function (): void {
    config(['launchroom.version' => '0.2.0']);
    [$fleet, $steps] = fleetOf($this->team, $this->owner, 2);

    resolve(AdvanceUpdates::class)->handle();
    $first = $steps[0]->refresh();
    expect($first->status)->toBe(FleetUpdateStepStatus::Updating);

    DaemonTask::query()->whereKey($first->daemon_task_id)->update(['updated_at' => now()->subMinutes(31)]);
    resolve(AdvanceUpdates::class)->handle();

    expect($first->refresh()->status)->toBe(FleetUpdateStepStatus::Failed)
        ->and($first->error)->toContain('may be offline')
        ->and(DaemonTask::query()->find($first->daemon_task_id)?->status)->toBe(DaemonTaskStatus::Cancelled)
        ->and($steps[1]->refresh()->status)->toBe(FleetUpdateStepStatus::Skipped)
        ->and($fleet->refresh()->status)->toBe(FleetUpdateStatus::Failed);
});

test('stopping a server rollout skips the servers that haven’t started and waits for the one installing', function (bool $taken): void {
    config(['launchroom.version' => '0.2.0']);
    [$fleet, $steps] = fleetOf($this->team, $this->owner, 3);
    resolve(AdvanceUpdates::class)->handle();
    $task = DaemonTask::query()->findOrFail($steps[0]->refresh()->daemon_task_id);

    if ($taken) {
        $task->forceFill(['lease_expires_at' => now()->addMinutes(5)]);
        $task->transitionTo(DaemonTaskStatus::Leased);
    }

    $this->actingAs($this->owner)
        ->post(route('updates.servers.cancel', $fleet))
        ->assertSessionHasNoErrors();

    expect($steps[1]->refresh()->status)->toBe(FleetUpdateStepStatus::Skipped)
        ->and($steps[1]->error)->toBe(CancelFleetUpdate::SKIPPED)
        ->and($steps[2]->refresh()->status)->toBe(FleetUpdateStepStatus::Skipped);

    if (! $taken) {
        expect($steps[0]->refresh()->status)->toBe(FleetUpdateStepStatus::Skipped)
            ->and($task->refresh()->status)->toBe(DaemonTaskStatus::Cancelled)
            ->and($fleet->refresh()->status)->toBe(FleetUpdateStatus::Cancelled);

        return;
    }

    // The server installing finishes; then the rollout ends cancelled.
    expect($fleet->refresh()->status)->toBe(FleetUpdateStatus::Running);
    $task->transitionTo(DaemonTaskStatus::Succeeded);
    resolve(AdvanceUpdates::class)->handle();

    expect($steps[0]->refresh()->status)->toBe(FleetUpdateStepStatus::Succeeded)
        ->and($fleet->refresh()->status)->toBe(FleetUpdateStatus::Cancelled)
        ->and(FleetUpdate::query()->where('status', 'running')->exists())->toBeFalse();
})->with(['task not taken' => [false], 'task taken' => [true]]);

test('restic progress reaches the caller on every chunk and status line, and it can stop the backup', function (): void {
    $dir = sys_get_temp_dir().'/lr-progress-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    // A stand-in restic: records its progress setting, reads stdin, prints a status line and the summary.
    file_put_contents($dir.'/restic', <<<SH
        #!/bin/sh
        echo "\$RESTIC_PROGRESS_FPS" > {$dir}/fps
        cat > /dev/null
        echo '{"message_type":"status","bytes_done":1}'
        echo '{"message_type":"summary","snapshot_id":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef","data_added":10}'
        SH);
    chmod($dir.'/restic', 0700);
    $restic = new ResticCli($dir.'/restic', $dir.'/cache', 60);
    $target = new ResticTarget('s3:https://s3.example.com/bucket', null, new DestinationCredentials('AKIA', 'secret', 'repository-password-0123456789'));

    try {
        $seen = [];
        $outcome = $restic->backupFromProcess($target, new Process(['head', '-c', '300000', '/dev/zero']), 'x', ['kind:control-plane'], 60, function (int $bytes) use (&$seen): void {
            $seen[] = $bytes;
        });

        expect($outcome->sizeBytes)->toBe(300000)
            ->and($seen)->not->toBeEmpty()
            ->and(end($seen))->toBe(300000)
            ->and(trim((string) file_get_contents($dir.'/fps')))->toBe('0.2');

        // A run given up as abandoned stops the worker that was only slow.
        expect(fn (): BackupOutcome => $restic->backupFromProcess($target, new Process(['head', '-c', '300000', '/dev/zero']), 'x', [], 60, function (): void {
            throw new RuntimeException(BackupRun::ABANDONED);
        }))->toThrow(RuntimeException::class, BackupRun::ABANDONED);
    } finally {
        removeProgressDir($dir);
    }
});

function removeProgressDir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;
        is_dir($path) ? removeProgressDir($path) : unlink($path);
    }

    rmdir($dir);
}
