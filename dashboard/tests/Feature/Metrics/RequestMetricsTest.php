<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Metrics\RollupMetrics;
use App\Data\Audit\AuditActor;
use App\Data\Servers\HostFactsData;
use App\Enums\DomainKind;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Team;
use App\Support\Metrics\RequestStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDaemon;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

beforeEach(function (): void {
    Projects::useTestKek();
    CarbonImmutable::setTestNow('2026-10-20 12:00:30');
    $this->team = Team::factory()->create();
    $seed = random_bytes(32);
    $keypair = sodium_crypto_box_keypair();
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create([
        'team_id' => $this->team->id,
        'name' => 'fsn1-edge',
        'roles' => ['edge', 'runtime', 'build'],
        'sealing_public_key' => 'x25519:'.base64_encode(sodium_crypto_box_publickey($keypair)),
        'facts' => new HostFactsData('host', 'linux', 'arm64', 4, 8192),
        'public_ipv4' => '203.0.113.9',
    ]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    $this->instance = $this->project->production()->sole()->serviceInstances()->sole();
    $this->domain = Domain::query()->create([
        'team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'shop.example.com', 'kind' => DomainKind::Custom,
    ]);
    $this->route = Domain::routeIdFor($this->domain->public_id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  list<array<string, mixed>>  $requests
 */
function requestsReport(Server $server, array $requests, int $seq = 1): array
{
    return [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'seq' => $seq, 'full' => true, 'appliedVersion' => 0, 'workloads' => [],
        'metrics' => ['host' => [], 'workloads' => [], 'requests' => $requests],
    ];
}

/**
 * One route-minute: 100 requests, 95 2xx, 5 5xx, latencies in the 25–50 ms bucket (p50) and 250–500 ms (the slow tail).
 *
 * @return array<string, mixed>
 */
function routeMinute(string $route, string $at, int $requests = 100, int $errors = 5): array
{
    $latency = array_fill(0, 15, 0);
    $latency[5] = $requests - 10; // ≤ 50 ms
    $latency[8] = 10;             // ≤ 500 ms

    return ['route' => $route, 'at' => $at, 'requests' => $requests, 'status2xx' => $requests - $errors, 'status5xx' => $errors, 'bytesIn' => 1000, 'bytesOut' => 60_000, 'latencyMs' => $latency];
}

test('request metrics are stored idempotently and attributed to the route\'s service', function (): void {
    $body = requestsReport($this->server, [
        routeMinute($this->route, '2026-10-20T11:58:00Z'),
        routeMinute($this->route, '2026-10-20T11:59:00Z', 200, 0),
        // The dashboard's own route and other unknown routes are dropped.
        routeMinute('rt_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ', '2026-10-20T11:59:00Z'),
    ]);

    expect(ProtocolSchemas::errors('report', (string) json_encode($body)))->toBe([]);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $body)->assertNoContent();
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', [...$body, 'seq' => 2])->assertNoContent();

    $rows = DB::table('request_metric_samples')->orderBy('at')->get();

    expect($rows)->toHaveCount(2)
        ->and((array) $rows[0])->toMatchArray([
            'server_id' => $this->server->id, 'route_id' => $this->route, 'resolution' => 1, 'service_instance_id' => $this->instance->id,
            'project_id' => $this->project->id, 'requests' => 100, 'status_2xx' => 95, 'status_5xx' => 5, 'bytes_in' => 1000, 'bytes_out' => 60_000,
        ])
        ->and(RequestStats::parse($rows[0]->latency))->toBe([0, 0, 0, 0, 0, 90, 0, 0, 10, 0, 0, 0, 0, 0, 0]);
});

test('immutable deployment URLs count for their service', function (): void {
    $deployment = Deployment::query()->where('service_instance_id', $this->instance->id)->firstOrFail();
    $route = 'rt_'.substr($deployment->public_id, 4);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', requestsReport($this->server, [routeMinute($route, '2026-10-20T11:59:00Z')]))->assertNoContent();

    expect(DB::table('request_metric_samples')->sole()->service_instance_id)->toBe($this->instance->id);
});

test('another team\'s routes are never attributed', function (): void {
    $other = Projects::create(Team::factory()->create(), 'Other');
    $service = Projects::image($other, 'api');
    $instance = $service->instances()->sole();
    $foreign = Domain::query()->create(['team_id' => $other->team_id, 'service_instance_id' => $instance->id, 'hostname' => 'other.example.com', 'kind' => DomainKind::Custom]);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', requestsReport($this->server, [routeMinute(Domain::routeIdFor($foreign->public_id), '2026-10-20T11:59:00Z')]))->assertNoContent();

    expect(DB::table('request_metric_samples')->count())->toBe(0);
});

test('request rows that do not add up are dropped without failing the report', function (): void {
    $badLatency = routeMinute($this->route, '2026-10-20T11:57:00Z');
    $badLatency['latencyMs'] = [1, 2, 3];

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', requestsReport($this->server, [
        $badLatency,                                                                   // histogram dropped, row kept
        [...routeMinute($this->route, '2026-10-20T11:58:00Z'), 'status2xx' => 500],   // more 2xx than requests
        [...routeMinute($this->route, '2026-10-20T11:59:30Z')],                        // not a whole minute
        [...routeMinute($this->route, '2026-10-20T11:59:00Z'), 'requests' => 0],       // no requests
    ]))->assertNoContent();

    $row = DB::table('request_metric_samples')->sole();

    expect($row->at)->toStartWith('2026-10-20 11:57')
        ->and($row->latency)->toBeNull();
});

