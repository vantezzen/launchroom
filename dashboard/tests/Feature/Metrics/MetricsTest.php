<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Metrics\RollupMetrics;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\EnrollRequest;
use App\Data\Servers\HostFactsData;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Events\Broadcasts\ServerMetricsUpdated;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Team;
use App\Support\Servers\DeviceClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        'name' => 'fsn1-web',
        'roles' => [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build],
        'sealing_public_key' => 'x25519:'.base64_encode(sodium_crypto_box_publickey($keypair)),
        'facts' => new HostFactsData('host', 'linux', 'arm64', 4, 8192),
        'public_ipv4' => '203.0.113.9',
    ]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    $this->workload = Placement::query()->where('server_id', $this->server->id)->sole()->workload_id;
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  list<array<string, mixed>>  $host
 * @param  list<array<string, mixed>>  $workloads
 */
function metricsReport(Server $server, array $host, array $workloads = [], int $seq = 1): array
{
    return [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'seq' => $seq, 'full' => true, 'appliedVersion' => 0, 'workloads' => [],
        'metrics' => ['host' => $host, 'workloads' => $workloads],
    ];
}

function hostMinute(string $at, float $cpu = 20.0, int $mem = 2048): array
{
    return ['at' => $at, 'samples' => 4, 'cpuPct' => $cpu, 'memUsedMb' => $mem, 'memTotalMb' => 8192, 'diskUsedMb' => 20_000, 'diskTotalMb' => 80_000, 'netRxBps' => 1000, 'netTxBps' => 500, 'load1' => 0.5];
}

test('report metrics are stored idempotently and attributed to the workload\'s service', function (): void {
    Event::fake([ServerMetricsUpdated::class]);
    $body = metricsReport($this->server, [hostMinute('2026-10-20T11:58:00Z'), hostMinute('2026-10-20T11:59:00Z', 40.0)], [
        ['workload' => $this->workload, 'at' => '2026-10-20T11:59:00Z', 'cpuCores' => 0.25, 'memMb' => 300],
        ['workload' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ', 'at' => '2026-10-20T11:59:00Z', 'memMb' => 99],
    ]);

    expect(ProtocolSchemas::errors('report', (string) json_encode($body)))->toBe([]);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $body)->assertNoContent();
    // The same minutes again (a lost acknowledgement) overwrite themselves.
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', [...$body, 'seq' => 2])->assertNoContent();

    $instance = $this->project->production()->sole()->serviceInstances()->sole();

    expect(DB::table('server_metric_samples')->where('server_id', $this->server->id)->count())->toBe(2)
        ->and(DB::table('workload_metric_samples')->count())->toBe(1)
        ->and(DB::table('workload_metric_samples')->sole())->toMatchArray([
            'workload_id' => $this->workload, 'resolution' => 1, 'service_instance_id' => $instance->id, 'project_id' => $this->project->id, 'mem_mb' => 300,
        ]);

    Event::assertDispatched(ServerMetricsUpdated::class, fn (ServerMetricsUpdated $e): bool => $e->serverId === $this->server->public_id && $e->teamId === $this->team->public_id);
});

test('odd metric samples are dropped without failing the report', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', metricsReport($this->server, [
        hostMinute('2026-10-20T11:59:30Z'),             // not a whole minute
        hostMinute('2026-10-19T00:00:00Z'),             // too old
        ['at' => '2026-10-20T11:59:00Z', 'samples' => 4, 'cpuPct' => 'lots', 'memUsedMb' => -5],
    ]))->assertNoContent();

    expect(DB::table('server_metric_samples')->sole())->toMatchArray(['cpu_pct' => null, 'mem_used_mb' => null]);
});

test('the Go metrics and hardware fixtures are accepted and stored', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.metrics.json'), true);
    $fixture['serverId'] = $this->server->public_id;
    CarbonImmutable::setTestNow('2026-10-17 12:02:05');

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $fixture)->assertNoContent();

    $server = $this->server->refresh();

    expect(DB::table('server_metric_samples')->where('server_id', $server->id)->count())->toBe(2)
        ->and($server->facts->hardware?->dmi?->sysVendor)->toBe('Hetzner')
        ->and($server->facts->hardware?->timezone)->toBe('Europe/Berlin')
        ->and($server->facts->hostname)->toBe('host');
});

test('hardware facts with control characters are refused', function (): void {
    $body = [...metricsReport($this->server, []), 'host' => ['cpus' => 4, 'memoryMb' => 8192, 'diskTotalMb' => 1, 'diskFreeMb' => 1, 'daemonVersion' => '0.5.0', 'hardware' => ['cpuModel' => "evil\u{0007}"]]];

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $body)->assertUnprocessable();
});

