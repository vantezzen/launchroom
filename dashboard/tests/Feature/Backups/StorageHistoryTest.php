<?php

declare(strict_types=1);

use App\Actions\Backups\DeleteBackupDestination;
use App\Actions\Backups\RunBackupTick;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\DestinationErrorKind;
use App\Enums\Backups\DestinationStatus;
use App\Enums\TeamRole;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\RestoreRun;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Services\Backups\DestinationSecrets;
use Carbon\CarbonImmutable;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
| Backup storage (docs/14 §3, §9.2): removing it keeps the backups and their
| history; adding the same bucket and folder again reconnects them; two
| storages never share one repository; launchroom itself can keep its own
| copy in the team's bucket.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storageForm(array $overrides = []): array
{
    return [
        'name' => 'Cloudflare R2',
        'endpoint' => 'https://abc.r2.cloudflarestorage.com',
        'bucket' => 'launchroom-backups',
        'region' => 'auto',
        'prefix' => 'prod',
        'accessKeyId' => 'AKIATESTKEY000001',
        'secretAccessKey' => 'super-secret-access-key-value',
        ...$overrides,
    ];
}

function succeededRun(BackupDestination $destination, int $instanceId, int $teamId): BackupRun
{
    return tap(BackupRun::query()->create([
        'team_id' => $teamId, 'destination_id' => $destination->id, 'subject' => BackupSubject::Database,
        'service_instance_id' => $instanceId, 'reason' => BackupReason::Scheduled,
    ]), fn (BackupRun $run) => $run->forceFill(['status' => BackupRunStatus::Succeeded, 'snapshot_id' => bin2hex(random_bytes(32)), 'size_bytes' => 10, 'finished_at' => now()])->save());
}

test('removing storage keeps every backup run and restore, wipes its access keys and stops its schedules', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);
    $run = succeededRun($destination, $instance->id, $team->id);
    $restore = RestoreRun::query()->forceCreate([
        'team_id' => $team->id, 'destination_id' => $destination->id, 'snapshot_id' => $run->snapshot_id,
        'source_service_instance_id' => $instance->id, 'mode' => 'new_service', 'status' => 'failed', 'subject' => 'database',
    ]);
    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();
    $schedule->forceFill(['cron' => '15 4 * * *'])->save();

    $this->actingAs($owner)->delete(route('backups.destinations.destroy', $destination))->assertRedirect()->assertSessionHasNoErrors();

    $removed = BackupDestination::withTrashed()->findOrFail($destination->id);
    expect($removed->trashed())->toBeTrue()
        ->and($removed->credentials)->toBe('')
        ->and($removed->keepsRepositoryPassword())->toBeTrue()
        ->and(BackupDestination::query()->whereKey($destination->id)->exists())->toBeFalse()
        ->and($run->refresh()->destination_id)->toBe($destination->id)
        ->and($run->destination->trashed())->toBeTrue()
        ->and(RestoreRun::query()->whereKey($restore->id)->exists())->toBeTrue()
        ->and($schedule->refresh()->cron)->toBe('15 4 * * *');

    // Nothing runs (or fails) for storage that is gone.
    BackupSchedule::query()->whereKey($schedule->id)->update(['cron' => '* * * * *']);
    resolve(RunBackupTick::class)->handle(CarbonImmutable::now()->addMinute());
    expect(BackupRun::query()->count())->toBe(1);

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->has('page.storage.destinations', 0)
            ->where('page.storage.removed.0.id', $destination->public_id)
            ->where('page.storage.removed.0.runs', 1)
            ->where('page.storage.removed.0.passwordKept', true)
            ->where('page.groups.1.items.0.storageRemoved', true)
            ->where('page.groups.1.items.0.backUp.disabled.code', 'no_storage'));

    // Gone from routes: it can't be edited or removed again.
    $this->actingAs($owner)->delete(route('backups.destinations.destroy', $destination->public_id))->assertNotFound();
});

