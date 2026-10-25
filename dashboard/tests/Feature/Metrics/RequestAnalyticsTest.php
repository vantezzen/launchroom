<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Metrics\RollupRequestAnalytics;
use App\Data\Audit\AuditActor;
use App\Data\Servers\HostFactsData;
use App\Enums\ApiAbility;
use App\Enums\DaemonFeature;
use App\Enums\DomainKind;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Team;
use App\Support\Metrics\AnalyticsRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;
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
        'daemon_features' => [DaemonFeature::RequestAnalytics->value],
    ]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    $this->environment = $this->project->production()->sole();
    $this->instance = $this->environment->serviceInstances()->sole();
    $this->domain = Domain::query()->create([
        'team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'shop.example.com', 'kind' => DomainKind::Custom,
    ]);
    $this->route = Domain::routeIdFor($this->domain->public_id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  list<array<string, mixed>>  $analytics
 * @return array<string, mixed>
 */
function analyticsReport(Server $server, array $analytics, int $seq = 1): array
{
    return [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'seq' => $seq, 'full' => true, 'appliedVersion' => 0, 'workloads' => [],
        'metrics' => ['host' => [], 'workloads' => [], 'analytics' => $analytics],
    ];
}

/**
 * One analytics window: 100 requests, 60 on "/", 30 on "/users/:id" (3 4xx, 1 5xx), 10 elsewhere.
 *
 * @return array<string, mixed>
 */
function analyticsWindow(string $route, string $at): array
{
    return [
        'route' => $route, 'at' => $at, 'requests' => 100,
        'paths' => [['path' => '/', 'requests' => 60], ['path' => '/users/:id', 'requests' => 30, 'status4xx' => 3, 'status5xx' => 1]],
        'otherPaths' => ['requests' => 10, 'status4xx' => 2],
        'referrers' => [['host' => 'news.ycombinator.com', 'requests' => 12]],
        'otherReferrers' => 1,
        'browsers' => 80, 'bots' => 15, 'otherAgents' => 5,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function analyticsRow(object $test, int $resolution, string $at, array $overrides = []): void
{
    DB::table('request_analytics_samples')->insert([
        'server_id' => $test->server->id, 'route_id' => $test->route, 'resolution' => $resolution, 'at' => $at,
        'service_instance_id' => $test->instance->id, 'project_id' => $test->project->id, 'requests' => 100,
        'paths' => json_encode([['p' => '/', 'r' => 60, 'e4' => 0, 'e5' => 0], ['p' => '/users/:id', 'r' => 30, 'e4' => 3, 'e5' => 1]]),
        'other_requests' => 10, 'other_4xx' => 2, 'other_5xx' => 0,
        'referrers' => json_encode([['h' => 'news.ycombinator.com', 'r' => 12]]), 'other_referrers' => 1,
        'browsers' => 80, 'bots' => 15, 'other_agents' => 5,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function requestRow(object $test, int $resolution, string $at, array $overrides = []): void
{
    DB::table('request_metric_samples')->insert([
        'server_id' => $test->server->id, 'route_id' => $test->route, 'resolution' => $resolution, 'at' => $at,
        'service_instance_id' => $test->instance->id, 'project_id' => $test->project->id, 'requests' => 100,
        'status_2xx' => 90, 'status_3xx' => 2, 'status_4xx' => 5, 'status_5xx' => 3, 'bytes_in' => 0, 'bytes_out' => 0, 'latency' => null,
        ...$overrides,
    ]);
}

test('analytics windows are stored idempotently and attributed to the route\'s service', function (): void {
    $body = analyticsReport($this->server, [
        analyticsWindow($this->route, '2026-10-20T11:40:00Z'),
        analyticsWindow($this->route, '2026-10-20T11:50:00Z'),
        analyticsWindow('rt_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ', '2026-10-20T11:50:00Z'),
    ]);

    expect(ProtocolSchemas::errors('report', (string) json_encode($body)))->toBe([]);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $body)->assertNoContent();
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', [...$body, 'seq' => 2])->assertNoContent();

    $rows = DB::table('request_analytics_samples')->orderBy('at')->get();

    expect($rows)->toHaveCount(2)
        ->and((array) $rows[0])->toMatchArray([
            'server_id' => $this->server->id, 'route_id' => $this->route, 'resolution' => 10, 'service_instance_id' => $this->instance->id,
            'project_id' => $this->project->id, 'requests' => 100, 'other_requests' => 10, 'other_4xx' => 2, 'other_referrers' => 1,
            'browsers' => 80, 'bots' => 15, 'other_agents' => 5,
        ])
        ->and(json_decode($rows[0]->paths, true))->toBe([['p' => '/', 'r' => 60, 'e4' => 0, 'e5' => 0], ['p' => '/users/:id', 'r' => 30, 'e4' => 3, 'e5' => 1]])
        ->and(json_decode($rows[0]->referrers, true))->toBe([['h' => 'news.ycombinator.com', 'r' => 12]]);
});

test('analytics windows that do not add up or carry raw URLs are dropped without failing the report', function (): void {
    $window = analyticsWindow($this->route, '2026-10-20T11:50:00Z');

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', analyticsReport($this->server, [
        [...$window, 'at' => '2026-10-20T11:20:00Z', 'requests' => 99],                                                  // paths ≠ requests
        [...$window, 'at' => '2026-10-20T11:25:00Z'],                                                                     // not a 10-minute window
        [...$window, 'at' => '2026-10-20T11:30:00Z', 'paths' => [['path' => '/search?q=secret', 'requests' => 90]]],    // query string
        [...$window, 'at' => '2026-10-20T11:40:00Z', 'referrers' => [['host' => 'https://evil.example/x', 'requests' => 1]]], // a URL, not a host
        [...$window, 'at' => '2026-10-20T10:40:00Z', 'browsers' => 200],                                                  // agents > requests
        $window,
    ]))->assertNoContent();

    expect(DB::table('request_analytics_samples')->sole()->at)->toStartWith('2026-10-20 11:50');
});

test('rollups merge windows into hours and days, keep the top paths and fold the rest into other', function (): void {
    // 60 distinct paths over six windows of one hour: the hourly row keeps the top 50.
    for ($w = 0; $w < 6; $w++) {
        $paths = [];

        for ($p = 0; $p < 10; $p++) {
            $n = $w * 10 + $p;
            $paths[] = ['p' => "/page-{$n}", 'r' => 100 + $n, 'e4' => 0, 'e5' => $n === 59 ? 2 : 0];
        }

        analyticsRow($this, RollupRequestAnalytics::TEN_MINUTES, CarbonImmutable::parse('2026-10-20 10:00:00')->addMinutes($w * 10)->toIso8601String(), [
            'requests' => array_sum(array_column($paths, 'r')) + 5, 'paths' => json_encode($paths), 'other_requests' => 5, 'other_4xx' => 0,
            'referrers' => json_encode([['h' => "site-{$w}.example", 'r' => 3]]),
        ]);
    }

    $result = resolve(RollupRequestAnalytics::class)->handle(CarbonImmutable::parse('2026-10-20 12:00:00'));
    resolve(RollupRequestAnalytics::class)->handle(CarbonImmutable::parse('2026-10-20 12:00:00'));

    $hour = DB::table('request_analytics_samples')->where('resolution', RollupRequestAnalytics::HOUR)->sole();
    $day = DB::table('request_analytics_samples')->where('resolution', RollupRequestAnalytics::DAY)->sole();
    $paths = json_decode($hour->paths, true);
    $total = array_sum(array_map(static fn (int $n): int => 100 + $n, range(0, 59))) + 30;

    expect($result['rolledUp'])->toBeGreaterThan(0)
        ->and($hour->at)->toStartWith('2026-10-20 10:00')
        ->and($hour->requests)->toBe($total)
        ->and($paths)->toHaveCount(AnalyticsRow::TOP_PATHS)
        ->and($paths[0])->toBe(['p' => '/page-59', 'r' => 159, 'e4' => 0, 'e5' => 2])
        ->and(array_sum(array_column($paths, 'r')) + $hour->other_requests)->toBe($total)
        ->and(json_decode($hour->referrers, true))->toHaveCount(6)
        ->and($day->requests)->toBe($total)
        ->and($day->at)->toStartWith('2026-10-20 00:00');
});

test('retention keeps 10-minute rows 48 hours, hourly rows 7 days and daily rows 90 days by default', function (): void {
    $now = CarbonImmutable::parse('2026-10-20 12:00:00');
    analyticsRow($this, 10, $now->subHours(49)->toIso8601String());
    analyticsRow($this, 10, $now->subHours(47)->toIso8601String());
    analyticsRow($this, 60, $now->subDays(8)->toIso8601String());
    analyticsRow($this, 60, $now->subDays(6)->toIso8601String());
    analyticsRow($this, 1440, $now->subDays(91)->startOfDay()->toIso8601String());
    analyticsRow($this, 1440, $now->subDays(89)->startOfDay()->toIso8601String());

    $result = resolve(RollupRequestAnalytics::class)->handle($now);

    expect($result['pruned'])->toBe(3)
        ->and(DB::table('request_analytics_samples')->where('at', '<', $now->subHours(48)->toIso8601String())->where('resolution', 10)->count())->toBe(0);

    config(['launchroom.analytics.hourly_retention_days' => 1]);
    resolve(RollupRequestAnalytics::class)->handle($now);

    expect(DB::table('request_analytics_samples')->where('resolution', 60)->where('at', '<', $now->subDay()->toIso8601String())->count())->toBe(0);
});

test('the analytics endpoint counts requests per hour by status class with the top paths', function (): void {
    $other = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'hel-edge', 'roles' => ['edge']]);

    // Recent minutes come from 1-minute rows, older hours from 10-minute rows.
    requestRow($this, 1, '2026-10-20 11:59:00+00');
    requestRow($this, 1, '2026-10-20 11:59:00+00', ['server_id' => $other->id, 'requests' => 50, 'status_2xx' => 50, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0]);
    requestRow($this, 10, '2026-10-20 08:10:00+00');
    // Ignored: a 1-minute row whose 10-minute rollup already counts it.
    requestRow($this, 1, '2026-10-20 08:10:00+00');
    // The previous week.
    requestRow($this, 10, '2026-10-12 08:10:00+00');
    requestRow($this, 10, '2026-10-10 08:10:00+00', ['requests' => 125, 'status_2xx' => 125, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0]);

    analyticsRow($this, 10, '2026-10-20 11:50:00+00');
    analyticsRow($this, 60, '2026-10-20 08:00:00+00');
    analyticsRow($this, 60, '2026-10-20 08:00:00+00', ['server_id' => $other->id, 'paths' => json_encode([['p' => '/checkout', 'r' => 100, 'e4' => 0, 'e5' => 20]]), 'other_requests' => 0, 'other_4xx' => 0, 'referrers' => '[]', 'other_referrers' => 0]);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/analytics?range=7d")
        ->assertOk();

    expect($response->json('timestamps'))->toHaveCount(168)
        ->and($response->json('timestamps.167'))->toBe('2026-10-20T12:00:00+00:00')
        ->and($response->json('status2xx.166'))->toBe(140)
        ->and($response->json('status5xx.166'))->toBe(3)
        ->and($response->json('status2xx.163'))->toBe(90)
        ->and($response->json('totals'))->toMatchArray([
            'requests' => 250, 'status2xx' => 230, 'status3xx' => 4, 'status4xx' => 10, 'status5xx' => 6,
            'errorRatePct' => 2.4, 'previousRequests' => 225, 'previousErrorRatePct' => 1.33,
        ])
        ->and($response->json('servers'))->toHaveCount(2)
        ->and($response->json('servers.0'))->toMatchArray(['id' => $this->server->public_id, 'name' => 'fsn1-edge', 'requests' => 200])
        ->and($response->json('services.0'))->toMatchArray(['name' => 'web', 'requests' => 250])
        ->and($response->json('paths.0'))->toBe(['path' => '/', 'requests' => 120, 'status4xx' => 0, 'status5xx' => 0])
        ->and($response->json('paths.1'))->toBe(['path' => '/checkout', 'requests' => 100, 'status4xx' => 0, 'status5xx' => 20])
        ->and($response->json('pathRequests'))->toBe(300)
        ->and($response->json('referrers.0'))->toBe(['host' => 'news.ycombinator.com', 'requests' => 24])
        ->and($response->json('referrerRequests'))->toBe(26)
        ->and($response->json('agents'))->toBe(['browsers' => 240, 'bots' => 45, 'other' => 15])
        ->and($response->json('serversWithoutPaths'))->toBe(['hel-edge']);
});

