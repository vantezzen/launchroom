<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\DestinationStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\Backups\DestinationSecrets;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function destinationForm(array $overrides = []): array
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

test('admins add a destination; secrets are envelope-encrypted, hidden and never rendered', function (): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), destinationForm())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $destination = BackupDestination::query()->where('team_id', $team->id)->sole();

    expect($destination->is_default)->toBeTrue()
        ->and($destination->credentials)->toStartWith('lr1:')
        ->and($destination->repository_password)->toStartWith('lr1:')
        ->and(json_encode($destination->toArray(), JSON_THROW_ON_ERROR))->not->toContain('lr1:')
        ->and($destination->repository())->toBe('s3:https://abc.r2.cloudflarestorage.com/launchroom-backups/prod')
        // restic is absent in tests, so the background test fails visibly.
        ->and($destination->refresh()->status)->toBe(DestinationStatus::Failing)
        ->and($destination->last_error)->toContain('restic is not installed');

    $credentials = resolve(DestinationSecrets::class)->reveal($destination);
    expect($credentials->secretAccessKey)->toBe('super-secret-access-key-value')
        ->and(print_r($credentials, true))->not->toContain('super-secret');

    $page = $this->actingAs($admin)->get(route('backups.settings'))->assertOk();
    expect($page->getContent())->not->toContain('super-secret-access-key-value')
        ->not->toContain('AKIATESTKEY000001')
        ->not->toContain($credentials->repositoryPassword);

    $audit = AuditEvent::query()->where('action', AuditAction::BackupDestinationCreated->value)->sole();
    expect(json_encode($audit->meta, JSON_THROW_ON_ERROR))->not->toContain('super-secret');
});

test('endpoints must be public https unless an instance owner allows a private network', function (string $endpoint, bool $private, ?string $error): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);
    $admin->forceFill(['is_instance_owner' => $private])->save();

    $response = $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), destinationForm(['endpoint' => $endpoint, 'allowPrivateNetwork' => $private]));

    if ($error === null) {
        $response->assertSessionHasNoErrors();
        expect(BackupDestination::query()->where('team_id', $team->id)->count())->toBe(1);
    } else {
        $response->assertSessionHasErrors('endpoint');
        expect(session('errors')->first('endpoint'))->toContain($error);
        expect(BackupDestination::query()->count())->toBe(0);
    }
})->with([
    'public https' => ['https://s3.example.com', false, null],
    'plain http' => ['http://s3.example.com', false, 'https'],
    'private address' => ['https://internal.example.com', false, 'private'],
    'loopback literal' => ['https://127.0.0.1:9000', false, 'private'],
    'with path' => ['https://s3.example.com/bucket', false, 'without a bucket'],
    'with credentials' => ['https://user:pw@s3.example.com', false, 'without a bucket'],
    'shell metacharacters' => ['https://s3.example.com;id', false, 'without a bucket'],
    'LAN with override' => ['http://minio.local:9000', true, null],
]);

test('allowing a private network is audited', function (): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Owner);
    $admin->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), destinationForm(['endpoint' => 'http://versitygw:7070', 'allowPrivateNetwork' => true]))
        ->assertSessionHasNoErrors();

    expect(AuditEvent::query()->where('action', AuditAction::BackupDestinationPrivateNetworkAllowed->value)->count())->toBe(1);
});

test('team admins and owners who are not instance owners cannot allow a private network (M1)', function (TeamRole $role): void {
    $team = Team::factory()->create();
    $member = memberOf($team, $role);

    $this->actingAs($member)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), destinationForm(['endpoint' => 'http://169.254.169.254', 'allowPrivateNetwork' => true]))
        ->assertSessionHasErrors('allowPrivateNetwork');

    expect(BackupDestination::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::BackupDestinationPrivateNetworkAllowed->value)->count())->toBe(0);
})->with([TeamRole::Admin, TeamRole::Owner]);

test('team admins cannot enable the override on update or move a destination that has it (M1)', function (): void {
    $team = Team::factory()->create();
    $instanceOwner = memberOf($team, TeamRole::Owner);
    $instanceOwner->forceFill(['is_instance_owner' => true])->save();
    $admin = memberOf($team, TeamRole::Admin);
    $public = Backups::destination($team, $admin);

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put(route('backups.destinations.update', $public), destinationForm(['endpoint' => 'http://minio.local:9000', 'allowPrivateNetwork' => true]))
        ->assertSessionHasErrors('allowPrivateNetwork');
    expect($public->refresh()->allow_private_network)->toBeFalse();

    $this->actingAs($instanceOwner)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), destinationForm(['name' => 'LAN', 'endpoint' => 'http://minio.local:9000', 'allowPrivateNetwork' => true]))
        ->assertSessionHasNoErrors();
    $lan = BackupDestination::query()->where('name', 'LAN')->sole();

    // Editing other fields keeps the override; pointing it elsewhere doesn't.
    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put(route('backups.destinations.update', $lan), destinationForm(['name' => 'LAN 2', 'endpoint' => 'http://minio.local:9000', 'allowPrivateNetwork' => true, 'accessKeyId' => '', 'secretAccessKey' => '']))
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put(route('backups.destinations.update', $lan), destinationForm(['endpoint' => 'http://nas.local:9000', 'allowPrivateNetwork' => true, 'accessKeyId' => '', 'secretAccessKey' => '']))
        ->assertSessionHasErrors('allowPrivateNetwork');

    expect($lan->refresh()->endpoint)->toBe('http://minio.local:9000')
        ->and($lan->name)->toBe('LAN 2');
});

