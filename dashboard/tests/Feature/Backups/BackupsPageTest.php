<?php

declare(strict_types=1);

use App\Broadcasting\Channel;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\TeamRole;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Backups;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
| The Backups page (docs/14 §3, docs/design/flows.md §14): one row per
| database, per volume and for launchroom itself, grouped by project, with
| every disabled action's reason as data.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

test('Backups lives in Settings, and the old Backups page address moved there for good', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);

    expect(route('backups.index', [], false))->toBe('/settings/backups');

    $this->actingAs($owner)->get('/backups')
        ->assertStatus(301)
        ->assertRedirect('/settings/backups');
    // Only GET moved: the old address takes no other verb.
    $this->actingAs($owner)->post('/backups')->assertNotFound();
    $this->actingAs($owner)->get(route('backups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/backups'));
});

test('before storage is connected every row says what to do, and launchroom itself is set up separately', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    $instance->loadMissing('environment.project');

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/backups')
            ->where('page.summary.state', 'unconfigured')
            ->where('page.groups.0.key', 'launchroom')
            ->where('page.groups.0.items.0.backUp.url', null)
            ->where('page.groups.0.items.0.backUp.disabled.code', 'no_launchroom_storage')
            ->where('page.groups.0.items.0.backUp.disabled.fixHref', '#setup-launchroom')
            ->where('page.groups.1.projectId', $instance->environment->project->public_id)
            ->where('page.groups.1.items.0.id', 'database:'.$instance->public_id)
            ->where('page.groups.1.items.0.kind', 'database')
            ->where('page.groups.1.items.0.engine', 'postgres')
            ->where('page.groups.1.items.0.backUp.disabled.code', 'no_storage')
            ->where('page.launchroom.setup.disabled.code', 'no_storage')
            ->where('page.launchroom.recoveryKit.step', 'passphrase')
            ->where('page.storage.canProtectLaunchroom', true)
            ->where('page.everything.action.disabled.code', 'no_storage')
            ->where('page.live.channel', 'backups.'.$team->public_id)
            ->where('page.live.pollMs', null));
});

test('with storage a database row can be backed up now; launchroom waits for its passphrase', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    Backups::destination($team, $owner);
    Backups::destination(null, $owner);

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.summary.state', 'waiting')
            ->where('page.groups.0.items.0.backUp.disabled.code', 'no_passphrase')
            ->where('page.groups.0.items.0.backUp.disabled.fixHref', '#recovery-kit')
            ->where('page.groups.1.items.0.state', 'waiting')
            ->where('page.groups.1.items.0.backUp.url', route('backups.services.run', $instance))
            ->where('page.groups.1.items.0.schedule.cronDescription', fn (?string $s): bool => $s !== null && str_starts_with($s, 'Daily at'))
            ->where('page.groups.1.items.0.restoreHref', fn (string $href): bool => str_ends_with($href, '/settings/backups'))
            ->has('page.groups.1.items.0.days', 30)
            ->where('page.everything.action.url', route('backups.everything'))
            ->has('page.storage.destinations', 1)
            ->where('page.storage.destinations.0.removeUrl', fn (?string $url): bool => $url !== null)
            ->where('page.storage.canProtectLaunchroom', false));
});

test('rows show their own state: a failed run carries its error, a volume its own size', function (): void {
    [$team, $server, , $app] = VolumeServices::runningApp();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);
    $volume = $app->volumes()->sole();
    $second = $app->volumes()->create(['team_id' => $team->id, 'mount_path' => '/data/cache', 'server_id' => $server->id]);

    $run = fn (BackupRunStatus $status, int $volumeId, int $size, ?string $error = null) => tap(BackupRun::query()->create([
        'team_id' => $team->id, 'destination_id' => $destination->id, 'subject' => BackupSubject::Volume,
        'service_instance_id' => $app->id, 'volume_id' => $volumeId, 'reason' => BackupReason::Scheduled,
    ]), fn (BackupRun $r) => $r->forceFill(['status' => $status, 'size_bytes' => $size, 'error' => $error, 'finished_at' => now()])->save());

    $run(BackupRunStatus::Succeeded, $volume->id, 1000);
    $run(BackupRunStatus::Succeeded, $second->id, 50);
    $run(BackupRunStatus::Failed, $second->id, 0, "restic: unable to open\nmore detail");

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.summary.state', 'attention')
            ->where('page.summary.attention.0.id', 'volume:'.$second->public_id)
            ->where('page.groups.1.items.0.id', 'volume:'.$second->public_id)
            ->where('page.groups.1.items.0.state', 'failed')
            ->where('page.groups.1.items.0.lastError', 'restic: unable to open')
            ->where('page.groups.1.items.0.lastSizeBytes', 50)
            ->where('page.groups.1.items.0.volumeBackup.looksLikeCache', true)
            ->where('page.groups.1.items.0.schedule.sharedWithVolumes', 2)
            ->where('page.groups.1.items.1.id', 'volume:'.$volume->public_id)
            ->where('page.groups.1.items.1.state', 'ok')
            ->where('page.groups.1.items.1.lastSizeBytes', 1000)
            ->where('page.groups.1.items.1.backUp.url', route('backups.services.volumes.run', ['serviceInstance' => $app, 'volume' => $volume->public_id])));
});