test('the 30-day range reads daily rows before yesterday', function (): void {
    requestRow($this, 1440, '2026-10-01 00:00:00+00', ['requests' => 1000, 'status_2xx' => 1000, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0]);
    requestRow($this, 10, '2026-10-01 08:00:00+00'); // already in the daily row
    requestRow($this, 10, '2026-10-19 08:00:00+00');
    analyticsRow($this, 1440, '2026-10-01 00:00:00+00');

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/analytics?range=30d")
        ->assertOk();

    expect($response->json('bucketSeconds'))->toBe(86400)
        ->and($response->json('timestamps'))->toHaveCount(30)
        ->and($response->json('totals.requests'))->toBe(1100)
        ->and($response->json('pathRequests'))->toBe(100);
});

test('the service filter narrows to one service and is scoped to the project', function (): void {
    $api = Projects::image($this->project, 'api');
    $apiInstance = $api->instances()->where('environment_id', $this->environment->id)->sole();
    requestRow($this, 1, '2026-10-20 11:59:00+00');
    requestRow($this, 1, '2026-10-20 11:59:00+00', ['service_instance_id' => $apiInstance->id, 'route_id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9AP']);
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($viewer)->getJson("/p/{$this->project->public_id}/-/analytics?range=24h&service={$api->public_id}")
        ->assertOk()
        ->assertJsonPath('totals.requests', 100)
        ->assertJsonPath('services.0.name', 'api');

    $this->actingAs($viewer)->getJson("/p/{$this->project->public_id}/-/analytics?range=24h")->assertJsonPath('totals.requests', 200);

    $foreign = Projects::image(Projects::create($this->team, 'Other'), 'worker');
    $this->actingAs($viewer)->getJson("/p/{$this->project->public_id}/-/analytics?service={$foreign->public_id}")->assertNotFound();
    $this->actingAs($viewer)->getJson("/p/{$this->project->public_id}/-/analytics?range=1y")->assertUnprocessable();
});

