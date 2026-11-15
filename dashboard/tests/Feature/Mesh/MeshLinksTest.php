<?php

declare(strict_types=1);

use App\Actions\Deployments\AdvanceDeployment;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\PrepareNodeStateRender;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Enums\AdvisoryLock;
use App\Enums\ClientCertificateMode;
use App\Enums\DeploymentStatus;
use App\Enums\IngressCredentialPurpose;
use App\Enums\Mesh\MeshMode;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Events\NodeStateInvalidated;
use App\Jobs\RenderServerNodeState;
use App\Models\IngressCredential;
use App\Models\InstanceSettings;
use App\Models\NodeStateVersion;
use App\Models\Placement;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Volume;
use App\Services\Ingress\CertStorage;
use App\Services\Ingress\IngressSecrets;
use App\Services\Mesh\MeshPolicy;
use App\Services\NodeStates\MeshReadiness;
use App\Support\Network\Mesh;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

/*
| Cross-server links over the mesh (docs/05 §3): mesh-published ports, the
| per-peer allow-list, ambassadors on consumers, edge upstreams and
| immutable URLs on every edge, automatic shared certificate storage (§5).
*/

beforeEach(function (): void {
    Projects::useTestKek();
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now(), 'mesh_mode' => MeshMode::Embedded])->save();
});

/**
 * @return array<string, mixed>
 */
function meshRender(Server $server): array
{
    // As the queued render does: what renders read is prepared first, then the (read-only) render.
    resolve(PrepareNodeStateRender::class)->handle($server->refresh());
    $render = resolve(RenderNodeState::class)->handle($server->refresh());
    expect($render->error)->toBeNull();
    $document = $render->version->document;
    expect(ProtocolSchemas::errors('node-state', $document))->toBe([])
        ->and($server->refresh()->render_error)->toBeNull();

    return json_decode($document, true, 64, JSON_THROW_ON_ERROR);
}

/**
 * Two runtime servers on the mesh and a project with postgres + api,
 * deployed; postgres placed on $a, api on $b.
 *
 * @return array{Server, Server, Project, Placement, Placement}
 */
function meshProject(Team $team): array
{
    [$a] = Projects::server($team, [ServerRole::Runtime]);
    [$b] = Projects::server($team, [ServerRole::Runtime], ip: '203.0.113.9');
    $a->forceFill(['mesh_ip' => '100.64.0.1'])->save();
    $b->forceFill(['mesh_ip' => '100.64.0.2'])->save();

    // Deployed while the mesh is off (the scheduler picks servers); then
    // placed by hand and the mesh switched on.
    $settings = InstanceSettings::current();
    $meshOn = $settings->mesh_enabled_at;
    $settings->forceFill(['mesh_enabled_at' => null])->save();
    $project = Projects::create($team, 'shop');
    $postgres = Projects::postgres($project);
    $api = Projects::image($project, 'api', port: 3000);
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());

    $db = Placement::query()->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('service_id', $postgres->id))->sole();
    $app = Placement::query()->whereIn('service_instance_id', ServiceInstance::query()->select('id')->where('service_id', $api->id))->sole();
    $db->forceFill(['server_id' => $a->id])->save();
    $app->forceFill(['server_id' => $b->id])->save();
    Volume::query()->where('service_instance_id', $db->service_instance_id)->update(['server_id' => $a->id]);
    $settings->forceFill(['mesh_enabled_at' => $meshOn])->save();

    return [$a, $b, $project, $db->refresh(), $app->refresh()];
}

function workloadOf(array $state, string $id): array
{
    return collect($state['workloads'])->firstWhere('id', $id) ?? [];
}

