<?php

declare(strict_types=1);

use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Data\Protocol\V1\Report;
use App\Enums\Mesh\MeshMode;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Events\NodeStateInvalidated;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Queries\ServerTopology;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

/*
| Private-network link quality (docs/05 §2.1): launchroomd measures its mesh
| peers, the dashboard tells it whom (system.linkProbe, only for daemons that
| announced the feature), stores the latest measurement and hourly rollups,
| and shows them on the topology and the server page.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    InstanceSettings::current()->forceFill(['mesh_enabled_at' => now(), 'mesh_mode' => MeshMode::Embedded])->save();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
});

function linkServer(Team $team, string $meshIp, bool $controlPlane = false, array $features = ['linkProbe', 'shipLogs']): Server
{
    [$server] = Projects::server($team, [ServerRole::Runtime]);
    $server->forceFill(['mesh_ip' => $meshIp, 'is_control_plane' => $controlPlane, 'daemon_features' => $features])->save();

    return $server->refresh();
}

/**
 * @param  list<array<string, mixed>>  $links
 * @param  list<string>|null  $features
 */
function linkReport(Server $server, array $links, ?array $features = ['linkProbe', 'shipLogs'], string $daemonVersion = '0.1.0'): void
{
    static $seq = 1;
    $host = ['cpus' => 2, 'memoryMb' => 1024, 'diskTotalMb' => 100, 'diskFreeMb' => 50, 'daemonVersion' => $daemonVersion, 'links' => $links];

    if ($features !== null) {
        $host['features'] = $features;
    }

    resolve(RecordReport::class)->handle($server->refresh(), Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1',
        'kind' => 'Report',
        'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        'seq' => $seq++,
        'full' => true,
        'appliedVersion' => $server->desired_version,
        'workloads' => [],
        'host' => $host,
    ]));
}

function at(CarbonImmutable $time): string
{
    return $time->utc()->format('Y-m-d\TH:i:s.v\Z');
}

/**
 * @return array<string, mixed>
 */
function linkRender(Server $server): array
{
    $render = resolve(RenderNodeState::class)->handle($server->refresh());
    expect($render->error)->toBeNull()
        ->and(ProtocolSchemas::errors('node-state', $render->version->document))->toBe([]);

    return json_decode($render->version->document, true, 64, JSON_THROW_ON_ERROR);
}

test('servers that announced link probes measure their team’s mesh peers and the control plane', function (): void {
    $cp = linkServer(Team::factory()->create(), '100.64.0.1', controlPlane: true);
    $a = linkServer($this->team, '100.64.0.2');
    $b = linkServer($this->team, '100.64.0.3');
    linkServer($this->team, '100.64.0.4', features: []);                  // older launchroomd
    linkServer($this->team, '100.64.0.5')->forceFill(['measure_links' => false])->save(); // opted out
    linkServer(Team::factory()->create(), '100.64.0.6');                   // another team

    $peers = linkRender($a)['system']['linkProbe'];
    expect($peers['enabled'])->toBeTrue()
        ->and(array_column($peers['peers'], 'serverId'))->toBe([$cp->public_id, $b->public_id])
        ->and($peers['peers'][1])->toBe(['serverId' => $b->public_id, 'ipv4' => '100.64.0.3', 'signingKey' => $b->signing_public_key]);

    // The control plane measures every mesh server that measures (any team).
    expect(array_column(linkRender($cp)['system']['linkProbe']['peers'], 'ipv4'))->toBe(['100.64.0.2', '100.64.0.3', '100.64.0.6']);
});

test('servers without the feature, opted out or off the mesh get no linkProbe field', function (): void {
    linkServer($this->team, '100.64.0.2');
    $old = linkServer($this->team, '100.64.0.3', features: []);
    $off = linkServer($this->team, '100.64.0.4');
    $off->forceFill(['measure_links' => false])->save();

    expect(linkRender($old)['system'])->not->toHaveKey('linkProbe')
        ->and(linkRender($off)['system'])->not->toHaveKey('linkProbe');

    InstanceSettings::current()->forceFill(['mesh_enabled_at' => null, 'mesh_mode' => null])->save();
    expect(linkRender(linkServer($this->team, '100.64.0.9'))['system'])->not->toHaveKey('linkProbe');
});

