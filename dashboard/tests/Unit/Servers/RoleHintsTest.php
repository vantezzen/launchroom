<?php

declare(strict_types=1);

use App\Data\Servers\RoleHintData;
use App\Enums\DeviceKind;
use App\Enums\ServerRole;
use App\Enums\Servers\RoleHintTone;
use App\Support\Servers\RoleHintContext;
use App\Support\Servers\RoleHints;

/**
 * @param  list<ServerRole>  $roles
 * @return list<string>
 */
function hintIds(DeviceKind $device, array $roles, mixed ...$context): array
{
    return array_map(
        static fn (RoleHintData $hint): string => $hint->id,
        RoleHints::for(new RoleHintContext($device, $roles, ...$context)),
    );
}

it('says nothing for a cloud server that runs apps and serves traffic', function (): void {
    expect(hintIds(DeviceKind::Cloud, [ServerRole::Runtime, ServerRole::Edge]))->toBe([]);
});

it('explains that serving traffic from home needs a public address', function (DeviceKind $device): void {
    $hints = RoleHints::for(new RoleHintContext($device, [ServerRole::Edge]));

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->id)->toBe('edge-at-home')
        ->and($hints[0]->tone)->toBe(RoleHintTone::Info)
        ->and($hints[0]->body)->toContain('port')
        ->and($hints[0]->docs)->toBe('servers/home-machines');
})->with([DeviceKind::Minipc, DeviceKind::Sbc, DeviceKind::Tower, DeviceKind::Nas, DeviceKind::Compact]);

it('does not count cloud, rented or unknown machines as at home', function (DeviceKind $device): void {
    expect(hintIds($device, [ServerRole::Edge]))->not->toContain('edge-at-home');
})->with([DeviceKind::Cloud, DeviceKind::Dedicated, DeviceKind::Vm, DeviceKind::Unknown]);

it('notes builds next to apps in the wizard', function (): void {
    expect(hintIds(DeviceKind::Minipc, [ServerRole::Build, ServerRole::Runtime]))->toBe(['shared-builder'])
        ->and(RoleHints::for(new RoleHintContext(DeviceKind::Cloud, [ServerRole::Build, ServerRole::Edge]))[0]->body)
        ->toContain('while serving traffic');
});

it('stays quiet about builds next to apps when one server does everything', function (): void {
    expect(hintIds(DeviceKind::Cloud, [ServerRole::Build, ServerRole::Runtime, ServerRole::Edge], existing: true, cpus: 8, memoryMb: 16384))
        ->toBe([]);
});

it('notes builds next to apps on a server page when another server could build', function (): void {
    expect(hintIds(DeviceKind::Cloud, [ServerRole::Build, ServerRole::Runtime], existing: true, cpus: 8, memoryMb: 16384, otherServers: 1))
        ->toBe(['shared-builder']);
});

it('warns when a small machine builds next to apps, with its real size', function (): void {
    $hints = RoleHints::for(new RoleHintContext(DeviceKind::Cloud, [ServerRole::Build, ServerRole::Runtime], existing: true, cpus: 1, memoryMb: 1024));

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->id)->toBe('small-builder')
        ->and($hints[0]->tone)->toBe(RoleHintTone::Warning)
        ->and($hints[0]->body)->toContain('1 GB and 1 core');
});

it('counts a machine as small below 2 GB or 2 cores', function (?int $cpus, ?int $memoryMb, bool $small): void {
    $ids = hintIds(DeviceKind::Cloud, [ServerRole::Build, ServerRole::Edge], existing: true, cpus: $cpus, memoryMb: $memoryMb, otherServers: 1, otherEdges: 1);

    expect(in_array('small-builder', $ids, true))->toBe($small);
})->with([
    'half a gig' => [4, 512, true],
    'one core' => [1, 8192, true],
    'two gig, two cores' => [2, 2048, false],
    'unknown size' => [null, null, false],
]);

