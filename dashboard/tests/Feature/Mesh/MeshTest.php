<?php

declare(strict_types=1);

use App\Actions\Crypto\RotateKek;
use App\Actions\Mesh\EnableMesh;
use App\Actions\Mesh\IssueMeshAuthKey;
use App\Actions\Mesh\MaintainMesh;
use App\Actions\Mesh\RemoveMeshNode;
use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\Report;
use App\Enums\AuditAction;
use App\Enums\Mesh\MeshCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Events\ServerStatusChanged;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Mesh\ProvisionMeshMemberJob;
use App\Jobs\Mesh\RemoveMeshNodeJob;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Mesh\MeshCredential;
use App\Models\NodeStateVersion;
use App\Models\Server;
use App\Models\Team;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use App\Services\Mesh\HeadscaleConnection;
use App\Services\Mesh\MeshSecrets;
use App\Services\Mesh\MeshTopology;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeHeadscale;
use Tests\Support\ProtocolSchemas;

/*
| The mesh (docs/05 §2): the built-in Headscale is enabled when a second
| server joins, servers get single-use tagged keys sealed into their
| NodeState, report their mesh address, and leave the mesh when revoked.
*/

/**
 * A server with a real X25519 key pair, so sealed mesh keys can be opened.
 *
 * @param  list<ServerRole>  $roles
 * @return array{0: Server, 1: string} server, sodium box key pair
 */
function meshMember(Team $team, array $roles, bool $controlPlane = false): array
{
    $pair = sodium_crypto_box_keypair();
    $server = Server::factory()->online()->create([
        'team_id' => $team->id,
        'roles' => $roles,
        'is_control_plane' => $controlPlane,
        'sealing_public_key' => 'x25519:'.base64_encode(sodium_crypto_box_publickey($pair)),
    ]);

    return [$server->refresh(), $pair];
}

/**
 * @return array<string, mixed>
 */
function latestState(Server $server): array
{
    $latest = NodeStateVersion::query()->where('server_id', $server->id)->orderByDesc('version')->firstOrFail();

    return json_decode($latest->document, true, 64, JSON_THROW_ON_ERROR);
}

function meshReport(Server $server, array $mesh): void
{
    resolve(RecordReport::class)->handle($server->refresh(), Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1',
        'kind' => 'Report',
        'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'seq' => (int) (microtime(true) * 1_000_000),
        'full' => true,
        'appliedVersion' => $server->desired_version,
        'workloads' => [],
        'host' => ['cpus' => 2, 'memoryMb' => 1024, 'diskTotalMb' => 100, 'diskFreeMb' => 50, 'daemonVersion' => '0.1.0', 'mesh' => $mesh],
    ]));
}

beforeEach(function (): void {
    $this->headscale = FakeHeadscale::install();
    $this->bootstrapFile = $this->headscale->writeBootstrapFile();
    $this->team = Team::factory()->create();
    [$this->cp, $this->cpPair] = meshMember($this->team, [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build], true);
    [$this->worker, $this->workerPair] = meshMember($this->team, [ServerRole::Runtime, ServerRole::Build]);
});

test('enabling the mesh bootstraps a dashboard-owned API key, the policy and one tagged key per server', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());

    $settings = InstanceSettings::current();
    expect($settings->mesh_mode)->toBe(MeshMode::Embedded)
        ->and($settings->mesh_enabled_at)->not->toBeNull()
        ->and($settings->mesh_error)->toBeNull();

    // The bootstrap key is expired in Headscale and its file is gone; the
    // dashboard's own key is stored encrypted only.
    $live = $this->headscale->liveApiKeys();
    $stored = MeshCredential::query()->where('purpose', MeshCredentialPurpose::HeadscaleApiKey->value)->sole();
    expect($live)->toHaveCount(1)
        ->and($live[0])->not->toBe(FakeHeadscale::BOOTSTRAP_KEY)
        ->and(file_exists($this->bootstrapFile))->toBeFalse()
        ->and($stored->ciphertext)->not->toContain($live[0])
        ->and(resolve(MeshSecrets::class)->reveal($stored))->toBe($live[0])
        ->and($stored->toArray())->not->toHaveKeys(['ciphertext', 'wrapped_dek']);

    expect(json_decode((string) $this->headscale->policy, true)['acls'])->toHaveCount(5);

    // One single-use key per server, tagged by role.
    $tags = array_map(static fn (array $k): array => $k['tags'], array_values($this->headscale->preAuthKeys));
    expect($tags)->toEqualCanonicalizing([
        ['tag:lr-build', 'tag:lr-cp', 'tag:lr-edge', 'tag:lr-runtime'],
        ['tag:lr-build', 'tag:lr-runtime'],
    ]);

    expect(AuditEvent::query()->where('action', AuditAction::MeshEnabled->value)->count())->toBe(1);

    // Idempotent: a second run issues nothing new and audits nothing new.
    $keys = count($this->headscale->preAuthKeys);
    resolve(EnableMesh::class)->handle(AuditActor::system());
    expect(AuditEvent::query()->where('action', AuditAction::MeshEnabled->value)->count())->toBe(1)
        ->and($this->headscale->liveApiKeys())->toBe($live)
        ->and(count($this->headscale->preAuthKeys))->toBe($keys * 2);
});

