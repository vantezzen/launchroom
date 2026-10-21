<?php

declare(strict_types=1);

use App\Data\Servers\HardwareFactsData;
use App\Data\Servers\HostFactsData;
use App\Data\Servers\RuntimeCapabilityData;
use App\Data\Servers\ServerRuntimeData;
use App\Enums\ServerRole;
use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\DockerEngine;
use App\Enums\Servers\DockerMode;
use App\Enums\Servers\HostOs;
use App\Enums\Servers\RuntimeCapability;
use App\Support\Servers\HostRoleLimits;

/*
| Roles a machine can't take because of how Docker runs on it (D53): a Mac
| never serves traffic, Docker Desktop never runs apps.
*/

function macRuntime(DockerEngine $engine, ?CapabilityState $workloads = null, HostOs $os = HostOs::Darwin): ServerRuntimeData
{
    $capabilities = $workloads instanceof CapabilityState ? [new RuntimeCapabilityData(RuntimeCapability::Workloads, $workloads)] : [];

    return new ServerRuntimeData(DockerMode::Rootful, true, $capabilities, '2026-10-20T12:00:00Z', $os, $engine);
}

dataset('machines', [
    'Linux VPS' => [null, new HostFactsData('vps', 'linux', 'amd64'), [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build]],
    'Linux, Docker Engine reported' => [macRuntime(DockerEngine::DockerEngine, CapabilityState::Full, HostOs::Linux), new HostFactsData('vps', 'linux', 'amd64'), [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build]],
    'Mac at enrollment (installer hint)' => [null, new HostFactsData('mac', 'darwin', 'arm64'), [ServerRole::Runtime, ServerRole::Build]],
    'Mac on OrbStack' => [macRuntime(DockerEngine::OrbStack, CapabilityState::Full), new HostFactsData('mac', 'linux', 'arm64'), [ServerRole::Runtime, ServerRole::Build]],
    'Mac on Colima' => [macRuntime(DockerEngine::Colima, CapabilityState::Full), new HostFactsData('mac', 'darwin', 'arm64'), [ServerRole::Runtime, ServerRole::Build]],
    'Mac on Docker Desktop' => [macRuntime(DockerEngine::DockerDesktop, CapabilityState::Unavailable), new HostFactsData('mac', 'darwin', 'arm64'), [ServerRole::Build]],
    'Docker Desktop known only from the hardware facts' => [null, new HostFactsData('mac', 'darwin', 'arm64', hardware: new HardwareFactsData(osName: 'Docker Desktop')), [ServerRole::Build]],
    'Docker Desktop on Linux' => [macRuntime(DockerEngine::DockerDesktop, CapabilityState::Unavailable, HostOs::Linux), new HostFactsData('pc', 'linux', 'amd64'), [ServerRole::Build]],
]);

test('machines take the roles their Docker allows', function (?ServerRuntimeData $runtime, HostFactsData $facts, array $allowed): void {
    foreach ([ServerRole::Edge, ServerRole::Runtime, ServerRole::Build] as $role) {
        $refusal = HostRoleLimits::refusalFor($role, $runtime, $facts);

        expect($refusal === null)->toBe(in_array($role, $allowed, true), "{$role->value}: ".($refusal ?? 'allowed'));
    }

    expect(HostRoleLimits::allowed([ServerRole::Edge, ServerRole::Runtime, ServerRole::Build], $runtime, $facts))->toBe($allowed);
})->with('machines');

test('a Mac asked to only serve traffic still builds', function (): void {
    expect(HostRoleLimits::allowed([ServerRole::Edge], null, new HostFactsData('mac', 'darwin', 'arm64')))->toBe([ServerRole::Build]);
});
