<?php

declare(strict_types=1);

use App\Actions\Servers\CreateEnrollment;
use App\Data\Audit\AuditActor;
use App\Data\Servers\CreateEnrollmentData;
use App\Data\Teams\TeamSettingsData;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\Signer;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

function issueJoinToken(Team $team, array $roles = ['edge', 'runtime', 'build'], bool $controlPlane = false): string
{
    return resolve(CreateEnrollment::class)
        ->handle($team, new CreateEnrollmentData($roles), null, AuditActor::system(), $controlPlane)
        ->token;
}

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->daemon = new FakeDaemon;
});

test('join tokens match the daemon grammar and are stored hashed', function (): void {
    $token = issueJoinToken($this->team);

    expect($token)->toMatch('/^[A-Za-z0-9_.-]{16,256}$/')
        ->and(strlen($token))->toBe(49)
        ->and(ServerEnrollment::query()->where('token_hash', $token)->exists())->toBeFalse()
        ->and(ServerEnrollment::query()->sole()->token_hash)->toBe(hash('sha256', $token));
});

test('a daemon enrolls and receives a signed, schema-valid response', function (): void {
    $token = issueJoinToken($this->team);

    $response = $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token));

    $response->assertCreated();
    $body = (string) $response->getContent();
    $signer = resolve(Signer::class);

    expect(ResponseSignature::verify((string) $response->headers->get('X-LR-Signature'), $body, [$signer->kid => $signer->publicKey()]))->toBeTrue()
        ->and(ProtocolSchemas::errors('enroll-response', $body))->toBe([]);

    $server = Server::query()->sole();
    $json = json_decode($body, true);

    expect($json['serverId'])->toBe($server->public_id)
        ->and($json['status'])->toBe('active')
        ->and($json['controlPlaneKeys'][$signer->kid])->toBe($signer->publicKey())
        ->and($server->status)->toBe(ServerStatus::Online)
        ->and($server->signing_public_key)->toBe($this->daemon->signingKey())
        ->and($server->roles->all())->toBe([ServerRole::Edge, ServerRole::Runtime, ServerRole::Build])
        ->and($server->facts->hostname)->toBe('server-1')
        ->and($server->desired_version)->toBe(1)
        ->and(ServerEnrollment::query()->sole()->used_at)->not->toBeNull();
});

test('a Mac enrolls without the edge role it cannot take (D53)', function (): void {
    $token = issueJoinToken($this->team);

    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token, [
        'host' => ['hostname' => 'mac-mini', 'os' => 'darwin', 'arch' => 'arm64', 'cpus' => 8, 'memoryMb' => 8192, 'hardware' => ['cpuVendor' => 'Apple', 'hostModel' => 'Mac mini']],
    ]))->assertCreated();

    $server = Server::query()->sole();

    expect($server->roles->all())->toBe([ServerRole::Runtime, ServerRole::Build])
        ->and($server->facts->os)->toBe('darwin')
        ->and($server->facts->hardware?->hostModel)->toBe('Mac mini')
        ->and(AuditEvent::query()->where('action', 'server.enrolled')->sole()->meta['roles_dropped'] ?? null)->toBe(['edge']);
});

test('a used token cannot enroll another key', function (): void {
    $token = issueJoinToken($this->team);
    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token))->assertCreated();

    $other = new FakeDaemon;
    $other->send($this, 'POST', '/daemon/v1/enroll', $other->enrollBody($token))->assertForbidden();

    expect(Server::query()->count())->toBe(1);
});

test('retrying with the same token and key returns the same server', function (): void {
    $token = issueJoinToken($this->team);
    $first = $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token))->json('serverId');
    $second = $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token))->assertCreated()->json('serverId');

    expect($second)->toBe($first)->and(Server::query()->count())->toBe(1);
});

test('expired and unknown tokens are refused', function (): void {
    $token = issueJoinToken($this->team);
    $this->travel(31)->minutes();

    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token))->assertForbidden();
    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody('lr_jt_'.str_repeat('x', 43)))->assertForbidden();

    expect(Server::query()->count())->toBe(0);
});

test('the signature must be made with the key being enrolled', function (): void {
    $token = issueJoinToken($this->team);
    $body = $this->daemon->enrollBody($token, ['signingKey' => (new FakeDaemon)->signingKey()]);

    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $body)->assertUnauthorized();
});

test('invalid enroll bodies are rejected with 422', function (): void {
    $token = issueJoinToken($this->team);

    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token, ['kind' => 'Other']))->assertUnprocessable();
    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token, ['host' => ['hostname' => 'bad host!', 'os' => 'linux', 'arch' => 'x', 'cpus' => 1]]))->assertUnprocessable();
});

test('teams that require approval get pending servers that cannot fetch state', function (): void {
    $this->team->forceFill(['settings' => new TeamSettingsData(requireServerApproval: true)])->save();
    $token = issueJoinToken($this->team);

    $response = $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token))->assertCreated();
    $server = Server::query()->sole();

    expect($response->json('status'))->toBe('pending_approval')
        ->and($server->status)->toBe(ServerStatus::PendingApproval);

    $this->daemon->as($server->public_id)->send($this, 'GET', '/daemon/v1/state?have=0')->assertForbidden();
});

test('the control plane server is approved even when the team requires approval', function (): void {
    $this->team->forceFill(['settings' => new TeamSettingsData(requireServerApproval: true)])->save();
    $token = issueJoinToken($this->team, controlPlane: true);

    $this->daemon->send($this, 'POST', '/daemon/v1/enroll', $this->daemon->enrollBody($token))->assertCreated()->assertJsonPath('status', 'active');

    expect(Server::query()->sole()->is_control_plane)->toBeTrue();
});
