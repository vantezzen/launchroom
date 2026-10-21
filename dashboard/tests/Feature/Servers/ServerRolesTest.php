<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Servers\UpdateServerRoles;
use App\Data\Audit\AuditActor;
use App\Data\Servers\HostFactsData;
use App\Data\Servers\RuntimeCapabilityData;
use App\Data\Servers\ServerRuntimeData;
use App\Data\Servers\UpdateServerRolesData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\DockerEngine;
use App\Enums\Servers\DockerMode;
use App\Enums\Servers\HostOs;
use App\Enums\Servers\RuntimeCapability;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Mesh\MaintainMeshJob;
use App\Models\AuditEvent;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Support\Projects;

/*
| Changing a server's roles after enrollment (docs/02 §2.2): edge, runtime
| and build; refusals name the reason; re-render, build pins and mesh tags
| follow.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->developer = memberOf($this->team, TeamRole::Developer);
});

function rolesOf(Server $server): array
{
    return array_map(static fn (ServerRole $role): string => $role->value, $server->refresh()->roles->all());
}

function updateRoles(Server $server, array $roles): Server
{
    return resolve(UpdateServerRoles::class)->handle($server, UpdateServerRolesData::from(['roles' => $roles]), AuditActor::system());
}

test('a Mac never takes the edge role; Docker Desktop never runs apps (D53)', function (): void {
    [$mac] = Projects::server($this->team, [ServerRole::Build]);
    $mac->forceFill(['facts' => new HostFactsData('mac', 'darwin', 'arm64', 8, 8192)])->save();

    expect(fn (): Server => updateRoles($mac, ['build', 'edge']))->toThrow(DomainRuleViolation::class, 'A Mac can’t serve traffic');
    expect(rolesOf(updateRoles($mac, ['build', 'runtime'])))->toBe(['runtime', 'build']);

    [$desktop] = Projects::server($this->team, [ServerRole::Build], ip: '203.0.113.8');
    $desktop->forceFill(['runtime' => new ServerRuntimeData(DockerMode::Rootful, true, [new RuntimeCapabilityData(RuntimeCapability::Workloads, CapabilityState::Unavailable)], '2026-10-20T12:00:00Z', HostOs::Darwin, DockerEngine::DockerDesktop)])->save();

    expect(fn (): Server => updateRoles($desktop, ['build', 'runtime']))->toThrow(DomainRuleViolation::class, 'Docker Desktop can’t make apps');

    // The settings tab shows why.
    $this->actingAs($this->admin)->get(route('servers.tab', [$desktop->public_id, 'settings']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('roleLimits.edge', fn (string $reason): bool => str_starts_with($reason, 'A Mac can’t serve traffic'))
            ->where('roleLimits.runtime', fn (string $reason): bool => str_starts_with($reason, 'Docker Desktop'))
            ->missing('roleLimits.build'));
});

test('admins change roles behind a password confirmation; developers cannot; audited', function (): void {
    [$cp] = Projects::server($this->team);
    Projects::server($this->team, [ServerRole::Build], ip: '203.0.113.9');

    // Without a recent password the request goes to the confirmation first.
    $this->actingAs($this->admin)->put(route('servers.roles.update', $cp->public_id), ['roles' => ['edge', 'runtime']])->assertRedirectContains('confirm-password');
    expect(rolesOf($cp))->toBe(['edge', 'runtime', 'build']);

    $this->actingAs($this->developer)->withSession(passwordConfirmed())->put(route('servers.roles.update', $cp->public_id), ['roles' => ['edge', 'runtime']])->assertForbidden();

    $this->actingAs($this->admin)->withSession(passwordConfirmed())->put(route('servers.roles.update', $cp->public_id), ['roles' => ['edge', 'runtime']])->assertRedirect();

    expect(rolesOf($cp))->toBe(['edge', 'runtime'])
        ->and(AuditEvent::query()->where('action', AuditAction::ServerRolesUpdated->value)->sole()->meta)->toEqual(['from' => ['edge', 'runtime', 'build'], 'to' => ['edge', 'runtime']]);
});

test('roles are validated: at least one, only edge/runtime/build', function (): void {
    [$server] = Projects::server($this->team);

    $this->actingAs($this->admin)->withSession(passwordConfirmed())->put(route('servers.roles.update', $server->public_id), ['roles' => []])->assertSessionHasErrors('roles');
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->put(route('servers.roles.update', $server->public_id), ['roles' => ['registry']])->assertSessionHasErrors('roles.0');
    expect(rolesOf($server))->toBe(['edge', 'runtime', 'build']);
});

test('servers of other teams cannot be changed; revoked ones neither', function (): void {
    [$foreign] = Projects::server(Team::factory()->create());
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->put(route('servers.roles.update', $foreign->public_id), ['roles' => ['edge']])->assertNotFound();

    [$revoked] = Projects::server($this->team);
    $revoked->forceFill(['status' => ServerStatus::Revoked, 'revoked_at' => now()])->save();
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->put(route('servers.roles.update', $revoked->public_id), ['roles' => ['edge']])->assertForbidden();
    expect(fn (): Server => updateRoles($revoked, ['edge']))->toThrow(DomainRuleViolation::class, 'revoked');
});

test('the last online build server and the last edge keep their roles', function (): void {
    [$server] = Projects::server($this->team);

    expect(fn (): Server => updateRoles($server, ['edge', 'runtime']))->toThrow(DomainRuleViolation::class, 'last online build server')
        ->and(fn (): Server => updateRoles($server, ['runtime', 'build']))->toThrow(DomainRuleViolation::class, 'last edge');

    // An offline builder doesn't count; an offline edge does (it comes back).
    [$other] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Build], ip: '203.0.113.9');
    $other->forceFill(['status' => ServerStatus::Offline])->save();

    expect(fn (): Server => updateRoles($server, ['edge', 'runtime']))->toThrow(DomainRuleViolation::class, 'last online build server');
    expect(rolesOf(updateRoles($server, ['runtime', 'build'])))->toBe(['runtime', 'build']);
});

test('runtime stays while the server hosts workloads or volumes', function (): void {
    [$server] = Projects::server($this->team);
    $project = Projects::create($this->team, 'shop');
    Projects::image($project, 'web');
    resolve(DeployEnvironment::class)->handle($project->production()->sole(), null, AuditActor::system());
    expect(Placement::query()->where('server_id', $server->id)->count())->toBe(1);

    expect(fn (): Server => updateRoles($server, ['edge', 'build']))->toThrow(DomainRuleViolation::class, 'still hosts 1 workload: move them');

    Placement::query()->where('server_id', $server->id)->delete();
    expect(rolesOf(updateRoles($server, ['edge', 'build'])))->toBe(['edge', 'build']);
});

test('a change re-renders the mesh servers, frees unleased build pins and syncs mesh tags', function (): void {
    [$cp] = Projects::server($this->team);
    [$home] = Projects::server($this->team, [ServerRole::Build], ip: '203.0.113.9');
    $cp->forceFill(['mesh_node_id' => '1', 'roles' => [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build, ServerRole::Registry]])->save();

    $pinned = DaemonTask::query()->create(['team_id' => $this->team->id, 'server_id' => $cp->id, 'pinned_to_server' => true, 'required_role' => ServerRole::Build, 'type' => DaemonTaskType::Build, 'spec' => (object) [], 'max_attempts' => 1, 'timeout_seconds' => 60]);
    $release = DaemonTask::query()->create(['team_id' => $this->team->id, 'server_id' => $cp->id, 'pinned_to_server' => true, 'type' => DaemonTaskType::RunOnce, 'spec' => (object) [], 'max_attempts' => 1, 'timeout_seconds' => 60]);

    Event::fake([NodeStateInvalidated::class]);
    Bus::fake([MaintainMeshJob::class]);

    // Registry is not editable and stays.
    expect(rolesOf(updateRoles($cp, ['edge', 'runtime'])))->toBe(['edge', 'runtime', 'registry']);

    Event::assertDispatched(NodeStateInvalidated::class, fn (NodeStateInvalidated $event): bool => in_array($cp->id, $event->serverIds, true) && in_array($home->id, $event->serverIds, true));
    Bus::assertDispatched(MaintainMeshJob::class);

    $pinned->refresh();
    expect($pinned->pinned_to_server)->toBeFalse()
        ->and($pinned->server_id)->toBeNull()
        ->and($pinned->status)->toBe(DaemonTaskStatus::Pending)
        ->and($release->refresh()->pinned_to_server)->toBeTrue();

    // No change: nothing happens, nothing audited twice.
    updateRoles($cp, ['runtime', 'edge']);
    expect(AuditEvent::query()->where('action', AuditAction::ServerRolesUpdated->value)->count())->toBe(1);
});

test('the server page offers roles to admins only', function (): void {
    [$server] = Projects::server($this->team);

    $this->actingAs($this->admin)->get(route('servers.tab', [$server->public_id, 'settings']))
        ->assertInertia(fn ($page) => $page->component('servers/show')->where('canUpdateRoles', true));
    $this->actingAs($this->developer)->get(route('servers.tab', [$server->public_id, 'settings']))
        ->assertInertia(fn ($page) => $page->where('canUpdateRoles', false));
});

test('launchroom:servers:update changes roles and labels through the same rules', function (): void {
    [$cp] = Projects::server($this->team);
    [$home] = Projects::server($this->team, [ServerRole::Build], ip: '203.0.113.9');
    $home->forceFill(['name' => 'server-3'])->save();

    $this->artisan('launchroom:servers:update', ['server' => $cp->public_id, '--roles' => 'edge,runtime'])
        ->expectsOutputToContain('"roles":["edge","runtime"]')
        ->assertSuccessful();
    $this->artisan('launchroom:servers:update', ['server' => 'server-3', '--labels' => 'location=home,zone=home'])
        ->expectsOutputToContain('"location":"home"')
        ->assertSuccessful();

    expect($home->refresh()->labels)->toEqual(['location' => 'home', 'zone' => 'home'])
        ->and(AuditEvent::query()->where('action', AuditAction::ServerRolesUpdated->value)->sole()->actor_type->value)->toBe('system');

    // The last build server keeps its role; bad labels and unknown servers fail.
    $this->artisan('launchroom:servers:update', ['server' => 'server-3', '--roles' => 'runtime'])
        ->expectsOutputToContain('last online build server')
        ->assertFailed();
    $this->artisan('launchroom:servers:update', ['server' => 'server-3', '--labels' => 'Bad!=x'])->assertFailed();
    $this->artisan('launchroom:servers:update', ['server' => 'nope', '--roles' => 'edge'])->assertFailed();
    $this->artisan('launchroom:servers:update', ['server' => 'server-3'])->assertExitCode(2);
});

test('launchroom:servers:update --shared-edge admits an edge in the instance owner\'s name', function (): void {
    [$edge] = Projects::server($this->team, [ServerRole::Edge, ServerRole::Runtime], ip: '203.0.113.9');
    $owner = User::factory()->create(['is_instance_owner' => true]);

    $this->artisan('launchroom:servers:update', ['server' => $edge->public_id, '--shared-edge' => 'yes'])
        ->expectsOutputToContain('"sharedEdge":true')
        ->assertSuccessful();
    expect($edge->refresh()->shared_edge_approved_by)->toBe($owner->id);

    $this->artisan('launchroom:servers:update', ['server' => $edge->public_id, '--shared-edge' => 'no'])
        ->expectsOutputToContain('"sharedEdge":false')
        ->assertSuccessful();
    $this->artisan('launchroom:servers:update', ['server' => $edge->public_id, '--shared-edge' => 'maybe'])->assertExitCode(2);
});
