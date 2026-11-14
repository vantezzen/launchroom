<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\DomainKind;
use App\Enums\TeamRole;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Projects;

/*
| Requests the web firewall refused in request analytics (docs/15 §5.4):
| a blocked request is a 403 in the access log (the daemon integration test
| TestWafBlocksAndReports counts it as 4xx), so analytics carves
| `waf_samples.blocked` out of 4xx per bucket, per path and per project,
| and reports it as its own series, total and previous total.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    CarbonImmutable::setTestNow('2026-10-20 12:00:30');
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->online()->create(['team_id' => $this->team->id, 'name' => 'fsn1-edge', 'roles' => ['edge', 'runtime', 'build']]);
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    $this->environment = $this->project->production()->sole();
    $this->instance = $this->environment->serviceInstances()->sole();
    $domain = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'shop.example.com', 'kind' => DomainKind::Custom]);
    $this->route = Domain::routeIdFor($domain->public_id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, mixed>  $overrides
 */
function blockedRequestRow(object $test, int $resolution, string $at, array $overrides = []): void
{
    DB::table('request_metric_samples')->insert([
        'server_id' => $test->server->id, 'route_id' => $test->route, 'resolution' => $resolution, 'at' => $at,
        'service_instance_id' => $test->instance->id, 'project_id' => $test->project->id, 'requests' => 100,
        'status_2xx' => 80, 'status_3xx' => 0, 'status_4xx' => 15, 'status_5xx' => 5, 'bytes_in' => 0, 'bytes_out' => 0, 'latency' => null,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function blockedWafRow(object $test, int $resolution, string $at, int $blocked, array $overrides = []): void
{
    DB::table('waf_samples')->insert([
        'server_id' => $test->server->id, 'route_id' => $test->route, 'resolution' => $resolution, 'at' => $at,
        'service_instance_id' => $test->instance->id, 'project_id' => $test->project->id, 'blocked' => $blocked, 'detected' => 0,
        'categories' => json_encode([['c' => 'sqli', 'b' => $blocked, 'd' => 0]]), 'rules' => '[]',
        'paths' => json_encode([['p' => '/login', 'b' => $blocked, 'd' => 0]]),
        ...$overrides,
    ]);
}

test('blocked requests are carved out of 4xx per bucket and reported as their own series', function (): void {
    blockedRequestRow($this, 1, '2026-10-20 11:59:00+00');
    blockedRequestRow($this, 10, '2026-10-20 08:10:00+00');
    blockedWafRow($this, 10, '2026-10-20 11:50:00+00', 10);
    // More blocked than 4xx in a bucket (windows that don't line up): clamped.
    blockedWafRow($this, 10, '2026-10-20 08:10:00+00', 40, ['paths' => json_encode([['p' => '/admin', 'b' => 40, 'd' => 0]])]);
    // Detected only (Log only) never counts as blocked.
    blockedWafRow($this, 10, '2026-10-20 09:10:00+00', 0, ['detected' => 7]);
    // The previous day.
    blockedWafRow($this, 10, '2026-10-19 08:10:00+00', 4);
    DB::table('request_analytics_samples')->insert([
        'server_id' => $this->server->id, 'route_id' => $this->route, 'resolution' => 10, 'at' => '2026-10-20 11:50:00+00',
        'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'requests' => 100,
        'paths' => json_encode([['p' => '/login', 'r' => 40, 'e4' => 12, 'e5' => 0], ['p' => '/', 'r' => 60, 'e4' => 3, 'e5' => 0]]),
        'other_requests' => 0, 'other_4xx' => 0, 'other_5xx' => 0, 'referrers' => '[]', 'other_referrers' => 0, 'browsers' => 100, 'bots' => 0, 'other_agents' => 0,
    ]);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/analytics?range=24h")
        ->assertOk();

    expect($response->json('blocked'))->toHaveCount(24)
        ->and($response->json('blocked.23'))->toBe(0)
        ->and($response->json('blocked.22'))->toBe(10)
        ->and($response->json('status4xx.22'))->toBe(5)
        ->and($response->json('blocked.19'))->toBe(15)
        ->and($response->json('status4xx.19'))->toBe(0)
        ->and($response->json('totals'))->toMatchArray(['requests' => 200, 'status4xx' => 5, 'blocked' => 25, 'previousBlocked' => 4])
        ->and($response->json('paths.0'))->toBe(['path' => '/', 'requests' => 60, 'status4xx' => 3, 'status5xx' => 0, 'blocked' => 0])
        ->and($response->json('paths.1'))->toBe(['path' => '/login', 'requests' => 40, 'status4xx' => 2, 'status5xx' => 0, 'blocked' => 10]);
});

test('another team\'s blocked requests never reach the numbers', function (): void {
    blockedRequestRow($this, 1, '2026-10-20 11:59:00+00');
    $stranger = Projects::create(Team::factory()->create(), 'Foreign');
    $strangerInstance = Projects::image($stranger, 'web')->instances()->sole();
    blockedWafRow($this, 10, '2026-10-20 11:50:00+00', 9, ['service_instance_id' => $strangerInstance->id, 'project_id' => $stranger->id, 'route_id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9FO']);

    $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/analytics?range=24h")
        ->assertOk()
        ->assertJsonPath('totals.blocked', 0)
        ->assertJsonPath('totals.status4xx', 15);
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson('/analytics/-/data?range=24h')
        ->assertOk()
        ->assertJsonPath('totals.blocked', 0);
});

test('the team overview carves blocked requests out of each project\'s 4xx', function (): void {
    blockedRequestRow($this, 1, '2026-10-20 11:59:00+00');
    blockedWafRow($this, 10, '2026-10-20 11:50:00+00', 10);

    $response = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson('/analytics/-/data?range=24h')
        ->assertOk();

    expect($response->json('totals'))->toMatchArray(['status4xx' => 5, 'blocked' => 10])
        ->and($response->json('projects.0'))->toMatchArray(['name' => 'Shop', 'blocked' => 10, 'clientErrorPct' => 5]);
});

test('analytics carry deploy markers that link to the deployment', function (): void {
    blockedRequestRow($this, 1, '2026-10-20 11:59:00+00');
    $deployment = $this->instance->deployments()->latest('id')->firstOrFail();
    $deployment->forceFill(['status' => 'active', 'started_at' => '2026-10-20 11:25:00+00', 'finished_at' => '2026-10-20 11:30:00+00'])->save();

    $marker = $this->actingAs(memberOf($this->team, TeamRole::Viewer))
        ->getJson("/p/{$this->project->public_id}/-/analytics?range=24h")
        ->assertOk()
        ->json('deploys.0');

    expect($marker)->toMatchArray([
        'deploymentId' => $deployment->public_id,
        'href' => "/p/{$this->project->public_id}/{$this->environment->public_id}/d/{$deployment->public_id}",
    ]);
});