test('rollups sum request counters and latency histograms', function (): void {
    $start = CarbonImmutable::parse('2026-10-20 10:00:00');

    for ($i = 0; $i < 30; $i++) {
        $minute = routeMinute($this->route, $start->addMinutes($i)->format('Y-m-d\TH:i:s\Z'));
        DB::table('request_metric_samples')->insert([
            'server_id' => $this->server->id, 'route_id' => $this->route, 'resolution' => 1, 'at' => $start->addMinutes($i)->toIso8601String(),
            'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'requests' => 100, 'status_2xx' => 95, 'status_5xx' => 5,
            'bytes_in' => 1000, 'bytes_out' => 60_000, 'latency' => RequestStats::literal($minute['latencyMs']),
        ]);
    }

    resolve(RollupMetrics::class)->handle(CarbonImmutable::parse('2026-10-20 11:00:00'));
    resolve(RollupMetrics::class)->handle(CarbonImmutable::parse('2026-10-20 11:00:00'));

    $tens = DB::table('request_metric_samples')->where('resolution', 10)->orderBy('at')->get();
    $day = DB::table('request_metric_samples')->where('resolution', 1440)->sole();

    expect($tens)->toHaveCount(3)
        ->and($tens[0]->requests)->toBe(1000)
        ->and($tens[0]->status_5xx)->toBe(50)
        ->and(RequestStats::parse($tens[0]->latency))->toBe([0, 0, 0, 0, 0, 900, 0, 0, 100, 0, 0, 0, 0, 0, 0])
        ->and($day->requests)->toBe(3000)
        ->and($day->bytes_out)->toBe(1_800_000);
});

