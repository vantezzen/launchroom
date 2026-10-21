<?php

declare(strict_types=1);

use App\Enums\ServerRole;
use App\Models\Server;
use App\Services\Mesh\HeadscaleClient;
use App\Services\Mesh\MeshPolicy;

function meshServer(array $roles, bool $controlPlane = false): Server
{
    $server = new Server;
    $server->forceFill(['roles' => $roles, 'is_control_plane' => $controlPlane]);

    return $server;
}

test('servers are tagged by role, the control plane additionally as tag:lr-cp', function (): void {
    expect(MeshPolicy::tags(meshServer([ServerRole::Runtime, ServerRole::Build])))->toBe(['tag:lr-build', 'tag:lr-runtime'])
        ->and(MeshPolicy::tags(meshServer([ServerRole::Edge, ServerRole::Runtime, ServerRole::Build], true)))->toBe(['tag:lr-build', 'tag:lr-cp', 'tag:lr-edge', 'tag:lr-runtime'])
        ->and(MeshPolicy::tags(meshServer([])))->toBe(['tag:lr-runtime']);
});

test('the ACL is default deny with exactly the documented allowances', function (): void {
    $policy = MeshPolicy::document('launchroom');

    expect($policy)->toHaveKeys(['tagOwners', 'acls'])
        ->and(array_keys($policy))->toBe(['tagOwners', 'acls'])
        ->and($policy['tagOwners'])->toHaveKeys(['tag:lr-cp', 'tag:lr-edge', 'tag:lr-runtime', 'tag:lr-build', 'tag:lr-registry'])
        ->and(array_unique(array_merge(...array_values($policy['tagOwners']))))->toBe(['launchroom@'])
        ->and($policy['acls'])->toBe([
            ['action' => 'accept', 'src' => ['tag:lr-build', 'tag:lr-cp', 'tag:lr-edge', 'tag:lr-registry', 'tag:lr-runtime'], 'dst' => ['tag:lr-cp:443,5000']],
            ['action' => 'accept', 'src' => ['tag:lr-edge'], 'dst' => ['tag:lr-cp:6379']],
            ['action' => 'accept', 'src' => ['tag:lr-edge'], 'dst' => ['tag:lr-runtime:31000-32999']],
            ['action' => 'accept', 'src' => ['tag:lr-runtime'], 'dst' => ['tag:lr-runtime:31000-32999']],
            // Link probes between every pair of servers (docs/05 §2.1).
            ['action' => 'accept', 'src' => ['tag:lr-build', 'tag:lr-cp', 'tag:lr-edge', 'tag:lr-registry', 'tag:lr-runtime'], 'dst' => ['tag:lr-build:7400', 'tag:lr-cp:7400', 'tag:lr-edge:7400', 'tag:lr-registry:7400', 'tag:lr-runtime:7400']],
        ]);

    // Nothing reaches SSH, Caddy's admin API or arbitrary ports.
    $rendered = MeshPolicy::render('launchroom');
    expect($rendered)->not->toContain(':22')->not->toContain('2019')->not->toContain('"*"')
        ->and(json_decode($rendered, true))->toEqual($policy)
        ->and(MeshPolicy::render('launchroom'))->toBe($rendered);
});

test('API key prefixes are read from both key formats', function (): void {
    expect(HeadscaleClient::apiKeyPrefix('hskey-api-dgV97i44iYDJ-dsiFyhLFAHEkKvTy70qg7CCjov6fGtA1viyTTvmoyTktH333wda73hfmTJC6Aeqn'))->toBe('dgV97i44iYDJ')
        ->and(HeadscaleClient::apiKeyPrefix('abcdefg.secretpart'))->toBe('abcdefg')
        ->and(HeadscaleClient::apiKeyPrefix('garbage'))->toBeNull();
});
