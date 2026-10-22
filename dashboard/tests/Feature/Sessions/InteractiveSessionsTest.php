<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\Permission;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Membership;
use App\Models\Server;
use App\Models\Sessions\DatabaseQuery;
use App\Models\Sessions\InteractiveSession;
use App\Models\Team;
use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\Signer;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\FakeDaemon;
use Tests\Support\Sessions;

// Interactive sessions: Console and Data tabs (docs/03 §11, docs/09 R33).

beforeEach(function (): void {
    $world = Sessions::world();
    $this->team = $world['team'];
    $this->server = $world['server'];
    $this->keypair = $world['keypair'];
    $this->instance = $world['instance'];
    $this->daemon = $world['daemon'];
    $this->spy = $world['spy'];
});

function sessionComplete($test, array $task, array $session, string $outcome = 'succeeded'): void
{
    $response = $test->daemon->send($test, 'POST', '/daemon/v1/tasks/'.$task['id'].'/complete', [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => $task['attempt'], 'outcome' => $outcome, 'session' => $session,
    ], ['Idempotency-Key' => $task['id'].':'.$task['attempt']]);
    $response->assertSuccessful();
}

test('the permission matrix: owners and admins open sessions, developers only when granted, viewers never', function (string $role, bool $console, bool $data): void {
    $user = memberOf($this->team, TeamRole::from($role));

    $this->actingAs($user)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'console'))
        ->assertStatus($console ? 201 : 403);
    $this->actingAs($user)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'data'))
        ->assertStatus($data ? 201 : 403);
    $this->actingAs($user)->getJson(Sessions::url($this->instance, 'console'))->assertStatus($console ? 200 : 403);
})->with([
    'owner' => ['owner', true, true],
    'admin' => ['admin', true, true],
    'developer' => ['developer', false, false],
    'viewer' => ['viewer', false, false],
]);

test('roles map to the new permissions and only developers accept grants', function (): void {
    expect(TeamRole::Admin->can(Permission::ExecContainers))->toBeTrue()
        ->and(TeamRole::Admin->can(Permission::QueryDatabases))->toBeTrue()
        ->and(TeamRole::Developer->can(Permission::ExecContainers))->toBeFalse()
        ->and(TeamRole::Developer->can(Permission::Deploy))->toBeTrue()
        ->and(TeamRole::Developer->acceptsGrant(Permission::ExecContainers))->toBeTrue()
        ->and(TeamRole::Viewer->acceptsGrant(Permission::ExecContainers))->toBeFalse()
        ->and(TeamRole::Developer->acceptsGrant(Permission::Deploy))->toBeFalse();
});

test('other teams’ services and sessions are not found', function (): void {
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'console'))->assertNotFound();

    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance);

    $this->actingAs($outsider)->postJson('/sessions/'.$opened['id'].'/end')->assertNotFound();
    $this->actingAs($outsider)->postJson('/sessions/'.$opened['id'].'/authorize', ['socket_id' => '1.2', 'ticket' => $opened['ticket']])->assertNotFound();
});

test('opening an authorized session creates a ticket and pinned task without an extra password', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);

    $response = $this->actingAs($owner)->postJson(Sessions::url($this->instance, 'console'), ['shell' => '/bin/sh', 'cols' => 120, 'rows' => 40])
        ->assertCreated();

    $session = InteractiveSession::query()->where('public_id', $response->json('id'))->sole();
    $task = DaemonTask::query()->findOrFail($session->daemon_task_id);

    expect($response->json('ticket'))->toMatch('/^lr_st_[A-Za-z0-9_-]{43}$/')
        ->and($response->json('key'))->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($response->json('channel'))->toBe('session.'.$session->public_id)
        ->and($session->ticket_hash)->toBe(hash('sha256', (string) $response->json('ticket')))
        ->and($session->session_key)->toBe($response->json('key'))
        ->and($session->getRawOriginal('session_key'))->not->toContain((string) $response->json('key'))
        ->and($session->toArray())->not->toHaveKeys(['ticket_hash', 'session_key'])
        ->and($session->status)->toBe(InteractiveSessionStatus::Pending)
        ->and($task->pinned_to_server)->toBeTrue()
        ->and($task->server_id)->toBe($this->server->id)
        ->and($task->max_attempts)->toBe(1)
        ->and((array) $task->spec)->toMatchArray([
            'session' => $session->public_id, 'kind' => 'console', 'channel' => 'private-session.'.$session->public_id,
            'shell' => '/bin/sh', 'cols' => 120, 'rows' => 40, 'idleTimeout' => '15m',
        ])
        ->and((array) $task->spec)->not->toHaveKey('key');

    $audit = AuditEvent::query()->where('action', AuditAction::ConsoleSessionStarted->value)->sole();
    expect($audit->meta)->toMatchArray(['session' => $session->public_id, 'replica' => 1, 'shell' => '/bin/sh'])
        ->and(json_encode($audit->meta))->not->toContain((string) $response->json('key'))
        ->and(json_encode($audit->meta))->not->toContain((string) $response->json('ticket'));
});