it('does not mention builds on a build-only server', function (): void {
    expect(hintIds(DeviceKind::Cloud, [ServerRole::Build], existing: true, cpus: 1, memoryMb: 512, otherServers: 2))->toBe([]);
});

it('reminds that a laptop takes its apps offline when it sleeps', function (): void {
    expect(hintIds(DeviceKind::Laptop, [ServerRole::Runtime], existing: true))->toBe(['sleeps'])
        ->and(hintIds(DeviceKind::Minipc, [ServerRole::Runtime], existing: true, battery: true))->toBe(['sleeps'])
        ->and(hintIds(DeviceKind::Laptop, [ServerRole::Build], existing: true))->toBe([]);
});

it('says an ARM build server builds for ARM only when x86 servers have no builder', function (): void {
    $hints = RoleHints::for(new RoleHintContext(DeviceKind::Sbc, [ServerRole::Build], arch: 'arm64', otherServers: 1, otherRuntimeArchs: ['amd64']));

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->id)->toBe('build-arch')
        ->and($hints[0]->title)->toBe('Builds here are for ARM servers')
        ->and($hints[0]->body)->toContain('x86 servers need a build server');

    expect(hintIds(DeviceKind::Sbc, [ServerRole::Build], arch: 'arm64', otherRuntimeArchs: ['amd64'], otherBuildArchs: ['amd64']))->toBe([])
        ->and(hintIds(DeviceKind::Sbc, [ServerRole::Build], arch: 'arm64', otherRuntimeArchs: ['arm64']))->toBe([]);
});

it('warns when apps here have no build server of their architecture', function (): void {
    $hints = RoleHints::for(new RoleHintContext(DeviceKind::Sbc, [ServerRole::Runtime], existing: true, arch: 'arm64', otherServers: 1, otherBuildArchs: ['amd64']));

    expect($hints[0]->id)->toBe('build-arch-gap')
        ->and($hints[0]->tone)->toBe(RoleHintTone::Warning)
        ->and($hints[0]->title)->toBe('No ARM build server yet');

    // No build server at all is shown on the Servers page instead.
    expect(hintIds(DeviceKind::Sbc, [ServerRole::Runtime], existing: true, arch: 'arm64', otherServers: 1))->toBe([]);
});

it('points out the only edge when other servers could take traffic', function (): void {
    expect(hintIds(DeviceKind::Cloud, [ServerRole::Edge], existing: true, otherServers: 2))->toBe(['only-edge'])
        ->and(hintIds(DeviceKind::Cloud, [ServerRole::Edge], existing: true, otherServers: 2, otherEdges: 1))->toBe([])
        ->and(hintIds(DeviceKind::Cloud, [ServerRole::Edge], existing: true))->toBe([]);
});

it('mentions a registry at home only when other servers pull from it', function (): void {
    expect(hintIds(DeviceKind::Compact, [ServerRole::Registry], existing: true, otherServers: 1))->toBe(['registry-at-home'])
        ->and(hintIds(DeviceKind::Compact, [ServerRole::Registry], existing: true))->toBe([])
        ->and(hintIds(DeviceKind::Cloud, [ServerRole::Registry], existing: true, otherServers: 1))->toBe([]);
});

it('shows at most two hints, risks first', function (): void {
    $ids = hintIds(
        DeviceKind::Laptop,
        [ServerRole::Build, ServerRole::Runtime, ServerRole::Edge],
        existing: true,
        cpus: 1,
        memoryMb: 1024,
        otherServers: 1,
    );

    expect($ids)->toBe(['small-builder', 'edge-at-home']);
});

it('normalizes architectures from facts', function (?string $arch, ?string $expected): void {
    expect(RoleHintContext::normalizeArch($arch))->toBe($expected);
})->with([
    ['x86_64', 'amd64'],
    ['amd64', 'amd64'],
    ['aarch64', 'arm64'],
    ['arm64', 'arm64'],
    ['riscv64', null],
    [null, null],
]);
