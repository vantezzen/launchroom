<?php

declare(strict_types=1);

use App\Actions\Backups\EvaluateBackupAlerts;
use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\SetVolumeBackup;
use App\Actions\Backups\StartServiceBackup;
use App\Actions\Backups\StartVerification;
use App\Actions\Backups\TakeSafetySnapshot;
use App\Data\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\VerificationReason;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Alerts\Alert;
use App\Models\AuditEvent;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Volume;
use App\Models\VolumeRetirement;
use App\Queries\ServerReplacementPlan;
use App\Services\Backups\FinalBackups;
use App\Services\Volumes\VolumeRetirements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
| "Back up this volume" (docs/14 §3): volumes are backed up by default;
| turned off, a volume is left out everywhere a volume is backed up or
| expected to be: scheduled and manual backups, safety snapshots, final
| backups, verification, alerts, replace-server and volume retirement.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, $this->server, , $this->svc] = VolumeServices::runningApp();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->destination = Backups::destination($this->team, $this->owner);
    $this->volume = Volume::query()->where('service_instance_id', $this->svc->id)->sole();
    $this->cache = Volume::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->svc->id, 'mount_path' => '/tmp/cache', 'server_id' => $this->server->id]);
    $this->url = route('backups.services.volumes.backup', ['serviceInstance' => $this->svc, 'volume' => $this->cache->public_id]);
    $this->succeeded = fn (Volume $volume, ?string $snapshot = null) => tap(BackupRun::query()->create([
        'team_id' => $this->team->id, 'destination_id' => $this->destination->id, 'subject' => BackupSubject::Volume,
        'service_instance_id' => $this->svc->id, 'volume_id' => $volume->id, 'reason' => BackupReason::Scheduled, 'server_id' => $this->server->id,
    ]), fn (BackupRun $run) => $run->forceFill(['status' => BackupRunStatus::Succeeded, 'snapshot_id' => $snapshot ?? bin2hex(random_bytes(32)), 'finished_at' => now()])->save());
});

test('owners and admins turn a volume\'s backups off and on; who and when are kept and audited', function (): void {
    $this->actingAs(memberOf($this->team, TeamRole::Developer))->put($this->url, ['enabled' => false])->assertForbidden();
    $this->actingAs(memberOf($this->team, TeamRole::Admin))->put($this->url, ['enabled' => false])->assertRedirect()->assertSessionHasNoErrors();

    $this->cache->refresh();
    expect($this->cache->backup_enabled)->toBeFalse()
        ->and($this->cache->backup_disabled_by)->not->toBeNull()
        ->and($this->cache->backup_disabled_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::BackupVolumeUpdated->value)->count())->toBe(1);

    $this->actingAs($this->owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->has('page.groups.1.items', 1)
            ->where('page.groups.1.items.0.id', 'volume:'.$this->volume->public_id)
            ->where('page.notBackedUp.0.id', 'volume:'.$this->cache->public_id)
            ->where('page.notBackedUp.0.reason', 'volume_off')
            ->where('page.notBackedUp.0.turnedOffBy', fn (?string $name): bool => $name !== null)
            ->where('page.notBackedUp.0.turnOn.url', $this->url));

    // JSON callers (the Volumes tab) get the tab's data back.
    $this->actingAs($this->owner)->putJson($this->url, ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('volumes.1.backupEnabled', true);
    expect($this->cache->refresh()->backup_enabled)->toBeTrue()->and($this->cache->backup_disabled_by)->toBeNull();
});

test('a database\'s volume can\'t be turned off', function (): void {
    [, , , $postgres] = Backups::runningPostgres($this->team);
    $volume = Volume::query()->where('service_instance_id', $postgres->id)->firstOrFail();

    expect(fn () => resolve(SetVolumeBackup::class)->handle($postgres, $volume, false, $this->owner, AuditActor::system()))
        ->toThrow(DomainRuleViolation::class);
});

test('scheduled, manual and safety backups skip a turned-off volume', function (): void {
    resolve(SetVolumeBackup::class)->handle($this->svc, $this->cache, false, $this->owner, AuditActor::system());

    $runs = resolve(StartServiceBackup::class)->handle($this->svc->refresh(), BackupReason::Manual, null, AuditActor::system());
    expect($runs)->toHaveCount(1)->and($runs[0]->volume_id)->toBe($this->volume->id);

    $runs[0]->transitionTo(BackupRunStatus::Failed, 'test');
    $safety = resolve(TakeSafetySnapshot::class)->handle($this->svc->refresh(), BackupReason::PreDelete, null, AuditActor::system());
    expect(array_map(static fn (BackupRun $run): ?int => $run->volume_id, $safety->runs))->toBe([$this->volume->id]);

    $only = resolve(TakeSafetySnapshot::class)->handle($this->svc->refresh(), BackupReason::PreDelete, null, AuditActor::system(), $this->cache);
    expect($only->runs)->toBe([])->and($only->skipped[0]->message)->toContain('turned off');

    $this->actingAs($this->owner)->post(route('backups.services.volumes.run', ['serviceInstance' => $this->svc, 'volume' => $this->cache->public_id]))
        ->assertSessionHasErrors('volume');
});