test('a dependency on another server is published on the mesh IP, allowed only from its consumers and reached through an ambassador', function (): void {
    $team = Team::factory()->create();
    [$a, $b, $project, $db, $app] = meshProject($team);

    $provider = meshRender($a);
    $consumer = meshRender($b);
    $db->refresh();
    $network = $project->production()->sole()->networkId();

    expect($db->mesh_port)->toBe(Mesh::FIRST_PORT)
        ->and(workloadOf($provider, $db->workload_id)['publishOnMesh'])->toBe(['port' => 31000])
        ->and($provider['firewall']['meshAllow'])->toBe([['port' => 31000, 'from' => ['100.64.0.2']]])
        ->and($provider['ambassadors'])->toBe([['network' => $network, 'alias' => 'api', 'port' => 3000, 'upstreams' => ['100.64.0.2:31000']]])
        ->and($consumer['ambassadors'])->toBe([['network' => $network, 'alias' => 'postgres', 'port' => 5432, 'upstreams' => ['100.64.0.1:31000']]])
        // api is consumed from A too (environment-relative), so it is published on B.
        ->and(workloadOf($consumer, $app->workload_id)['publishOnMesh'])->toBe(['port' => 31000])
        ->and($consumer['firewall']['meshAllow'])->toBe([['port' => 31000, 'from' => ['100.64.0.1']]]);

    // Both on one server: no ambassador, no allowed peer; the port stays
    // (sticky, the database is not recreated).
    $app->refresh()->forceFill(['server_id' => $a->id, 'mesh_port' => null])->save();
    $state = meshRender($a);

    expect($state['ambassadors'])->toBe([])
        ->and($state['firewall']['meshAllow'])->toBe([])
        ->and(workloadOf($state, $db->workload_id)['publishOnMesh'])->toBe(['port' => 31000]);
});

test('without the mesh nothing is published', function (): void {
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => null])->save();
    $team = Team::factory()->create();
    [$a, $b, , $db] = meshProject($team);

    $state = meshRender($a);

    expect(workloadOf($state, $db->workload_id))->not->toHaveKey('publishOnMesh')
        ->and($state['firewall']['meshAllow'])->toBe([])
        ->and(meshRender($b)['ambassadors'])->toBe([])
        ->and($db->refresh()->mesh_port)->toBeNull();
});

