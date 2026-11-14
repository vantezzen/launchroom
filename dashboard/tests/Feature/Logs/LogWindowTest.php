<?php

declare(strict_types=1);

use App\Data\Logs\LogQueryData;
use App\Enums\AnalyticsView;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\User;
use App\Queries\DeployMarkers;
use App\Support\Links;
use Carbon\CarbonImmutable;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
| The observe scope's logs side (docs/08 §1, §12): an absolute window
| (`from`/`to`) on the log explorer, deploy markers with links to their
| deployment, log lines that link their deployment and server, the short
| deployment link and the deep links alerts and charts use.
*/

beforeEach(function (): void {
    Projects::useTestKek();
});

function windowLines(DeploymentUi $world, Deployment $deployment, string $text, CarbonImmutable $at): void
{
    $placement = Placement::query()->where('deployment_id', $deployment->id)->firstOrFail();

    LogChunk::query()->create([
        'team_id' => $deployment->team_id, 'server_id' => $world->server->id, 'deployment_id' => $deployment->id,
        'source_kind' => LogSourceKind::Workload, 'source_id' => $placement->workload_id,
        'seq' => random_int(1, 1_000_000),
        'lines' => [['time' => $at->utc()->format('Y-m-d\TH:i:s.v\Z'), 'stream' => 'stdout', 'text' => $text]],
        'created_at' => $at,
    ]);
}

test('an absolute window returns only the lines of that time', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $now = CarbonImmutable::now();
    windowLines($world, $web, 'three hours ago', $now->subHours(3));
    windowLines($world, $web, 'ninety minutes ago', $now->subMinutes(90));
    windowLines($world, $web, 'just now', $now->subMinute());
    $viewer = $world->member(TeamRole::Viewer);
    $url = route('projects.logs.entries', $world->envArgs());

    $page = $this->actingAs($viewer)->getJson($url.'?range=24h&from='.$now->subHours(2)->getTimestamp().'&to='.$now->subHour()->getTimestamp())
        ->assertOk()->json();

    expect(collect($page['entries'])->pluck('message')->all())->toBe(['ninety minutes ago'])
        ->and(CarbonImmutable::parse($page['from'])->getTimestamp())->toBe($now->subHours(2)->getTimestamp())
        ->and(CarbonImmutable::parse($page['to'])->getTimestamp())->toBe($now->subHour()->getTimestamp())
        ->and($page['buckets'])->toHaveCount(60)
        ->and(array_sum(array_column($page['buckets'], 'total')))->toBe(1);

    // Without a window the range still applies.
    expect(collect($this->actingAs($viewer)->getJson($url.'?range=24h')->json('entries'))->pluck('message')->all())
        ->toBe(['three hours ago', 'ninety minutes ago', 'just now']);

    // A lone bound is no window: the range applies.
    expect($this->actingAs($viewer)->getJson($url.'?range=24h&from='.$now->subHour()->getTimestamp())->json('entries'))->toHaveCount(3);
});

test('a window is clamped to the last 30 days and to now', function (): void {
    $now = CarbonImmutable::parse('2026-11-13 12:00:00');
    [$from, $to] = new LogQueryData(from: 1, to: $now->addDay()->getTimestamp())->window($now);

    expect($from->getTimestamp())->toBe($now->subDays(30)->getTimestamp())
        ->and($to->getTimestamp())->toBe($now->getTimestamp());

    [$from, $to] = new LogQueryData(from: $now->subMinutes(5)->getTimestamp(), to: $now->subMinutes(5)->getTimestamp())->window($now);
    expect($to->getTimestamp() - $from->getTimestamp())->toBe(60);
});

test('log lines link their deployment and server, markers link their deployment', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    windowLines($world, $web, 'GET / 200', CarbonImmutable::now()->subMinutes(2));
    $viewer = $world->member(TeamRole::Viewer);

    $page = $this->actingAs($viewer)->getJson(route('projects.logs.entries', $world->envArgs()))->assertOk()->json();
    $line = collect($page['entries'])->firstWhere('message', 'GET / 200');
    $marker = collect($page['markers'])->firstWhere('deploymentId', $web->public_id);

    expect($line['deploymentId'])->toBe($web->public_id)
        ->and($line['serverId'])->toBe($world->server->public_id)
        ->and($marker)->not->toBeNull()
        ->and($marker['href'])->toBe(Links::deployment($web))
        ->and($marker['serviceName'])->toBe('web');
});

test('the short deployment link redirects within the team only', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $viewer = $world->member(TeamRole::Viewer);

    $this->actingAs($viewer)->get(route('deployments.link', $web->public_id))
        ->assertRedirect(Links::deployment($web));

    $stranger = User::factory()->create();
    expect($this->actingAs($stranger)->get(route('deployments.link', $web->public_id))->status())->toBeIn([403, 404]);
});

test('deploy markers of a window come with the service, the short sha and a link', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $at = CarbonImmutable::instance($web->finished_at ?? $web->started_at ?? $web->queued_at);

    $markers = DeployMarkers::forInstances([$web->service_instance_id], $at->subMinute(), $at->addMinute());
    $outside = DeployMarkers::forInstances([$web->service_instance_id], $at->addMinutes(5));

    expect($markers)->toHaveCount(1)
        ->and($markers[0]->deploymentId)->toBe($web->public_id)
        ->and($markers[0]->href)->toBe(Links::deployment($web))
        ->and($outside)->toBe([]);
});

test('deep links name the filtered view', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $web->loadMissing('serviceInstance');
    $instance = $web->serviceInstance;
    $from = CarbonImmutable::parse('2026-11-13 12:00:00');
    [$project, $environment] = array_values($world->envArgs());
    $service = $world->web->public_id;

    expect(Links::serviceLogs($instance, 'level:error', $from, $from->addMinutes(10)))
        ->toBe("/p/{$project}/{$environment}/s/{$service}/logs?q=level%3Aerror&from={$from->getTimestamp()}&to={$from->addMinutes(10)->getTimestamp()}")
        ->and(Links::serviceSettings($instance, 'domains', 'shop.example.com'))
        ->toBe("/p/{$project}/{$environment}/s/{$service}/settings/domains#shop.example.com")
        ->and(Links::server($world->server, 'resources', ['range' => '1h']))
        ->toBe("/servers/{$world->server->public_id}/resources?range=1h")
        // With a domain, a service's analytics open on Traffic (D75).
        ->and(Links::serviceAnalytics($instance, '7d'))
        ->toBe("/p/{$project}/{$environment}/analytics?service={$service}&range=7d")
        ->and(Links::analytics($instance->environment, $instance))
        ->toBe("/p/{$project}/{$environment}/analytics?service={$service}")
        ->and(Links::analytics($instance->environment, null, '7d', AnalyticsView::Resources))
        ->toBe("/p/{$project}/{$environment}/analytics/resources?range=7d")
        ->and(Links::server($world->server, null, [], 'disk'))
        ->toBe("/servers/{$world->server->public_id}#disk")
        ->and(Links::security($instance, 'firewall'))
        ->toBe("/security?service={$service}#firewall")
        ->and(Links::deployment($web))
        ->toBe("/p/{$project}/{$environment}/d/{$web->public_id}");
});