test('the NodeState carries the mesh client with a key sealed to that server only', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    resolve(RenderNodeState::class)->handle($this->worker);
    resolve(RenderNodeState::class)->handle($this->cp);

    $state = latestState($this->worker);
    $tailscale = $state['system']['tailscale'];
    $raw = NodeStateVersion::query()->where('server_id', $this->worker->id)->orderByDesc('version')->firstOrFail()->document;

    expect(ProtocolSchemas::errors('node-state', $raw))->toBe([])
        ->and($tailscale['enabled'])->toBeTrue()
        ->and($tailscale['image'])->toBe(config('launchroom.daemon.system_images.tailscale'))
        ->and($tailscale['loginServer'])->toBe('https://dash.example.com')
        ->and($tailscale)->not->toHaveKey('tags')
        ->and($state['system']['registry'])->toBe([]); // the control plane's mesh IP is not known yet

    $plain = sodium_crypto_box_seal_open(base64_decode($tailscale['authKey']['sealed'], true), $this->workerPair);
    $doc = json_decode((string) $plain, true);
    $issued = array_column($this->headscale->preAuthKeys, 'key');

    expect($doc['serverId'])->toBe($this->worker->public_id)
        ->and($issued)->toContain($doc['authKey'])
        ->and(sodium_crypto_box_seal_open(base64_decode($tailscale['authKey']['sealed'], true), $this->cpPair))->toBeFalse()
        ->and($raw)->not->toContain($doc['authKey']);

    // Re-rendering re-seals (randomized) but is not a new version.
    $version = $this->worker->refresh()->desired_version;
    resolve(RenderNodeState::class)->handle($this->worker);
    expect($this->worker->refresh()->desired_version)->toBe($version);

    expect(latestState($this->cp)['system']['registry'])->toBe(['serveOnMesh' => true]);
});

test('a joined server reports its address, loses its key and others forward the registry to the control plane', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    $this->headscale->join($this->cp, '100.64.0.1');
    $workerNode = $this->headscale->join($this->worker, '100.64.0.2');

    meshReport($this->cp, ['state' => 'connected', 'ipv4' => '100.64.0.1', 'ipv6' => 'fd7a:115c:a1e0::1', 'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'peers' => [
        ['ipv4' => '100.64.0.2', 'online' => true, 'connection' => 'direct', 'relay' => 'lr'],
    ]]);
    meshReport($this->worker, ['state' => 'connected', 'ipv4' => '100.64.0.2', 'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'peers' => [
        ['ipv4' => '100.64.0.1', 'online' => true, 'connection' => 'relay', 'relay' => 'lr', 'lastHandshake' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
    ], 'unknownField' => 'dropped']);

    $this->worker->refresh();
    expect($this->worker->mesh_ip)->toBe('100.64.0.2')
        ->and($this->worker->mesh_status['peers'][0]['connection'])->toBe('relay')
        ->and($this->worker->mesh_status)->not->toHaveKey('unknownField')
        ->and(MeshCredential::query()->where('scope', MeshSecrets::serverScope($this->worker))->exists())->toBeFalse();

    resolve(RenderNodeState::class)->handle($this->worker);
    $state = latestState($this->worker);
    expect($state['system']['tailscale'])->not->toHaveKey('authKey')
        ->and($state['system']['registry'])->toBe(['forwardTo' => '100.64.0.1:5000']);

    // The scheduled maintenance links the Headscale node (MaintainMeshJob ran synchronously too).
    resolve(MaintainMesh::class)->handle();
    expect($this->worker->refresh()->mesh_node_id)->toBe($workerNode);

    // The servers page shows the path to the control plane.
    $viewer = memberOf($this->team, TeamRole::Viewer);
    $this->actingAs($viewer)->get(route('servers.show', $this->worker->public_id))
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->where('mesh.state', 'connected')
            ->where('mesh.ip', '100.64.0.2')
            ->where('mesh.pathToControlPlane', 'relay')
            ->missing('meshPeers')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('meshPeers.0.name', $this->cp->name)
                ->where('meshPeers.0.isControlPlane', true)));
    $this->actingAs($viewer)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->where('mesh.enabled', true)
            ->where('mesh.canManage', false)
            ->where('mesh.error', null)
            ->where('servers.1.mesh.state', 'connected'));
});