test('rollups fold three days of minutes into 10-minute and daily rows and retention prunes them', function (): void {
    $start = CarbonImmutable::parse('2026-10-17 00:00:00');
    $instance = $this->project->production()->sole()->serviceInstances()->sole();
    $host = [];
    $work = [];

    for ($t = $start; $t->lessThan($start->addDays(3)); $t = $t->addMinutes(1)) {
        $host[] = ['server_id' => $this->server->id, 'resolution' => 1, 'at' => $t->toIso8601String(), 'cpu_pct' => $t->minute % 10 < 5 ? 10.0 : 30.0, 'mem_used_mb' => 1000, 'mem_total_mb' => 8192, 'disk_used_mb' => 5000, 'disk_total_mb' => 80_000, 'net_rx_bps' => 100, 'net_tx_bps' => 50, 'load1' => 1.0];
        $work[] = ['workload_id' => $this->workload, 'resolution' => 1, 'at' => $t->toIso8601String(), 'server_id' => $this->server->id, 'service_instance_id' => $instance->id, 'project_id' => $this->project->id, 'replica' => 0, 'cpu_cores' => 0.5, 'mem_mb' => 200];
    }

    foreach (array_chunk($host, 1000) as $chunk) {
        DB::table('server_metric_samples')->insert($chunk);
    }

    foreach (array_chunk($work, 1000) as $chunk) {
        DB::table('workload_metric_samples')->insert($chunk);
    }

    // Hourly runs (the scheduler runs every 10 minutes; each run recomputes the last 3 hours).
    for ($t = $start->addHour(); $t->lessThanOrEqualTo($start->addDays(3)); $t = $t->addHour()) {
        resolve(RollupMetrics::class)->handle($t);
    }

    $tenMinute = DB::table('server_metric_samples')->where('resolution', 10)->orderBy('at')->get();
    $daily = DB::table('server_metric_samples')->where('resolution', 1440)->orderBy('at')->get();

    expect($tenMinute)->toHaveCount(3 * 144)
        ->and((float) $tenMinute->first()->cpu_pct)->toBe(20.0)
        ->and($tenMinute->first()->mem_total_mb)->toBe(8192)
        ->and($daily)->toHaveCount(3)
        ->and((float) $daily->first()->cpu_pct)->toBe(20.0)
        ->and(DB::table('workload_metric_samples')->where('resolution', 10)->count())->toBe(3 * 144)
        ->and(DB::table('workload_metric_samples')->where('resolution', 1440)->count())->toBe(3)
        // 1-minute rows older than 48 h are gone (the last run was at day 3 00:00).
        ->and(DB::table('server_metric_samples')->where('resolution', 1)->min('at'))->toStartWith('2026-10-18');

    // Idempotent: running again changes nothing.
    $before = DB::table('server_metric_samples')->where('resolution', 10)->count();
    resolve(RollupMetrics::class)->handle($start->addDays(3));

    expect(DB::table('server_metric_samples')->where('resolution', 10)->count())->toBe($before);

    // A year later everything is pruned.
    resolve(RollupMetrics::class)->handle($start->addDays(400));

    expect(DB::table('server_metric_samples')->count())->toBe(0)
        ->and(DB::table('workload_metric_samples')->count())->toBe(0);
});

test('the fleet endpoint returns aligned series, current values and per-project usage', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', metricsReport($this->server, [hostMinute('2026-10-20T11:58:00Z', 10.0, 4096), hostMinute('2026-10-20T11:59:00Z', 30.0, 2048)], [
        ['workload' => $this->workload, 'at' => '2026-10-20T11:59:00Z', 'cpuCores' => 0.25, 'memMb' => 512],
    ]))->assertNoContent();
    $empty = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'zz-empty']);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Developer))->getJson('/servers/-/metrics?range=1h')->assertOk();
    $servers = collect($response->json('servers'))->keyBy('serverId');

    expect($response->json('range'))->toBe('1h')
        ->and($response->json('bucketSeconds'))->toBe(60)
        // The current minute has no data yet, so 59 buckets.
        ->and($response->json('timestamps'))->toHaveCount(59)
        ->and($response->json('timestamps.58'))->toBe('2026-10-20T11:59:00+00:00')
        ->and($servers[$this->server->public_id]['cpuPct'][58])->toEqual(30)
        ->and($servers[$this->server->public_id]['cpuPct'][57])->toEqual(10)
        ->and($servers[$this->server->public_id]['memPct'][58])->toEqual(25)
        ->and($servers[$this->server->public_id]['current']['memUsedMb'])->toBe(2048)
        ->and($servers[$this->server->public_id]['usedBy'][0])->toMatchArray(['projectId' => $this->project->public_id, 'name' => 'Shop', 'memMb' => 512, 'memShare' => 0.0625])
        // No data: empty series hide the charts.
        ->and($servers[$empty->public_id]['cpuPct'])->toBe([])
        ->and($servers[$empty->public_id]['current'])->toBeNull()
        ->and($servers[$empty->public_id]['usedBy'])->toBe([]);
});