test('adding the same bucket and folder again with a given repository password reconnects the history and the schedules', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $this->actingAs($owner)->post(route('backups.destinations.store'), storageForm())->assertSessionHasNoErrors();
    $old = BackupDestination::query()->where('team_id', $team->id)->sole();
    $password = resolve(DestinationSecrets::class)->reveal($old)->repositoryPassword;
    $run = succeededRun($old, $instance->id, $team->id);
    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();
    $schedule->forceFill(['cron' => '15 4 * * *'])->save();

    resolve(DeleteBackupDestination::class)->handle($old, AuditActor::user($owner));

    $this->actingAs($owner)->post(route('backups.destinations.store'), storageForm(['prefix' => '/prod/', 'repositoryPassword' => $password]))->assertSessionHasNoErrors();
    $new = BackupDestination::query()->where('team_id', $team->id)->sole();

    expect($new->id)->not->toBe($old->id)
        ->and(resolve(DestinationSecrets::class)->reveal($new)->repositoryPassword)->toBe($password)
        ->and($run->refresh()->destination_id)->toBe($new->id)
        ->and($schedule->refresh()->destination_id)->toBe($new->id)
        ->and($schedule->cron)->toBe('15 4 * * *')
        ->and(BackupSchedule::query()->where('service_instance_id', $instance->id)->count())->toBe(1);
});

test('removing the default storage moves its schedules to the next one', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $first = Backups::destination($team, $owner, 'First');
    $second = Backups::destination($team, $owner, 'Second');

    resolve(DeleteBackupDestination::class)->handle($first, AuditActor::user($owner));

    expect($second->refresh()->is_default)->toBeTrue()
        ->and(BackupSchedule::query()->where('service_instance_id', $instance->id)->sole()->destination_id)->toBe($second->id);
});

test('two storages can\'t share a bucket and folder, in any team or for launchroom itself', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    $this->actingAs($owner)->post(route('backups.destinations.store'), storageForm())->assertSessionHasNoErrors();

    $this->actingAs($owner)->post(route('backups.destinations.store'), storageForm(['prefix' => 'prod/']))->assertSessionHasErrors('prefix');
    $this->actingAs($owner)->post(route('backups.destinations.store'), [...storageForm(), 'scope' => 'instance'])->assertSessionHasErrors('prefix');

    $other = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($other)->post(route('backups.destinations.store'), storageForm())->assertSessionHasErrors('prefix');

    $this->actingAs($owner)->post(route('backups.destinations.store'), storageForm(['prefix' => 'other']))->assertSessionHasNoErrors();
    $other = BackupDestination::query()->where('prefix', 'other')->sole();
    $this->actingAs($owner)->put(route('backups.destinations.update', $other), storageForm())->assertSessionHasErrors('prefix');
});

test('"Also protect launchroom itself" copies the team storage into its own folder with its own password', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    InstanceSettings::current()->forceFill(['recovery_kit_downloaded_at' => now(), 'recovery_kit_confirmed_at' => now()])->save();

    $this->actingAs($owner)->post(route('backups.destinations.store'), storageForm(['protectLaunchroom' => true]))->assertSessionHasNoErrors();

    $teamStorage = BackupDestination::query()->where('team_id', $team->id)->sole();
    $instance = BackupDestination::forInstance();
    $secrets = resolve(DestinationSecrets::class);

    expect($instance)->not->toBeNull()
        ->and($instance->bucket)->toBe($teamStorage->bucket)
        ->and($instance->endpoint)->toBe($teamStorage->endpoint)
        ->and($instance->prefix)->toBe('prod/launchroom')
        ->and($instance->copied_from_id)->toBe($teamStorage->id)
        ->and($instance->wrapped_dek)->not->toBe($teamStorage->wrapped_dek)
        ->and($secrets->reveal($instance)->secretAccessKey)->toBe('super-secret-access-key-value')
        ->and($secrets->reveal($instance)->repositoryPassword)->not->toBe($secrets->reveal($teamStorage)->repositoryPassword)
        ->and(BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->sole()->destination_id)->toBe($instance->id)
        ->and(InstanceSettings::current()->recovery_kit_confirmed_at)->toBeNull();

    $page = $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($p) => $p
            ->where('page.launchroom.copiedFrom', $teamStorage->public_id)
            ->where('page.storage.destinations.0.sharedWithLaunchroom', true)
            ->where('page.storage.canProtectLaunchroom', false)
            ->where('page.launchroom.recoveryKit.step', 'passphrase'));

    expect($page->getContent())
        ->not->toContain('super-secret-access-key-value')
        ->not->toContain('AKIATESTKEY000001')
        ->not->toContain($secrets->reveal($instance)->repositoryPassword)
        ->not->toContain($secrets->reveal($teamStorage)->repositoryPassword);

    // Removing the team's storage leaves launchroom's own copy alone.
    resolve(DeleteBackupDestination::class)->handle($teamStorage, AuditActor::user($owner));
    expect(BackupDestination::forInstance()?->id)->toBe($instance->id)
        ->and($secrets->reveal($instance->refresh())->secretAccessKey)->toBe('super-secret-access-key-value');
});