test('the Analytics page and endpoint are team-scoped', function (): void {
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->get("/p/{$this->project->public_id}/{$this->environment->public_id}/analytics?service=".$this->instance->service->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/analytics')
            ->where('services.0.name', 'web')
            ->where('initialService', $this->instance->service->public_id));

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($outsider)->get("/p/{$this->project->public_id}/{$this->environment->public_id}/analytics")->assertNotFound();
    $this->actingAs($outsider)->getJson("/p/{$this->project->public_id}/-/analytics")->assertNotFound();
});

test('the public API returns analytics with the read ability', function (): void {
    requestRow($this, 1, '2026-10-20 11:59:00+00');
    analyticsRow($this, 10, '2026-10-20 11:50:00+00');
    $plain = Api::token(memberOf($this->team, TeamRole::Viewer), $this->team, [ApiAbility::Read]);
    $env = $this->environment->public_id;
    $service = $this->instance->service->public_id;

    Api::get("environments/{$env}/analytics?range=24h", $plain)
        ->assertOk()
        ->assertJsonPath('data.totals.requests', 100)
        ->assertJsonPath('data.paths.0.path', '/');
    Api::get("environments/{$env}/services/{$service}/analytics", $plain)
        ->assertOk()
        ->assertJsonPath('data.range', '7d')
        ->assertJsonPath('data.services.0.id', $service);

    $stranger = Api::token(memberOf($other = Team::factory()->create(), TeamRole::Owner), $other, [ApiAbility::Read]);
    Api::get("environments/{$env}/analytics", $stranger)->assertNotFound();
});

