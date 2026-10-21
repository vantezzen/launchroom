<?php

declare(strict_types=1);

use App\Enums\HealthCheckState;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Queries\ServerHealth;
use Tests\Support\FakeDaemon;
use Tests\Support\ProtocolSchemas;

function healthReport(Server $server, array $health, int $seq = 1): array
{
    return [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => now()->toIso8601ZuluString(), 'seq' => $seq, 'full' => true, 'appliedVersion' => 0, 'workloads' => [],
        'host' => ['cpus' => 4, 'memoryMb' => 8192, 'diskTotalMb' => 100_000, 'diskFreeMb' => 50_000, 'dockerVersion' => '29.0.1', 'daemonVersion' => '0.5.0', 'health' => $health],
    ];
}

function healthyFacts(array $overrides = []): array
{
    return array_replace([
        'collectedAt' => now()->toIso8601ZuluString(),
        'dataDisk' => ['totalMb' => 100_000, 'freeMb' => 60_000],
        'memory' => ['totalMb' => 8192, 'availableMb' => 6000],
        'load' => ['load1' => 0.5, 'load5' => 0.4, 'load15' => 0.3],
        'inotify' => ['maxUserWatches' => 524288, 'maxUserInstances' => 1024, 'maxQueuedEvents' => 16384],
        'networks' => ['total' => 6, 'managed' => 3],
        'docker' => ['imagesMb' => 2048, 'imagesReclaimableMb' => 512, 'containersMb' => 40],
        'clockSkewMs' => 0,
    ], $overrides);
}

/**
 * @return array<string, HealthCheckState>
 */
function healthStates(Server $server): array
{
    $states = [];

    foreach (resolve(ServerHealth::class)->evaluate($server->refresh())->checks as $check) {
        $states[$check->key] = $check->state;
    }

    return $states;
}

beforeEach(function (): void {
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create();
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

test('the Go health report fixture is accepted and stored normalized', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.health.json'), true);
    $fixture['serverId'] = $this->server->public_id;
    $fixture['host']['health']['unexpected'] = 'dropped';

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $fixture)->assertNoContent();

    $server = $this->server->refresh();
    expect($server->host_status_at)->not->toBeNull()
        ->and($server->daemon_version)->toBe('0.4.0')
        ->and($server->host_status['dockerVersion'])->toBe('29.0.1')
        ->and($server->host_status['health']['inotify'])->toEqual(['maxUserWatches' => 524288, 'maxUserInstances' => 128, 'maxQueuedEvents' => 16384])
        ->and($server->host_status['health']['load']['load1'])->toBe(0.42)
        ->and($server->host_status['health']['cleanup'])->toMatchArray(['trigger' => 'schedule', 'removedImages' => 4, 'reclaimedMb' => 830])
        ->and($server->host_status['health'])->not->toHaveKey('unexpected');
});

test('invalid health facts are rejected', function (): void {
    foreach ([
        ['dataDisk' => ['totalMb' => -1, 'freeMb' => 0]],
        ['load' => ['load1' => -1, 'load5' => 0, 'load15' => 0]],
        ['cleanup' => ['at' => 'yesterday', 'trigger' => 'schedule']],
        ['cleanup' => ['at' => '2026-10-17T03:12:00Z', 'trigger' => 'whenever']],
        ['collectedAt' => 'now'],
    ] as $i => $bad) {
        $this->daemon->send($this, 'POST', '/daemon/v1/reports', healthReport($this->server, healthyFacts($bad), $i + 1))->assertUnprocessable();
    }

    expect($this->server->refresh()->host_status)->toBeNull();
});