test('ambassadors prefer serving replicas, fall back to draining ones and skip stopped ones', function (): void {
    $team = Team::factory()->create();
    [$a, $b, , $db] = meshProject($team);
    [$c] = Projects::server($team, [ServerRole::Runtime], ip: '203.0.113.10');
    $c->forceFill(['mesh_ip' => '100.64.0.3'])->save();

    $old = $db->replicate(['mesh_port']);
    $old->forceFill(['server_id' => $c->id, 'workload_id' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0AAA0', 'slot' => 1, 'role' => WorkloadRole::Draining, 'drain_until' => now()->addHour(), 'phase' => 'running'])->save();

    expect(meshRender($b)['ambassadors'][0]['upstreams'])->toBe(['100.64.0.1:31000']);

    $db->forceFill(['phase' => 'exited', 'role' => WorkloadRole::Draining, 'drain_until' => now()])->save();
    expect(meshRender($b)['ambassadors'][0]['upstreams'])->toBe(['100.64.0.3:31000']);
});

test('ports are the lowest free one per server and unique', function (): void {
    $team = Team::factory()->create();
    [$a, , , $db] = meshProject($team);
    $other = $db->replicate(['mesh_port']);
    $other->forceFill(['workload_id' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0BBB0', 'slot' => 1, 'mesh_port' => 31000])->save();

    meshRender($a);
    expect($db->refresh()->mesh_port)->toBe(31001);

    $clash = $db->replicate(['mesh_port']);
    $clash->forceFill(['workload_id' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0CCC0', 'slot' => 2, 'mesh_port' => 31001]);
    expect(fn () => $clash->save())->toThrow(QueryException::class);
});

test('a new version re-renders the servers of its environments and the edges; an unchanged one nothing', function (): void {
    $team = Team::factory()->create();
    [$a, $b] = meshProject($team);
    [$edge] = Projects::server($team, [ServerRole::Edge], ip: '203.0.113.11');
    Event::fake([NodeStateInvalidated::class]);

    meshRender($a);
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $event): bool => $event->serverIds === [$b->id, $edge->id]);

    Event::fake([NodeStateInvalidated::class]);
    meshRender($a);
    Event::assertNotDispatched(NodeStateInvalidated::class);
});

test('edges reach web replicas elsewhere through an allocated mesh port, which the provider allows', function (): void {
    $team = Team::factory()->create();
    [$a, $b, , , $app] = meshProject($team);
    [$edge] = Projects::server($team, [ServerRole::Edge], ip: '203.0.113.11');
    $edge->forceFill(['mesh_ip' => '100.64.0.9'])->save();
    Projects::report($b);
    Placement::query()->whereKey($app->id)->update(['mesh_port' => null]);
    Event::fake([NodeStateInvalidated::class]);

    $routes = meshRender($edge)['routes'];
    $app->refresh();

    expect($app->mesh_port)->toBe(31000)
        ->and($routes)->not->toBe([])
        ->and(collect($routes)->pluck('upstreams')->flatten(1)->unique()->values()->all())->toBe([['address' => '100.64.0.2:31000']]);
    // The provider is re-rendered to publish the port.
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $event): bool => in_array($b->id, $event->serverIds, true));

    expect(meshRender($b)['firewall']['meshAllow'])->toBe([['port' => 31000, 'from' => ['100.64.0.1', '100.64.0.9']]]);
});

test('two edges on the mesh share the control plane’s Valkey through a dedicated ACL user', function (): void {
    config(['launchroom.ingress.caddy_modules' => true, 'launchroom.ingress.cert_storage.address' => null]);
    $team = Team::factory()->create();
    [$cp] = Projects::server($team);
    [$edge, $edgeKey] = Projects::server($team, [ServerRole::Edge], ip: '203.0.113.8');
    $cp->forceFill(['is_control_plane' => true, 'mesh_ip' => '100.64.0.1'])->save();
    $edge->forceFill(['mesh_ip' => '100.64.0.2'])->save();

    // An edge no instance owner admitted gets nothing install-wide (review M2).
    expect(meshRender($cp)['system'])->not->toHaveKey('certStorage')
        ->and(meshRender($edge))->not->toHaveKey('ingress');
    $edge->forceFill(['shared_edge_approved_at' => now()])->save();

    $connection = Mockery::mock();
    $connection->shouldReceive('command')->once()->withArgs(function (string $method, array $args): bool {
        $password = resolve(IngressSecrets::class)->get(IngressCredentialPurpose::StoragePassword);

        return $method === 'acl' && $args === CertStorage::setUserArguments((string) $password)
            && ! in_array($password, $args, true) && in_array('#'.hash('sha256', (string) $password), $args, true)
            && in_array('~lr-caddy/*', $args, true) && in_array('-@all', $args, true);
    });
    Redis::shouldReceive('connection')->andReturn($connection);

    $state = meshRender($cp);
    $password = resolve(IngressSecrets::class)->get(IngressCredentialPurpose::StoragePassword);
    $sealed = meshRender($edge)['ingress']['storage'];
    $values = json_decode((string) sodium_crypto_box_seal_open((string) base64_decode($sealed['credentials']['sealed'], true), $edgeKey), true)['values'];

    expect($state['system']['certStorage'])->toBe(['serveOnMesh' => true, 'allowFrom' => ['100.64.0.2']])
        ->and($state['ingress']['storage']['address'])->toBe('100.64.0.1:6379')
        ->and($sealed)->toMatchArray(['address' => '100.64.0.1:6379', 'keyPrefix' => 'lr-caddy'])
        ->and($values['username'])->toBe('lr-caddy')
        ->and($values['password'])->toBe($password)
        ->and(strlen((string) $password))->toBeGreaterThanOrEqual(32);

    // One edge: no shared storage, nothing served.
    $edge->forceFill(['roles' => [ServerRole::Runtime]])->save();
    $state = meshRender($cp);
    expect($state['system'])->not->toHaveKey('certStorage')
        ->and($state)->not->toHaveKey('ingress');
});

test('the mesh ACL lets edges reach the certificate storage on the control plane', function (): void {
    expect(MeshPolicy::document('launchroom')['acls'])->toContain(['action' => 'accept', 'src' => ['tag:lr-edge'], 'dst' => ['tag:lr-cp:6379']]);
});

test('a replica is mesh-ready only once its providers applied a NodeState that admits its server', function (): void {
    $team = Team::factory()->create();
    [$a, $b, , $db, $app] = meshProject($team);
    $readiness = resolve(MeshReadiness::class);

    // Provider A rendered (allows B) but has not applied it yet.
    meshRender($a);
    $a->refresh();
    expect($db->refresh()->mesh_port)->toBe(Mesh::FIRST_PORT)
        ->and($readiness->ready($app->refresh()))->toBeFalse();

    $a->forceFill(['observed_version' => $a->desired_version])->save();
    expect($readiness->ready($app->refresh()))->toBeTrue();

    // A provider that isn't online can't apply anything: it isn't waited for.
    $a->forceFill(['observed_version' => 0, 'status' => ServerStatus::Unreachable])->save();
    expect($readiness->ready($app->refresh()))->toBeTrue();

    // Without a mesh IP there is nothing to wait for.
    $a->forceFill(['status' => ServerStatus::Online])->save();
    $b->forceFill(['mesh_ip' => null])->save();
    expect($readiness->ready($app->refresh()))->toBeTrue();
});

test('a healthy replica waits in health checking until its providers admit it on the mesh', function (): void {
    $team = Team::factory()->create();
    [$a, , , $db, $app] = meshProject($team);
    meshRender($a);
    $a->refresh();
    $deployment = $app->deployment;
    $deployment->forceFill(['status' => DeploymentStatus::HealthChecking])->save();
    $app->forceFill(['phase' => WorkloadPhase::Healthy])->save();

    resolve(AdvanceDeployment::class)->handle($deployment->id);
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::HealthChecking);

    $a->forceFill(['observed_version' => $a->desired_version])->save();
    resolve(AdvanceDeployment::class)->handle($deployment->id);
    expect($deployment->refresh()->status)->not->toBe(DeploymentStatus::HealthChecking);
});

function meshVersionCount(Server $server): int
{
    return NodeStateVersion::query()->where('server_id', $server->id)->count();
}

test('placing a workload allocates nothing in its transaction; the queued render gives the ports it makes necessary', function (): void {
    $team = Team::factory()->create();
    [$a, $b, , $db, $app] = meshProject($team);
    expect($db->mesh_port)->toBeNull();
    $versions = meshVersionCount($a);
    Event::fake([NodeStateInvalidated::class]);

    // A second api replica on A: postgres (A) and api (B) are now consumed across servers.
    DB::transaction(function () use ($team, $app, $a, $db): void {
        Placement::query()->create([
            'team_id' => $team->id, 'service_instance_id' => $app->service_instance_id, 'deployment_id' => $app->deployment_id,
            'server_id' => $a->id, 'slot' => 1, 'workload_id' => 'wl_01JB7Z6X9Q3T8M5N2P4R6S8V0W', 'memory_mb' => 256, 'cpus' => 0.5,
        ]);

        // No port (and no global allocation lock) inside the placing transaction.
        expect($db->refresh()->mesh_port)->toBeNull()
            ->and(DB::table('pg_locks')->where('locktype', 'advisory')->where('pid', DB::raw('pg_backend_pid()'))->where('objid', AdvisoryLock::MeshPorts->key())->exists())->toBeFalse();
    });

    expect($db->refresh()->mesh_port)->toBeNull();

    new RenderServerNodeState($a->id)->handle(resolve(PrepareNodeStateRender::class), resolve(RenderNodeState::class));

    expect($db->refresh()->mesh_port)->toBe(Mesh::FIRST_PORT)
        ->and($app->refresh()->mesh_port)->toBe(Mesh::FIRST_PORT)
        ->and(meshVersionCount($a))->toBe($versions + 1);
    // B publishes its new port; A rendered with its own already.
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $event): bool => $event->serverIds === [$b->id]);
});

