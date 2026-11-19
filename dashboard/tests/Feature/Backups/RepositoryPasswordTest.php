<?php

declare(strict_types=1);

use App\Actions\Backups\DeleteBackupDestination;
use App\Actions\Crypto\RotateKek;
use App\Data\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\TeamRole;
use App\Exceptions\DecryptionFailed;
use App\Models\AuditEvent;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Team;
use App\Services\Backups\DestinationSecrets;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
| The restic repository password of backup storage (docs/14 §3): removing
| storage keeps it (only the access keys go), adding the same bucket and
| folder again in the same team reuses it, and owners and admins can reveal
| it, audited, to read the backups with plain restic.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reconnectForm(array $overrides = []): array
{
    return [
        'name' => 'Hetzner',
        'endpoint' => 'https://fsn1.your-objectstorage.com',
        'bucket' => 'team-backups',
        'region' => 'fsn1',
        'prefix' => 'prod',
        'accessKeyId' => 'AKIANEWKEY0000001',
        'secretAccessKey' => 'a-brand-new-secret-access-key',
        ...$overrides,
    ];
}

function passwordOf(BackupDestination $destination): string
{
    return resolve(DestinationSecrets::class)->repositoryPassword($destination);
}

test('removing storage wipes its access keys but keeps its repository password', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);
    $password = passwordOf($destination);

    resolve(DeleteBackupDestination::class)->handle($destination, AuditActor::user($owner));

    $removed = BackupDestination::withTrashed()->findOrFail($destination->id);
    expect($removed->credentials)->toBe('')
        ->and($removed->keepsRepositoryPassword())->toBeTrue()
        ->and(passwordOf($removed))->toBe($password)
        ->and(fn () => resolve(DestinationSecrets::class)->reveal($removed))->toThrow(DecryptionFailed::class)
        ->and(BackupDestination::sealedRows()->whereKey($removed->id)->exists())->toBeTrue();
});

test('adding the same bucket and folder again reconnects with the kept password and only new keys', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $this->actingAs($owner)->post(route('backups.destinations.store'), reconnectForm(['accessKeyId' => 'AKIAOLDKEY0000001']))->assertSessionHasNoErrors();
    $old = BackupDestination::query()->where('team_id', $team->id)->sole();
    $password = passwordOf($old);
    $run = BackupRun::query()->create([
        'team_id' => $team->id, 'destination_id' => $old->id, 'subject' => BackupSubject::Database,
        'service_instance_id' => $instance->id, 'reason' => BackupReason::Scheduled,
    ]);
    $run->forceFill(['status' => BackupRunStatus::Succeeded, 'snapshot_id' => bin2hex(random_bytes(32)), 'finished_at' => now()])->save();
    resolve(DeleteBackupDestination::class)->handle($old, AuditActor::user($owner));

    $this->actingAs($owner)->post(route('backups.destinations.store'), reconnectForm(['prefix' => '/prod/']))->assertSessionHasNoErrors();

    $new = BackupDestination::query()->where('team_id', $team->id)->sole();
    $credentials = resolve(DestinationSecrets::class)->reveal($new);
    $removed = BackupDestination::withTrashed()->findOrFail($old->id);
    expect($credentials->repositoryPassword)->toBe($password)
        ->and($credentials->accessKeyId)->toBe('AKIANEWKEY0000001')
        ->and($run->refresh()->destination_id)->toBe($new->id)
        // The removed row handed its password over and keeps no copy.
        ->and($removed->keepsRepositoryPassword())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::BackupDestinationCreated->value)->latest('id')->firstOrFail()->meta['repositoryPassword'] ?? null)->toBe('kept');

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page->has('page.storage.destinations', 1)->has('page.storage.removed', 0));
});

test('a given repository password wins over the kept one', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $this->actingAs($owner)->post(route('backups.destinations.store'), reconnectForm())->assertSessionHasNoErrors();
    $old = BackupDestination::query()->where('team_id', $team->id)->sole();
    resolve(DeleteBackupDestination::class)->handle($old, AuditActor::user($owner));

    $this->actingAs($owner)->post(route('backups.destinations.store'), reconnectForm(['repositoryPassword' => 'made-with-plain-restic-elsewhere']))->assertSessionHasNoErrors();

    expect(passwordOf(BackupDestination::query()->where('team_id', $team->id)->sole()))->toBe('made-with-plain-restic-elsewhere')
        ->and(BackupDestination::withTrashed()->findOrFail($old->id)->keepsRepositoryPassword())->toBeTrue();
});

test('another team adding the same bucket and folder never gets the kept password', function (): void {
    $teamA = Team::factory()->create();
    $ownerA = memberOf($teamA, TeamRole::Owner);
    $this->actingAs($ownerA)->post(route('backups.destinations.store'), reconnectForm())->assertSessionHasNoErrors();
    $old = BackupDestination::query()->where('team_id', $teamA->id)->sole();
    $password = passwordOf($old);
    resolve(DeleteBackupDestination::class)->handle($old, AuditActor::user($ownerA));

    $teamB = Team::factory()->create();
    $ownerB = memberOf($teamB, TeamRole::Owner);
    $this->actingAs($ownerB)->post(route('backups.destinations.store'), reconnectForm())->assertSessionHasNoErrors();

    $other = BackupDestination::query()->where('team_id', $teamB->id)->sole();
    expect(passwordOf($other))->not->toBe($password)
        ->and(passwordOf(BackupDestination::withTrashed()->findOrFail($old->id)))->toBe($password)
        ->and(AuditEvent::query()->where('action', AuditAction::BackupDestinationCreated->value)->where('team_id', $teamB->id)->sole()->meta['repositoryPassword'] ?? null)->toBe('generated');
});

