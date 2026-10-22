<?php

declare(strict_types=1);

use App\Data\Servers\HostFactsData;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;

function buildServer(Team $team, int $cpus, int $memoryMb, array $roles = [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build]): Server
{
    return Server::factory()->online()->create([
        'team_id' => $team->id,
        'roles' => $roles,
        'facts' => new HostFactsData('droplet', 'linux', 'amd64', $cpus, $memoryMb),
    ]);
}

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
});

test('the only build server being small shows the hint on the servers page and its server page', function (): void {
    // A 1 vCPU / 2 GB droplet reports about 1987 MB.
    $server = buildServer($this->team, 1, 1987);

    $this->actingAs($this->owner)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->where('smallServerHint', ['serverId' => $server->public_id, 'serverName' => $server->name, 'cpus' => 1, 'memoryMb' => 1987]));
    $this->actingAs($this->owner)->get(route('servers.show', $server))
        ->assertInertia(fn ($page) => $page->where('smallServerHint.serverId', $server->public_id));
    $this->actingAs($this->owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where('onboarding.smallServerHint.serverId', $server->public_id));
});

test('small means under 2 CPUs or under 3.5 GB; unknown memory never counts', function (int $cpus, int $memoryMb, bool $small): void {
    buildServer($this->team, $cpus, $memoryMb);

    $this->actingAs($this->owner)->get(route('servers.index'))
        ->assertInertia(fn ($page) => $small ? $page->whereNot('smallServerHint', null) : $page->where('smallServerHint', null));
})->with([
    '1 CPU, 8 GB' => [1, 8192, true],
    '2 CPUs, 2 GB' => [2, 1987, true],
    '2 CPUs, 4 GB droplet' => [2, 3911, false],
    '4 CPUs, 16 GB' => [4, 16384, false],
    '4 CPUs, memory unknown' => [4, 0, false],
]);

test('another build server, even a big one elsewhere, hides the hint', function (): void {
    buildServer($this->team, 1, 1987);
    buildServer($this->team, 8, 16384, [ServerRole::Build]);

    $this->actingAs($this->owner)->get(route('servers.index'))->assertInertia(fn ($page) => $page->where('smallServerHint', null));
});

test('servers that cannot build, are pending or revoked do not count', function (): void {
    $small = buildServer($this->team, 1, 1987);
    buildServer($this->team, 8, 16384, [ServerRole::Edge, ServerRole::Runtime]);
    Server::factory()->create(['team_id' => $this->team->id, 'roles' => [ServerRole::Build], 'status' => ServerStatus::PendingApproval]);

    $this->actingAs($this->owner)->get(route('servers.index'))->assertInertia(fn ($page) => $page->where('smallServerHint.serverId', $small->public_id));
});

test('the hint is dismissed per user and hidden from people who cannot add servers', function (): void {
    buildServer($this->team, 1, 1987);
    $admin = memberOf($this->team, TeamRole::Admin);
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($viewer)->get(route('servers.index'))->assertInertia(fn ($page) => $page->where('smallServerHint', null));
    $this->actingAs($viewer)->post(route('servers.small-server-hint.dismiss'))->assertForbidden();

    $this->actingAs($this->owner)->post(route('servers.small-server-hint.dismiss'))->assertRedirect();
    $this->actingAs($this->owner)->get(route('servers.index'))->assertInertia(fn ($page) => $page->where('smallServerHint', null));
    $this->actingAs($admin)->get(route('servers.index'))->assertInertia(fn ($page) => $page->whereNot('smallServerHint', null));
});