test('even with the override, loopback, metadata and control-plane services are refused (M1)', function (string $endpoint): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), destinationForm(['endpoint' => $endpoint, 'allowPrivateNetwork' => true]))
        ->assertSessionHasErrors('endpoint');

    expect(BackupDestination::query()->count())->toBe(0);
})->with([
    'loopback' => 'http://127.0.0.1:9000',
    'metadata' => 'http://169.254.169.254',
    'unspecified' => 'http://0.0.0.0:9000',
    'ipv6 loopback-ish' => 'http://localhost:9000',
    'postgres' => 'http://postgres:5432',
    'valkey' => 'http://valkey:6379',
    'reverb' => 'http://reverb:6001',
    'gcp metadata' => 'http://metadata.google.internal',
]);

test('"test destination" re-runs the guard and never contacts a refused endpoint (M1)', function (): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);
    $destination = Backups::destination($team, $admin);
    // A row that predates the rule.
    $destination->forceFill(['endpoint' => 'http://169.254.169.254', 'allow_private_network' => true])->save();

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.test', $destination))
        ->assertRedirect();

    expect($destination->refresh()->status)->toBe(DestinationStatus::Failing)
        ->and($destination->last_error)->toContain('link-local')
        ->and($destination->last_error)->not->toContain('restic');
});

test('bucket, prefix and region follow the daemon grammar', function (array $overrides, string $field): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), destinationForm($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'uppercase bucket' => [['bucket' => 'Backups'], 'bucket'],
    'prefix traversal' => [['prefix' => 'a/../b'], 'prefix'],
    'prefix with spaces' => [['prefix' => 'a b'], 'prefix'],
    'region injection' => [['region' => 'us-east-1 --insecure-tls'], 'region'],
]);

test('developers and viewers cannot manage destinations; other teams cannot see them', function (): void {
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);

    foreach ([TeamRole::Developer, TeamRole::Viewer] as $role) {
        $member = memberOf($team, $role);
        $this->actingAs($member)->withSession(passwordConfirmed())
            ->post(route('backups.destinations.store'), destinationForm())->assertForbidden();
        $this->actingAs($member)->withSession(passwordConfirmed())
            ->delete(route('backups.destinations.destroy', $destination))->assertForbidden();
        $this->actingAs($member)->get(route('backups.settings'))->assertOk();
    }

    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($stranger)->withSession(passwordConfirmed())
        ->put(route('backups.destinations.update', $destination), destinationForm())->assertNotFound();
    $this->actingAs($stranger)->post(route('backups.destinations.test', $destination))->assertNotFound();
});

test('the control-plane destination and recovery kit belong to instance owners', function (): void {
    $team = Team::factory()->create();
    $admin = memberOf($team, TeamRole::Admin);

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), [...destinationForm(), 'scope' => 'instance'])
        ->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->post(route('backups.recovery.passphrase'), ['passphrase' => 'correct horse battery staple', 'passphrase_confirmation' => 'correct horse battery staple'])
        ->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())->get(route('backups.recovery.kit'))->assertForbidden();

    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('backups.destinations.store'), [...destinationForm(), 'scope' => 'instance'])
        ->assertSessionHasNoErrors();

    $instance = BackupDestination::forInstance();
    expect($instance)->not->toBeNull()
        ->and(BackupSchedule::query()->where('subject', 'control_plane')->sole()->cron)->toMatch('/^\d{1,2} \*\/6 \* \* \*$/');

    // Instance destinations never resolve for non-owners (404, not 403).
    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put(route('backups.destinations.update', $instance), destinationForm())->assertNotFound();
});