test('maintenance keeps node tags equal to roles and refreshes keys of servers that have not joined', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    $node = $this->headscale->join($this->worker, '100.64.0.2', ['tag:lr-runtime']);

    $before = MeshCredential::query()->where('scope', MeshSecrets::serverScope($this->cp))->sole();
    $before->forceFill(['expires_at' => now()->addMinutes(2)])->save();

    resolve(MaintainMesh::class)->handle();

    $after = MeshCredential::query()->where('scope', MeshSecrets::serverScope($this->cp))->sole();
    expect($this->headscale->nodes[$node]['tags'])->toBe(['tag:lr-build', 'tag:lr-runtime'])
        ->and($after->external_id)->not->toBe($before->external_id)
        ->and($this->headscale->preAuthKeys[(string) $before->external_id]['expired'])->toBeTrue();
});

test('the API key is rotated before it expires', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    MeshCredential::query()->where('purpose', MeshCredentialPurpose::HeadscaleApiKey->value)->update(['expires_at' => now()->addDays(3)]);
    $old = $this->headscale->liveApiKeys()[0];

    resolve(MaintainMesh::class)->handle();

    $live = $this->headscale->liveApiKeys();
    expect($live)->toHaveCount(1)->and($live[0])->not->toBe($old);
});

test('Headscale errors are recorded for the owner and nothing is switched on', function (): void {
    $this->headscale->down = true;

    expect(fn () => resolve(EnableMesh::class)->handle(AuditActor::system()))->toThrow(HeadscaleUnavailable::class);

    $settings = InstanceSettings::current();
    expect($settings->mesh_enabled_at)->toBeNull()
        ->and($settings->mesh_error)->toContain('503');
});

test('without the built-in Headscale only your own Tailscale network is offered', function (): void {
    config(['launchroom.mesh.headscale_url' => null]);

    expect(fn () => resolve(EnableMesh::class)->handle(AuditActor::system()))->toThrow(DomainRuleViolation::class);
});

test('approving a second server switches the mesh on; revoking takes the node out', function (): void {
    Bus::fake([RemoveMeshNodeJob::class]);
    InstanceSettings::current();

    event(new ServerStatusChanged($this->worker->id, ServerStatus::PendingApproval, ServerStatus::Online));
    expect(InstanceSettings::current()->mesh_mode)->toBe(MeshMode::Embedded);

    $this->worker->forceFill(['revoked_at' => now()])->save();
    $this->worker->transitionTo(ServerStatus::Revoked);
    Bus::assertDispatched(RemoveMeshNodeJob::class, fn (RemoveMeshNodeJob $job): bool => $job->serverId === $this->worker->id);
});

test('a revoked server’s node is deleted and its fields cleared', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    $node = $this->headscale->join($this->worker, '100.64.0.2');
    $this->worker->forceFill(['mesh_ip' => '100.64.0.2', 'mesh_node_id' => $node, 'revoked_at' => now()])->save();

    new RemoveMeshNodeJob($this->worker->id)->handle(resolve(RemoveMeshNode::class));

    expect($this->headscale->nodes)->toBe([])
        ->and($this->worker->refresh()->mesh_ip)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::MeshNodeRemoved->value)->count())->toBe(1);
});