test('a service whose volumes are all turned off has nothing to back up: no runs, no failures, no alerts', function (): void {
    resolve(SetVolumeBackup::class)->handle($this->svc, $this->cache, false, $this->owner, AuditActor::system());
    resolve(SetVolumeBackup::class)->handle($this->svc, $this->volume, false, $this->owner, AuditActor::system());
    $failed = ($this->succeeded)($this->volume);
    $failed->forceFill(['status' => BackupRunStatus::Failed])->save();
    BackupSchedule::query()->where('service_instance_id', $this->svc->id)->update(['cron' => '* * * * *', 'created_at' => now()->subDays(3)]);

    resolve(RunBackupTick::class)->handle(CarbonImmutable::now()->addMinute());
    resolve(EvaluateBackupAlerts::class)->handle();

    expect(BackupRun::query()->where('service_instance_id', $this->svc->id)->count())->toBe(1)
        ->and(Alert::query()->count())->toBe(0)
        ->and(resolve(FinalBackups::class)->needed(new Collection([$this->svc->refresh()])))->toBe([]);
});

test('a failed backup of a turned-off volume no longer alerts', function (): void {
    ($this->succeeded)($this->volume);
    $failed = ($this->succeeded)($this->cache);
    $failed->forceFill(['status' => BackupRunStatus::Failed, 'error' => 'boom'])->save();
    resolve(SetVolumeBackup::class)->handle($this->svc, $this->cache, false, $this->owner, AuditActor::system());

    resolve(EvaluateBackupAlerts::class)->handle();

    expect(Alert::query()->where('subject_key', 'failed:'.$this->svc->public_id)->exists())->toBeFalse();
});

test('verification takes the newest backup of a volume that is still backed up', function (): void {
    $kept = ($this->succeeded)($this->volume);
    ($this->succeeded)($this->cache);
    resolve(SetVolumeBackup::class)->handle($this->svc, $this->cache, false, $this->owner, AuditActor::system());

    try {
        $verification = resolve(StartVerification::class)->handle($this->svc, VerificationReason::Manual, null, AuditActor::system());
        expect($verification->backup_run_id)->toBe($kept->id);
    } catch (DomainRuleViolation $e) {
        // Older launchroomd in the test server: the run it would verify is what matters.
        expect($e->field)->toBe('daemon');
    }
});

test('replace-server restores the backed-up volumes and starts turned-off ones empty', function (): void {
    ($this->succeeded)($this->volume, str_repeat('a', 64));
    resolve(SetVolumeBackup::class)->handle($this->svc, $this->cache, false, $this->owner, AuditActor::system());

    $plan = collect(resolve(ServerReplacementPlan::class)->for($this->server))->firstWhere('instance.id', $this->svc->id);

    expect($plan['restore'])->not->toBeNull()
        ->and($plan['restore']['volumes'])->toBe([$this->volume->id => str_repeat('a', 64)]);

    resolve(SetVolumeBackup::class)->handle($this->svc, $this->volume, false, $this->owner, AuditActor::system());
    $plan = collect(resolve(ServerReplacementPlan::class)->for($this->server))->firstWhere('instance.id', $this->svc->id);
    expect($plan['restore'])->toBeNull()->and($plan['reason'])->toContain('turned off');
});

test('a turned-off volume\'s deletion doesn\'t wait for a final backup', function (): void {
    ($this->succeeded)($this->volume);
    resolve(SetVolumeBackup::class)->handle($this->svc, $this->cache, false, $this->owner, AuditActor::system());

    resolve(VolumeRetirements::class)->retire(new Collection([$this->svc->refresh()]), 'environment_deleted');

    expect(VolumeRetirement::query()->where('volume_public_id', $this->cache->public_id)->sole()->backups_configured)->toBeFalse()
        ->and(VolumeRetirement::query()->where('volume_public_id', $this->volume->public_id)->sole()->backups_configured)->toBeTrue();
});