test('an old password confirmation does not interrupt an authorized session', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time() - 3600])
        ->postJson(Sessions::url($this->instance, 'console'))
        ->assertCreated();
});

test('the lease seals the key to the server and task, opens the session and wipes the stored key', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance, 'data');
    $task = Sessions::lease($this, $this->daemon);

    expect($task['type'])->toBe('session')
        ->and($task['spec']['engine'])->toBe('postgres')
        ->and($task['spec'])->toHaveKey('key');

    $token = json_decode((string) sodium_crypto_box_seal_open((string) base64_decode($task['spec']['key']['sealed'], true), $this->keypair), true);
    expect($token)->toBe(['serverId' => $this->server->public_id, 'taskId' => $task['id'], 'purpose' => 'session', 'token' => $opened['key']]);

    $session = InteractiveSession::query()->where('public_id', $opened['id'])->sole();
    expect($session->status)->toBe(InteractiveSessionStatus::Open)
        ->and($session->session_key)->toBeNull();
});

test('tickets are single use, user bound and expire after a minute', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $admin = memberOf($this->team, TeamRole::Admin);
    $opened = Sessions::open($this, $owner, $this->instance);
    $url = '/sessions/'.$opened['id'].'/authorize';

    $this->actingAs($admin)->postJson($url, ['socket_id' => '1234.5678', 'ticket' => $opened['ticket']])->assertForbidden();
    $this->actingAs($owner)->postJson($url, ['socket_id' => '1234.5678', 'ticket' => 'lr_st_'.str_repeat('A', 43)])->assertForbidden();

    $auth = $this->actingAs($owner)->postJson($url, ['socket_id' => '1234.5678', 'ticket' => $opened['ticket']])->assertOk()->json('auth');
    expect($auth)->toBe('test-key:'.hash_hmac('sha256', '1234.5678:private-session.'.$opened['id'], 'test-secret'));

    $this->actingAs($owner)->postJson($url, ['socket_id' => '1234.5678', 'ticket' => $opened['ticket']])->assertForbidden();

    $later = Sessions::open($this, $owner, $this->instance);
    $this->travel(61)->seconds();
    $this->actingAs($owner)->postJson('/sessions/'.$later['id'].'/authorize', ['socket_id' => '1.2', 'ticket' => $later['ticket']])->assertForbidden();
});

test('browsers can never join session channels through the normal broadcasting auth', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance);
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => ['driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test', 'options' => ['host' => 'reverb', 'port' => 6001, 'scheme' => 'http']]]);
    Broadcast::forgetDrivers();

    $this->actingAs($owner)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-session.'.$opened['id']])->assertForbidden();
});

test('only the server holding the session task joins its channel', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance);
    $body = ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'BroadcastAuthRequest', 'socketId' => '9.9', 'channel' => 'private-session.'.$opened['id']];

    // Not leased yet.
    $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', $body)->assertForbidden();

    Sessions::lease($this, $this->daemon);
    $this->daemon->send($this, 'POST', '/daemon/v1/broadcasting/auth', $body)->assertOk()
        ->assertJson(['auth' => 'test-key:'.hash_hmac('sha256', '9.9:private-session.'.$opened['id'], 'test-secret')]);

    $seed = random_bytes(32);
    $other = Server::factory()->withSigningSeed($seed)->online()->create(['team_id' => $this->team->id]);
    new FakeDaemon($seed)->as($other->public_id)->send($this, 'POST', '/daemon/v1/broadcasting/auth', $body)->assertForbidden();
});

test('heartbeats re-authorize: losing the permission or the instance switch cancels the session', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);
    $opened = Sessions::open($this, $admin, $this->instance);
    $task = Sessions::lease($this, $this->daemon);
    $beat = fn () => $this->daemon->send($this, 'POST', '/daemon/v1/tasks/'.$task['id'].'/heartbeat', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => 1])->assertOk();

    expect($beat()->json('cancel'))->toBeFalse();

    Membership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->update(['role' => 'developer']);
    expect($beat()->json('cancel'))->toBeTrue();

    $owner = memberOf($this->team, TeamRole::Owner);
    Sessions::open($this, $owner, $this->instance);
    $second = Sessions::lease($this, $this->daemon);
    config(['launchroom.sessions.enabled' => false]);
    expect($this->daemon->send($this, 'POST', '/daemon/v1/tasks/'.$second['id'].'/heartbeat', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskHeartbeat', 'attempt' => 1])->json('cancel'))->toBeTrue();
});

