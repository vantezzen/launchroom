<?php

declare(strict_types=1);

use App\Actions\Alerts\EvaluateAlertConditions;
use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\RunControlPlaneBackup;
use App\Actions\Backups\StartServiceBackup;
use App\Data\Audit\AuditActor;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertStatus;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Alerts\Alert;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\Volume;
use App\Support\Refusal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
| Starting backups from the Backups page: one row, everything, launchroom
| itself; truthful answers when one is already running; nothing for
| launchroom before its recovery passphrase exists.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

function backupToast(): ?array
{
    $toast = session('inertia.flash_data.toast');

    return is_array($toast) ? $toast : null;
}

test('backing up a database is idempotent and says so', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $developer = memberOf($team, TeamRole::Developer);
    Backups::destination($team, memberOf($team, TeamRole::Owner));
    $instance->loadMissing('service');

    $this->actingAs($developer)->post(route('backups.services.run', $instance))->assertRedirect()->assertSessionHasNoErrors();
    expect(backupToast())->toBe(['type' => 'success', 'message' => "Backup of {$instance->service->name} started."]);

    $this->actingAs($developer)->post(route('backups.services.run', $instance))->assertRedirect();
    expect(backupToast())->toBe(['type' => 'info', 'message' => "A backup of {$instance->service->name} is already running."])
        ->and(BackupRun::query()->where('service_instance_id', $instance->id)->count())->toBe(1);

    $this->actingAs(memberOf($team, TeamRole::Viewer))->post(route('backups.services.run', $instance))->assertForbidden();
    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->post(route('backups.services.run', $instance))->assertNotFound();
});

test('the page shows the running row as queued until its server starts it, and the button as the run', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $this->actingAs($owner)->post(route('backups.services.run', $instance));

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.summary.state', 'running')
            ->where('page.groups.1.items.0.state', 'queued')
            ->where('page.groups.1.items.0.run.phase', 'queued')
            ->where('page.groups.1.items.0.run.waitingFor', fn (?string $name): bool => $name !== null)
            ->where('page.groups.1.items.0.backUp.url', null)
            ->where('page.groups.1.items.0.backUp.disabled.code', 'running')
            ->where('page.live.pollMs', 5000));
});

test('back up everything queues every item, skips stopped services with the reason, and is idempotent', function (): void {
    [$team, , , $postgres] = Backups::runningPostgres();
    [, , , $stopped] = Backups::runningPostgres($team);
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $stopped->forceFill(['stopped_at' => now()])->save();

    $this->actingAs($owner)->post(route('backups.everything'))->assertRedirect()->assertSessionHasNoErrors();

    $runs = BackupRun::query()->whereNotNull('batch_id')->get();
    expect($runs)->toHaveCount(1)
        ->and($runs->first()->service_instance_id)->toBe($postgres->id)
        ->and(backupToast())->toBe(['type' => 'success', 'message' => 'Backing up 1 item.']);

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.everything.batch.total', 1)
            ->where('page.everything.batch.queued', 1)
            ->where('page.everything.batch.finishedAt', null)
            ->where('page.everything.batch.refused.0.message', fn (string $m): bool => str_contains($m, 'is stopped'))
            ->where('page.everything.action.disabled.code', 'running'));

    $this->actingAs($owner)->post(route('backups.everything'))->assertRedirect();
    expect(BackupRun::query()->count())->toBe(1)
        ->and(backupToast())->toBe(['type' => 'info', 'message' => 'Everything is already backing up.']);

    $this->actingAs(memberOf($team, TeamRole::Viewer))->post(route('backups.everything'))->assertForbidden();
});

test('a service with several volumes starts all of them or none', function (): void {
    [$team, $server, , $app] = VolumeServices::runningApp();
    Backups::destination($team, memberOf($team, TeamRole::Owner));
    [$offline] = Projects::server($team, ip: '203.0.113.8');
    $offline->forceFill(['status' => ServerStatus::Offline, 'name' => 'hel-2'])->save();
    Volume::query()->create(['team_id' => $team->id, 'service_instance_id' => $app->id, 'mount_path' => '/data/other', 'server_id' => $offline->id]);

    $result = resolve(StartServiceBackup::class)->handle($app->refresh(), BackupReason::Manual, null, AuditActor::system());

    expect($result)->toBeInstanceOf(Refusal::class)
        ->and($result->message)->toContain('hel-2')
        ->and(BackupRun::query()->where('service_instance_id', $app->id)->count())->toBe(0);
});

test('launchroom itself: truthful toasts, and its duration is recorded', function (): void {
    Queue::fake();
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    Backups::destination(null, $owner);

    $this->actingAs($owner)->post(route('backups.control-plane.run'))->assertSessionHasErrors('passphrase');
    expect(BackupRun::query()->count())->toBe(0);

    InstanceSettings::current()->forceFill(['recovery_public_key' => base64_encode(random_bytes(32))])->save();

    $this->actingAs($owner)->post(route('backups.control-plane.run'))->assertSessionHasNoErrors();
    expect(backupToast())->toBe(['type' => 'success', 'message' => 'Backup of launchroom started.']);
    $this->actingAs($owner)->post(route('backups.control-plane.run'));
    expect(backupToast())->toBe(['type' => 'info', 'message' => 'launchroom is already backing up.'])
        ->and(BackupRun::query()->count())->toBe(1);

    // restic is absent in tests: the run fails, with its duration.
    $run = resolve(RunControlPlaneBackup::class)->handle(BackupRun::query()->sole());
    expect($run->status)->toBe(BackupRunStatus::Failed)
        ->and($run->duration_ms)->toBeInt();
});

test('launchroom\'s schedule records nothing and raises nothing before the passphrase is set', function (): void {
    CarbonImmutable::setTestNow('2026-11-18 00:00:00');
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    [$server] = Projects::server($team);
    $server->forceFill(['is_control_plane' => true])->save();
    Backups::destination(null, $owner);
    BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->update(['cron' => '* * * * *', 'created_at' => now()->subDays(3)]);

    CarbonImmutable::setTestNow('2026-11-18 00:05:00');
    resolve(RunBackupTick::class)->handle(CarbonImmutable::now());
    resolve(EvaluateAlertConditions::class)->handle();

    expect(BackupRun::query()->where('subject', BackupSubject::ControlPlane->value)->count())->toBe(0)
        ->and(Alert::query()->where('rule', AlertRule::BackupFailed->value)->where('status', AlertStatus::Firing->value)->exists())->toBeFalse();

    // Once it is set, the owed slot runs.
    InstanceSettings::current()->forceFill(['recovery_public_key' => base64_encode(random_bytes(32)), 'recovery_passphrase_set_at' => now()])->save();
    CarbonImmutable::setTestNow('2026-11-18 00:06:00');
    resolve(RunBackupTick::class)->handle(CarbonImmutable::now());

    expect(BackupRun::query()->where('subject', BackupSubject::ControlPlane->value)->count())->toBe(1);
    CarbonImmutable::setTestNow();
});

test('launchroom\'s backup can\'t be started by anyone but an instance owner', function (): void {
    $team = Team::factory()->create();
    Backups::destination(null, tap(memberOf($team, TeamRole::Owner), fn ($u) => $u->forceFill(['is_instance_owner' => true])->save()));

    $this->actingAs(memberOf($team, TeamRole::Admin))->post(route('backups.control-plane.run'))->assertForbidden();
});