test('healthy facts are ok; thresholds turn into warnings and critical states', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', healthReport($this->server, healthyFacts()))->assertNoContent();

    expect(healthStates($this->server))->toMatchArray([
        'report' => HealthCheckState::Ok, 'disk' => HealthCheckState::Ok, 'memory' => HealthCheckState::Ok, 'load' => HealthCheckState::Ok,
        'inotify' => HealthCheckState::Ok, 'networks' => HealthCheckState::Ok, 'clock' => HealthCheckState::Ok,
        'build_cache' => HealthCheckState::Unknown, 'cleanup' => HealthCheckState::Unknown,
    ]);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', healthReport($this->server, healthyFacts([
        'dockerDisk' => ['totalMb' => 100_000, 'freeMb' => 5_000], // Docker's filesystem wins over the data dir
        'memory' => ['totalMb' => 8192, 'availableMb' => 1000],
        'load' => ['load1' => 30, 'load5' => 20, 'load15' => 10],
        'inotify' => ['maxUserWatches' => 8192, 'maxUserInstances' => 128, 'maxQueuedEvents' => 16384],
        'networks' => ['total' => 33, 'managed' => 3],
        'clockSkewMs' => -42_000,
        'buildCacheMb' => 3072,
        'cleanup' => ['at' => now()->subHour()->toIso8601ZuluString(), 'trigger' => 'disk_pressure', 'removedImages' => 2, 'removedContainers' => 0, 'reclaimedMb' => 900, 'error' => 'build cache: buildkitd went away'],
    ]), 2))->assertNoContent();

    $health = resolve(ServerHealth::class)->evaluate($this->server->refresh());
    $byKey = collect($health->checks)->keyBy('key');

    expect(healthStates($this->server))->toMatchArray([
        'disk' => HealthCheckState::Critical, 'memory' => HealthCheckState::Warning, 'load' => HealthCheckState::Critical,
        'inotify' => HealthCheckState::Warning, 'networks' => HealthCheckState::Critical, 'clock' => HealthCheckState::Critical,
        'build_cache' => HealthCheckState::Ok, 'cleanup' => HealthCheckState::Warning,
    ])->and($health->state)->toBe(HealthCheckState::Critical)
        ->and($byKey['disk']->label)->toBe('Disk (Docker)')
        ->and($byKey['disk']->value)->toBe('4.9 GB free of 97.7 GB (5%)')
        ->and($byKey['clock']->value)->toBe('42.0 s ahead of the dashboard')
        ->and($byKey['inotify']->detail)->toContain('fs.inotify.max_user_watches')
        ->and($byKey['cleanup']->detail)->toBe('Partly failed: build cache: buildkitd went away');
});

test('stale or missing facts are unknown, not ok', function (): void {
    expect(healthStates($this->server))->each->toBe(HealthCheckState::Unknown);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', healthReport($this->server, healthyFacts(['collectedAt' => now()->subHour()->toIso8601ZuluString()])))->assertNoContent();

    $health = resolve(ServerHealth::class)->evaluate($this->server->refresh());
    expect($health->stale)->toBeTrue()
        ->and(healthStates($this->server))->toMatchArray(['report' => HealthCheckState::Warning, 'disk' => HealthCheckState::Unknown, 'memory' => HealthCheckState::Unknown]);
});

test('reports without health keep working and leave stored facts alone', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', healthReport($this->server, healthyFacts()))->assertNoContent();
    $body = healthReport($this->server, healthyFacts(), 2);
    unset($body['host']);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', $body)->assertNoContent();

    expect($this->server->refresh()->host_status['health']['memory']['availableMb'])->toBe(6000);
});

test('the server page shows the health checks', function (): void {
    $team = Team::query()->findOrFail($this->server->team_id);
    $viewer = memberOf($team, TeamRole::Viewer);
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', healthReport($this->server, healthyFacts()))->assertNoContent();

    $this->actingAs($viewer)->get(route('servers.show', $this->server->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->where('health.state', 'unknown') // no build cache and no cleanup yet
            ->where('health.stale', false)
            ->where('health.checks.1.key', 'disk')
            ->where('health.checks.1.state', 'ok')
            ->where('health.checks.1.value', '58.6 GB free of 97.7 GB (60%)'));
});
