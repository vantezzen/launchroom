<?php

declare(strict_types=1);

use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Protocol\V1\SystemContainer;
use App\Data\Protocol\V1\SystemSpec;
use App\Data\Protocol\V1\TailscaleSpec;
use App\Data\Protocol\V1\VolumeSpec;
use App\Data\Protocol\V1\WorkloadSpec;
use App\Enums\DaemonFeature;
use App\Models\Server;
use App\Services\NodeStates\FeatureGate;
use Carbon\CarbonImmutable;

/*
| The NodeState renderer builds the full document; FeatureGate leaves out
| what an older launchroomd would refuse (docs/03 §10).
*/

function gatedState(): NodeState
{
    return new NodeState(
        serverId: 'srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK',
        version: 2,
        issuedAt: CarbonImmutable::parse('2026-11-14T12:00:00Z'),
        system: new SystemSpec(new SystemContainer(true), new SystemContainer(false), new TailscaleSpec(false), linkProbePeers: [], controlPlaneUrl: 'https://launchroom.example.com'),
        volumes: [new VolumeSpec('vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1', 'lr-v_a'), new VolumeSpec('vol_01J9Z8X7W6V5T4S3R2Q1P0N9V2', 'lr-v_b')],
        workloads: [new WorkloadSpec(
            id: 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7', service: 'svc_x', environment: 'env_x', deployment: 'dep_x', image: 'nginx', sealedEnv: 'x', envFingerprint: 'x',
            cpus: 1.0, memoryMb: 256, pids: 256, networks: [], role: 'serving',
            mounts: [['volume' => 'vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1', 'target' => '/data']],
            shipLogs: true, files: [['path' => '/etc/app.conf', 'content' => 'x']],
        )],
        routes: [new RouteSpec('rt_x', ['app.example.com'], 'acme', [], clientAuth: ['mode' => 'required'], waf: ['mode' => 'block'])],
    );
}

test('a launchroomd without the features gets the document without their fields', function (): void {
    $server = Server::factory()->online()->create(['daemon_features' => []]);

    $state = FeatureGate::downgrade(gatedState(), $server);

    expect($state->system->linkProbePeers)->toBeNull()
        ->and($state->system->controlPlaneUrl)->toBeNull()
        ->and($state->routes[0]->clientAuth)->toBeNull()
        ->and($state->routes[0]->waf)->toBeNull()
        ->and($state->workloads[0]->shipLogs)->toBeFalse()
        ->and($state->workloads[0]->files)->toBeNull()
        // The volume no workload mounts waits for a moving service: left out.
        ->and(array_map(static fn (VolumeSpec $volume): string => $volume->id, $state->volumes))->toBe(['vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1']);
});

test('a launchroomd with every feature gets the full document', function (): void {
    $server = Server::factory()->online()->create(['daemon_features' => array_map(static fn (DaemonFeature $feature): string => $feature->value, DaemonFeature::cases())]);

    expect(FeatureGate::downgrade(gatedState(), $server)->toArray())->toEqual(gatedState()->toArray());
});