test('statements are audited without their text, kept encrypted and relayed as signed commands', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance, 'data');
    $url = '/sessions/'.$opened['id'].'/commands';
    $statement = "UPDATE users SET api_token = 'tok-very-secret-123' WHERE id = 1";

    // Still connecting until the server leased it.
    $this->actingAs($owner)->postJson($url, ['command' => 'query', 'statement' => 'SELECT 1'])->assertStatus(409);

    $task = Sessions::lease($this, $this->daemon);
    $this->spy->sent = [];
    $op = $this->actingAs($owner)->postJson($url, ['command' => 'query', 'statement' => $statement, 'allowWrites' => true])->assertStatus(202)->json('op');

    $query = DatabaseQuery::query()->where('op_id', $op)->sole();
    $audit = AuditEvent::query()->where('action', AuditAction::DatabaseStatementExecuted->value)->sole();
    expect($query->getRawOriginal('statement'))->not->toContain('tok-very-secret')
        ->and($query->verb)->toBe('UPDATE')
        ->and($query->allow_writes)->toBeTrue()
        ->and($audit->meta)->toMatchArray(['op' => $op, 'verb' => 'UPDATE', 'writes' => true, 'sha256' => hash('sha256', $statement), 'bytes' => strlen($statement)])
        ->and(json_encode($audit->meta))->not->toContain('tok-very-secret');

    $sent = collect($this->spy->sent)->firstWhere('event', 'session.command');
    $signer = resolve(Signer::class);
    expect($sent['channels'])->toBe(['private-session.'.$opened['id']])
        ->and(ResponseSignature::verify($sent['payload']['signature'], $sent['payload']['payload'], [$signer->kid => $signer->publicKey()]))->toBeTrue();
    $command = json_decode($sent['payload']['payload'], true);
    expect($command)->toMatchArray(['kind' => 'SessionCommand', 'session' => $opened['id'], 'op' => $op, 'command' => 'query', 'statement' => $statement, 'allowWrites' => true]);

    // History is the author's own and decrypts.
    $history = $this->actingAs($owner)->getJson(Sessions::url($this->instance, 'data'))->assertOk()->json('history');
    expect($history[0]['statement'])->toBe($statement)->and($history[0]['status'])->toBe('sent');

    // The outcome arrives with the completion.
    sessionComplete($this, $task, ['endReason' => 'closed', 'bytesIn' => 10, 'bytesOut' => 20, 'commands' => [['op' => $op, 'status' => 'ok', 'rows' => 1, 'durationMs' => 7]]]);
    expect($query->refresh()->status->value)->toBe('ok')->and($query->rows)->toBe(1);
});

test('only the opener sends commands, only on data sessions, with valid identifiers', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $admin = memberOf($this->team, TeamRole::Admin);
    $data = Sessions::open($this, $owner, $this->instance, 'data');
    Sessions::lease($this, $this->daemon);

    $this->actingAs($admin)->postJson('/sessions/'.$data['id'].'/commands', ['command' => 'schema'])->assertForbidden();
    $this->actingAs($owner)->postJson('/sessions/'.$data['id'].'/commands', ['command' => 'rows', 'table' => 'us"ers'])->assertUnprocessable();
    $this->actingAs($owner)->postJson('/sessions/'.$data['id'].'/commands', ['command' => 'keys', 'cursor' => '1; FLUSHALL'])->assertUnprocessable();
    $this->actingAs($owner)->postJson('/sessions/'.$data['id'].'/commands', ['command' => 'query', 'statement' => str_repeat('x', 16385)])->assertUnprocessable();
    $this->actingAs($owner)->postJson('/sessions/'.$data['id'].'/commands', ['command' => 'rows', 'schema' => 'public', 'table' => 'users', 'page' => 2, 'pageSize' => 50])->assertStatus(202);

    $console = Sessions::open($this, $owner, $this->instance, 'console');
    Sessions::lease($this, $this->daemon);
    $this->actingAs($owner)->postJson('/sessions/'.$console['id'].'/commands', ['command' => 'schema'])->assertForbidden();
});

