<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\RemoteAccess\RemoteAccessEndReason;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Membership;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\RemoteAccess\AccessPrefix;
use App\Support\RemoteAccess\SshPublicKey;
use Tests\Support\Sessions;

// Temporary remote access: Connect via SSH and database access (D61, docs/09 R39).

const ED25519_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhscHR4f laptop';

beforeEach(function (): void {
    $world = Sessions::world();
    $this->team = $world['team'];
    $this->server = $world['server'];
    $this->keypair = $world['keypair'];
    $this->instance = $world['instance'];
    $this->daemon = $world['daemon'];
    $this->server->forceFill([
        'daemon_features' => ['consoleSessions', 'dataSessions', 'remoteAccessSsh', 'remoteAccessDatabase'],
        'public_ipv4' => '203.0.113.10',
    ])->save();
});

function accessUrl(ServiceInstance $instance, string $kind): string
{
    [$project, $environment, $service] = Sessions::route($instance);

    return "/access/p/{$project}/{$environment}/s/{$service}/{$kind}";
}

function accessLease($test): array
{
    $tasks = $test->daemon->send($test, 'POST', '/daemon/v1/tasks/lease', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['access']])
        ->assertOk()->json('tasks');

    return is_array($tasks) && isset($tasks[0]) ? $tasks[0] : [];
}

function accessBeat($test, array $task, array $access = []): array
{
    return $test->daemon->send($test, 'POST', '/daemon/v1/tasks/'.$task['id'].'/heartbeat', array_filter([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => $task['attempt'], 'access' => $access === [] ? null : $access,
    ], static fn ($v): bool => $v !== null))->assertOk()->json();
}

test('the permission matrix follows the Console and Data permissions; API tokens never open one', function (string $role, bool $allowed): void {
    $user = memberOf($this->team, TeamRole::from($role));

    $this->actingAs($user)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])
        ->assertStatus($allowed ? 201 : 403);
    $this->actingAs($user)->getJson(accessUrl($this->instance, 'database'))->assertStatus($allowed ? 200 : 403);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'developer' => ['developer', false],
    'viewer' => ['viewer', false],
]);

test('other teams’ services and accesses are not found', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $id = $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertCreated()->json('id');
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertNotFound();
    $this->actingAs($outsider)->getJson('/access/'.$id)->assertNotFound();
    $this->actingAs($outsider)->postJson('/access/'.$id.'/end')->assertNotFound();
});

test('starting a database access creates a role, a pinned task and an audit entry without the password', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);

    $response = $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7', '2001:db8::/48'], 'durationMinutes' => 60, 'allowWrites' => true])
        ->assertCreated();

    $access = RemoteAccess::query()->where('public_id', $response->json('id'))->sole();
    $task = DaemonTask::query()->findOrFail($access->daemon_task_id);
    $password = (string) $response->json('password');

    expect($password)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($response->json('username'))->toMatch('/^lr_tmp_[a-z0-9]{12}$/')
        ->and($access->getRawOriginal('password'))->not->toContain($password)
        ->and($access->toArray())->not->toHaveKey('password')
        ->and($task->pinned_to_server)->toBeTrue()
        ->and($task->max_attempts)->toBe(1)
        ->and($task->timeout_seconds)->toBe(3600)
        ->and((array) $task->spec)->toMatchArray([
            'kind' => 'database', 'engine' => 'postgres', 'user' => $access->username, 'allowWrites' => true,
            'allowFrom' => ['198.51.100.7/32', '2001:db8::/48'], 'hosts' => ['203.0.113.10'], 'idleTimeout' => '15m',
        ])
        ->and(json_encode($task->spec))->not->toContain($password);

    $audit = AuditEvent::query()->where('action', AuditAction::DatabaseAccessStarted->value)->sole();
    expect($audit->meta)->toMatchArray(['access' => $access->public_id, 'method' => 'password', 'writes' => true, 'minutes' => 60])
        ->and(json_encode($audit->meta))->not->toContain($password);
});