test('the fleet endpoint sums the system load, CPU weighted by cores', function (): void {
    // fsn1-web: 4 cores at 50 %, 2 of 8 GB, 20 of 80 GB. big: 12 cores at 10 %, 6 of 24 GB, 40 of 120 GB.
    $big = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'big', 'facts' => new HostFactsData('big', 'linux', 'amd64', 12, 24_576)]);
    $row = static fn (Server $server, string $at, float $cpu, int $mem, int $memTotal, int $disk, int $diskTotal): array => ['server_id' => $server->id, 'resolution' => 1, 'at' => $at, 'cpu_pct' => $cpu, 'mem_used_mb' => $mem, 'mem_total_mb' => $memTotal, 'disk_used_mb' => $disk, 'disk_total_mb' => $diskTotal, 'net_rx_bps' => 0, 'net_tx_bps' => 0, 'load1' => 0.1];
    DB::table('server_metric_samples')->insert([
        $row($this->server, '2026-10-20T11:58:00Z', 50.0, 2048, 8192, 20_000, 80_000),
        $row($this->server, '2026-10-20T11:59:00Z', 50.0, 2048, 8192, 20_000, 80_000),
        $row($big, '2026-10-20T11:59:00Z', 10.0, 6144, 24_576, 40_000, 120_000),
    ]);
    // Another team's server never counts.
    Server::factory()->online()->create();

    $totals = $this->actingAs(memberOf($this->team, TeamRole::Viewer))->getJson('/servers/-/metrics?range=1h')->assertOk()->json('totals');

    // 11:58 only fsn1-web reported: its own share, not a dip. 11:59: (50·4 + 10·12) / 16 = 20 %.
    expect($totals['cpuPct'][57])->toEqual(50)
        ->and($totals['cpuPct'][58])->toEqual(20)
        ->and($totals['memPct'][58])->toEqual(25)
        ->and($totals['diskPct'][58])->toEqual(30)
        ->and($totals['current'])->toMatchArray([
            'servers' => 2, 'cpuCores' => 16, 'cpuUsedCores' => 3.2, 'cpuPct' => 20,
            'memUsedMb' => 8192, 'memTotalMb' => 32_768, 'memPct' => 25,
            'diskUsedMb' => 60_000, 'diskTotalMb' => 200_000, 'diskPct' => 30,
        ]);
});

test('the fleet totals are empty without samples', function (): void {
    $totals = $this->actingAs(memberOf($this->team, TeamRole::Viewer))->getJson('/servers/-/metrics?range=1h')->assertOk()->json('totals');

    expect($totals)->toBe(['cpuPct' => [], 'memPct' => [], 'diskPct' => [], 'current' => null]);
});

test('the server endpoint lists what runs there and the project endpoint per service and replica', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', metricsReport($this->server, [hostMinute('2026-10-20T11:59:00Z')], [
        ['workload' => $this->workload, 'at' => '2026-10-20T11:58:00Z', 'cpuCores' => 0.5, 'memMb' => 256],
        ['workload' => $this->workload, 'at' => '2026-10-20T11:59:00Z', 'cpuCores' => 0.25, 'memMb' => 512],
    ]))->assertNoContent();
    $user = memberOf($this->team, TeamRole::Viewer);
    $service = $this->project->services()->sole();

    $server = $this->actingAs($user)->getJson("/servers/{$this->server->public_id}/-/metrics?range=6h")->assertOk();

    expect($server->json('bucketSeconds'))->toBe(180)
        ->and($server->json('host.cpuPct'))->not->toBe([])
        ->and($server->json('services.0'))->toMatchArray(['serviceId' => $service->public_id, 'serviceName' => 'web', 'projectName' => 'Shop', 'currentMemMb' => 512])
        ->and($server->json('services.0.memMb'))->toContain(384);

    $project = $this->actingAs($user)->getJson("/p/{$this->project->public_id}/-/metrics?range=1h&service={$service->public_id}")->assertOk();

    expect($project->json('environmentId'))->toBe($this->project->production()->sole()->public_id)
        ->and($project->json('services'))->toHaveCount(1)
        ->and($project->json('services.0.currentCpuCores'))->toEqual(0.25)
        ->and($project->json('services.0.replicas.0.replica'))->toBe(0)
        ->and($project->json('services.0.replicas.0.memMb'))->toContain(512);

    $all = $this->actingAs($user)->getJson("/p/{$this->project->public_id}/-/metrics")->assertOk();

    expect($all->json('services.0.replicas'))->toBe([])
        ->and($all->json('range'))->toBe('1h');
});