test('a reported mesh address never links another server’s node; nodes are linked by their pre-auth key (review M4)', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    $cpNode = $this->headscale->join($this->cp, '100.64.0.1', ['tag:lr-build', 'tag:lr-cp', 'tag:lr-edge', 'tag:lr-runtime']);
    resolve(MaintainMesh::class)->handle();
    expect($this->cp->refresh()->mesh_node_id)->toBe($cpNode)
        ->and($this->cp->mesh_ip)->toBe('100.64.0.1');

    // The worker claims the control plane's address; its real node is elsewhere.
    $workerNode = $this->headscale->join($this->worker, '100.64.0.9');
    meshReport($this->worker, ['state' => 'connected', 'ipv4' => '100.64.0.1', 'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'peers' => []]);
    resolve(MaintainMesh::class)->handle();

    expect($this->worker->refresh()->mesh_node_id)->toBe($workerNode)
        ->and($this->worker->mesh_ip)->toBe('100.64.0.9')
        ->and($this->cp->refresh()->mesh_node_id)->toBe($cpNode)
        ->and($this->headscale->nodes[$cpNode]['tags'])->toContain('tag:lr-cp');

    // A node registered with a key nobody was issued is linked to no one.
    $this->headscale->addNode('100.64.0.20', [], 'unknown-key');
    resolve(MaintainMesh::class)->handle();
    expect(Server::query()->where('mesh_ip', '100.64.0.20')->exists())->toBeFalse();

    // Revoking the worker deletes its own node, never the control plane's.
    $this->worker->forceFill(['revoked_at' => now()])->save();
    new RemoveMeshNodeJob($this->worker->id)->handle(resolve(RemoveMeshNode::class));
    expect($this->headscale->nodes)->toHaveKey($cpNode)
        ->and($this->headscale->nodes)->not->toHaveKey($workerNode);
});

test('with your own Tailscale a reported address inside the mesh range is taken unless another server has it', function (): void {
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now(), 'mesh_mode' => MeshMode::OwnTailscale])->save();
    $this->cp->forceFill(['mesh_ip' => '100.64.0.1'])->save();

    meshReport($this->worker, ['state' => 'connected', 'ipv4' => '100.64.0.1', 'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'peers' => []]);
    expect($this->worker->refresh()->mesh_ip)->toBeNull();

    meshReport($this->worker, ['state' => 'connected', 'ipv4' => '10.0.0.5', 'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'peers' => []]);
    expect($this->worker->refresh()->mesh_ip)->toBeNull();

    meshReport($this->worker, ['state' => 'connected', 'ipv4' => '100.64.0.2', 'checkedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'peers' => []]);
    expect($this->worker->refresh()->mesh_ip)->toBe('100.64.0.2');
});

test('a single server never turns the mesh on by itself', function (): void {
    $this->worker->forceFill(['revoked_at' => now(), 'status' => ServerStatus::Revoked])->save();

    new ProvisionMeshMemberJob($this->cp->id)->handle(
        resolve(EnableMesh::class), resolve(IssueMeshAuthKey::class),
        resolve(HeadscaleConnection::class), resolve(MeshTopology::class),
    );

    expect(InstanceSettings::current()->mesh_enabled_at)->toBeNull()
        ->and(latestStateOrNull($this->cp))->toBeNull();
});

function latestStateOrNull(Server $server): ?array
{
    return NodeStateVersion::query()->where('server_id', $server->id)->exists() ? latestState($server) : null;
}

test('KEK rotation rewraps mesh credentials', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    $current = resolve(Envelope::class);
    $next = new Envelope(SecretKey::generate());
    $key = $this->headscale->liveApiKeys()[0];

    resolve(RotateKek::class)->handle($current, $next);
    app()->instance(Envelope::class, $next);

    $credential = MeshCredential::query()->where('purpose', MeshCredentialPurpose::HeadscaleApiKey->value)->sole();
    expect($credential->kek_id)->toBe($next->kekId())
        ->and(resolve(MeshSecrets::class)->reveal($credential))->toBe($key);
});

test('your own tailnet key is sealed only to servers an instance owner enrolled or approved (review L1)', function (): void {
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now(), 'mesh_mode' => MeshMode::OwnTailscale, 'mesh_login_server' => 'https://controlplane.tailscale.com'])->save();
    resolve(MeshSecrets::class)->put(MeshSecrets::instanceScope(), MeshCredentialPurpose::TailscaleAuthKey, 'tskey-auth-OWNER');

    $admin = memberOf($this->team, TeamRole::Admin);
    $this->worker->forceFill(['approved_by' => $admin->id])->save();
    resolve(RenderNodeState::class)->handle($this->worker);
    expect(latestState($this->worker)['system']['tailscale'])->not->toHaveKey('authKey');

    $owner = memberOf($this->team, TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();
    $this->worker->forceFill(['approved_by' => $owner->id])->save();
    resolve(RenderNodeState::class)->handle($this->worker);
    $sealed = latestState($this->worker)['system']['tailscale']['authKey']['sealed'];

    expect(json_decode((string) sodium_crypto_box_seal_open(base64_decode($sealed, true), $this->workerPair), true)['authKey'])->toBe('tskey-auth-OWNER');
});

test('maintenance re-applies the ACL policy when Headscale lost it', function (): void {
    resolve(EnableMesh::class)->handle(AuditActor::system());
    expect($this->headscale->policy)->not->toBeNull();

    // A reset Headscale database: no policy (allow-all) while our hash is unchanged.
    $this->headscale->policy = null;
    resolve(MaintainMesh::class)->handle();

    expect($this->headscale->policy)->not->toBeNull()
        ->and(json_decode((string) $this->headscale->policy, true)['acls'])->not->toBe([]);
});