test('announced features are stored (known ones only) and re-render the mesh when they change', function (): void {
    $server = linkServer($this->team, '100.64.0.2', features: []);
    Event::fake([NodeStateInvalidated::class]);

    linkReport($server, [], ['linkProbe', 'futureThing']);
    expect($server->refresh()->daemon_features)->toBe(['linkProbe']);
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $e): bool => in_array($server->id, $e->serverIds, true));

    Event::fake([NodeStateInvalidated::class]);
    linkReport($server, [], ['linkProbe']);
    Event::assertNotDispatched(NodeStateInvalidated::class);
});

test('a new launchroomd version re-renders its server (system image pins, D54)', function (): void {
    $server = linkServer($this->team, '100.64.0.2');

    Event::fake([NodeStateInvalidated::class]);
    linkReport($server, []);
    Event::assertNotDispatched(NodeStateInvalidated::class);

    linkReport($server, [], daemonVersion: '0.2.0');
    expect($server->refresh()->daemon_version)->toBe('0.2.0');
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $e): bool => $e->serverIds === [$server->id]);
});

test('measurements are stored once per measuredAt, with hourly rollups', function (): void {
    $a = linkServer($this->team, '100.64.0.2');
    $b = linkServer($this->team, '100.64.0.3');
    $t = CarbonImmutable::now()->startOfMinute()->addMilliseconds(437); // milliseconds survive storage

    $link = ['peer' => $b->public_id, 'measuredAt' => at($t), 'rttMs' => 12.5, 'throughputMbps' => 480.2, 'throughputAt' => at($t->subMinute())];
    linkReport($a, [$link]);
    linkReport($a, [$link]); // the next report repeats it
    linkReport($a, [[...$link, 'measuredAt' => at($t->addMinute()), 'rttMs' => 14.5]]);
    linkReport($a, [['peer' => $b->public_id, 'measuredAt' => at($t->addMinutes(2)), 'error' => 'timed out']]);

    $row = DB::table('server_links')->where('server_id', $a->id)->sole();
    expect($row->peer_server_id)->toBe($b->id)
        ->and($row->rtt_ms)->toBeNull()
        ->and($row->error)->toBe('timed out')
        ->and((float) $row->throughput_mbps)->toBe(480.2);

    $samples = DB::table('server_link_samples')->where('server_id', $a->id)->get();
    expect((int) $samples->sum('samples'))->toBe(2)
        ->and((int) $samples->sum('failures'))->toBe(1)
        ->and((float) $samples->sum('rtt_sum_ms'))->toBe(27.0)
        ->and((float) $samples->min('rtt_min_ms'))->toBe(12.5)
        ->and((float) $samples->max('rtt_max_ms'))->toBe(14.5);
});

