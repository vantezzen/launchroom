<?php

declare(strict_types=1);

use App\Actions\Servers\MarkServerSeen;
use App\Enums\AuditAction;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\NodeStateVersion;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
});

test('the servers list shows the current team’s servers only', function (): void {
    Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'mine']);
    Server::factory()->online()->create(['name' => 'theirs']);

    $this->actingAs($this->viewer)->get(route('servers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/index')
            ->has('servers', 1)
            ->where('servers.0.name', 'mine')
            ->where('servers.0.status', 'online')
            ->where('canAddServer', false));
});

test('server detail exposes a key fingerprint, never the key', function (): void {
    $server = Server::factory()->online()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->viewer)->get(route('servers.show', $server->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->where('server.id', $server->public_id)
            ->where('server.signingKeyFingerprint', fn (string $fp): bool => str_starts_with($fp, 'SHA256:'))
            ->missing('server.signingPublicKey')
            ->where('server.canRevoke', false));

    expect($server->toArray())->not->toHaveKeys(['signing_public_key', 'sealing_public_key']);
});

test('servers of other teams are not found', function (): void {
    $foreign = Server::factory()->online()->create();

    $this->actingAs($this->admin)->get(route('servers.show', $foreign->public_id))->assertNotFound();
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->post(route('servers.revoke', $foreign->public_id))->assertNotFound();
    $this->actingAs($this->admin)->get('/servers/srv_not-a-valid-id')->assertNotFound();
});

test('adding a server issues a join token shown once, with the install command inputs', function (): void {
    $this->actingAs($this->admin)->get(route('servers.create'))->assertOk();

    $response = $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('servers.enrollments.store'), ['roles' => ['runtime', 'build'], 'name' => 'Home box']);

    $enrollment = ServerEnrollment::query()->sole();
    $response->assertRedirect(route('servers.enrollments.show', $enrollment->public_id));

    $this->get(route('servers.enrollments.show', $enrollment->public_id))
        ->assertInertia(fn ($page) => $page->component('servers/enrollment')
            ->where('joinToken', fn (string $token): bool => hash('sha256', $token) === $enrollment->token_hash)
            ->where('controlPlaneKey', fn (string $key): bool => str_starts_with($key, 'ed25519:'))
            ->where('enrollment.roles', ['runtime', 'build'])
            ->where('enrollment.server', null));

    // The token is gone on the next view (only its hash is stored).
    $this->get(route('servers.enrollments.show', $enrollment->public_id))
        ->assertInertia(fn ($page) => $page->where('joinToken', null));

    expect(AuditEvent::query()->where('action', AuditAction::ServerEnrollmentCreated->value)->count())->toBe(1);
});

test('adding a server needs a confirmed password and the servers permission', function (): void {
    $this->actingAs($this->admin)->post(route('servers.enrollments.store'), ['roles' => ['runtime']])
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->viewer)->withSession(passwordConfirmed())
        ->post(route('servers.enrollments.store'), ['roles' => ['runtime']])->assertForbidden();
    $this->actingAs($this->viewer)->get(route('servers.create'))->assertForbidden();

    expect(ServerEnrollment::query()->count())->toBe(0);
});

test('roles are validated', function (): void {
    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('servers.enrollments.store'), ['roles' => ['root']])->assertSessionHasErrors('roles.0');
    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('servers.enrollments.store'), ['roles' => []])->assertSessionHasErrors('roles');
});

test('enrollments of other teams are not found', function (): void {
    $other = Team::factory()->create();
    $enrollment = ServerEnrollment::query()->create(['team_id' => $other->id, 'token_hash' => str_repeat('a', 64), 'roles' => [], 'expires_at' => now()->addHour()]);

    $this->actingAs($this->admin)->get(route('servers.enrollments.show', $enrollment->public_id))->assertNotFound();
});

test('admins approve pending servers, which renders their first desired state', function (): void {
    $server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->admin)->withSession(passwordConfirmed())->post(route('servers.approve', $server->public_id))->assertRedirect();

    expect($server->refresh()->status)->toBe(ServerStatus::Online)
        ->and($server->approved_by)->toBe($this->admin->id)
        ->and(NodeStateVersion::query()->where('server_id', $server->id)->count())->toBe(1);

    // Approving twice is not allowed (policy: only pending servers).
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->post(route('servers.approve', $server->public_id))->assertForbidden();
});

test('admins revoke servers; viewers cannot', function (): void {
    $server = Server::factory()->online()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->viewer)->withSession(passwordConfirmed())->post(route('servers.revoke', $server->public_id))->assertForbidden();
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->post(route('servers.revoke', $server->public_id))->assertRedirect();

    expect($server->refresh()->status)->toBe(ServerStatus::Revoked)
        ->and($server->revoked_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::ServerRevoked->value)->count())->toBe(1);
});

test('servers that stop polling become unreachable, then offline, and recover', function (): void {
    $server = Server::factory()->online()->create(['team_id' => $this->team->id]);

    $this->travel(80)->seconds();
    $this->artisan('launchroom:servers:check-liveness');
    expect($server->refresh()->status)->toBe(ServerStatus::Unreachable);

    $this->travel(301)->seconds();
    $this->artisan('launchroom:servers:check-liveness');
    expect($server->refresh()->status)->toBe(ServerStatus::Offline);

    resolve(MarkServerSeen::class)->handle($server);
    expect($server->refresh()->status)->toBe(ServerStatus::Online);
});