test('who sees what: viewers can\'t back up, admins see launchroom itself read-only, other teams see nothing', function (): void {
    [$team] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $this->actingAs(memberOf($team, TeamRole::Viewer))->get(route('backups.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.launchroom', null)
            ->where('page.groups.0.items.0.backUp.disabled.code', 'no_permission')
            ->where('page.everything.action.disabled.code', 'no_permission')
            ->where('page.storage.add.disabled.code', 'no_permission'));

    $this->actingAs(memberOf($team, TeamRole::Developer))->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.launchroom', null)
            ->where('page.groups.0.items.0.backUp.url', fn (?string $url): bool => $url !== null));

    $this->actingAs(memberOf($team, TeamRole::Admin))->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.launchroom.isOwner', false)
            ->where('page.launchroom.item.backUp.disabled.code', 'owner_only')
            ->where('page.launchroom.destination', null)
            ->where('page.launchroom.recoveryKit', null)
            ->where('page.launchroom.runs', []));

    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($stranger)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page->has('page.groups', 1)->where('page.groups.0.key', 'launchroom')->has('page.storage.destinations', 0));
});

test('services in previews and turned-off schedules are listed as not backed up, with the reason', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    BackupSchedule::query()->where('service_instance_id', $instance->id)->update(['enabled' => false]);

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->has('page.groups', 1)
            ->where('page.notBackedUp.0.id', 'database:'.$instance->public_id)
            ->where('page.notBackedUp.0.reason', 'schedule_off')
            ->where('page.notBackedUp.0.turnOn.url', fn (?string $url): bool => $url !== null)
            ->where('page.notBackedUp.0.schedule.enabled', false));

    // "Turn on" sends only {enabled: true}: cron and retention stay.
    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();
    [$cron, $retention] = [$schedule->cron, $schedule->retention->summary()];
    $this->actingAs($owner)->put(route('backups.schedules.update', $schedule), ['enabled' => true])->assertSessionHasNoErrors();
    expect($schedule->refresh()->enabled)->toBeTrue()
        ->and($schedule->cron)->toBe($cron)
        ->and($schedule->retention->summary())->toBe($retention);
});

test('the page\'s queries don\'t grow with the number of databases', function (): void {
    [$team] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    Backups::destination($team, $owner);

    $count = function () use ($owner): int {
        $this->actingAs($owner)->get(route('backups.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('backups.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $one = $count();
    Backups::runningPostgres($team);
    Backups::runningPostgres($team);
    $three = $count();

    expect($three)->toBeLessThanOrEqual($one + 2)
        ->and($one)->toBeLessThan(60);
});

test('the backups policy matrix', function (TeamRole $role, bool $view, bool $backUp, bool $configure): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $user = memberOf($team, $role);
    $gate = Gate::forUser($user);

    expect($gate->allows('viewAny', [BackupDestination::class, $team]))->toBe($view)
        ->and($gate->allows('createAny', [BackupRun::class, $team]))->toBe($backUp)
        ->and($gate->allows('create', [BackupRun::class, $instance]))->toBe($backUp)
        ->and($gate->allows('configure', [BackupRun::class, $instance]))->toBe($configure)
        ->and($gate->allows('create', [BackupDestination::class, $team]))->toBe($configure)
        ->and($gate->allows('manageInstance', BackupDestination::class))->toBeFalse();

    $other = memberOf(Team::factory()->create(), TeamRole::Owner);
    expect(Gate::forUser($other)->allows('createAny', [BackupRun::class, $team]))->toBeFalse()
        ->and(Gate::forUser($other)->allows('configure', [BackupRun::class, $instance]))->toBeFalse();
})->with([
    'owner' => [TeamRole::Owner, true, true, true],
    'admin' => [TeamRole::Admin, true, true, true],
    'developer' => [TeamRole::Developer, true, true, false],
    'viewer' => [TeamRole::Viewer, true, false, false],
]);

test('the recovery kit steps move on without a reload: the download records itself and says when', function (): void {
    config(['launchroom.backups.recovery_kdf' => ['opslimit' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, 'memlimit' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE]]);
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    Backups::destination(null, $owner);
    $step = fn (): string => $this->actingAs($owner)->get(route('backups.index'))->viewData('page')['props']['page']['launchroom']['recoveryKit']['step'];

    expect($step())->toBe('passphrase');

    $this->actingAs($owner)->post(route('backups.recovery.passphrase'), ['passphrase' => 'correct horse battery staple', 'passphrase_confirmation' => 'correct horse battery staple'])->assertSessionHasNoErrors();
    expect($step())->toBe('download');

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page->where('page.launchroom.recoveryKit.confirm.disabled.code', 'kit_not_downloaded'));

    $this->actingAs($owner)->get(route('backups.recovery.kit'))->assertOk()->assertHeader('X-Kit-Downloaded-At');
    expect($step())->toBe('keep_safe');

    $this->actingAs($owner)->post(route('backups.recovery.confirm'))->assertSessionHasNoErrors();
    expect($step())->toBe('done');

    // A new passphrase asks for the kit to be put away again, and says why.
    $this->actingAs($owner)->post(route('backups.recovery.passphrase'), ['passphrase' => 'another long passphrase', 'passphrase_confirmation' => 'another long passphrase']);
    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.launchroom.recoveryKit.step', 'keep_safe')
            ->where('page.launchroom.recoveryKit.resetReason', 'passphrase_changed'));

    $this->actingAs($owner)->post(route('backups.recovery.confirm'))->assertSessionHasNoErrors();
    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page->where('page.launchroom.recoveryKit.resetReason', null));
});

test('only members who can see the team\'s backups join its backups channel', function (): void {
    $team = Team::factory()->create();
    $viewer = memberOf($team, TeamRole::Viewer);
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $backups = Broadcast::driver()->getChannels()[Channel::TeamBackups->pattern()];

    expect($backups($viewer, $team->public_id))->toBeTrue()
        ->and($backups($stranger, $team->public_id))->toBeFalse()
        ->and($backups($viewer, 'team_nope'))->toBeFalse();
});