test('the recovery kit flow: passphrase, audited download, confirmation', function (): void {
    config(['launchroom.backups.recovery_kdf' => ['opslimit' => SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, 'memlimit' => SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE]]);
    $team = Team::factory()->create();
    $owner = memberOf($team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    Backups::destination(null, $owner, 'Control plane');

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('backups.recovery.confirm'))
        ->assertSessionHasErrors('recoveryKit');

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('backups.recovery.passphrase'), ['passphrase' => 'short', 'passphrase_confirmation' => 'short'])
        ->assertSessionHasErrors('passphrase');

    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('backups.recovery.passphrase'), ['passphrase' => 'correct horse battery staple', 'passphrase_confirmation' => 'correct horse battery staple'])
        ->assertSessionHasNoErrors();

    $settings = InstanceSettings::current();
    expect($settings->recovery_public_key)->toStartWith('x25519:')
        ->and(json_encode($settings->toArray(), JSON_THROW_ON_ERROR))->not->toContain('correct horse');

    // Password confirmation is required for the download.
    $this->flushSession();
    $this->actingAs($owner)->get(route('backups.recovery.kit'))->assertRedirect(route('password.confirm'));

    $kit = $this->actingAs($owner)->withSession(passwordConfirmed())->get(route('backups.recovery.kit'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($kit->getContent())->toContain('repository-password: ')
        ->toContain('endpoint: https://s3.example.com')
        ->not->toContain('correct horse')
        ->and(AuditEvent::query()->where('action', AuditAction::RecoveryKitDownloaded->value)->count())->toBe(1);

    $this->actingAs($owner)->withSession(passwordConfirmed())->post(route('backups.recovery.confirm'))->assertSessionHasNoErrors();
    expect(InstanceSettings::current()->recovery_kit_confirmed_at)->not->toBeNull();

    // Rotating the passphrase asks for a new kit.
    $this->actingAs($owner)->withSession(passwordConfirmed())
        ->post(route('backups.recovery.passphrase'), ['passphrase' => 'another long passphrase', 'passphrase_confirmation' => 'another long passphrase']);
    expect(InstanceSettings::current()->recovery_kit_confirmed_at)->toBeNull();
});

test('the settings page lists destinations and schedules', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $this->actingAs($owner)->get(route('backups.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/backups')
            ->has('destinations', 1)
            ->has('schedules', 1)
            ->where('schedules.0.serviceId', $instance->service->public_id)
            ->where('instance', null)
            ->where('canManage', true));
});

test('a schedule can be edited with cron and retention, validated', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);
    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();

    $this->actingAs($owner)->put(route('backups.schedules.update', $schedule), ['cron' => '0 * * * *', 'retention' => ['keepHourly' => 24, 'keepDaily' => 7], 'enabled' => true])
        ->assertSessionHasNoErrors();
    expect($schedule->refresh()->cron)->toBe('0 * * * *')->and($schedule->retention->keepHourly)->toBe(24);

    $this->actingAs($owner)->put(route('backups.schedules.update', $schedule), ['cron' => '* * * *', 'retention' => ['keepDaily' => 7], 'enabled' => true])
        ->assertSessionHasErrors('cron');
    $this->actingAs($owner)->put(route('backups.schedules.update', $schedule), ['cron' => '0 3 * * *', 'retention' => [], 'enabled' => true])
        ->assertSessionHasErrors('retention');

    $developer = memberOf($team, TeamRole::Developer);
    $this->actingAs($developer)->put(route('backups.schedules.update', $schedule), ['cron' => '0 3 * * *', 'retention' => ['keepDaily' => 1], 'enabled' => false])
        ->assertForbidden();
});

test('a user without a team membership is never shown another team destination', function (): void {
    $team = Team::factory()->create();
    $destination = Backups::destination($team, memberOf($team, TeamRole::Owner));
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->post(route('backups.destinations.test', $destination))->assertNotFound();
});

test('the settings page shows a 30-day strip per schedule and what each destination holds', function (): void {
    [$team, , , $instance] = Backups::runningPostgres();
    $owner = memberOf($team, TeamRole::Owner);
    $destination = Backups::destination($team, $owner);
    $schedule = BackupSchedule::query()->where('service_instance_id', $instance->id)->sole();

    $run = fn (BackupRunStatus $status, int $size, string $at) => tap(BackupRun::query()->create([
        'team_id' => $team->id,
        'schedule_id' => $schedule->id,
        'destination_id' => $destination->id,
        'subject' => BackupSubject::Database,
        'service_instance_id' => $instance->id,
        'reason' => BackupReason::Scheduled,
    ]), fn (BackupRun $r) => $r->forceFill(['status' => $status, 'size_bytes' => $size, 'created_at' => $at, 'finished_at' => $at])->save());

    $run(BackupRunStatus::Succeeded, 1000, now()->subDays(3)->toDateTimeString());
    $run(BackupRunStatus::Failed, 0, now()->subDay()->toDateTimeString());
    $run(BackupRunStatus::Succeeded, 2000, now()->subHour()->toDateTimeString());

    $this->actingAs($owner)->get(route('backups.settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/backups')
            ->has('protection', 1)
            ->where('protection.0.scheduleId', $schedule->public_id)
            ->has('protection.0.days', 30)
            ->where('protection.0.days.29.status', 'ok')
            ->where('protection.0.days.28.status', now()->subDay()->isSameDay(now()->subHour()) ? 'ok' : 'failed')
            ->where('protection.0.lastSizeBytes', 2000)
            ->where('schedules.0.cronDescription', fn (?string $sentence): bool => $sentence !== null && str_starts_with($sentence, 'Daily at'))
            ->has('usage', 1)
            ->where('usage.0.destinationId', $destination->public_id)
            ->where('usage.0.databaseBytes', 2000)
            ->where('usage.0.volumeBytes', 0));
});