test('project cards show production requests of the last 24 hours', function (): void {
    requestRow($this, 1, '2026-10-20 11:59:00+00');
    requestRow($this, 10, '2026-10-20 02:00:00+00', ['requests' => 40]);

    $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->get('/projects')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/index')
            ->where('projects.0.requests.22', 100)
            ->where('projects.0.requests.13', 40));
});

test('the team overview groups requests per project across all of the team\'s projects', function (): void {
    $blog = Projects::create($this->team, 'Blog');
    $blogInstance = Projects::image($blog, 'site')->instances()->sole();
    $blogRoute = 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9BL';
    $other = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'hel-edge', 'roles' => ['edge']]);

    requestRow($this, 1, '2026-10-20 11:59:00+00');                    // shop, recent tier
    requestRow($this, 10, '2026-10-20 08:10:00+00');                   // shop, 10-minute tier
    requestRow($this, 1, '2026-10-20 08:10:00+00');                    // ignored: covered by the 10-minute row
    requestRow($this, 10, '2026-10-12 08:10:00+00', ['requests' => 50, 'status_2xx' => 50, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0]); // previous week
    requestRow($this, 1, '2026-10-20 11:59:00+00', [
        'server_id' => $other->id, 'route_id' => $blogRoute, 'service_instance_id' => $blogInstance->id, 'project_id' => $blog->id,
        'requests' => 40, 'status_2xx' => 40, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0,
    ]);
    analyticsRow($this, 10, '2026-10-20 11:50:00+00');
    analyticsRow($this, 10, '2026-10-20 11:50:00+00', [
        'route_id' => $blogRoute, 'service_instance_id' => $blogInstance->id, 'project_id' => $blog->id, 'requests' => 40,
        'paths' => json_encode([['p' => '/posts/:id', 'r' => 40, 'e4' => 0, 'e5' => 0]]), 'other_requests' => 0, 'other_4xx' => 0,
    ]);

    // Another team's traffic never shows up.
    $stranger = Projects::create(Team::factory()->create(), 'Foreign');
    $strangerInstance = Projects::image($stranger, 'web')->instances()->sole();
    requestRow($this, 1, '2026-10-20 11:59:00+00', [
        'route_id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9FO', 'service_instance_id' => $strangerInstance->id, 'project_id' => $stranger->id, 'requests' => 999, 'status_2xx' => 999, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0,
    ]);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson('/analytics/-/data?range=7d')
        ->assertOk();

    expect($response->json('timestamps'))->toHaveCount(168)
        ->and($response->json('totals'))->toMatchArray(['requests' => 240, 'status4xx' => 10, 'status5xx' => 6, 'previousRequests' => 50])
        ->and($response->json('projects'))->toHaveCount(2)
        ->and($response->json('projects.0'))->toMatchArray([
            'id' => $this->project->public_id, 'name' => 'Shop', 'environmentId' => $this->environment->public_id,
            'requests' => 200, 'previousRequests' => 50, 'errorRatePct' => 3, 'clientErrorPct' => 5,
        ])
        ->and($response->json('projects.0.requestsSeries.166'))->toBe(100)
        ->and($response->json('projects.0.requestsSeries.163'))->toBe(100)
        ->and($response->json('projects.1'))->toMatchArray(['name' => 'Blog', 'requests' => 40, 'previousRequests' => null])
        ->and($response->json('projects.1.requestsSeries.166'))->toBe(40)
        ->and(array_column($response->json('servers'), 'requests', 'name'))->toBe(['fsn1-edge' => 200, 'hel-edge' => 40])
        ->and($response->json('paths.0'))->toMatchArray(['projectName' => 'Shop', 'path' => '/', 'requests' => 60])
        ->and(array_column($response->json('paths'), 'path'))->toContain('/posts/:id')
        ->and($response->json('pathRequests'))->toBe(140);
});