test('the project endpoint derives rates, error rate and percentiles per service and for the environment', function (): void {
    // Two edges served the same minute: their histograms add up.
    $other = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'hel-edge', 'roles' => ['edge']]);

    foreach ([$this->server, $other] as $server) {
        foreach (['11:57', '11:58', '11:59'] as $minute) {
            $sample = routeMinute($this->route, "2026-10-20T{$minute}:00Z");
            DB::table('request_metric_samples')->insert([
                'server_id' => $server->id, 'route_id' => $this->route, 'resolution' => 1, 'at' => "2026-10-20 {$minute}:00+00",
                'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'requests' => 120, 'status_2xx' => 114, 'status_5xx' => 6,
                'bytes_in' => 1000, 'bytes_out' => 60_000, 'latency' => RequestStats::literal($sample['latencyMs']),
            ]);
        }
    }

    // The previous hour had half the traffic.
    DB::table('request_metric_samples')->insert([
        'server_id' => $this->server->id, 'route_id' => $this->route, 'resolution' => 1, 'at' => '2026-10-20 10:30:00+00',
        'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'requests' => 360, 'status_2xx' => 360,
        'bytes_in' => 0, 'bytes_out' => 0, 'latency' => null,
    ]);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/metrics?range=1h")
        ->assertOk();

    $web = $response->json('services.0.requests');

    expect($web['requestsPerSecond'][58])->toEqual(4)       // 240 requests / 60 s
        ->and($web['requestsPerSecond'][50])->toEqual(0)    // no traffic in that minute
        ->and($web['errorRatePct'][58])->toEqual(5)
        ->and($web['p50Ms'][58])->toBeGreaterThan(25)->toBeLessThanOrEqual(50)
        ->and($web['p95Ms'][58])->toBeGreaterThan(250)->toBeLessThanOrEqual(500)
        ->and($web['bytesOutPerSecond'][58])->toEqual(2000)
        ->and($web['totals']['requests'])->toBe(720)
        ->and($web['totals']['status5xx'])->toBe(36)
        ->and($web['previous']['requests'])->toBe(360)
        ->and($web['previous']['p95Ms'])->toBeNull()
        ->and($response->json('totals.requests.totals.requests'))->toBe(720)
        ->and($response->json('totals.requests.totals.errorRatePct'))->toEqual(5);
});

test('services without traffic have no request series', function (): void {
    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/metrics?range=1h")
        ->assertOk();

    expect($response->json('services.0.requests'))->toBeNull()
        ->and($response->json('totals.requests'))->toBeNull();
});

test('deploy markers carry the service name and short commit', function (): void {
    Deployment::query()->where('service_instance_id', $this->instance->id)->update(['started_at' => '2026-10-20 11:40:00', 'commit_sha' => str_repeat('3b7d5e1a', 5)]);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/metrics?range=1h")
        ->assertOk();

    expect($response->json('deploys.0'))->toMatchArray(['serviceName' => 'web', 'sha' => '3b7d5e1']);
});

test('the server endpoint returns the public traffic of an edge and deploy markers of what ran there', function (): void {
    $sample = routeMinute($this->route, '2026-10-20T11:59:00Z');
    DB::table('request_metric_samples')->insert([
        'server_id' => $this->server->id, 'route_id' => $this->route, 'resolution' => 1, 'at' => '2026-10-20 11:59:00+00',
        'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'requests' => 600, 'status_2xx' => 600,
        'bytes_in' => 0, 'bytes_out' => 0, 'latency' => RequestStats::literal($sample['latencyMs']),
    ]);
    $placement = $this->instance->placements()->firstOrFail();
    DB::table('workload_metric_samples')->insert([
        'workload_id' => $placement->workload_id, 'resolution' => 1, 'at' => '2026-10-20 11:59:00+00', 'server_id' => $this->server->id,
        'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'replica' => 0, 'cpu_cores' => 0.1, 'mem_mb' => 100,
    ]);
    Deployment::query()->where('service_instance_id', $this->instance->id)->update(['started_at' => '2026-10-20 11:40:00']);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/servers/{$this->server->public_id}/-/metrics?range=1h")
        ->assertOk();

    expect($response->json('traffic.requestsPerSecond.58'))->toEqual(10)
        ->and($response->json('traffic.totals.requests'))->toBe(600)
        ->and($response->json('deploys'))->toHaveCount(1);
});

test('percentiles interpolate inside the bucket that holds them', function (): void {
    $histogram = array_fill(0, 15, 0);
    $histogram[4] = 100; // 10–25 ms

    expect(RequestStats::quantile($histogram, 0.5))->toEqual(17.5)
        ->and(RequestStats::quantile(array_fill(0, 15, 0), 0.5))->toBeNull()
        ->and(RequestStats::quantile([...array_fill(0, 14, 0), 3], 0.95))->toEqual(30000);
});

test('the project Metrics page renders for members and is team-scoped', function (): void {
    $environment = $this->project->production()->sole();

    $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->get("/p/{$this->project->public_id}/{$environment->public_id}/metrics")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/metrics')
            ->where('services.0.name', 'web')
            ->where('environment.id', $environment->public_id));

    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))
        ->get("/p/{$this->project->public_id}/{$environment->public_id}/metrics")
        ->assertNotFound();
});
