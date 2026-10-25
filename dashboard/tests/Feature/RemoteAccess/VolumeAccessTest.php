<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Volume;
use Tests\Support\Sessions;

// Connect with SFTP: temporary access into one volume (D67, docs/03 §12.4).

const VOLUME_ACCESS_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhscHR4f laptop';

beforeEach(function (): void {
    $world = Sessions::world();
    $this->team = $world['team'];
    $this->server = $world['server'];
    $this->keypair = $world['keypair'];
    $this->instance = $world['instance'];
    $this->daemon = $world['daemon'];
    $this->server->forceFill([
        'daemon_features' => ['consoleSessions', 'dataSessions', 'remoteAccessSsh', 'remoteAccessVolume'],
        'public_ipv4' => '203.0.113.10',
    ])->save();
    $this->volume = Volume::query()->where('service_instance_id', $this->instance->id)->sole();
});

function volumeAccessUrl(ServiceInstance $instance, ?Volume $volume = null): string
{
    [$project, $environment, $service] = Sessions::route($instance);

    return "/access/p/{$project}/{$environment}/s/{$service}/volume".($volume instanceof Volume ? '?volume='.$volume->public_id : '');
}

function volumeAccessLease($test): array
{
    $tasks = $test->daemon->send($test, 'POST', '/daemon/v1/tasks/lease', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['access']])
        ->assertOk()->json('tasks');

    return is_array($tasks) && isset($tasks[0]) ? $tasks[0] : [];
}

function volumeAccessBeat($test, array $task, array $access = []): array
{
    return $test->daemon->send($test, 'POST', '/daemon/v1/tasks/'.$task['id'].'/heartbeat', array_filter([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => $task['attempt'], 'access' => $access === [] ? null : $access,
    ], static fn ($v): bool => $v !== null))->assertOk()->json();
}

/**
 * The fixture's service is a database; tests about app volumes turn it into
 * an image service (only the service's kind matters to volume access).
 */
function asImageService(ServiceInstance $instance): void
{
    $instance->service->forceFill(['kind' => 'image'])->save();
}

test('the permission matrix follows the Console permission; API tokens never open one', function (string $role, bool $allowed): void {
    $user = memberOf($this->team, TeamRole::from($role));
    $body = ['allowFrom' => ['198.51.100.7'], 'publicKeys' => [VOLUME_ACCESS_KEY], 'volume' => $this->volume->public_id];

    $this->actingAs($user)->postJson(volumeAccessUrl($this->instance), $body)->assertStatus($allowed ? 201 : 403);
    $this->actingAs($user)->getJson(volumeAccessUrl($this->instance, $this->volume))->assertStatus($allowed ? 200 : 403);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'developer' => ['developer', false],
    'viewer' => ['viewer', false],
]);

test('a volume access pins a task to the volume’s server with the volume, the pinned helper image and the key', function (): void {
    asImageService($this->instance);
    $owner = memberOf($this->team, TeamRole::Owner);

    $json = $this->actingAs($owner)->postJson(volumeAccessUrl($this->instance), [
        'allowFrom' => ['198.51.100.7'], 'publicKeys' => [VOLUME_ACCESS_KEY], 'volume' => $this->volume->public_id, 'allowWrites' => true, 'durationMinutes' => 60,
    ])->assertCreated()->json();
    $access = RemoteAccess::query()->where('public_id', $json['id'])->sole();
    $task = DaemonTask::query()->findOrFail($access->daemon_task_id);

    expect($json)->toMatchArray(['kind' => 'volume', 'volumeId' => $this->volume->public_id, 'mountPath' => $this->volume->mount_path, 'password' => null, 'authMethod' => 'key', 'allowWrites' => true])
        ->and($json['username'])->toMatch('/^lr-[a-z0-9]{10}$/')
        ->and($access->volume_id)->toBe($this->volume->id)
        ->and($task->server_id)->toBe($this->volume->server_id)
        ->and($task->pinned_to_server)->toBeTrue()
        ->and((array) $task->spec)->toMatchArray([
            'kind' => 'volume', 'volume' => $this->volume->public_id, 'allowWrites' => true, 'user' => $access->username,
            'image' => config('launchroom.daemon.system_images.files'), 'authorizedKeys' => [explode(' laptop', VOLUME_ACCESS_KEY)[0]],
        ])
        ->and((array) $task->spec)->not->toHaveKey('engine')
        ->and((string) config('launchroom.daemon.system_images.files'))->toContain('@sha256:');

    expect(AuditEvent::query()->where('action', AuditAction::VolumeAccessStarted->value)->sole()->meta)
        ->toMatchArray(['access' => $json['id'], 'volume' => $this->volume->public_id, 'mountPath' => $this->volume->mount_path, 'writes' => true, 'method' => 'key']);
});