test('who may reveal a team storage\'s repository password', function (TeamRole $role, int $status): void {
    $team = Team::factory()->create();
    $destination = Backups::destination($team, memberOf($team, TeamRole::Owner));
    $user = memberOf($team, $role);

    $response = $this->actingAs($user)->postJson(route('backups.destinations.repository-password', $destination->public_id))->assertStatus($status);

    if ($status === 200) {
        expect($response->json('value'))->toBe(passwordOf($destination))
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }

    expect(AuditEvent::query()->where('action', AuditAction::BackupRepositoryPasswordRevealed->value)->count())->toBe($status === 200 ? 1 : 0);
})->with([
    'owner' => [TeamRole::Owner, 200],
    'admin' => [TeamRole::Admin, 200],
    'developer' => [TeamRole::Developer, 403],
    'viewer' => [TeamRole::Viewer, 403],
]);

test('another team\'s storage is not found', function (): void {
    $team = Team::factory()->create();
    $destination = Backups::destination($team, memberOf($team, TeamRole::Owner));
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $stranger->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($stranger)->postJson(route('backups.destinations.repository-password', $destination->public_id))->assertNotFound();
});

test('launchroom\'s own storage: instance owners only', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $instanceOwner = memberOf($team, TeamRole::Owner);
    $instanceOwner->forceFill(['is_instance_owner' => true])->save();
    $destination = Backups::destination(null, $instanceOwner);

    $this->actingAs($owner)->postJson(route('backups.destinations.repository-password', $destination->public_id))->assertNotFound();
    $this->actingAs($instanceOwner)->postJson(route('backups.destinations.repository-password', $destination->public_id))
        ->assertOk()->assertJsonPath('value', passwordOf($destination));
});

test('removed storage\'s password can be revealed, audited, and never shows up in props, audit entries or logs', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $developer = memberOf($team, TeamRole::Developer);
    $destination = Backups::destination($team, $owner);
    $password = passwordOf($destination);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $page = $this->actingAs($owner)->get(route('backups.index'))->assertOk();
    expect($page->getContent())->not->toContain($password)
        ->and(json_encode($destination->toArray()))->not->toContain($password);

    resolve(DeleteBackupDestination::class)->handle($destination, AuditActor::user($owner));

    $this->actingAs($owner)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page
            ->where('page.storage.removed.0.passwordKept', true)
            ->where('page.storage.removed.0.repositoryPasswordUrl', route('backups.destinations.repository-password', $destination->public_id)));
    $this->actingAs($developer)->get(route('backups.index'))
        ->assertInertia(fn ($page) => $page->where('page.storage.removed.0.repositoryPasswordUrl', null));

    $this->actingAs($owner)->postJson(route('backups.destinations.repository-password', $destination->public_id))
        ->assertOk()->assertJsonPath('value', $password);

    $audit = AuditEvent::query()->where('action', AuditAction::BackupRepositoryPasswordRevealed->value)->sole();
    expect($audit->subject_id)->toBe($destination->public_id)
        ->and($audit->team_id)->toBe($team->id)
        ->and($audit->meta)->toMatchArray(['scope' => 'team', 'removed' => true])
        ->and(json_encode(AuditEvent::query()->get()->toArray()))->not->toContain($password)
        ->and(implode("\n", $logged))->not->toContain($password);
});

test('without the password there is nothing to reveal', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);
    resolve(DeleteBackupDestination::class)->handle($destination, AuditActor::user($owner));
    BackupDestination::withTrashed()->whereKey($destination->id)->update(['repository_password' => '']);

    $this->actingAs($owner)->postJson(route('backups.destinations.repository-password', $destination->public_id))->assertStatus(409);
    expect(AuditEvent::query()->where('action', AuditAction::BackupRepositoryPasswordRevealed->value)->count())->toBe(0);
});

test('KEK rotation re-wraps removed storage, so its kept password still opens', function (): void {
    $old = new Envelope(SecretKey::generate());
    app()->instance(Envelope::class, $old);
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);
    $password = passwordOf($destination);
    resolve(DeleteBackupDestination::class)->handle($destination, AuditActor::user($owner));

    $new = new Envelope(SecretKey::generate());
    resolve(RotateKek::class)->handle($old, $new);
    app()->instance(Envelope::class, $new);

    $removed = BackupDestination::withTrashed()->findOrFail($destination->id);
    expect($removed->kek_id)->toBe($new->kekId())
        ->and(resolve(DestinationSecrets::class)->repositoryPassword($removed))->toBe($password);
});