test('the completion ends the session with an audit entry of duration and reason', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance);
    $task = Sessions::lease($this, $this->daemon);
    $this->travel(90)->seconds();

    sessionComplete($this, $task, ['startedAt' => now()->subSeconds(80)->utc()->format('Y-m-d\TH:i:s\Z'), 'endReason' => 'exited', 'exitCode' => 0, 'bytesIn' => 12, 'bytesOut' => 3400]);

    $session = InteractiveSession::query()->where('public_id', $opened['id'])->sole();
    $audit = AuditEvent::query()->where('action', AuditAction::ConsoleSessionEnded->value)->sole();
    expect($session->status)->toBe(InteractiveSessionStatus::Ended)
        ->and($session->end_reason?->value)->toBe('exited')
        ->and($session->exit_code)->toBe(0)
        ->and($session->bytes_out)->toBe(3400)
        ->and($audit->actor_type->value)->toBe('server')
        ->and($audit->meta)->toMatchArray(['session' => $opened['id'], 'reason' => 'exited', 'exitCode' => 0, 'bytesOut' => 3400])
        ->and($audit->meta['seconds'])->toBeGreaterThanOrEqual(79);
});

test('a session is never handed to another launchroomd process', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance);
    $task = Sessions::lease($this, $this->daemon);

    $this->daemon->send($this, 'POST', '/daemon/v1/tasks/'.$task['id'].'/complete', [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'TaskCompletion', 'attempt' => 1, 'outcome' => 'released',
    ], ['Idempotency-Key' => $task['id'].':1'])->assertSuccessful();

    expect(DaemonTask::query()->where('public_id', $task['id'])->sole()->status)->toBe(DaemonTaskStatus::Failed)
        ->and(InteractiveSession::query()->where('public_id', $opened['id'])->sole()->status)->toBe(InteractiveSessionStatus::Ended);
});

test('ending: the opener and owners/admins can, other developers can’t', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);
    $developer = memberOf($this->team, TeamRole::Developer);
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $admin, $this->instance);
    $task = Sessions::lease($this, $this->daemon);

    $this->actingAs($developer)->postJson('/sessions/'.$opened['id'].'/end')->assertForbidden();

    $this->spy->sent = [];
    $this->actingAs($owner)->postJson('/sessions/'.$opened['id'].'/end')->assertNoContent();

    expect(DaemonTask::query()->where('public_id', $task['id'])->sole()->cancel_requested_at)->not->toBeNull()
        ->and(collect($this->spy->sent)->pluck('event')->all())->toContain('session.end');

    // A pending session ends at once (its task never reached a server).
    $pending = Sessions::open($this, $admin, $this->instance);
    $this->actingAs($admin)->postJson('/sessions/'.$pending['id'].'/end')->assertNoContent();
    $row = InteractiveSession::query()->where('public_id', $pending['id'])->sole();
    expect($row->status)->toBe(InteractiveSessionStatus::Ended)->and($row->end_reason?->value)->toBe('closed');
});

test('at most three open sessions per user', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);

    foreach (range(1, 3) as $ignored) {
        Sessions::open($this, $owner, $this->instance);
    }

    $this->actingAs($owner)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'console'))->assertStatus(409);
});

test('the instance kill switch refuses new sessions', function (): void {
    config(['launchroom.sessions.enabled' => false]);
    $owner = memberOf($this->team, TeamRole::Owner);

    $this->actingAs($owner)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'console'))->assertStatus(409);
    expect($this->actingAs($owner)->getJson(Sessions::url($this->instance, 'console'))->json('available'))->toBeFalse();
});

test('granting a developer Console access lets them open one; revoking ends their sessions', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);
    $developer = memberOf($this->team, TeamRole::Developer);
    $viewer = memberOf($this->team, TeamRole::Viewer);
    $membership = Membership::query()->where('team_id', $this->team->id)->where('user_id', $developer->id)->sole();
    $viewerMembership = Membership::query()->where('team_id', $this->team->id)->where('user_id', $viewer->id)->sole();

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put('/settings/team/members/'.$membership->public_id.'/permissions', ['permissions' => ['containers.exec']])
        ->assertRedirect();
    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put('/settings/team/members/'.$viewerMembership->public_id.'/permissions', ['permissions' => ['containers.exec']])
        ->assertForbidden();
    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put('/settings/team/members/'.$membership->public_id.'/permissions', ['permissions' => ['deployments.create']])
        ->assertSessionHasErrors('permissions.0');

    $meta = AuditEvent::query()->where('action', AuditAction::MemberPermissionsChanged->value)->sole()->meta;
    expect($meta)->toEqual(['from' => [], 'to' => ['containers.exec']]);

    $developer->forgetTeamRoles();
    $opened = Sessions::open($this, $developer, $this->instance);
    $this->actingAs($developer)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'data'))->assertForbidden();

    $this->actingAs($admin)->withSession(passwordConfirmed())
        ->put('/settings/team/members/'.$membership->public_id.'/permissions', ['permissions' => []])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(InteractiveSession::query()->where('public_id', $opened['id'])->sole()->status)->toBe(InteractiveSessionStatus::Ended);
});