test('the team overview is empty without traffic and reads the current team only', function (): void {
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    requestRow($this, 1, '2026-10-20 11:59:00+00');

    $this->actingAs($outsider)->getJson('/analytics/-/data?range=24h')
        ->assertOk()
        ->assertJsonPath('totals.requests', 0)
        ->assertJsonPath('projects', [])
        ->assertJsonPath('paths', []);

    $this->actingAs(memberOf($this->team, TeamRole::Viewer))->get('/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('analytics')->where('team.name', $this->team->name));
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))->getJson('/analytics/-/data?range=1y')->assertUnprocessable();
});

test('the drill-down links resolve: project analytics and one service by URL', function (): void {
    $service = $this->instance->service->public_id;
    $viewer = memberOf($this->team, TeamRole::Viewer);
    requestRow($this, 1, '2026-10-20 11:59:00+00');

    $this->actingAs($viewer)->get("/p/{$this->project->public_id}/{$this->environment->public_id}/analytics")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/analytics')->where('initialService', null)->where('project.name', 'Shop'));
    $this->actingAs($viewer)->get("/p/{$this->project->public_id}/{$this->environment->public_id}/analytics?service={$service}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('initialService', $service));
    // A service of another project is not preselected.
    $foreign = Projects::image(Projects::create($this->team, 'Other'), 'worker');
    $this->actingAs($viewer)->get("/p/{$this->project->public_id}/{$this->environment->public_id}/analytics?service={$foreign->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('initialService', null));
});

test('the public API returns the team overview of the token\'s team', function (): void {
    requestRow($this, 1, '2026-10-20 11:59:00+00');
    $plain = Api::token(memberOf($this->team, TeamRole::Viewer), $this->team, [ApiAbility::Read]);

    Api::get('analytics?range=24h', $plain)
        ->assertOk()
        ->assertJsonPath('data.totals.requests', 100)
        ->assertJsonPath('data.projects.0.name', 'Shop');

    $stranger = Api::token(memberOf($other = Team::factory()->create(), TeamRole::Owner), $other, [ApiAbility::Read]);
    Api::get('analytics', $stranger)->assertOk()->assertJsonPath('data.projects', []);
});

test('a removed server keeps its own row in the server split, marked removed, even when a new server took its name', function (): void {
    $old = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'fsn1-edge', 'roles' => [], 'status' => ServerStatus::Revoked, 'revoked_at' => '2026-10-18 21:00:00']);
    requestRow($this, 10, '2026-10-19 08:10:00+00', ['server_id' => $old->id, 'requests' => 300, 'status_2xx' => 300, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0]);
    requestRow($this, 1, '2026-10-20 11:59:00+00');
    $viewer = memberOf($this->team, TeamRole::Viewer);

    foreach (['/analytics/-/data?range=7d', "/p/{$this->project->public_id}/-/analytics?range=7d"] as $url) {
        $servers = $this->actingAs($viewer)->getJson($url)->assertOk()->json('servers');

        expect($servers)->toHaveCount(2)
            ->and(array_column($servers, 'id'))->toBe([$old->public_id, $this->server->public_id])
            ->and(array_column($servers, 'name'))->toBe(['fsn1-edge', 'fsn1-edge'])
            ->and(array_column($servers, 'removed'))->toBe([true, false])
            ->and(array_column($servers, 'requests'))->toBe([300, 100]);
    }
});
