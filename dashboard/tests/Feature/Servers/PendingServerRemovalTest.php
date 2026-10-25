<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Queries\EdgeOverview;
use App\Support\Crypto\OpaqueToken;
use Tests\Support\FakeDaemon;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
});

function waitingEnrollment(Team $team, array $attributes = []): array
{
    $token = OpaqueToken::generate('lr_jt_');
    $enrollment = ServerEnrollment::query()->create([
        'team_id' => $team->id,
        'token_hash' => OpaqueToken::hash($token),
        'roles' => [ServerRole::Runtime],
        'name' => 'home-box',
        'expires_at' => now()->addMinutes(30),
        ...$attributes,
    ]);

    return [$enrollment, $token];
}

test('admins remove a server that never connected: the join token is gone and the removal audited', function (): void {
    [$enrollment, $token] = waitingEnrollment($this->team);

    $this->actingAs($this->admin)->get(route('servers.enrollments.show', $enrollment->public_id))
        ->assertInertia(fn ($page) => $page->where('canCancel', true)->where('enrollment.name', 'home-box'));

    $this->actingAs($this->admin)->delete(route('servers.enrollments.destroy', $enrollment->public_id))
        ->assertRedirect(route('servers.index'));

    expect(ServerEnrollment::query()->whereKey($enrollment->id)->exists())->toBeFalse()
        ->and(ServerEnrollment::query()->where('token_hash', OpaqueToken::hash($token))->exists())->toBeFalse();

    $audit = AuditEvent::query()->where('action', AuditAction::ServerEnrollmentCanceled->value)->sole();
    expect($audit->subject_id)->toBe($enrollment->public_id)
        ->and($audit->team_id)->toBe($this->team->id);

    $this->actingAs($this->admin)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->has('pendingEnrollments', 0));
});

test('the cancelled install command no longer enrolls a server', function (): void {
    [$enrollment, $token] = waitingEnrollment($this->team);
    $this->actingAs($this->admin)->delete(route('servers.enrollments.destroy', $enrollment->public_id))->assertRedirect();

    // Same answer as a token that never existed (fail closed, EnrollmentTest).
    $daemon = new FakeDaemon;
    $daemon->send($this, 'POST', '/daemon/v1/enroll', $daemon->enrollBody($token))->assertForbidden();

    expect(Server::query()->where('team_id', $this->team->id)->count())->toBe(0);
});

test('only members who manage servers remove waiting servers', function (): void {
    [$enrollment] = waitingEnrollment($this->team);

    foreach ([$this->developer, $this->viewer] as $member) {
        $this->actingAs($member)->delete(route('servers.enrollments.destroy', $enrollment->public_id))->assertForbidden();
    }

    expect($enrollment->fresh())->not->toBeNull();
});

test('waiting servers of other teams are not found', function (): void {
    [$foreign] = waitingEnrollment(Team::factory()->create());

    $this->actingAs($this->admin)->delete(route('servers.enrollments.destroy', $foreign->public_id))->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('an enrolment a server already used is not removed here; the server keeps its revoke flow', function (): void {
    $server = Server::factory()->online()->create(['team_id' => $this->team->id]);
    [$used] = waitingEnrollment($this->team);
    $used->forceFill(['used_at' => now(), 'server_id' => $server->id])->save();
    [$controlPlane] = waitingEnrollment($this->team, ['is_control_plane' => true]);

    $this->actingAs($this->admin)->delete(route('servers.enrollments.destroy', $used->public_id))->assertForbidden();
    $this->actingAs($this->admin)->delete(route('servers.enrollments.destroy', $controlPlane->public_id))->assertForbidden();

    expect($used->fresh())->not->toBeNull()
        ->and($server->refresh()->status)->toBe(ServerStatus::Online);

    // Enrolled servers still leave through Revoke and stay listed under "Revoked".
    $this->actingAs($this->admin)->post(route('servers.revoke', $server->public_id))->assertRedirect();
    $this->actingAs($this->admin)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->where('servers.0.status', 'revoked'));
});

test('a connected server still waiting for approval is removed by revoking its key, then leaves the list', function (): void {
    $pending = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'stranger']);
    expect($pending->status)->toBe(ServerStatus::PendingApproval);

    $this->actingAs($this->viewer)->post(route('servers.revoke', $pending->public_id))->assertForbidden();
    $this->actingAs($this->admin)->post(route('servers.revoke', $pending->public_id))
        ->assertRedirect(route('servers.index'));

    expect($pending->refresh()->status)->toBe(ServerStatus::Revoked)
        ->and(AuditEvent::query()->where('action', AuditAction::ServerRevoked->value)->where('subject_id', $pending->public_id)->exists())->toBeTrue();

    $this->actingAs($this->admin)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->has('servers', 0));
});