test('launchroom\'s copy can also be made later from the launchroom row, by instance owners only', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $admin = memberOf($team, TeamRole::Admin);
    $this->actingAs($owner)->post(route('backups.destinations.store'), storageForm())->assertSessionHasNoErrors();
    $teamStorage = BackupDestination::query()->where('team_id', $team->id)->sole();

    $this->actingAs($admin)->post(route('backups.destinations.store'), storageForm(['prefix' => 'b', 'protectLaunchroom' => true]))->assertSessionHasErrors('protectLaunchroom');
    $this->actingAs($admin)->post(route('backups.control-plane.storage'), ['from' => $teamStorage->public_id])->assertForbidden();
    $this->actingAs($owner)->post(route('backups.control-plane.storage'), ['from' => $teamStorage->public_id])->assertForbidden();

    $owner->forceFill(['is_instance_owner' => true])->save();
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $foreign = Backups::destination($stranger->teams()->first(), $stranger);
    $this->actingAs($owner)->post(route('backups.control-plane.storage'), ['from' => $foreign->public_id])->assertNotFound();

    $this->actingAs($owner)->post(route('backups.control-plane.storage'), ['from' => $teamStorage->public_id])->assertSessionHasNoErrors();
    expect(BackupDestination::forInstance()?->copied_from_id)->toBe($teamStorage->id);

    $this->actingAs($owner)->post(route('backups.control-plane.storage'), ['from' => $teamStorage->public_id])->assertSessionHasErrors();
    expect(BackupDestination::query()->instanceWide()->count())->toBe(1);
});

test('a failing storage says why', function (?string $error, ?DestinationErrorKind $kind): void {
    expect(DestinationErrorKind::classify($error))->toBe($kind);
})->with([
    'nothing' => [null, null],
    'wrong password' => ['restic could not open the repository: Fatal: wrong password or no key found', DestinationErrorKind::WrongRepositoryPassword],
    'bad key' => ['restic: The AWS Access Key Id you provided does not exist in our records. (InvalidAccessKeyId)', DestinationErrorKind::WrongCredentials],
    'denied' => ['Access Denied', DestinationErrorKind::WrongCredentials],
    'no bucket' => ['NoSuchBucket: The specified bucket does not exist', DestinationErrorKind::BucketMissing],
    'dns' => ['dial tcp: lookup s3.example.com: no such host', DestinationErrorKind::Unreachable],
    'other' => ['restic is not installed in this dashboard image.', DestinationErrorKind::Other],
]);

test('a failing test is stored with its kind and shown on the card', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);

    $destination->markChecked(DestinationStatus::Failing, 'Fatal: wrong password or no key found');

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.storage.destinations.0.status', 'failing')
            ->where('page.storage.destinations.0.errorKind', 'wrong_repository_password'));
});