test('the lease seals the password to the server and task; heartbeats store the endpoint and audit connections', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertCreated()->json();
    $task = accessLease($this);

    expect($task['type'])->toBe('access');
    $token = json_decode((string) sodium_crypto_box_seal_open((string) base64_decode($task['spec']['password']['sealed'], true), $this->keypair), true);
    expect($token)->toBe(['serverId' => $this->server->public_id, 'taskId' => $task['id'], 'purpose' => 'access', 'token' => $opened['password']]);

    $beat = accessBeat($this, $task, [
        'port' => 40123, 'activeConnections' => 1, 'database' => 'app', 'behindNat' => true, 'privateAddresses' => ['192.168.1.20'],
        'certificate' => ['sha256' => str_repeat('ab', 32), 'pem' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n"],
        'events' => [
            ['at' => now()->toIso8601String(), 'type' => 'connected', 'remote' => '198.51.100.7', 'method' => 'password', 'channel' => 'database'],
            ['at' => now()->toIso8601String(), 'type' => 'auth_failed', 'remote' => '198.51.100.7', 'count' => 2],
        ],
    ]);
    expect($beat['cancel'])->toBeFalse();

    $view = $this->actingAs($owner)->getJson('/access/'.$opened['id'])->assertOk()->json();
    expect($view)->toMatchArray(['status' => 'open', 'port' => 40123, 'host' => '203.0.113.10', 'database' => 'app', 'behindNat' => true, 'password' => $opened['password'], 'connections' => 1]);

    expect(AuditEvent::query()->where('action', AuditAction::AccessConnected->value)->sole()->meta)
        ->toMatchArray(['access' => $opened['id'], 'remote' => '198.51.100.7', 'channel' => 'database'])
        ->and(AuditEvent::query()->where('action', AuditAction::AccessAuthFailed->value)->sole()->meta)->toMatchArray(['count' => 2]);

    // Someone else in the team (an admin) sees it but not its password.
    $admin = memberOf($this->team, TeamRole::Admin);
    expect($this->actingAs($admin)->getJson('/access/'.$opened['id'])->assertOk()->json('password'))->toBeNull();
});

test('heartbeats re-authorize: a lost permission, the instance switch or the hard cap cancels it', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);
    $this->actingAs($admin)->postJson(accessUrl($this->instance, 'ssh'), ['allowFrom' => ['198.51.100.7'], 'publicKeys' => [ED25519_KEY]])->assertCreated();
    $task = accessLease($this);
    expect(accessBeat($this, $task)['cancel'])->toBeFalse();

    Membership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->update(['role' => 'developer']);
    expect(accessBeat($this, $task)['cancel'])->toBeTrue();

    $owner = memberOf($this->team, TeamRole::Owner);
    $this->actingAs($owner)->postJson(accessUrl($this->instance, 'ssh'), ['allowFrom' => ['198.51.100.7'], 'publicKeys' => [ED25519_KEY]])->assertCreated();
    $second = accessLease($this);
    config(['launchroom.remote_access.enabled' => false]);
    expect(accessBeat($this, $second)['cancel'])->toBeTrue();
});

test('ssh with a key sends only the key; the answer has no password', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $json = $this->actingAs($owner)->postJson(accessUrl($this->instance, 'ssh'), ['allowFrom' => ['198.51.100.7'], 'publicKeys' => [ED25519_KEY]])->assertCreated()->json();
    $task = DaemonTask::query()->where('type', 'access')->sole();

    expect($json['password'])->toBeNull()
        ->and($json['authMethod'])->toBe('key')
        ->and($json['keyFingerprints'][0])->toStartWith('SHA256:')
        ->and((array) $task->spec)->toMatchArray(['kind' => 'ssh', 'authorizedKeys' => [explode(' laptop', ED25519_KEY)[0]]])
        ->and((array) $task->spec)->not->toHaveKey('password')
        ->and($task->seal)->toBeNull();
});

test('input is validated: allow-lists, keys, writes on ssh', function (array $body, string $field): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $kind = $field === 'allowWrites' || str_starts_with($field, 'publicKeys') ? 'ssh' : 'database';

    $this->actingAs($owner)->postJson(accessUrl($this->instance, $kind), $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'no allow-list' => [['allowFrom' => []], 'allowFrom'],
    'everyone' => [['allowFrom' => ['0.0.0.0/0']], 'allowFrom.0'],
    'metadata' => [['allowFrom' => ['169.254.169.254']], 'allowFrom.0'],
    'non-canonical' => [['allowFrom' => ['198.51.100.7/24']], 'allowFrom.0'],
    'a hostname' => [['allowFrom' => ['example.com']], 'allowFrom.0'],
    'a private key' => [['allowFrom' => ['198.51.100.7'], 'publicKeys' => ['-----BEGIN OPENSSH PRIVATE KEY-----']], 'publicKeys.0'],
    'a short rsa key' => [['allowFrom' => ['198.51.100.7'], 'publicKeys' => ['ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQDK']], 'publicKeys.0'],
    'writes on ssh' => [['allowFrom' => ['198.51.100.7'], 'allowWrites' => true], 'allowWrites'],
    'too long' => [['allowFrom' => ['198.51.100.7'], 'durationMinutes' => 600], 'durationMinutes'],
]);