test('a database’s volume opens read-only; writes are refused', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $body = ['allowFrom' => ['198.51.100.7'], 'volume' => $this->volume->public_id];

    $this->actingAs($owner)->postJson(volumeAccessUrl($this->instance), [...$body, 'allowWrites' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['allowWrites']);
    expect($this->actingAs($owner)->getJson(volumeAccessUrl($this->instance, $this->volume))->assertOk()->json())
        ->toMatchArray(['kind' => 'volume', 'available' => true, 'writable' => false, 'mountPath' => $this->volume->mount_path, 'volumeId' => $this->volume->public_id]);

    $json = $this->actingAs($owner)->postJson(volumeAccessUrl($this->instance), $body)->assertCreated()->json();
    $task = DaemonTask::query()->where('type', 'access')->sole();
    expect($json['password'])->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and((array) $task->spec)->not->toHaveKey('allowWrites')
        ->and($task->seal)->toBe(['accessPassword' => ['access' => $json['id']]]);
});

test('volumes are found through the service: other services’, removed and unplaced ones are not', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $other = Sessions::world();
    $foreign = Volume::query()->where('service_instance_id', $other['instance']->id)->sole();

    $this->actingAs($owner)->getJson(volumeAccessUrl($this->instance, $foreign))->assertNotFound();
    $this->actingAs($owner)->getJson(volumeAccessUrl($this->instance))->assertNotFound();
    $this->actingAs($owner)->postJson(volumeAccessUrl($this->instance), ['allowFrom' => ['198.51.100.7'], 'volume' => $foreign->public_id])
        ->assertUnprocessable()->assertJsonValidationErrors(['volume']);
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->getJson(volumeAccessUrl($this->instance, $this->volume))->assertNotFound();

    $staged = Volume::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'mount_path' => '/staged']);
    expect($this->actingAs($owner)->getJson(volumeAccessUrl($this->instance, $staged))->assertOk()->json())
        ->toMatchArray(['available' => false, 'unavailableReason' => 'This volume is created with the next deploy. Deploy the service, then connect.']);
    $this->actingAs($owner)->postJson(volumeAccessUrl($this->instance), ['allowFrom' => ['198.51.100.7'], 'volume' => $staged->public_id])->assertConflict();

    $this->volume->forceFill(['removed_at' => now()])->save();
    $this->actingAs($owner)->getJson(volumeAccessUrl($this->instance, $this->volume))->assertNotFound();
});

test('servers without the feature are refused', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $this->server->forceFill(['daemon_features' => ['consoleSessions', 'remoteAccessSsh']])->save();

    expect($this->actingAs($owner)->getJson(volumeAccessUrl($this->instance, $this->volume))->json('available'))->toBeFalse();
    $this->actingAs($owner)->postJson(volumeAccessUrl($this->instance), ['allowFrom' => ['198.51.100.7'], 'volume' => $this->volume->public_id])->assertConflict();
});

test('heartbeats store the owner; a removed volume or lost permission ends the access', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);
    $opened = $this->actingAs($admin)->postJson(volumeAccessUrl($this->instance), ['allowFrom' => ['198.51.100.7'], 'publicKeys' => [VOLUME_ACCESS_KEY], 'volume' => $this->volume->public_id])->assertCreated()->json();
    $task = volumeAccessLease($this);

    $beat = volumeAccessBeat($this, $task, [
        'port' => 40150, 'activeConnections' => 1, 'owner' => ['uid' => 999, 'gid' => 999],
        'hostKey' => ['type' => 'ssh-ed25519', 'sha256' => 'SHA256:'.str_repeat('A', 43), 'publicKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhscHR4f'],
        'events' => [['at' => now()->toIso8601String(), 'type' => 'connected', 'remote' => '198.51.100.7', 'method' => 'publickey', 'channel' => 'sftp']],
    ]);
    expect($beat['cancel'])->toBeFalse();

    $view = $this->actingAs($admin)->getJson('/access/'.$opened['id'])->assertOk()->json();
    expect($view)->toMatchArray(['status' => 'open', 'port' => 40150, 'fileOwner' => '999:999', 'mountPath' => $this->volume->mount_path])
        ->and($view['knownHostsLine'])->toStartWith('[203.0.113.10]:40150 ssh-ed25519 ');
    expect(AuditEvent::query()->where('action', AuditAction::AccessConnected->value)->sole()->meta)->toMatchArray(['channel' => 'sftp']);

    $this->volume->forceFill(['removed_at' => now()])->save();
    expect(volumeAccessBeat($this, $task)['cancel'])->toBeTrue();
});

test('a malformed owner is refused at the heartbeat', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $this->actingAs($owner)->postJson(volumeAccessUrl($this->instance), ['allowFrom' => ['198.51.100.7'], 'publicKeys' => [VOLUME_ACCESS_KEY], 'volume' => $this->volume->public_id])->assertCreated();
    $task = volumeAccessLease($this);

    $this->daemon->send($this, 'POST', '/daemon/v1/tasks/'.$task['id'].'/heartbeat', [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => $task['attempt'],
        'access' => ['port' => 40150, 'activeConnections' => 0, 'owner' => ['uid' => -1, 'gid' => 0]],
    ])->assertUnprocessable();
});