test('a stale grant counts for nothing after a promotion to viewer', function (): void {
    $developer = memberOf($this->team, TeamRole::Developer);
    Membership::query()->where('team_id', $this->team->id)->where('user_id', $developer->id)->update(['permissions' => json_encode(['containers.exec']), 'role' => 'viewer']);
    $developer->forgetTeamRoles();

    expect($developer->hasTeamPermission($this->team, Permission::ExecContainers))->toBeFalse();
});

test('removing a member ends their sessions', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $admin = memberOf($this->team, TeamRole::Admin);
    $opened = Sessions::open($this, $admin, $this->instance);
    $membership = Membership::query()->where('team_id', $this->team->id)->where('user_id', $admin->id)->sole();

    $this->actingAs($owner)->delete('/settings/team/members/'.$membership->public_id)->assertRedirect();

    expect(InteractiveSession::query()->where('public_id', $opened['id'])->sole()->status)->toBe(InteractiveSessionStatus::Ended);
});

test('sessions no server picks up are ended by the sweep', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $opened = Sessions::open($this, $owner, $this->instance);
    $this->travel(3)->minutes();

    $this->artisan('launchroom:sessions:sweep')->assertSuccessful();

    expect(InteractiveSession::query()->where('public_id', $opened['id'])->sole()->status)->toBe(InteractiveSessionStatus::Ended);
});

test('console recording is an owner switch and is carried into new sessions', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $admin = memberOf($this->team, TeamRole::Admin);

    $this->actingAs($admin)->withSession(passwordConfirmed())->put('/settings/team/console-recording', ['enabled' => true])->assertForbidden();
    $this->actingAs($owner)->withSession(passwordConfirmed())->put('/settings/team/console-recording', ['enabled' => true])->assertRedirect();

    $opened = Sessions::open($this, $owner, $this->instance);
    $task = DaemonTask::query()->findOrFail(InteractiveSession::query()->where('public_id', $opened['id'])->sole()->daemon_task_id);

    expect($opened['record'])->toBeTrue()
        ->and($task->spec->record ?? null)->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::ConsoleRecordingChanged->value)->exists())->toBeTrue();

    // Reading it is authorized and audited.
    $leased = Sessions::lease($this, $this->daemon);
    sessionComplete($this, $leased, ['endReason' => 'closed', 'bytesIn' => 1, 'bytesOut' => 1]);
    $this->flushSession();
    $this->actingAs($owner)->postJson('/sessions/'.$opened['id'].'/recording')->assertOk()->assertJson(['lines' => []]);
    expect(AuditEvent::query()->where('action', AuditAction::SessionRecordingViewed->value)->count())->toBe(1);
});

test('the tabs are only in the panel for members with the permission', function (): void {
    [$project, $environment, $service] = Sessions::route($this->instance);
    $developer = memberOf($this->team, TeamRole::Developer);
    $owner = memberOf($this->team, TeamRole::Owner);

    $this->actingAs($developer)->get("/p/{$project}/{$environment}/s/{$service}/console")->assertNotFound();
    $this->actingAs($developer)->get("/p/{$project}/{$environment}/s/{$service}/deployments")
        ->assertInertia(fn ($page) => $page->where('panel.canConsole', false)->where('panel.canData', false));
    $this->actingAs($owner)->get("/p/{$project}/{$environment}/s/{$service}/data")
        ->assertInertia(fn ($page) => $page->where('panel.tab', 'data')->where('panel.canConsole', true)->where('panel.canData', true));
});

test('servers whose local policy doesn’t allow a kind are not offered', function (): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $this->server->forceFill(['daemon_features' => ['dataSessions']])->save();

    expect($this->actingAs($owner)->getJson(Sessions::url($this->instance, 'console'))->json('available'))->toBeFalse();
    $this->actingAs($owner)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'console'))->assertUnprocessable();
    $this->actingAs($owner)->withSession(passwordConfirmed())->postJson(Sessions::url($this->instance, 'data'))->assertCreated();
});
