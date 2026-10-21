<?php

declare(strict_types=1);

use App\Data\Servers\ServerRuntimeData;
use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\DockerEngine;
use App\Enums\Servers\DockerMode;
use App\Enums\Servers\HostOs;
use App\Enums\Servers\RuntimeCapability;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use Tests\Support\FakeDaemon;

/**
 * Report.host.runtime (D49): Docker mode, dockerd-guard and capabilities,
 * stored on the server and shown on its Network tab.
 */
beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create(['team_id' => $this->team->id, 'desired_version' => 1]);
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

/**
 * @param  array<string, mixed>|null  $runtime  null: the report omits it
 * @return array<string, mixed>
 */
function runtimeReport(Server $server, int $seq, ?array $runtime): array
{
    $host = ['cpus' => 2, 'memoryMb' => 2048, 'diskTotalMb' => 100, 'diskFreeMb' => 50, 'daemonVersion' => '0.1.0'];

    return [
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $server->public_id,
        'observedAt' => '2026-10-17T12:00:05Z', 'seq' => $seq, 'full' => false, 'appliedVersion' => 1, 'workloads' => [],
        'host' => $runtime === null ? $host : [...$host, 'runtime' => $runtime],
    ];
}

/** @return array<string, mixed> */
function rootlessRuntime(): array
{
    return [
        'docker' => 'rootless',
        'guard' => true,
        'capabilities' => [
            ['name' => 'dockerAccess', 'state' => 'full'],
            ['name' => 'clientAddresses', 'state' => 'degraded', 'message' => 'Rootless Docker hides visitor addresses behind its port forwarder.'],
            ['name' => 'firewall', 'state' => 'unavailable'],
            ['name' => 'quantumTunnel', 'state' => 'full'],
        ],
    ];
}

test('reports store the runtime and drop unknown capabilities', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 1, rootlessRuntime()))->assertNoContent();

    $runtime = $this->server->refresh()->runtime;

    expect($runtime)->toBeInstanceOf(ServerRuntimeData::class)
        ->and($runtime->docker)->toBe(DockerMode::Rootless)
        ->and($runtime->guard)->toBeTrue()
        ->and(array_map(static fn ($capability) => $capability->name, $runtime->capabilities))
        ->toBe([RuntimeCapability::DockerAccess, RuntimeCapability::ClientAddresses, RuntimeCapability::Firewall])
        ->and($runtime->capabilities[1]->state)->toBe(CapabilityState::Degraded)
        ->and($runtime->capabilities[1]->message)->toBe('Rootless Docker hides visitor addresses behind its port forwarder.')
        ->and($runtime->capabilities[2]->message)->toBeNull();
});

test('a Mac reports its OS, engine and build platforms (D53)', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 1, [
        'docker' => 'rootful', 'guard' => true, 'hostOs' => 'darwin', 'engine' => 'orbstack',
        'buildPlatforms' => ['linux/amd64', 'linux/arm64', 'linux/riscv64'],
        'capabilities' => [
            ['name' => 'edgePorts', 'state' => 'unavailable', 'message' => 'Docker runs in OrbStack\'s Linux VM here'],
            ['name' => 'workloads', 'state' => 'full'],
        ],
    ]))->assertNoContent();

    $runtime = $this->server->refresh()->runtime;

    expect($runtime?->hostOs)->toBe(HostOs::Darwin)
        ->and($runtime?->engine)->toBe(DockerEngine::OrbStack)
        // Platforms launchroom doesn't build for are dropped.
        ->and($runtime?->buildPlatforms)->toBe(['linux/amd64', 'linux/arm64'])
        ->and($runtime?->capability(RuntimeCapability::Workloads))->toBe(CapabilityState::Full);

    // Unknown values from newer servers are dropped, not refused.
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 2, [
        'docker' => 'rootful', 'guard' => true, 'hostOs' => 'plan9', 'engine' => 'lima-next', 'capabilities' => [],
    ]))->assertNoContent();

    $runtime = $this->server->refresh()->runtime;

    expect($runtime?->hostOs)->toBeNull()->and($runtime?->engine)->toBeNull()->and($runtime?->buildPlatforms)->toBeNull();
});

