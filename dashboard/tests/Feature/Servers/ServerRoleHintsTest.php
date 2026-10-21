<?php

declare(strict_types=1);

use App\Data\Servers\HostFactsData;
use App\Enums\ServerRole;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;

/*
| Role hints on the add-server wizard and the server page (docs/08 §14):
| computed from the team's own fleet only.
*/

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

function hintServer(Team $team, array $roles, string $arch = 'amd64', int $cpus = 4, int $memoryMb = 8192): Server
{
    return Server::factory()->online()->create([
        'team_id' => $team->id,
        'roles' => $roles,
        'facts' => new HostFactsData('host', 'linux', $arch, $cpus, $memoryMb),
    ]);
}

test('the wizard gets hints for every machine and role choice', function (): void {
    hintServer($this->team, [ServerRole::Edge, ServerRole::Runtime]);

    $this->actingAs($this->admin)->get(route('servers.create'))
        ->assertInertia(fn ($page) => $page->component('servers/create')
            ->has('roleHints', 35)
            ->has('roleHints.mac:build')
            ->where('roleHints.cloud:edge,runtime', [])
            ->where('roleHints.home:edge.0.id', 'edge-at-home')
            ->where('roleHints.home:build,runtime.0.id', 'shared-builder')
            // A small board builds for ARM; this team's apps run on x86.
            ->where('roleHints.board:build.0.id', 'build-arch')
            ->where('roleHints.board:build.0.tone', 'info'));
});

test('the wizard only looks at the current team’s servers', function (): void {
    hintServer(Team::factory()->create(), [ServerRole::Runtime]);

    $this->actingAs($this->admin)->get(route('servers.create'))
        ->assertInertia(fn ($page) => $page->where('roleHints.board:build', []));
});

test('the server page warns about a small machine building next to apps', function (): void {
    $small = hintServer($this->team, [ServerRole::Build, ServerRole::Runtime], cpus: 1, memoryMb: 1024);

    $this->actingAs($this->admin)->get(route('servers.show', $small->public_id))
        ->assertInertia(fn ($page) => $page->component('servers/show')
            ->has('roleHints', 1)
            ->where('roleHints.0.id', 'small-builder')
            ->where('roleHints.0.tone', 'warning'));
});

test('one big server doing everything gets no hints', function (): void {
    $server = hintServer($this->team, [ServerRole::Edge, ServerRole::Build, ServerRole::Runtime]);

    $this->actingAs($this->admin)->get(route('servers.show', $server->public_id))
        ->assertInertia(fn ($page) => $page->where('roleHints', []));
});

test('the server page names the only edge once other servers exist', function (): void {
    $edge = hintServer($this->team, [ServerRole::Edge, ServerRole::Runtime]);
    hintServer($this->team, [ServerRole::Build]);

    $this->actingAs($this->admin)->get(route('servers.show', $edge->public_id))
        ->assertInertia(fn ($page) => $page->where('roleHints.0.id', 'only-edge'));
});