test('the servers grid lists waiting enrolments, replaceable servers and the realtime channel', function (): void {
    $dead = Server::factory()->create(['team_id' => $this->team->id, 'status' => ServerStatus::Unreachable, 'last_seen_at' => now()->subMinutes(8)]);
    $connected = Server::factory()->online()->create(['team_id' => $this->team->id]);
    ServerEnrollment::query()->create(['team_id' => $this->team->id, 'token_hash' => str_repeat('a', 64), 'roles' => ['build'], 'expires_at' => now()->addHour()]);
    ServerEnrollment::query()->create(['team_id' => $this->team->id, 'token_hash' => str_repeat('b', 64), 'roles' => ['runtime'], 'expires_at' => now()->subMinute()]);
    ServerEnrollment::query()->create(['team_id' => $this->team->id, 'token_hash' => str_repeat('c', 64), 'roles' => ['runtime'], 'expires_at' => now()->addHour()])
        ->forceFill(['used_at' => now(), 'server_id' => $connected->id])->save();

    $this->actingAs($this->admin)->get(route('servers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/index')
            ->where('teamId', $this->team->public_id)
            ->has('pendingEnrollments', 1)
            ->where('pendingEnrollments.0.roles', ['build'])
            ->where('replaceable', [$dead->public_id]));

    // Viewers can't add servers, so they don't see install links waiting either.
    $this->actingAs($this->viewer)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->has('pendingEnrollments', 0)->where('replaceable', []));
});

test('the add-server wizard carries the machine kind to the waiting step', function (): void {
    $this->actingAs($this->admin)->get(route('servers.create'))
        ->assertInertia(fn ($page) => $page->component('servers/create')->where('serverCount', 0));

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('servers.enrollments.store'), ['roles' => ['build'], 'machine' => 'home'])
        ->assertRedirect(route('servers.enrollments.show', ['enrollment' => ServerEnrollment::query()->sole()->public_id, 'machine' => 'home']));

    $this->actingAs($this->admin)->withSession(passwordConfirmed())
        ->post(route('servers.enrollments.store'), ['roles' => ['build'], 'machine' => 'toaster'])
        ->assertSessionHasErrors('machine');

    $enrollment = ServerEnrollment::query()->sole();
    $this->actingAs($this->admin)->get(route('servers.enrollments.show', ['enrollment' => $enrollment->public_id, 'machine' => '<script>']))
        ->assertInertia(fn ($page) => $page->where('machine', null)->where('canApprove', false));
});

test('edge servers show how public traffic reaches them', function (): void {
    $edge = Server::factory()->online()->create(['team_id' => $this->team->id, 'roles' => ['edge', 'runtime']]);
    $worker = Server::factory()->online()->create(['team_id' => $this->team->id, 'roles' => ['runtime']]);

    $this->actingAs($this->viewer)->get(route('servers.show', $edge->public_id))
        ->assertInertia(fn ($page) => $page->where('edge.id', $edge->public_id)->where('teamId', $this->team->public_id));
    $this->actingAs($this->viewer)->get(route('servers.show', $worker->public_id))
        ->assertInertia(fn ($page) => $page->where('edge', null));
});

test('a server page has deep-linkable tabs that load only their own props', function (): void {
    $server = Server::factory()->for($this->team)->online()->create();

    $this->actingAs($this->viewer)->get(route('servers.show', $server->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->where('tab', 'overview')
            ->has('health')
            ->has('placement')
            ->has('roleHints')
            ->has('retiredVolumes')
            ->missing('meshPeers')
            ->missing('canUpdateRoles')
            ->missing('measureLinks')
            ->loadDeferredProps(fn ($reload) => $reload->has('meshPeers')));

    $this->actingAs($this->viewer)->get(route('servers.tab', [$server->public_id, 'metrics']))
        ->assertInertia(fn ($page) => $page->where('tab', 'metrics')
            ->where('server.id', $server->public_id)
            ->has('teamId')
            ->missing('health')
            ->missing('placement')
            ->missing('meshPeers'));

    $this->actingAs($this->viewer)->get(route('servers.tab', [$server->public_id, 'network']))
        ->assertInertia(fn ($page) => $page->where('tab', 'network')
            ->has('mesh')
            ->has('measureLinks')
            ->missing('placement'));

    $this->actingAs($this->viewer)->get(route('servers.tab', [$server->public_id, 'settings']))
        ->assertInertia(fn ($page) => $page->where('tab', 'settings')
            ->has('placement')
            ->where('canUpdateRoles', false)
            ->where('canConfirmVolumeDeletion', false)
            ->missing('health')
            ->missing('meshPeers'));

    // Overview is the bare URL; unknown tabs don't exist.
    $this->actingAs($this->viewer)->get('/servers/'.$server->public_id.'/overview')->assertNotFound();
    $this->actingAs($this->viewer)->get('/servers/'.$server->public_id.'/bogus')->assertNotFound();
});

test('tabs of another team’s server are not found', function (): void {
    $foreign = Server::factory()->create();

    $this->actingAs($this->admin)->get(route('servers.tab', [$foreign->public_id, 'settings']))->assertNotFound();
});