test('a report without the runtime keeps the stored one, a newer one replaces it', function (): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 1, rootlessRuntime()))->assertNoContent();
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 2, null))->assertNoContent();

    expect($this->server->refresh()->runtime?->docker)->toBe(DockerMode::Rootless);

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 3, [
        'docker' => 'rootful', 'guard' => false, 'capabilities' => [['name' => 'dockerAccess', 'state' => 'degraded']],
    ]))->assertNoContent();

    $runtime = $this->server->refresh()->runtime;

    expect($runtime?->docker)->toBe(DockerMode::Rootful)
        ->and($runtime?->guard)->toBeFalse()
        ->and($runtime?->capabilities)->toHaveCount(1);
});

test('reports with a malformed runtime are rejected', function (array $runtime): void {
    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 1, $runtime))->assertUnprocessable();

    expect($this->server->refresh()->runtime)->toBeNull();
})->with([
    'unknown docker mode' => [['docker' => 'podman', 'guard' => true, 'capabilities' => []]],
    'guard not a boolean' => [['docker' => 'rootful', 'guard' => 'yes', 'capabilities' => []]],
    'unknown state' => [['docker' => 'rootful', 'guard' => true, 'capabilities' => [['name' => 'mesh', 'state' => 'perfect']]]],
    'bad capability name' => [['docker' => 'rootful', 'guard' => true, 'capabilities' => [['name' => 'Mesh; drop', 'state' => 'full']]]],
    'duplicate names' => [['docker' => 'rootful', 'guard' => true, 'capabilities' => [['name' => 'mesh', 'state' => 'full'], ['name' => 'mesh', 'state' => 'degraded']]]],
    'message too long' => [['docker' => 'rootful', 'guard' => true, 'capabilities' => [['name' => 'mesh', 'state' => 'full', 'message' => str_repeat('a', 513)]]]],
    'bad engine' => [['docker' => 'rootful', 'guard' => true, 'engine' => 'Orb Stack', 'capabilities' => []]],
    'bad build platform' => [['docker' => 'rootful', 'guard' => true, 'buildPlatforms' => ['linux/amd64; x'], 'capabilities' => []]],
    'too many build platforms' => [['docker' => 'rootful', 'guard' => true, 'buildPlatforms' => array_map(static fn (int $i): string => 'linux/a'.$i, range(1, 9)), 'capabilities' => []]],
    'too many capabilities' => [['docker' => 'rootful', 'guard' => true, 'capabilities' => array_map(static fn (int $i): array => ['name' => 'cap'.$i, 'state' => 'full'], range(1, 17))]],
]);

test('the Network tab shows the runtime to the team and nobody else', function (): void {
    $this->actingAs($this->viewer)->get(route('servers.tab', [$this->server->public_id, 'network']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'network')->where('runtime', null));

    $this->daemon->send($this, 'POST', '/daemon/v1/reports', runtimeReport($this->server, 1, rootlessRuntime()))->assertNoContent();

    $this->actingAs($this->viewer)->get(route('servers.tab', [$this->server->public_id, 'network']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->where('runtime.docker', 'rootless')
            ->where('runtime.guard', true)
            ->has('runtime.capabilities', 3)
            ->where('runtime.capabilities.1.name', 'clientAddresses')
            ->where('runtime.capabilities.1.state', 'degraded')
            ->has('runtime.reportedAt'));

    // Other tabs don't load it.
    $this->actingAs($this->viewer)->get(route('servers.show', $this->server->public_id))
        ->assertInertia(fn ($page) => $page->missing('runtime'));

    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->get(route('servers.tab', [$this->server->public_id, 'network']))->assertNotFound();
});