test('measurements about foreign, unknown or revoked servers and from the future are dropped', function (): void {
    $a = linkServer($this->team, '100.64.0.2');
    $foreign = linkServer(Team::factory()->create(), '100.64.0.3');
    $revoked = linkServer($this->team, '100.64.0.4');
    $revoked->forceFill(['revoked_at' => now()])->save();
    $peer = linkServer($this->team, '100.64.0.5');
    $now = CarbonImmutable::now();

    linkReport($a, [
        ['peer' => $foreign->public_id, 'measuredAt' => at($now), 'rttMs' => 1],
        ['peer' => $revoked->public_id, 'measuredAt' => at($now), 'rttMs' => 1],
        ['peer' => 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ', 'measuredAt' => at($now), 'rttMs' => 1],
        ['peer' => $peer->public_id, 'measuredAt' => at($now->addHour()), 'rttMs' => 1],
    ]);

    expect(DB::table('server_links')->count())->toBe(0)
        ->and(DB::table('server_link_samples')->count())->toBe(0);
});

test('invalid link measurements are refused with the report', function (): void {
    $a = linkServer($this->team, '100.64.0.2');
    $b = linkServer($this->team, '100.64.0.3');

    expect(fn () => linkReport($a, [['peer' => $b->public_id, 'measuredAt' => at(CarbonImmutable::now()), 'rttMs' => -1]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => linkReport($a, [['peer' => '../etc', 'measuredAt' => at(CarbonImmutable::now())]]))
        ->toThrow(ValidationException::class);
});

test('the topology labels links with the round trip and both directions’ rates', function (): void {
    $a = linkServer($this->team, '100.64.0.2');
    $b = linkServer($this->team, '100.64.0.3');
    $now = CarbonImmutable::now();

    linkReport($a, [['peer' => $b->public_id, 'measuredAt' => at($now), 'rttMs' => 10, 'throughputMbps' => 480, 'throughputAt' => at($now)]]);
    linkReport($b, [['peer' => $a->public_id, 'measuredAt' => at($now), 'rttMs' => 14, 'throughputMbps' => 120, 'throughputAt' => at($now)]]);
    // (reports without `mesh` clear it, so the peer status comes last)
    $a->forceFill(['mesh_status' => ['state' => 'connected', 'ipv4' => '100.64.0.2', 'ipv6' => null, 'message' => null, 'checkedAt' => null, 'peers' => [['ipv4' => '100.64.0.3', 'online' => true, 'connection' => 'direct', 'relay' => null, 'lastHandshake' => null]]]])->save();

    $links = resolve(ServerTopology::class)->links([$a->refresh(), $b->refresh()]);
    expect($links)->toHaveCount(1)
        ->and($links[0]->latencyMs)->toBe(12.0)
        ->and(collect($links[0]->speeds)->map(fn ($s): string => "{$s->from}>{$s->to}:{$s->mbps}")->sort()->values()->all())
        ->toBe(collect(["{$b->public_id}>{$a->public_id}:480", "{$a->public_id}>{$b->public_id}:120"])->sort()->values()->all());

    // Old latency is not current.
    $this->travel(11)->minutes();
    expect(resolve(ServerTopology::class)->links([$a, $b])[0]->latencyMs)->toBeNull();
});

test('the server page shows each peer’s link quality and the switch saves', function (): void {
    $a = linkServer($this->team, '100.64.0.2');
    $b = linkServer($this->team, '100.64.0.3');
    $now = CarbonImmutable::now();
    linkReport($a, [['peer' => $b->public_id, 'measuredAt' => at($now), 'rttMs' => 38.4, 'throughputMbps' => 52, 'throughputAt' => at($now)]]);
    $a->forceFill(['mesh_status' => ['state' => 'connected', 'ipv4' => '100.64.0.2', 'ipv6' => null, 'message' => null, 'checkedAt' => null, 'peers' => [['ipv4' => '100.64.0.3', 'online' => true, 'connection' => 'relay', 'relay' => 'lr', 'lastHandshake' => null]]]])->save();

    // The Network tab loads the peers after the first paint (deferred).
    $this->actingAs($this->viewer)->get(route('servers.tab', [$a->public_id, 'network']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->where('tab', 'network')
            ->where('measureLinks', true)
            ->missing('placement')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('meshPeers.0.name', $b->name)
                ->where('meshPeers.0.quality.latencyMs', 38.4)
                ->where('meshPeers.0.quality.downloadMbps', 52)
                ->where('meshPeers.0.quality.uploadMbps', null)
                ->has('meshPeers.0.quality.latencyHistory', 24)));
    $this->actingAs($this->viewer)->get(route('servers.tab', [$a->public_id, 'settings']))
        ->assertInertia(fn ($page) => $page->where('placement.measureLinks', true));

    Event::fake([NodeStateInvalidated::class]);
    $payload = ['labels' => ['zone' => 'hel'], 'allowPreviews' => true, 'allowProduction' => true, 'measureLinks' => false];
    $this->actingAs($this->viewer)->put(route('servers.settings.update', $a->public_id), $payload)->assertForbidden();
    $this->actingAs($this->admin)->put(route('servers.settings.update', $a->public_id), $payload)->assertRedirect();

    expect($a->refresh()->measure_links)->toBeFalse();
    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $e): bool => in_array($b->id, $e->serverIds, true));

    // Omitting the field leaves it alone (the CLI's label updates).
    $this->actingAs($this->admin)->put(route('servers.settings.update', $a->public_id), ['labels' => ['zone' => 'hel'], 'allowPreviews' => true, 'allowProduction' => true])->assertRedirect();
    expect($a->refresh()->measure_links)->toBeFalse();
});

test('old rollups are pruned', function (): void {
    $a = linkServer($this->team, '100.64.0.2');
    $b = linkServer($this->team, '100.64.0.3');
    DB::table('server_link_samples')->insert([
        ['server_id' => $a->id, 'peer_server_id' => $b->id, 'at' => now()->subDays(31)->startOfHour(), 'samples' => 60],
        ['server_id' => $a->id, 'peer_server_id' => $b->id, 'at' => now()->subDays(2)->startOfHour(), 'samples' => 60],
    ]);

    $this->artisan('launchroom:links:prune')->assertSuccessful();

    expect(DB::table('server_link_samples')->count())->toBe(1);
});