test('one server is not a single point of failure warning, only a gentle hint', function (): void {
    $only = Server::factory()->online()->create(['team_id' => $this->team->id, 'roles' => [ServerRole::Edge, ServerRole::Runtime]]);

    $overview = resolve(EdgeOverview::class)->for($this->team->id);
    expect($overview->singlePointOfFailure)->toBeFalse()
        ->and($overview->singleServer)->toBeTrue()
        ->and($overview->servers)->toBe(1);

    // A second server that could take traffic too: now one edge is a real risk.
    Server::factory()->online()->create(['team_id' => $this->team->id, 'roles' => [ServerRole::Runtime]]);
    $overview = resolve(EdgeOverview::class)->for($this->team->id);
    expect($overview->singlePointOfFailure)->toBeTrue()
        ->and($overview->singleServer)->toBeFalse();

    // Two edges: fine again.
    $only->forceFill(['roles' => [ServerRole::Edge]])->save();
    Server::factory()->online()->create(['team_id' => $this->team->id, 'roles' => [ServerRole::Edge]]);
    expect(resolve(EdgeOverview::class)->for($this->team->id)->singlePointOfFailure)->toBeFalse();
});

test('the topology lists private-network links between the team’s servers only, with the best path either side saw', function (): void {
    $peer = static fn (string $ip, string $connection, ?string $relay = null): array => ['ipv4' => $ip, 'online' => true, 'connection' => $connection, 'relay' => $relay, 'lastHandshake' => null];
    $status = static fn (string $ip, array $peers): array => ['state' => 'connected', 'ipv4' => $ip, 'ipv6' => null, 'message' => null, 'checkedAt' => now()->toIso8601String(), 'peers' => $peers];

    $a = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'a']);
    $b = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'b']);
    $c = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'c']);
    $foreign = Server::factory()->online()->create(['name' => 'theirs']);

    $a->forceFill(['mesh_ip' => '100.64.0.1', 'mesh_status' => $status('100.64.0.1', [$peer('100.64.0.2', 'relay', 'fra'), $peer('100.64.0.3', 'idle'), $peer('100.64.0.9', 'direct')])])->save();
    $b->forceFill(['mesh_ip' => '100.64.0.2', 'mesh_status' => $status('100.64.0.2', [$peer('100.64.0.1', 'direct')])])->save();
    $c->forceFill(['mesh_ip' => '100.64.0.3', 'mesh_status' => null])->save();
    $foreign->forceFill(['mesh_ip' => '100.64.0.9'])->save();

    $this->actingAs($this->viewer)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->has('meshLinks', 2)
            ->where('meshLinks', fn ($links): bool => collect($links)->contains(fn (array $l): bool => in_array($a->public_id, [$l['from'], $l['to']], true)
                    && in_array($b->public_id, [$l['from'], $l['to']], true) && $l['path'] === 'direct' && $l['relay'] === null)
                && collect($links)->contains(fn (array $l): bool => in_array($c->public_id, [$l['from'], $l['to']], true) && $l['path'] === 'idle')
                && collect($links)->doesntContain(fn (array $l): bool => in_array($foreign->public_id, [$l['from'], $l['to']], true))));
});