test('metrics endpoints are team-scoped and validate their input', function (): void {
    $outsider = memberOf(Team::factory()->create(), TeamRole::Developer);
    $member = memberOf($this->team, TeamRole::Developer);
    $otherProject = Projects::create(Team::factory()->create(), 'Other');

    $this->getJson('/servers/-/metrics')->assertUnauthorized();

    $this->actingAs($outsider)->getJson("/servers/{$this->server->public_id}/-/metrics")->assertNotFound();
    $this->actingAs($outsider)->getJson("/p/{$this->project->public_id}/-/metrics")->assertNotFound();
    $this->actingAs($outsider)->getJson('/servers/-/metrics')->assertOk()->assertJsonPath('servers', []);
    $this->actingAs($member)->getJson('/servers/-/metrics?range=5y')->assertUnprocessable();
    $this->actingAs($member)->getJson("/p/{$this->project->public_id}/-/metrics?environment=".$otherProject->production()->sole()->public_id)->assertNotFound();
    $this->actingAs($member)->getJson("/p/{$this->project->public_id}/-/metrics?service=".Projects::image($otherProject, 'api')->public_id)->assertNotFound();
});

test('admins override the device and confirm the location; others cannot', function (): void {
    $admin = memberOf($this->team, TeamRole::Admin);
    $developer = memberOf($this->team, TeamRole::Developer);
    $url = "/servers/{$this->server->public_id}/appearance";

    $this->actingAs($developer)->patchJson($url, ['device' => 'compact'])->assertForbidden();
    $this->actingAs($admin)->patchJson($url, ['device' => 'toaster'])->assertUnprocessable();
    $this->actingAs($admin)->patchJson($url, ['location' => ['label' => '<b>', 'latitude' => 1, 'longitude' => 2]])->assertUnprocessable();

    // Suggested from the server name ("fsn1-web") until confirmed.
    $show = $this->actingAs($admin)->get("/servers/{$this->server->public_id}")->assertOk();
    $props = $show->viewData('page')['props']['server'];

    expect($props['location'])->toMatchArray(['label' => 'Falkenstein', 'source' => 'region', 'confirmed' => false])
        ->and($props['canUpdateAppearance'])->toBeTrue()
        ->and($props['device']['kind'])->toBe('unknown');

    $this->actingAs($admin)->patch($url, ['device' => 'compact', 'location' => ['label' => 'Berlin, at home', 'latitude' => 52.52, 'longitude' => 13.4, 'countryCode' => 'DE']])->assertRedirect();

    $server = $this->server->refresh();
    $props = $this->actingAs($admin)->get("/servers/{$server->public_id}")->viewData('page')['props']['server'];

    expect($props['device'])->toMatchArray(['kind' => 'compact', 'detected' => 'unknown', 'overridden' => true])
        ->and($props['location'])->toMatchArray(['label' => 'Berlin, at home', 'source' => 'user', 'confirmed' => true])
        ->and(DB::table('audit_events')->where('action', 'server.appearance_updated')->count())->toBe(1);

    // Back to automatic.
    $this->actingAs($admin)->patch($url, ['device' => null, 'location' => null])->assertRedirect();

    expect($this->server->refresh()->device_override)->toBeNull()
        ->and($this->server->location)->toBeNull();
});

test('enrollment hardware facts are stored and classify the server', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/enroll-request.hardware.json'), true);
    $facts = EnrollRequest::validateAndCreate($fixture)->host->toStored();

    expect($facts->hardware?->deviceTreeModel)->toBe('Raspberry Pi 5 Model B Rev 1.0')
        ->and(DeviceClassifier::classify($facts->hardware)->model)->toBe('Raspberry Pi 5');
});
