<?php

declare(strict_types=1);

use App\Actions\Backups\RunBackupTick;
use App\Actions\Backups\StartServiceBackup;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DaemonTaskType;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Enums\ServerReplacementStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Backups\RestoreRun;
use App\Models\InstanceSettings;
use App\Models\Placement;
use App\Models\ServerReplacement;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Queries\ServerReplacementPlan;
use App\Services\Mesh\MeshSecrets;
use Tests\Support\Backups;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;
use Tests\Support\VolumeServices;

/*
| "Replace server" (docs/14 §5.3, §9.14): a dead server's roles move to a
| replacement, its databases and volumes are restored there from their
| latest backups, the other services are re-placed after them, and the dead
| server is revoked (it may only fetch its empty decommission state).
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    [$this->team, $this->old, , $this->postgres] = Backups::runningPostgres();
    $this->old->forceFill(['labels' => ['zone' => 'fsn1'], 'name' => 'old-box'])->save();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    Backups::destination($this->team, $this->owner, makeDefault: true);

    // The database is backed up; a web service runs next to it.
    resolve(StartServiceBackup::class)->handle($this->postgres, BackupReason::Manual, $this->owner, AuditActor::user($this->owner));
    VolumeServices::backedUp($this->old, str_repeat('ab', 32));
    $this->web = ServiceInstance::query()->where('service_id', Projects::image($this->postgres->environment->project, 'web')->id)->sole();
    resolve(DeployEnvironment::class)->handle($this->postgres->environment, null, AuditActor::system());
    Projects::report($this->old);
    Projects::report($this->old);

    [$this->new, $this->newKeypair] = Projects::server($this->team, [ServerRole::Runtime], ip: '203.0.113.8');
    $this->new->forceFill(['name' => 'new-box'])->save();

    $this->fake = new FakeDaemon;
    $this->old->forceFill(['signing_public_key' => $this->fake->signingKey()])->save();
    $this->fake->as($this->old->public_id);
    $this->old->refresh()->transitionTo(ServerStatus::Offline);
});

test('the wizard shows what lived on the dead server and who can take over', function (): void {
    $this->actingAs($this->owner)->get(route('servers.show', $this->old))
        ->assertInertia(fn ($page) => $page->where('replace.available', true));

    $this->actingAs($this->owner)->get(route('servers.replace', $this->old))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/replace')
            ->where('blockedReason', null)
            ->where('candidates.0.id', $this->new->public_id)
            ->where('plan', fn ($plan): bool => collect($plan)->firstWhere('instanceId', $this->postgres->public_id)['snapshot'] === 'abababab'
                && collect($plan)->firstWhere('instanceId', $this->web->public_id)['stateful'] === false));

    $this->actingAs(memberOf($this->team, TeamRole::Developer))->get(route('servers.replace', $this->old))->assertForbidden();
});

test('an online or control-plane server is not replaced from the wizard', function (): void {
    $this->actingAs($this->owner)->get(route('servers.replace', $this->new))
        ->assertInertia(fn ($page) => $page->where('blockedReason', fn (string $reason): bool => str_contains($reason, 'is online')));

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.replace.store', $this->new), ['replacement' => $this->old->public_id])
        ->assertSessionHasErrors('replacement');
});

test('replacing moves roles, labels and data, revokes the dead server and restores the database before re-placing the rest', function (): void {
    $url = route('servers.replace.store', $this->old);
    $this->actingAs($this->owner)->post($url, ['replacement' => $this->new->public_id])->assertRedirect(route('password.confirm'));
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post($url, ['replacement' => $this->new->public_id, 'restore' => [$this->postgres->public_id]])
        ->assertRedirect(route('servers.replace', $this->old));

    $old = $this->old->refresh();
    $new = $this->new->refresh();
    $replacement = ServerReplacement::query()->sole();

    expect($new->roles->map(fn (ServerRole $role): string => $role->value)->sort()->values()->all())->toBe(['build', 'edge', 'runtime'])
        ->and($new->labels)->toBe(['zone' => 'fsn1'])
        ->and($old->status)->toBe(ServerStatus::Revoked)
        ->and($old->replaced_by_server_id)->toBe($new->id)
        ->and(Volume::query()->where('service_instance_id', $this->postgres->id)->sole()->server_id)->toBe($new->id)
        ->and(Placement::query()->where('server_id', $old->id)->exists())->toBeFalse()
        ->and(Projects::nodeState($old)['workloads'] ?? [])->toBe([])
        ->and(AuditEvent::query()->where('action', AuditAction::ServerReplaced->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::ServerRevoked->value)->exists())->toBeTrue();

    $restore = RestoreRun::query()->sole();
    expect($restore->server_replacement_id)->toBe($replacement->id)
        ->and($restore->mode)->toBe(RestoreMode::InPlace)
        ->and($restore->subject)->toBe(BackupSubject::Database)
        ->and($restore->snapshot_id)->toBe(str_repeat('ab', 32))
        ->and($restore->status)->toBe(RestoreRunStatus::WaitingForTarget);

    // The database starts on the replacement first; the web service waits for it.
    expect(Placement::query()->where('service_instance_id', $this->postgres->id)->where('server_id', $new->id)->exists())->toBeTrue()
        ->and(Placement::query()->where('service_instance_id', $this->web->id)->exists())->toBeFalse();

    Projects::report($new);
    Projects::report($new);
    resolve(RunBackupTick::class)->handle();
    expect($restore->refresh()->status)->toBe(RestoreRunStatus::Restoring);

    $task = VolumeServices::lease($new, DaemonTaskType::Restore);
    $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    expect($spec['kind'])->toBe('database')
        ->and($spec['mode'])->toBe('in_place');
    VolumeServices::finish($new, $task, 'succeeded', ['restore' => ['sizeBytes' => 10, 'durationMs' => 3]]);
    resolve(RunBackupTick::class)->handle();

    expect(collect(ServerReplacement::query()->sole()->services)->firstWhere('instance', $this->postgres->public_id)['status'])->toBe('restored')
        ->and(Placement::query()->where('service_instance_id', $this->web->id)->where('server_id', $new->id)->exists())->toBeTrue();

    Projects::report($new);
    Projects::report($new);
    resolve(RunBackupTick::class)->handle();

    expect(ServerReplacement::query()->sole()->status)->toBe(ServerReplacementStatus::Completed);
});

test('a replaced server may only fetch its empty decommission state', function (): void {
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.replace.store', $this->old), ['replacement' => $this->new->public_id, 'restore' => []])
        ->assertRedirect();

    $state = $this->fake->send($this, 'GET', '/daemon/v1/state?have=0')->assertOk();
    $document = json_decode($state->getContent() ?: '{}', true, 64, JSON_THROW_ON_ERROR);
    expect($document['workloads'] ?? [])->toBe([])
        ->and($document['serverId'])->toBe($this->old->public_id);

    $this->fake->send($this, 'POST', '/daemon/v1/tasks/lease', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 1, 'types' => ['backup']])->assertUnauthorized();
    $this->fake->send($this, 'GET', '/daemon/v1/config')->assertUnauthorized();
    // No registry tokens either (docs/09 R25).
    $tokenRequest = ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'RegistryTokenRequest', 'scopes' => [['repository' => 'mirror/docker.io/library/node', 'actions' => ['pull']]]];
    $this->fake->send($this, 'POST', '/daemon/v1/registry-token', $tokenRequest)->assertUnauthorized();

    // A server revoked on purpose (not replaced) gets nothing at all.
    $other = new FakeDaemon;
    [$retired] = Projects::server($this->team, ip: '203.0.113.9');
    $retired->forceFill(['signing_public_key' => $other->signingKey()])->save();
    $retired->transitionTo(ServerStatus::Revoked);
    $other->as($retired->public_id)->send($this, 'GET', '/daemon/v1/state?have=0')->assertUnauthorized();
    $other->send($this, 'POST', '/daemon/v1/registry-token', $tokenRequest)->assertUnauthorized();
});

test('the decommission state turns the mesh client off and carries no key (review L9)', function (): void {
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now(), 'mesh_mode' => MeshMode::OwnTailscale, 'mesh_login_server' => 'https://login.tailscale.com'])->save();
    resolve(MeshSecrets::class)->put(MeshSecrets::instanceScope(), MeshCredentialPurpose::TailscaleAuthKey, 'tskey-auth-SECRET');

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.replace.store', $this->old), ['replacement' => $this->new->public_id, 'restore' => []])
        ->assertRedirect();

    $state = $this->fake->send($this, 'GET', '/daemon/v1/state?have=0')->assertOk();
    $document = json_decode($state->getContent() ?: '{}', true, 64, JSON_THROW_ON_ERROR);

    expect($document['system']['tailscale']['enabled'] ?? null)->toBeFalse()
        ->and($document['system']['tailscale'])->not->toHaveKey('authKey');
});

test('services without a backup start empty and are reported so', function (): void {
    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->post(route('servers.replace.store', $this->old), ['replacement' => $this->new->public_id, 'restore' => []])
        ->assertRedirect();

    expect(RestoreRun::query()->exists())->toBeFalse();
    $services = collect(ServerReplacement::query()->sole()->services);
    expect($services->firstWhere('instance', $this->postgres->public_id)['message'])->toContain('starts empty');
});

test('removed services are not brought back by a replacement', function (): void {
    $this->web->forceFill(['removed_at' => now()])->save();

    $plan = resolve(ServerReplacementPlan::class)->for($this->old);

    expect(collect($plan)->map(fn (array $entry): string => $entry['instance']->public_id)->all())->toBe([$this->postgres->public_id]);
});