test('end now cancels the task; completion closes it, wipes the password and audits the end', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertCreated()->json();
    $task = accessLease($this);

    $this->actingAs($owner)->postJson('/access/'.$opened['id'].'/end')->assertOk();
    expect(accessBeat($this, $task)['cancel'])->toBeTrue();

    $this->daemon->send($this, 'POST', '/daemon/v1/tasks/'.$task['id'].'/complete', [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => 'cancelled',
        'access' => ['endReason' => 'ended', 'connections' => 1, 'authFailures' => 0, 'bytesIn' => 10, 'bytesOut' => 20,
            'events' => [['at' => now()->toIso8601String(), 'type' => 'connected', 'remote' => '198.51.100.7', 'method' => 'password', 'channel' => 'database']]],
    ], ['Idempotency-Key' => $task['id'].':1'])->assertSuccessful();

    $access = RemoteAccess::query()->where('public_id', $opened['id'])->sole();
    expect($access->status)->toBe(RemoteAccessStatus::Ended)
        ->and($access->end_reason)->toBe(RemoteAccessEndReason::Ended)
        ->and($access->password)->toBeNull()
        ->and($access->connections)->toBe(1);

    $ended = AuditEvent::query()->where('action', AuditAction::DatabaseAccessEnded->value)->sole();
    expect($ended->meta)->toMatchArray(['access' => $opened['id'], 'reason' => 'ended', 'endedBy' => 'user', 'connections' => 1]);
    expect($this->actingAs($owner)->getJson('/access/'.$opened['id'])->json())->toMatchArray(['status' => 'ended', 'password' => null, 'certificatePem' => null]);
});

test('a restart of launchroomd fails the access instead of re-queuing it', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertCreated();
    $task = accessLease($this);

    $this->daemon->send($this, 'POST', '/daemon/v1/tasks/'.$task['id'].'/complete', [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => 'released',
    ], ['Idempotency-Key' => $task['id'].':1'])->assertSuccessful();

    expect(DaemonTask::query()->where('public_id', $task['id'])->sole()->status)->toBe(DaemonTaskStatus::Failed)
        ->and(RemoteAccess::query()->sole()->status)->toBe(RemoteAccessStatus::Ended);
});

test('limits: two live accesses per person; servers without the feature are refused', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertCreated();
    $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertCreated();
    $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertConflict();

    $this->server->forceFill(['daemon_features' => ['consoleSessions']])->save();
    $admin = memberOf($this->team, TeamRole::Admin);
    $this->actingAs($admin)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertConflict();
    expect($this->actingAs($admin)->getJson(accessUrl($this->instance, 'database'))->json('available'))->toBeFalse();
});

test('the panel prefills the requester’s address', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);

    $panel = $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->getJson(accessUrl($this->instance, 'ssh'))->assertOk()->json();
    expect($panel)->toMatchArray(['kind' => 'ssh', 'available' => true, 'requesterPrefix' => '198.51.100.23/32', 'idleMinutes' => 15]);
});

test('the sweep ends accesses no server picked up', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $this->actingAs($owner)->postJson(accessUrl($this->instance, 'database'), ['allowFrom' => ['198.51.100.7']])->assertCreated();
    $this->travel(3)->minutes();

    $this->artisan('launchroom:remote-access:sweep')->assertSuccessful();

    expect(RemoteAccess::query()->sole()->status)->toBe(RemoteAccessStatus::Ended);
});

test('prefixes and keys follow launchroomd’s grammar', function (): void {
    expect(AccessPrefix::normalize('198.51.100.7'))->toBe('198.51.100.7/32')
        ->and(AccessPrefix::normalize('2001:DB8::1'))->toBe('2001:db8::1/128')
        ->and(AccessPrefix::normalize('10.0.0.0/8'))->toBe('10.0.0.0/8')
        ->and(AccessPrefix::normalize('10.0.0.0/7'))->toBeNull()
        ->and(AccessPrefix::normalize('::/0'))->toBeNull()
        ->and(AccessPrefix::normalize('::ffff:198.51.100.7'))->toBeNull()
        ->and(AccessPrefix::normalize('224.0.0.1'))->toBeNull()
        ->and(AccessPrefix::normalize('198.51.100.7; rm -rf /'))->toBeNull();

    $key = SshPublicKey::parse(ED25519_KEY);
    expect($key?->line())->toBe(explode(' laptop', ED25519_KEY)[0])
        ->and(SshPublicKey::parse('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAAB'))->toBeNull()
        ->and(SshPublicKey::parse('ssh-rsa '.base64_encode(pack('N', 11).'ssh-ed25519')))->toBeNull();
});
