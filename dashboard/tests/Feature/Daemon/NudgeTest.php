<?php

declare(strict_types=1);

use App\Actions\Daemons\RequeueExpiredLeases;
use App\Actions\Servers\RenderNodeState;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\EnrollResponse;
use App\Data\Protocol\V1\NudgeConfig;
use App\Data\Protocol\V1\PollIntervals;
use App\Data\Protocol\V1\RegistryInfo;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Daemon\DaemonNudger;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;
use Tests\Support\SpyBroadcaster;

beforeEach(function (): void {
    $this->spy = SpyBroadcaster::install();
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create();
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

function nudgeAuthBody(string $channel, string $socket = '1234.5678'): array
{
    return ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'BroadcastAuthRequest', 'socketId' => $socket, 'channel' => $channel];
}

test('the config endpoint tells daemons where nudges come from', function (): void {
    config(['launchroom.daemon.nudge_url' => 'ws://reverb:6001/']);

    $response = $this->daemon->send($this, 'GET', '/daemon/v1/config')->assertOk();

    expect($response->json())->toBe(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'DaemonConfig', 'nudge' => ['key' => 'test-key', 'url' => 'ws://reverb:6001']])
        ->and($response->headers->get('X-LR-Signature'))->not->toBeNull()
        ->and(ProtocolSchemas::errors('daemon-config', (string) $response->getContent()))->toBe([]);

    // Without a URL the daemon derives it from its control-plane URL.
    config(['launchroom.daemon.nudge_url' => null]);
    expect($this->daemon->send($this, 'GET', '/daemon/v1/config')->json('nudge'))->toBe(['key' => 'test-key']);
});

test('nudges are off without a Pusher-protocol broadcaster, when disabled and for unapproved servers', function (): void {
    config(['launchroom.daemon.nudges' => false]);
    expect($this->daemon->send($this, 'GET', '/daemon/v1/config')->assertOk()->json())->toBe(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'DaemonConfig']);

    config(['launchroom.daemon.nudges' => true, 'broadcasting.default' => 'null']);
    expect($this->daemon->send($this, 'GET', '/daemon/v1/config')->json('nudge'))->toBeNull();

    config(['broadcasting.default' => 'reverb']);
    $this->server->forceFill(['status' => ServerStatus::PendingApproval])->save();
    expect($this->daemon->send($this, 'GET', '/daemon/v1/config')->assertOk()->json('nudge'))->toBeNull();
});

test('a daemon gets a channel authorization for its own channel only', function (): void {
    $channel = 'private-daemon.'.$this->server->public_id;

    $response = $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', nudgeAuthBody($channel))->assertOk();

    expect($response->json('auth'))->toBe('test-key:'.hash_hmac('sha256', '1234.5678:'.$channel, 'test-secret'))
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(ProtocolSchemas::errors('broadcast-auth', (string) $response->getContent()))->toBe([]);

    $other = Server::factory()->online()->create(['team_id' => $this->server->team_id]);
    $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', nudgeAuthBody('private-daemon.'.$other->public_id))->assertForbidden();
    $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', nudgeAuthBody('private-environments.env_01J9Z8X7W6V5T4S3R2Q1P0N9MK'))->assertUnprocessable();
    $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', nudgeAuthBody($channel, '1234.5678:other'))->assertUnprocessable();

    config(['launchroom.daemon.nudges' => false]);
    $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', nudgeAuthBody($channel))->assertNotFound();

    config(['launchroom.daemon.nudges' => true]);
    $this->server->forceFill(['status' => ServerStatus::PendingApproval])->save();
    $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', nudgeAuthBody($channel))->assertForbidden();
});

test('a new NodeState version nudges its daemon after commit; an unchanged one does not', function (): void {
    $render = resolve(RenderNodeState::class);

    DB::transaction(function () use ($render): void {
        $render->handle($this->server);
        expect($this->spy->sent)->toBe([]); // not before the commit
    });

    expect($this->spy->pairs())->toBe(['state.changed private-daemon.'.$this->server->public_id]);

    $render->handle($this->server->refresh());
    expect($this->spy->sent)->toHaveCount(1);
});

test('queued tasks nudge the servers that may lease them', function (): void {
    $team = $this->server->team_id;
    $this->server->forceFill(['roles' => [ServerRole::Edge, ServerRole::Runtime]])->save();
    $builder = Server::factory()->online()->create(['team_id' => $team, 'roles' => [ServerRole::Build]]);
    Server::factory()->create(['team_id' => $team, 'roles' => [ServerRole::Build], 'status' => ServerStatus::PendingApproval]);
    Server::factory()->online()->create(['roles' => [ServerRole::Build]]); // another team

    // Pinned (run_once on this server).
    $pinned = DaemonTask::query()->create(['team_id' => $team, 'type' => DaemonTaskType::RunOnce, 'spec' => (object) [], 'server_id' => $this->server->id, 'pinned_to_server' => true]);
    expect($this->spy->pairs())->toBe(['task.available private-daemon.'.$this->server->public_id]);

    // Unpinned build: only the team's approved build servers.
    $this->spy->sent = [];
    DaemonTask::query()->create(['team_id' => $team, 'type' => DaemonTaskType::Build, 'spec' => (object) [], 'required_role' => ServerRole::Build]);
    expect($this->spy->pairs())->toBe(['task.available private-daemon.'.$builder->public_id]);

    // Logs requests use their own event.
    $this->spy->sent = [];
    DaemonTask::query()->create(['team_id' => $team, 'type' => DaemonTaskType::Logs, 'spec' => (object) [], 'server_id' => $this->server->id, 'pinned_to_server' => true]);
    expect($this->spy->pairs())->toBe(['logs.requested private-daemon.'.$this->server->public_id]);

    // A re-queued expired lease nudges again.
    $this->spy->sent = [];
    $pinned->forceFill(['attempt' => 1, 'max_attempts' => 3, 'lease_expires_at' => now()->subMinute()]);
    $pinned->transitionTo(DaemonTaskStatus::Leased);
    resolve(RequeueExpiredLeases::class)->handle();
    expect($this->spy->pairs())->toBe(['task.available private-daemon.'.$this->server->public_id]);
});

test('a failing broadcaster never breaks the caller', function (): void {
    $this->spy->fail = true;

    $version = resolve(RenderNodeState::class)->handle($this->server);

    expect($version->version->version)->toBe(1);
});

test('enroll responses carry the nudge config and stay schema-valid', function (): void {
    $config = resolve(DaemonNudger::class)->config();
    $enroll = new EnrollResponse('srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 'active', ['cp-1' => 'ed25519:Kay64UG8yvCyLhqU000LxzYeUm0L/hLIl5S8kyKWbdc='], new RegistryInfo('127.0.0.1:5000'), new PollIntervals('30s', '10s'), $config);

    expect($config)->toEqual(new NudgeConfig('test-key'))
        ->and(ProtocolSchemas::errors('enroll-response', ProtocolJson::encode($enroll)))->toBe([])
        ->and(json_decode(ProtocolJson::encode($enroll), true)['nudge'])->toBe(['key' => 'test-key']);
});