test('rendering a server writes nothing but its own version', function (): void {
    config(['launchroom.ingress.caddy_modules' => true, 'launchroom.ingress.cert_storage.address' => null]);
    $team = Team::factory()->create();
    [$a, $b, , $db] = meshProject($team);
    $a->forceFill(['is_control_plane' => true, 'roles' => [ServerRole::Edge, ServerRole::Runtime], 'shared_edge_approved_at' => now()])->save();
    $b->forceFill(['roles' => [ServerRole::Edge, ServerRole::Runtime], 'shared_edge_approved_at' => now()])->save();
    InstanceSettings::current()->forceFill(['daemon_client_certificates' => ClientCertificateMode::Verify])->save();
    Redis::shouldReceive('connection')->never();
    // Only the render itself: what an invalidation provisions afterwards is not part of it.
    Event::fake([NodeStateInvalidated::class]);

    $render = resolve(RenderNodeState::class)->handle($a->refresh());

    expect($render->error)->toBeNull()
        // No mesh port allocated for the consumed postgres (PrepareNodeStateRender does that) …
        ->and($db->refresh()->mesh_port)->toBeNull()
        // … no certificate storage credentials or ACL user (ProvisionCertStorage) …
        ->and(IngressCredential::query()->count())->toBe(0)
        // … and no edge secret (SetClientCertificateMode).
        ->and(InstanceSettings::current()->daemon_edge_secret)->toBeNull();
});
